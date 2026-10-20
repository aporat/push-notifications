<?php

declare(strict_types=1);

namespace Aporat\PushNotifications;

/**
 * The answer to one push request.
 *
 * Every send returns one of these, whether the service accepted the message,
 * rejected it, or could not be reached at all. Only configuration and
 * message-construction errors throw.
 */
final readonly class Response
{
    /**
     * @param  Platform  $platform  The service that answered.
     * @param  string  $target  The device token, topic or condition the message was sent to.
     * @param  Outcome  $outcome  The normalized result.
     * @param  int  $statusCode  The HTTP status, or 0 when no response was received.
     * @param  string|null  $reason  The service's own error code: an APNs reason string
     *                               such as "Unregistered", or an FCM error code such as
     *                               "UNREGISTERED". Null on success.
     * @param  string|null  $id  The service's identifier for the message: the apns-id
     *                           header, or the FCM message name.
     * @param  string|null  $message  A human-readable description of a failure.
     * @param  int|null  $timestamp  APNs only: when the token became inactive, in
     *                               milliseconds since the epoch (sent with 410).
     * @param  int|null  $retryAfter  Seconds to wait before retrying, when the service said.
     * @param  array<string, mixed>  $body  The decoded response body, if any.
     * @param  array<string, string>  $headers  Response headers worth keeping, lower-cased.
     */
    public function __construct(
        public Platform $platform,
        public string $target,
        public Outcome $outcome,
        public int $statusCode = 200,
        public ?string $reason = null,
        public ?string $id = null,
        public ?string $message = null,
        public ?int $timestamp = null,
        public ?int $retryAfter = null,
        public array $body = [],
        public array $headers = [],
    ) {}

    /**
     * A response for a request that never reached the service.
     */
    public static function transportFailure(Platform $platform, string $target, string $message): self
    {
        return new self(
            platform: $platform,
            target: $target,
            outcome: Outcome::Unavailable,
            statusCode: 0,
            reason: 'TransportFailure',
            message: $message,
        );
    }

    public function succeeded(): bool
    {
        return $this->outcome->succeeded();
    }

    public function isRetryable(): bool
    {
        return $this->outcome->isRetryable();
    }

    public function tokenIsUnregistered(): bool
    {
        return $this->outcome->tokenIsUnregistered();
    }
}
