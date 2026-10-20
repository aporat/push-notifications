<?php

declare(strict_types=1);

namespace Aporat\PushNotifications\Apns;

use Aporat\PushNotifications\Exceptions\ConfigurationException;
use Aporat\PushNotifications\Support\Pem;

/**
 * An APNs authentication key: the .p8 file Apple issues, its 10-character
 * key ID, and the team it belongs to. One key signs for every app in the
 * team, and one connection serves every one of them.
 */
final readonly class Credentials
{
    public string $privateKey;

    /**
     * @param  string  $teamId  The 10-character Team ID from the developer account.
     * @param  string  $keyId  The 10-character Key ID shown next to the key.
     * @param  string  $privateKey  The .p8 key's PEM text, or the path of the file.
     *
     * @throws ConfigurationException
     */
    public function __construct(
        public string $teamId,
        public string $keyId,
        string $privateKey,
    ) {
        if (trim($teamId) === '') {
            throw new ConfigurationException('The APNs team ID is empty.');
        }

        if (trim($keyId) === '') {
            throw new ConfigurationException('The APNs key ID is empty.');
        }

        $this->privateKey = Pem::load($privateKey);
    }

    /**
     * A stable identifier for cache keys; never the key material itself.
     */
    public function fingerprint(): string
    {
        return hash('sha256', $this->teamId.'|'.$this->keyId.'|'.$this->privateKey);
    }
}
