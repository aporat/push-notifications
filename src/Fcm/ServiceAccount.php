<?php

declare(strict_types=1);

namespace Aporat\PushNotifications\Fcm;

use Aporat\PushNotifications\Exceptions\ConfigurationException;
use Aporat\PushNotifications\Support\Pem;

/**
 * A Google service account with the Firebase Cloud Messaging API role,
 * from the JSON key file the Firebase console issues.
 */
final readonly class ServiceAccount
{
    public const string DEFAULT_TOKEN_URI = 'https://oauth2.googleapis.com/token';

    public string $privateKey;

    /**
     * @param  string  $privateKey  The RSA key's PEM text, or the path of a file holding it.
     *
     * @throws ConfigurationException
     */
    public function __construct(
        public string $projectId,
        public string $clientEmail,
        string $privateKey,
        public string $tokenUri = self::DEFAULT_TOKEN_URI,
        public ?string $privateKeyId = null,
    ) {
        if (trim($projectId) === '') {
            throw new ConfigurationException('The Firebase project ID is empty.');
        }

        if (trim($clientEmail) === '') {
            throw new ConfigurationException('The service account email is empty.');
        }

        if (! str_starts_with($tokenUri, 'https://')) {
            throw new ConfigurationException('The token URI must be an https URL.');
        }

        $this->privateKey = Pem::load($privateKey);
    }

    /**
     * From the decoded contents of the key file.
     *
     * @param  array<string, mixed>  $json
     *
     * @throws ConfigurationException
     */
    public static function fromArray(array $json): self
    {
        if (isset($json['type']) && $json['type'] !== 'service_account') {
            throw new ConfigurationException(sprintf('Expected a service_account key, got "%s".', is_scalar($json['type']) ? (string) $json['type'] : gettype($json['type'])));
        }

        foreach (['project_id', 'client_email', 'private_key'] as $required) {
            if (! is_string($json[$required] ?? null) || $json[$required] === '') {
                throw new ConfigurationException(sprintf('The service account key is missing "%s".', $required));
            }
        }

        /** @var array{project_id: string, client_email: string, private_key: string, token_uri?: mixed, private_key_id?: mixed} $json */
        return new self(
            projectId: $json['project_id'],
            clientEmail: $json['client_email'],
            privateKey: $json['private_key'],
            tokenUri: is_string($json['token_uri'] ?? null) && $json['token_uri'] !== '' ? $json['token_uri'] : self::DEFAULT_TOKEN_URI,
            privateKeyId: is_string($json['private_key_id'] ?? null) ? $json['private_key_id'] : null,
        );
    }

    /**
     * @throws ConfigurationException
     */
    public static function fromJson(string $json): self
    {
        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            throw new ConfigurationException('The service account key is not valid JSON: '.json_last_error_msg());
        }

        /** @var array<string, mixed> $decoded */
        return self::fromArray($decoded);
    }

    /**
     * @throws ConfigurationException
     */
    public static function fromFile(string $path): self
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new ConfigurationException(sprintf('The service account key file "%s" is not readable.', $path));
        }

        $contents = file_get_contents($path);

        return self::fromJson($contents === false ? '' : $contents);
    }

    /**
     * A stable identifier for cache keys; never the key material itself.
     */
    public function fingerprint(): string
    {
        return hash('sha256', $this->clientEmail.'|'.$this->privateKey);
    }
}
