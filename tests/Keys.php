<?php

declare(strict_types=1);

namespace Aporat\PushNotifications\Tests;

use Aporat\PushNotifications\Support\Base64Url;
use RuntimeException;

/**
 * Throwaway keys for tests, generated once per run.
 */
final class Keys
{
    private static ?string $ec = null;

    private static ?string $rsa = null;

    public static function ec(): string
    {
        return self::$ec ??= self::generate(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    }

    public static function rsa(): string
    {
        return self::$rsa ??= self::generate(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
    }

    public static function publicKey(string $privatePem): string
    {
        $key = openssl_pkey_get_private($privatePem);
        $details = $key === false ? false : openssl_pkey_get_details($key);

        if ($details === false || ! is_string($details['key'] ?? null)) {
            throw new RuntimeException('Could not derive the public key.');
        }

        return $details['key'];
    }

    /**
     * @return array{header: array<string, mixed>, claims: array<string, mixed>, signature: string, input: string}
     */
    public static function decodeJwt(string $jwt): array
    {
        $parts = explode('.', $jwt);

        if (count($parts) !== 3) {
            throw new RuntimeException('Not a JWT.');
        }

        /** @var array<string, mixed> $header */
        $header = json_decode(Base64Url::decode($parts[0]), true);
        /** @var array<string, mixed> $claims */
        $claims = json_decode(Base64Url::decode($parts[1]), true);

        return [
            'header' => $header,
            'claims' => $claims,
            'signature' => Base64Url::decode($parts[2]),
            'input' => $parts[0].'.'.$parts[1],
        ];
    }

    /**
     * The inverse of the library's DER-to-raw conversion, so OpenSSL can verify.
     */
    public static function rawToDer(string $raw): string
    {
        $half = intdiv(strlen($raw), 2);
        $encode = static function (string $int): string {
            $int = ltrim($int, "\x00");
            if ($int === '' || (ord($int[0]) & 0x80) !== 0) {
                $int = "\x00".$int;
            }

            return "\x02".chr(strlen($int)).$int;
        };

        $body = $encode(substr($raw, 0, $half)).$encode(substr($raw, $half));

        return "\x30".chr(strlen($body)).$body;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function generate(array $config): string
    {
        $key = openssl_pkey_new($config);

        if ($key === false || ! openssl_pkey_export($key, $pem) || ! is_string($pem)) {
            throw new RuntimeException('Could not generate a key: '.(openssl_error_string() ?: 'unknown'));
        }

        return $pem;
    }
}
