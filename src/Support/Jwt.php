<?php

declare(strict_types=1);

namespace Aporat\PushNotifications\Support;

use Aporat\PushNotifications\Exceptions\ConfigurationException;
use OpenSSLAsymmetricKey;

/**
 * Minimal JWT signing with OpenSSL: ES256 for APNs provider tokens and
 * RS256 for Google service-account assertions. Nothing is parsed or
 * verified here; the services do that.
 */
final class Jwt
{
    /**
     * @param  array<string, mixed>  $header
     * @param  array<string, mixed>  $claims
     *
     * @throws ConfigurationException
     */
    public static function es256(array $header, array $claims, string $privateKeyPem): string
    {
        $key = self::key($privateKeyPem, OPENSSL_KEYTYPE_EC, 'EC');
        $input = self::signingInput(['alg' => 'ES256'] + $header, $claims);

        if (! openssl_sign($input, $der, $key, OPENSSL_ALGO_SHA256)) {
            throw new ConfigurationException('Signing with the EC private key failed: '.(openssl_error_string() ?: 'unknown error'));
        }

        return $input.'.'.Base64Url::encode(self::derToRaw($der, 32));
    }

    /**
     * @param  array<string, mixed>  $header
     * @param  array<string, mixed>  $claims
     *
     * @throws ConfigurationException
     */
    public static function rs256(array $header, array $claims, string $privateKeyPem): string
    {
        $key = self::key($privateKeyPem, OPENSSL_KEYTYPE_RSA, 'RSA');
        $input = self::signingInput(['alg' => 'RS256'] + $header, $claims);

        if (! openssl_sign($input, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new ConfigurationException('Signing with the RSA private key failed: '.(openssl_error_string() ?: 'unknown error'));
        }

        return $input.'.'.Base64Url::encode($signature);
    }

    /**
     * @param  array<string, mixed>  $header
     * @param  array<string, mixed>  $claims
     */
    private static function signingInput(array $header, array $claims): string
    {
        return Base64Url::encode(self::json(['typ' => 'JWT'] + $header)).'.'.Base64Url::encode(self::json($claims));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function json(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            throw new ConfigurationException('The JWT could not be encoded: '.json_last_error_msg());
        }

        return $json;
    }

    /**
     * @throws ConfigurationException
     */
    private static function key(string $pem, int $expectedType, string $label): OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_get_private($pem);

        if ($key === false) {
            throw new ConfigurationException(sprintf('The %s private key could not be read: %s', $label, openssl_error_string() ?: 'not a valid PEM private key'));
        }

        $details = openssl_pkey_get_details($key);

        if ($details === false || ($details['type'] ?? null) !== $expectedType) {
            throw new ConfigurationException(sprintf('The private key is not an %s key.', $label));
        }

        return $key;
    }

    /**
     * OpenSSL emits ECDSA signatures as a DER SEQUENCE of two INTEGERs; JOSE
     * wants the two values concatenated at a fixed width (RFC 7518 §3.4).
     *
     * @throws ConfigurationException
     */
    private static function derToRaw(string $der, int $partLength): string
    {
        $length = strlen($der);
        $bytes = array_values(unpack('C*', $der) ?: []);

        if ($length < 8 || $bytes[0] !== 0x30) {
            throw new ConfigurationException('The ECDSA signature is not a DER sequence.');
        }

        // The sequence length: short form, or long form with a byte count.
        $offset = 2;
        $sequenceLength = $bytes[1];

        if ($sequenceLength >= 0x80) {
            $offset += $sequenceLength - 0x80;
        }

        $raw = '';

        for ($i = 0; $i < 2; $i++) {
            if ($offset + 1 >= $length || $bytes[$offset] !== 0x02) {
                throw new ConfigurationException('The ECDSA signature is missing an integer.');
            }

            $integerLength = $bytes[$offset + 1];
            $offset += 2;
            $integer = ltrim(substr($der, $offset, $integerLength), "\x00");
            $offset += $integerLength;

            if (strlen($integer) > $partLength) {
                throw new ConfigurationException('The ECDSA signature integer is wider than the curve allows.');
            }

            $raw .= str_pad($integer, $partLength, "\x00", STR_PAD_LEFT);
        }

        return $raw;
    }
}
