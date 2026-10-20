<?php

declare(strict_types=1);

namespace Aporat\PushNotifications\Fcm;

use Aporat\PushNotifications\Exceptions\InvalidMessageException;
use Aporat\PushNotifications\Response;

/**
 * Where an FCM message goes: one device, a topic, or a condition over topics.
 */
final readonly class Target
{
    /**
     * The request field a Firebase Installation ID is sent in. FCM is
     * moving per-device targeting from registration tokens to installation
     * IDs; while that migration runs, the "token" field accepts both.
     */
    public const string FID_FIELD = 'token';

    private function __construct(
        public TargetType $type,
        public string $value,
    ) {}

    /**
     * An FCM registration token (FirebaseMessaging.getToken()).
     */
    public static function token(string $token): self
    {
        return new self(TargetType::Token, $token);
    }

    /**
     * A bare Firebase Installation ID (FirebaseInstallations.getId()).
     */
    public static function fid(string $fid): self
    {
        return new self(TargetType::Fid, $fid);
    }

    /**
     * Every device subscribed to a topic. Pass the name only, without "/topics/".
     */
    public static function topic(string $topic): self
    {
        return new self(TargetType::Topic, $topic);
    }

    /**
     * A boolean expression over topics, such as "'a' in topics && 'b' in topics".
     */
    public static function condition(string $condition): self
    {
        return new self(TargetType::Condition, $condition);
    }

    public function isDevice(): bool
    {
        return $this->type === TargetType::Token || $this->type === TargetType::Fid;
    }

    /**
     * The target as it appears on a {@see Response}.
     */
    public function describe(): string
    {
        return $this->isDevice() ? $this->value : $this->type->value.':'.$this->value;
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return match ($this->type) {
            TargetType::Token => ['token' => $this->value],
            TargetType::Fid => [self::FID_FIELD => $this->value],
            TargetType::Topic => ['topic' => $this->value],
            TargetType::Condition => ['condition' => $this->value],
        };
    }

    /**
     * @throws InvalidMessageException
     */
    public function validate(): void
    {
        if (trim($this->value) === '') {
            throw new InvalidMessageException(sprintf('The FCM %s is empty.', $this->type->value));
        }

        if ($this->type === TargetType::Topic && preg_match('/^[a-zA-Z0-9\-_.~%]+$/', $this->value) !== 1) {
            throw new InvalidMessageException(sprintf('"%s" is not a valid FCM topic name.', $this->value));
        }
    }
}
