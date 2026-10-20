<?php

declare(strict_types=1);

namespace Aporat\PushNotifications\Fcm;

use Aporat\PushNotifications\Outcome;

/**
 * The FCM v1 error codes (google.firebase.fcm.v1.FcmError.errorCode), each
 * mapped onto the normalized {@see Outcome}.
 */
enum ErrorCode: string
{
    case UnspecifiedError = 'UNSPECIFIED_ERROR';
    case InvalidArgument = 'INVALID_ARGUMENT';
    case Unregistered = 'UNREGISTERED';
    case SenderIdMismatch = 'SENDER_ID_MISMATCH';
    case QuotaExceeded = 'QUOTA_EXCEEDED';
    case Unavailable = 'UNAVAILABLE';
    case Internal = 'INTERNAL';
    case ThirdPartyAuthError = 'THIRD_PARTY_AUTH_ERROR';

    public function outcome(): Outcome
    {
        return match ($this) {
            self::Unregistered => Outcome::Unregistered,
            self::SenderIdMismatch => Outcome::InvalidTarget,
            self::InvalidArgument => Outcome::InvalidMessage,
            self::QuotaExceeded => Outcome::RateLimited,
            self::Unavailable, self::Internal => Outcome::Unavailable,
            self::ThirdPartyAuthError => Outcome::Unauthorized,
            self::UnspecifiedError => Outcome::Failed,
        };
    }

    /**
     * The outcome for a google.rpc.Status code when no FCM error code was given.
     *
     * A bare 404 says nothing about the token: a wrong project ID answers 404
     * to every send, and treating that as "unregistered" would have a
     * listener delete every token. FCM always attaches the UNREGISTERED
     * error code when it means the token, so only that maps to
     * {@see Outcome::Unregistered}.
     */
    public static function outcomeForStatus(?string $rpcStatus, int $httpStatus): Outcome
    {
        return match ($rpcStatus) {
            'NOT_FOUND' => Outcome::Failed,
            'INVALID_ARGUMENT' => Outcome::InvalidMessage,
            'UNAUTHENTICATED', 'PERMISSION_DENIED' => Outcome::Unauthorized,
            'RESOURCE_EXHAUSTED' => Outcome::RateLimited,
            'UNAVAILABLE', 'INTERNAL', 'DEADLINE_EXCEEDED' => Outcome::Unavailable,
            default => match (true) {
                $httpStatus === 401, $httpStatus === 403 => Outcome::Unauthorized,
                $httpStatus === 429 => Outcome::RateLimited,
                $httpStatus >= 500 => Outcome::Unavailable,
                default => Outcome::Failed,
            },
        };
    }
}
