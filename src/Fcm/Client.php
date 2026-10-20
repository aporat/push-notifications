<?php

declare(strict_types=1);

namespace Aporat\PushNotifications\Fcm;

use Aporat\PushNotifications\Clock\SystemClock;
use Aporat\PushNotifications\Contracts\Message as MessageContract;
use Aporat\PushNotifications\Contracts\Sender;
use Aporat\PushNotifications\Exceptions\AuthenticationException;
use Aporat\PushNotifications\Exceptions\ConfigurationException;
use Aporat\PushNotifications\Exceptions\InvalidMessageException;
use Aporat\PushNotifications\Exceptions\PushException;
use Aporat\PushNotifications\Outcome;
use Aporat\PushNotifications\Platform;
use Aporat\PushNotifications\Response;
use Aporat\PushNotifications\Support\Http;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\EachPromise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Sends through the FCM HTTP v1 API over a persistent HTTP/2 connection.
 *
 * Build one client per Firebase project and keep it for the life of the
 * process. FCM has no batch endpoint, so a fan-out is one request per
 * device, multiplexed over the connection by {@see sendMany()}.
 *
 * @phpstan-type Options array{
 *     timeout?: int|float,
 *     connect_timeout?: int|float,
 *     proxy?: string|array{http?: string|null, https?: string|null, no?: array<string>|string|null},
 *     concurrency?: int,
 *     token_refresh_margin?: int,
 * }
 */
final class Client implements Sender
{
    public const string BASE_URI = 'https://fcm.googleapis.com';

    public const int DEFAULT_CONCURRENCY = 50;

    private readonly ClientInterface $http;

    private readonly AccessTokenProvider $token;

    private readonly ClockInterface $clock;

    private readonly LoggerInterface $logger;

    private readonly int $concurrency;

    /**
     * @param  Options  $options
     * @param  ClientInterface|null  $http  A Guzzle client to use instead of the built-in HTTP/2 one; for tests, or to add middleware.
     * @param  AccessTokenProvider|null  $token  A token provider to share between clients of the same service account.
     * @param  CacheItemPoolInterface|null  $cache  Shares the access token with other processes.
     *
     * @throws ConfigurationException
     */
    public function __construct(
        private readonly ServiceAccount $account,
        array $options = [],
        ?ClientInterface $http = null,
        ?AccessTokenProvider $token = null,
        ?CacheItemPoolInterface $cache = null,
        ?ClockInterface $clock = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->clock = $clock ?? new SystemClock;
        $this->logger = $logger ?? new NullLogger;
        $this->concurrency = max(1, $options['concurrency'] ?? self::DEFAULT_CONCURRENCY);
        $this->http = $http ?? Http::client(self::BASE_URI, $options);
        $this->token = $token ?? new AccessTokenProvider(
            $account,
            $this->http,
            $this->clock,
            $cache,
            $options['token_refresh_margin'] ?? AccessTokenProvider::DEFAULT_REFRESH_MARGIN,
        );
    }

    public function account(): ServiceAccount
    {
        return $this->account;
    }

    /**
     * @throws AuthenticationException
     */
    public function send(MessageContract $message): Response
    {
        $message = $this->accept($message);
        $message->validate();

        return $this->await($this->sendAsync($message));
    }

    /**
     * @throws AuthenticationException
     */
    public function sendMany(iterable $messages): array
    {
        /** @var array<array-key, Message> $accepted */
        $accepted = [];

        foreach ($messages as $key => $message) {
            $accepted[$key] = $this->accept($message);
            $accepted[$key]->validate();
        }

        // One token for the whole batch, fetched before anything is sent,
        // so a credential problem throws here instead of after a partial send.
        $this->token->get();

        /** @var array<array-key, Response> $results */
        $results = [];

        $failure = null;

        $promises = (function () use ($accepted) {
            foreach ($accepted as $key => $message) {
                yield $key => $this->sendAsync($message);
            }
        })();

        (new EachPromise($promises, [
            'concurrency' => $this->concurrency,
            'fulfilled' => function (mixed $response, int|string $key) use (&$results, &$failure): void {
                if ($response instanceof Response) {
                    $results[$key] = $response;
                } else {
                    $failure ??= new PushException('A send settled without a response.');
                }
            },
            // Without this, EachPromise drops rejections on the floor and
            // an auth or configuration failure mid-batch would surface as
            // a generic transport failure, or not at all.
            'rejected' => function (mixed $reason) use (&$failure): void {
                $failure ??= $reason instanceof Throwable ? $reason : new PushException('A send was rejected without an exception.');
            },
        ]))->promise()->wait();

        if ($failure !== null) {
            throw $failure;
        }

        $ordered = [];

        foreach ($accepted as $key => $message) {
            $ordered[$key] = $results[$key] ?? Response::transportFailure(Platform::Fcm, $message->target(), 'No response was produced.');
        }

        return $ordered;
    }

    /**
     * Resolves to a {@see Response}.
     */
    private function sendAsync(Message $message, bool $retried = false): PromiseInterface
    {
        try {
            $accessToken = $this->token->get();
            $request = $this->request($message, $accessToken);
        } catch (Throwable $e) {
            return Create::rejectionFor($e);
        }

        return $this->http->sendAsync($request)->then(
            function (ResponseInterface $response) use ($message, $retried, $accessToken): PromiseInterface|Response {
                if (! $retried && $response->getStatusCode() === 401) {
                    $this->logger->info('FCM rejected the access token; fetching a new one and retrying once.');
                    $this->token->refreshIfStale($accessToken);

                    return $this->sendAsync($message, true);
                }

                $parsed = $this->parse($message, $response);
                $this->log($parsed);

                return $parsed;
            },
            function (mixed $reason) use ($message): Response {
                // With http_errors off this only happens through middleware
                // a caller added; the answer is still Apple's or Google's.
                if ($reason instanceof BadResponseException) {
                    $parsed = $this->parse($message, $reason->getResponse());
                    $this->log($parsed);

                    return $parsed;
                }

                $failure = Response::transportFailure(
                    Platform::Fcm,
                    $message->target(),
                    $reason instanceof Throwable ? Http::describeFailure($reason) : 'The request was rejected without an exception.',
                );
                $this->log($failure);

                return $failure;
            },
        );
    }

    private function request(Message $message, string $accessToken): Request
    {
        $headers = ['authorization' => 'Bearer '.$accessToken, 'content-type' => 'application/json'];

        return (new Request('POST', sprintf('/v1/projects/%s/messages:send', rawurlencode($this->account->projectId)), $headers, $message->body()))
            ->withProtocolVersion('2.0');
    }

    private function parse(Message $message, ResponseInterface $response): Response
    {
        $status = $response->getStatusCode();
        $body = Http::decode((string) $response->getBody());

        if ($status === 200) {
            return new Response(
                platform: Platform::Fcm,
                target: $message->target(),
                outcome: Outcome::Delivered,
                statusCode: 200,
                id: is_string($body['name'] ?? null) ? $body['name'] : null,
                body: $body,
            );
        }

        $error = is_array($body['error'] ?? null) ? $body['error'] : [];
        $rpcStatus = is_string($error['status'] ?? null) ? $error['status'] : null;
        $text = is_string($error['message'] ?? null) ? $error['message'] : 'FCM returned HTTP '.$status;

        [$errorCode, $tokenRejected] = $this->details(is_array($error['details'] ?? null) ? $error['details'] : []);

        $outcome = match (true) {
            $tokenRejected => Outcome::InvalidTarget,
            $errorCode !== null => $errorCode->outcome(),
            default => ErrorCode::outcomeForStatus($rpcStatus, $status),
        };

        return new Response(
            platform: Platform::Fcm,
            target: $message->target(),
            outcome: $outcome,
            statusCode: $status,
            reason: $errorCode === null ? $rpcStatus : $errorCode->value,
            message: $text,
            retryAfter: $response->hasHeader('retry-after')
                ? Http::retryAfter($response->getHeaderLine('retry-after'), $this->clock->now()->getTimestamp())
                : null,
            body: $body,
        );
    }

    /**
     * Read the FCM error code and whether a BadRequest named the target
     * field, which turns a generic INVALID_ARGUMENT into "this token is bad".
     *
     * @param  array<array-key, mixed>  $details
     * @return array{0: ErrorCode|null, 1: bool}
     */
    private function details(array $details): array
    {
        $errorCode = null;
        $tokenRejected = false;

        foreach ($details as $detail) {
            if (! is_array($detail)) {
                continue;
            }

            if (is_string($detail['errorCode'] ?? null)) {
                $errorCode ??= ErrorCode::tryFrom($detail['errorCode']);
            }

            $violations = $detail['fieldViolations'] ?? null;

            if (! is_array($violations)) {
                continue;
            }

            foreach ($violations as $violation) {
                $field = is_array($violation) && is_string($violation['field'] ?? null) ? $violation['field'] : '';

                if (str_ends_with($field, '.token') || $field === 'token') {
                    $tokenRejected = true;
                }
            }
        }

        return [$errorCode, $tokenRejected];
    }

    /**
     * @throws PushException
     */
    private function await(PromiseInterface $promise): Response
    {
        $result = $promise->wait();

        if (! $result instanceof Response) {
            throw new PushException('The send did not produce a response.');
        }

        return $result;
    }

    /**
     * @throws InvalidMessageException
     */
    private function accept(MessageContract $message): Message
    {
        if (! $message instanceof Message) {
            throw new InvalidMessageException(sprintf('The FCM client cannot send a %s message.', $message->platform()->value));
        }

        return $message;
    }

    private function log(Response $response): void
    {
        $context = [
            'project' => $this->account->projectId,
            'target' => $response->target,
            'status' => $response->statusCode,
            'reason' => $response->reason,
            'name' => $response->id,
        ];

        if ($response->succeeded()) {
            $this->logger->debug('FCM accepted the message.', $context);
        } else {
            $this->logger->warning('FCM did not accept the message.', $context + ['outcome' => $response->outcome->value, 'message' => $response->message]);
        }
    }
}
