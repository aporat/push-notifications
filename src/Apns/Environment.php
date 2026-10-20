<?php

declare(strict_types=1);

namespace Aporat\PushNotifications\Apns;

/**
 * The APNs gateway a client talks to. Tokens from a development build only
 * exist on the sandbox; App Store and TestFlight builds only on production.
 */
enum Environment: string
{
    case Production = 'production';
    case Sandbox = 'sandbox';

    public function host(): string
    {
        return match ($this) {
            self::Production => 'api.push.apple.com',
            self::Sandbox => 'api.sandbox.push.apple.com',
        };
    }

    /**
     * Apple also accepts 2197, for firewalls that must single out APNs traffic.
     */
    public function baseUri(int $port = 443): string
    {
        return sprintf('https://%s:%d', $this->host(), $port);
    }
}
