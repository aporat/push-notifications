<?php

declare(strict_types=1);

namespace Aporat\PushNotifications;

/**
 * The normalized result of one push, shared by every platform.
 *
 * Apple's reason strings and Google's FCM error codes both map onto these
 * cases, so callers can decide what to do (drop the token, retry later,
 * fix the message) without knowing which service answered.
 */
enum Outcome: string
{
    /** The service accepted the message for delivery. */
    case Delivered = 'delivered';

    /**
     * The device token is permanently inactive for this app: the app was
     * uninstalled or the token rotated. Stop pushing to it and remove it.
     */
    case Unregistered = 'unregistered';

    /**
     * The target can never be delivered to as sent: a malformed token, a
     * token from the other APNs environment, or a token issued to another
     * sender. Not retryable, but not proof the device is gone either.
     */
    case InvalidTarget = 'invalid_target';

    /** The message itself was rejected: too large, bad headers, bad fields. */
    case InvalidMessage = 'invalid_message';

    /** The service rejected the provider credentials. */
    case Unauthorized = 'unauthorized';

    /** Too many requests; retry after a delay. */
    case RateLimited = 'rate_limited';

    /** The service or the connection failed; retry with backoff. */
    case Unavailable = 'unavailable';

    /** Rejected for a reason that fits none of the above. */
    case Failed = 'failed';

    public function succeeded(): bool
    {
        return $this === self::Delivered;
    }

    /**
     * Whether the same message may succeed if sent again later.
     */
    public function isRetryable(): bool
    {
        return $this === self::RateLimited || $this === self::Unavailable;
    }

    /**
     * Whether the target token should be removed from storage.
     */
    public function tokenIsUnregistered(): bool
    {
        return $this === self::Unregistered;
    }
}
