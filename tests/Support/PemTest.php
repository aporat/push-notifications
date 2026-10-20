<?php

declare(strict_types=1);

namespace Aporat\PushNotifications\Tests\Support;

use Aporat\PushNotifications\Exceptions\ConfigurationException;
use Aporat\PushNotifications\Support\Pem;
use Aporat\PushNotifications\Tests\Keys;
use PHPUnit\Framework\TestCase;

final class PemTest extends TestCase
{
    public function test_normalize_restores_line_breaks_collapsed_to_spaces(): void
    {
        $pem = Keys::ec();
        $flattened = str_replace("\n", ' ', trim($pem));

        self::assertSame($pem, Pem::normalize($flattened));
        self::assertNotFalse(openssl_pkey_get_private(Pem::normalize($flattened)));
    }

    public function test_normalize_restores_escaped_newlines(): void
    {
        $pem = Keys::ec();
        $escaped = str_replace("\n", '\n', trim($pem));

        self::assertSame($pem, Pem::normalize($escaped));
    }

    public function test_normalize_leaves_non_pem_text_alone(): void
    {
        self::assertSame('hello world', Pem::normalize('  hello world '));
    }

    public function test_load_accepts_inline_pem(): void
    {
        self::assertSame(Keys::ec(), Pem::load(Keys::ec()));
    }

    public function test_load_reads_a_file(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'pem');
        self::assertNotFalse($path);
        file_put_contents($path, Keys::ec());

        try {
            self::assertSame(Keys::ec(), Pem::load($path));
        } finally {
            unlink($path);
        }
    }

    public function test_load_rejects_a_missing_file(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('neither PEM text nor a readable file path');

        Pem::load('/nonexistent/AuthKey.p8');
    }

    public function test_load_rejects_a_file_without_a_key(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'pem');
        self::assertNotFalse($path);
        file_put_contents($path, 'nope');

        try {
            $this->expectException(ConfigurationException::class);
            $this->expectExceptionMessage('does not contain a PEM key');
            Pem::load($path);
        } finally {
            unlink($path);
        }
    }

    public function test_load_rejects_empty(): void
    {
        $this->expectException(ConfigurationException::class);

        Pem::load('   ');
    }

    public function test_load_does_not_echo_a_malformed_key(): void
    {
        $secret = 'MIGTAgEAMBMGByqGSM49AgEGCCqGSM49AwEHBHkwdwIBAQQg';

        try {
            Pem::load($secret);
            self::fail('Expected a ConfigurationException.');
        } catch (ConfigurationException $e) {
            self::assertStringNotContainsString($secret, $e->getMessage());
            self::assertStringNotContainsString(substr($secret, 0, 12), $e->getMessage());
        }
    }
}
