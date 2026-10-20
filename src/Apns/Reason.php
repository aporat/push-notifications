<?php

declare(strict_types=1);

namespace Aporat\PushNotifications\Apns;

use Aporat\PushNotifications\Outcome;

/**
 * The "reason" strings APNs returns with a failed request, from Apple's
 * "Handling notification responses from APNs", each mapped onto the
 * normalized {@see Outcome}.
 */
enum Reason: string
{
    // 400
    case BadCollapseId = 'BadCollapseId';
    case BadDeviceToken = 'BadDeviceToken';
    case BadExpirationDate = 'BadExpirationDate';
    case BadMessageId = 'BadMessageId';
    case BadPriority = 'BadPriority';
    case BadTopic = 'BadTopic';
    case DeviceTokenNotForTopic = 'DeviceTokenNotForTopic';
    case DuplicateHeaders = 'DuplicateHeaders';
    case IdleTimeout = 'IdleTimeout';
    case InvalidPushType = 'InvalidPushType';
    case MissingDeviceToken = 'MissingDeviceToken';
    case MissingTopic = 'MissingTopic';
    case PayloadEmpty = 'PayloadEmpty';
    case TopicDisallowed = 'TopicDisallowed';

    // 403
    case BadCertificate = 'BadCertificate';
    case BadCertificateEnvironment = 'BadCertificateEnvironment';
    case ExpiredProviderToken = 'ExpiredProviderToken';
    case Forbidden = 'Forbidden';
    case InvalidProviderToken = 'InvalidProviderToken';
    case MissingProviderToken = 'MissingProviderToken';
    case UnrelatedKeyIdInToken = 'UnrelatedKeyIdInToken';
    case BadEnvironmentKeyIdInToken = 'BadEnvironmentKeyIdInToken';

    // 404, 405
    case BadPath = 'BadPath';
    case MethodNotAllowed = 'MethodNotAllowed';

    // 410
    case ExpiredToken = 'ExpiredToken';
    case Unregistered = 'Unregistered';

    // 413
    case PayloadTooLarge = 'PayloadTooLarge';

    // 429
    case TooManyProviderTokenUpdates = 'TooManyProviderTokenUpdates';
    case TooManyRequests = 'TooManyRequests';

    // 500, 503
    case InternalServerError = 'InternalServerError';
    case ServiceUnavailable = 'ServiceUnavailable';
    case Shutdown = 'Shutdown';

    public function outcome(): Outcome
    {
        return match ($this) {
            self::BadDeviceToken,
            self::DeviceTokenNotForTopic,
            self::MissingDeviceToken => Outcome::InvalidTarget,

            self::BadCollapseId,
            self::BadExpirationDate,
            self::BadMessageId,
            self::BadPriority,
            self::BadTopic,
            self::DuplicateHeaders,
            self::InvalidPushType,
            self::MissingTopic,
            self::PayloadEmpty,
            self::BadPath,
            self::MethodNotAllowed,
            self::PayloadTooLarge => Outcome::InvalidMessage,

            self::TopicDisallowed,
            self::BadCertificate,
            self::BadCertificateEnvironment,
            self::ExpiredProviderToken,
            self::Forbidden,
            self::InvalidProviderToken,
            self::MissingProviderToken,
            self::UnrelatedKeyIdInToken,
            self::BadEnvironmentKeyIdInToken => Outcome::Unauthorized,

            self::ExpiredToken,
            self::Unregistered => Outcome::Unregistered,

            self::TooManyProviderTokenUpdates,
            self::TooManyRequests => Outcome::RateLimited,

            self::IdleTimeout,
            self::InternalServerError,
            self::ServiceUnavailable,
            self::Shutdown => Outcome::Unavailable,
        };
    }

    /**
     * The outcome for a status whose reason string this library does not
     * know, or whose body carried none.
     */
    public static function outcomeForStatus(int $status): Outcome
    {
        return match (true) {
            $status === 200 => Outcome::Delivered,
            $status === 410 => Outcome::Unregistered,
            $status === 429 => Outcome::RateLimited,
            $status === 403 => Outcome::Unauthorized,
            $status === 413 => Outcome::InvalidMessage,
            $status >= 500 => Outcome::Unavailable,
            default => Outcome::Failed,
        };
    }
}
