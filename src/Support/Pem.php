<?php

declare(strict_types=1);

namespace Aporat\PushNotifications\Support;

use Aporat\PushNotifications\Exceptions\ConfigurationException;

/**
 * Loads private keys given either inline or as a file path, and repairs the
 * line structure of a key that passed through an environment variable.
 */
final class Pem
{
    /**
     * Accept the key's PEM text, or the path of a file holding it.
     *
     * @throws ConfigurationException
     */
    public static function load(string $keyOrPath): string
    {
        $value = trim($keyOrPath);

        if ($value === '') {
            throw new ConfigurationException('The private key is empty.');
        }

        if (str_contains($value, '-----BEGIN')) {
            return self::normalize($value);
        }

        if (! is_file($value) || ! is_readable($value)) {
            // The value is not echoed: it may be a key with a broken header.
            throw new ConfigurationException('The private key is neither PEM text nor a readable file path.');
        }

        $contents = file_get_contents($value);

        if ($contents === false || ! str_contains($contents, '-----BEGIN')) {
            throw new ConfigurationException(sprintf('The file "%s" does not contain a PEM key.', $value));
        }

        return self::normalize($contents);
    }

    /**
     * Restore the line structure of a PEM key: a paste can collapse its line
     * breaks to spaces, or hand them over as the two characters "\n", and
     * OpenSSL rejects both. Text without a PEM header is returned untouched.
     */
    public static function normalize(string $pem): string
    {
        $pem = trim(str_replace('\\n', "\n", $pem));

        if (! preg_match('/^-----BEGIN ([A-Z ]+)-----(.*)-----END \\1-----$/s', $pem, $m)) {
            return $pem;
        }

        $body = preg_replace('/\s+/', '', $m[2]);

        if (! is_string($body) || $body === '') {
            return $pem;
        }

        return "-----BEGIN {$m[1]}-----\n".chunk_split($body, 64, "\n")."-----END {$m[1]}-----\n";
    }
}
