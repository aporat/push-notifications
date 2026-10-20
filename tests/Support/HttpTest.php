<?php

declare(strict_types=1);

namespace Aporat\PushNotifications\Tests\Support;

use Aporat\PushNotifications\Support\Http;
use PHPUnit\Framework\TestCase;

final class HttpTest extends TestCase
{
    public function test_retry_after_seconds(): void
    {
        self::assertSame(120, Http::retryAfter('120', 1000));
    }

    public function test_retry_after_http_date(): void
    {
        $now = 1700000000;
        $later = gmdate('D, d M Y H:i:s \G\M\T', $now + 90);

        self::assertSame(90, Http::retryAfter($later, $now));
    }

    public function test_retry_after_in_the_past_is_zero(): void
    {
        $now = 1700000000;

        self::assertSame(0, Http::retryAfter(gmdate('D, d M Y H:i:s \G\M\T', $now - 5), $now));
    }

    public function test_retry_after_garbage_is_null(): void
    {
        self::assertNull(Http::retryAfter('soon', 1));
        self::assertNull(Http::retryAfter('', 1));
    }

    public function test_decode(): void
    {
        self::assertSame(['reason' => 'BadDeviceToken'], Http::decode('{"reason":"BadDeviceToken"}'));
        self::assertSame([], Http::decode(''));
        self::assertSame([], Http::decode('not json'));
        self::assertSame([], Http::decode('"a string"'));
    }

    public function test_client_speaks_http2(): void
    {
        Http::assertHttp2();
        $client = Http::client('https://api.push.apple.com:443', ['timeout' => 5]);

        self::assertSame('2.0', $client->getConfig('version'));
        self::assertSame(5, $client->getConfig('timeout'));
        self::assertFalse($client->getConfig('http_errors'));
    }

    public function test_describe_failure_strips_urls(): void
    {
        $e = new \RuntimeException('cURL error 28: Operation timed out (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for https://api.push.apple.com/3/device/abc');
        self::assertSame('cURL error 28: Operation timed out', Http::describeFailure($e));

        self::assertSame('Could not resolve proxy [url]', Http::describeFailure(new \RuntimeException('Could not resolve proxy http://user:pass@proxy.local:8080')));
        self::assertSame(\RuntimeException::class, Http::describeFailure(new \RuntimeException('')));
    }
}
