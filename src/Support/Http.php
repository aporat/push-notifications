<?php

declare(strict_types=1);

namespace Aporat\PushNotifications\Support;

use Aporat\PushNotifications\Exceptions\ConfigurationException;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\RequestOptions;
use Throwable;

/**
 * Builds the HTTP client both services share the shape of: HTTP/2 over a
 * curl multi handle that lives as long as the client, so a long-running
 * worker keeps one connection open and multiplexes requests over it.
 */
final class Http
{
    public const int DEFAULT_TIMEOUT = 30;

    public const int DEFAULT_CONNECT_TIMEOUT = 10;

    /**
     * @param  array{timeout?: int|float, connect_timeout?: int|float, proxy?: string|array{http?: string|null, https?: string|null, no?: array<string>|string|null}}  $options
     *
     * @throws ConfigurationException
     */
    public static function client(string $baseUri, array $options = []): ClientInterface
    {
        self::assertHttp2();

        $config = [
            'base_uri' => $baseUri,
            'handler' => HandlerStack::create(new CurlMultiHandler),
            RequestOptions::HTTP_ERRORS => false,
            RequestOptions::ALLOW_REDIRECTS => false,
            RequestOptions::VERSION => '2.0',
            RequestOptions::TIMEOUT => $options['timeout'] ?? self::DEFAULT_TIMEOUT,
            RequestOptions::CONNECT_TIMEOUT => $options['connect_timeout'] ?? self::DEFAULT_CONNECT_TIMEOUT,
        ];

        if (isset($options['proxy'])) {
            $config[RequestOptions::PROXY] = $options['proxy'];
        }

        return new Client($config);
    }

    /**
     * APNs speaks HTTP/2 only, so a curl without nghttp2 fails on every push.
     * Catch that at construction instead.
     *
     * @throws ConfigurationException
     */
    public static function assertHttp2(): void
    {
        $version = curl_version();

        if ($version === false || (($version['features'] ?? 0) & CURL_VERSION_HTTP2) === 0) {
            throw new ConfigurationException('The curl extension was built without HTTP/2 support, which APNs and FCM require.');
        }
    }

    /**
     * Decode a JSON response body into an array, or an empty array when the
     * body is empty or not JSON.
     *
     * @return array<string, mixed>
     */
    public static function decode(string $body): array
    {
        if (trim($body) === '') {
            return [];
        }

        $decoded = json_decode($body, true);

        /** @var array<string, mixed> */
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * A connection failure's message without the request URL, which for
     * APNs carries the device token and behind a proxy may carry its
     * credentials.
     */
    public static function describeFailure(Throwable $e): string
    {
        $message = $e->getMessage();
        $message = preg_replace('~\\s*\\(see https?://\\S+\\)~', '', $message) ?? $message;
        $message = preg_replace('~\\s+for https?://\\S+~', '', $message) ?? $message;
        $message = preg_replace('~https?://\\S+~', '[url]', $message) ?? $message;
        $message = trim($message);

        return $message === '' ? $e::class : $message;
    }

    /**
     * Parse a Retry-After header into seconds, accepting both the delay-seconds
     * and the HTTP-date forms.
     */
    public static function retryAfter(string $header, int $now): ?int
    {
        $header = trim($header);

        if ($header === '') {
            return null;
        }

        if (ctype_digit($header)) {
            return (int) $header;
        }

        $at = strtotime($header);

        return $at === false ? null : max(0, $at - $now);
    }
}
