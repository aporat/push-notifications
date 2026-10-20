<?php

declare(strict_types=1);

namespace Aporat\PushNotifications\Apns;

use Aporat\PushNotifications\Clock\SystemClock;
use Aporat\PushNotifications\Contracts\Message as MessageContract;
use Aporat\PushNotifications\Contracts\Sender;
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
 * Sends to APNs over one persistent HTTP/2 connection with token-based
 * authentication.
 *
 * Build one client per team and environment and keep it for the life of
 * the process: Apple binds a connection to the team that first pushes on
 * it, asks providers to reuse connections for hours, and treats connection
 * churn as abuse. Every app in the team sends through the same client; the
 * message carries its bundle ID.
 *
 * @phpstan-type Options array{
 *     port?: int,
 *     timeout?: int|float,
 *     connect_timeout?: int|float,
 *     proxy?: string|array{http?: string|null, https?: string|null, no?: array<string>|string|null},
 *     concurrency?: int,
 *     token_refresh_after?: int,
 * }
 */
final class Client implements Sender
{
    public const int DEFAULT_CONCURRENCY = 50;

    private readonly ClientInterface $http;

    private readonly ProviderToken $token;

    private readonly ClockInterface $clock;

    private readonly LoggerInterface $logger;

    private readonly int $concurrency;

    /**
     * @param  Options  $options
     * @param  ClientInterface|null  $http  A Guzzle client to use instead of the built-in HTTP/2 one; for tests, or to add middleware.
     * @param  ProviderToken|null  $token  A token provider to share between clients of the same key.
     * @param  CacheItemPoolInterface|null  $cache  Shares the provider token with other processes.
     *
     * @throws ConfigurationException
     */
    public function __construct(
        private readonly Credentials $credentials,
        private readonly Environment $environment = Environment::Production,
        private readonly array $options = [],
        ?ClientInterface $http = null,
        ?ProviderToken $token = null,
        ?CacheItemPoolInterface $cache = null,
        ?ClockInterface $clock = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->clock = $clock ?? new SystemClock;
        $this->logger = $logger ?? new NullLogger;
        $this->concurrency = max(1, $options['concurrency'] ?? self::DEFAULT_CONCURRENCY);
        $this->token = $token ?? new ProviderToken(
            $credentials,
            $this->clock,
            $cache,
            $options['token_refresh_after'] ?? ProviderToken::DEFAULT_REFRESH_AFTER,
        );
        $this->http = $http ?? Http::client($environment->baseUri($options['port'] ?? 443), $options);
    }

    /**
     * The same key and settings against the other gateway, on its own
     * connection. The provider token is shared, since one key signs for
     * both environments.
     */
    public function withEnvironment(Environment $environment): self
    {
        if ($environment === $this->environment) {
            return $this;
        }

        return new self($this->credentials, $environment, $this->options, null, $this->token, null, $this->clock, $this->logger);
    }

    public function environment(): Environment
    {
        return $this->environment;
    }

    public function credentials(): Credentials
    {
        return $this->credentials;
    }

    public function send(MessageContract $message): Response
    {
        $message = $this->accept($message);
        $message->validate();

        return $this->await($this->sendAsync($message));
    }

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
            $ordered[$key] = $results[$key] ?? Response::transportFailure(Platform::Apns, $message->target(), 'No response was produced.');
        }

        return $ordered;
    }

    /**
     * Resolves to a {@see Response}.
     */
    private function sendAsync(Message $message, bool $retried = false): PromiseInterface
    {
        try {
            $providerToken = $this->token->get();
            $request = $this->request($message, $providerToken);
        } catch (Throwable $e) {
            return Create::rejectionFor($e);
        }

        return $this->http->sendAsync($request)->then(
            function (ResponseInterface $response) use ($message, $retried, $providerToken): PromiseInterface|Response {
                $parsed = $this->parse($message, $response);

                if (! $retried && $parsed->reason === Reason::ExpiredProviderToken->value) {
                    $this->logger->info('APNs reported an expired provider token; minting a new one and retrying once.');
                    $this->token->refreshIfStale($providerToken);

                    return $this->sendAsync($message, true);
                }

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
                    Platform::Apns,
                    $message->target(),
                    $reason instanceof Throwable ? Http::describeFailure($reason) : 'The request was rejected without an exception.',
                );
                $this->log($failure);

                return $failure;
            },
        );
    }

    private function request(Message $message, string $providerToken): Request
    {
        $headers = ['authorization' => 'bearer '.$providerToken, 'content-type' => 'application/json'] + $message->headers();

        return (new Request('POST', '/3/device/'.$message->deviceToken(), $headers, $message->body()))
            ->withProtocolVersion('2.0');
    }

    private function parse(Message $message, ResponseInterface $response): Response
    {
        $status = $response->getStatusCode();
        $body = Http::decode((string) $response->getBody());

        $reasonString = is_string($body['reason'] ?? null) ? $body['reason'] : null;
        $reason = $reasonString === null ? null : Reason::tryFrom($reasonString);

        $outcome = $status === 200
            ? Outcome::Delivered
            : ($reason?->outcome() ?? Reason::outcomeForStatus($status));

        $headers = [];

        foreach (['apns-id', 'apns-unique-id'] as $name) {
            if ($response->hasHeader($name)) {
                $headers[$name] = $response->getHeaderLine($name);
            }
        }

        $timestamp = $body['timestamp'] ?? null;

        return new Response(
            platform: Platform::Apns,
            target: $message->target(),
            outcome: $outcome,
            statusCode: $status,
            reason: $reasonString,
            id: $headers['apns-id'] ?? $message->getId(),
            message: $outcome === Outcome::Delivered ? null : ($reasonString ?? 'APNs returned HTTP '.$status),
            timestamp: is_int($timestamp) || (is_string($timestamp) && ctype_digit($timestamp)) ? (int) $timestamp : null,
            retryAfter: $response->hasHeader('retry-after')
                ? Http::retryAfter($response->getHeaderLine('retry-after'), $this->clock->now()->getTimestamp())
                : null,
            body: $body,
            headers: $headers,
        );
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
            throw new InvalidMessageException(sprintf('The APNs client cannot send a %s message.', $message->platform()->value));
        }

        return $message;
    }

    private function log(Response $response): void
    {
        $context = [
            'environment' => $this->environment->value,
            'target' => $response->target,
            'status' => $response->statusCode,
            'reason' => $response->reason,
            'apns_id' => $response->id,
        ];

        if ($response->succeeded()) {
            $this->logger->debug('APNs accepted the notification.', $context);
        } else {
            $this->logger->warning('APNs did not accept the notification.', $context + ['outcome' => $response->outcome->value]);
        }
    }
}
