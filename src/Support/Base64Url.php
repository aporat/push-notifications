<?php

declare(strict_types=1);

namespace Aporat\PushNotifications\Support;

/**
 * The URL-safe, unpadded base64 alphabet JWTs use (RFC 7515 §2).
 */
final class Base64Url
{
    public static function encode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function decode(string $data): string
    {
        $decoded = base64_decode(strtr($data, '-_', '+/'), true);

        return $decoded === false ? '' : $decoded;
    }
}
