<?php

declare(strict_types=1);

namespace Aporat\PushNotifications\Apns;

use Aporat\PushNotifications\Contracts\Message as MessageContract;
use Aporat\PushNotifications\Exceptions\InvalidMessageException;
use Aporat\PushNotifications\Platform;
use DateTimeInterface;

/**
 * One push to one device: the payload plus the request headers.
 *
 * The push type is chosen when the message is created and never inferred
 * from the payload, so an alert with content-available stays an alert, and
 * a background push that asks for priority 10 is refused rather than sent.
 */
final class Message implements MessageContract
{
    private ?string $bundleId = null;

    private ?string $topic = null;

    private ?Priority $priority = null;

    private ?int $expiration = null;

    private ?string $collapseId = null;

    private ?string $id = null;

    private function __construct(
        private readonly PushType $pushType,
        private readonly string $deviceToken,
        private Payload $payload,
    ) {}

    public static function of(PushType $pushType, string $deviceToken, ?Payload $payload = null): self
    {
        return new self($pushType, $deviceToken, $payload ?? Payload::create());
    }

    public static function alert(string $deviceToken, ?Payload $payload = null): self
    {
        return self::of(PushType::Alert, $deviceToken, $payload);
    }

    public static function background(string $deviceToken, ?Payload $payload = null): self
    {
        return self::of(PushType::Background, $deviceToken, $payload ?? Payload::create()->contentAvailable());
    }

    public static function voip(string $deviceToken, ?Payload $payload = null): self
    {
        return self::of(PushType::Voip, $deviceToken, $payload);
    }

    public static function liveActivity(string $deviceToken, ?Payload $payload = null): self
    {
        return self::of(PushType::LiveActivity, $deviceToken, $payload);
    }

    public static function complication(string $deviceToken, ?Payload $payload = null): self
    {
        return self::of(PushType::Complication, $deviceToken, $payload);
    }

    public static function fileProvider(string $deviceToken, ?Payload $payload = null): self
    {
        return self::of(PushType::FileProvider, $deviceToken, $payload);
    }

    public static function location(string $deviceToken, ?Payload $payload = null): self
    {
        return self::of(PushType::Location, $deviceToken, $payload);
    }

    public static function pushToTalk(string $deviceToken, ?Payload $payload = null): self
    {
        return self::of(PushType::PushToTalk, $deviceToken, $payload);
    }

    public static function widgets(string $deviceToken, ?Payload $payload = null): self
    {
        return self::of(PushType::Widgets, $deviceToken, $payload);
    }

    public static function controls(string $deviceToken, ?Payload $payload = null): self
    {
        return self::of(PushType::Controls, $deviceToken, $payload);
    }

    public static function accessory(string $deviceToken, ?Payload $payload = null): self
    {
        return self::of(PushType::Accessory, $deviceToken, $payload);
    }

    public static function mdm(string $deviceToken, string $topic, ?Payload $payload = null): self
    {
        return self::of(PushType::Mdm, $deviceToken, $payload)->topic($topic);
    }

    /**
     * The app's bundle ID. The topic is derived from it and the push type.
     */
    public function bundleId(string $bundleId): self
    {
        $this->bundleId = $bundleId;

        return $this;
    }

    /**
     * Send to this exact apns-topic instead of deriving it from the bundle ID.
     */
    public function topic(string $topic): self
    {
        $this->topic = $topic;

        return $this;
    }

    public function priority(Priority $priority): self
    {
        $this->priority = $priority;

        return $this;
    }

    /**
     * When APNs should stop trying to deliver, as a UNIX timestamp. Zero
     * means try once and never store the notification.
     */
    public function expiresAt(DateTimeInterface|int $expiration): self
    {
        $this->expiration = $expiration instanceof DateTimeInterface ? $expiration->getTimestamp() : $expiration;

        return $this;
    }

    public function expiresIn(int $seconds): self
    {
        return $this->expiresAt(time() + max(0, $seconds));
    }

    /**
     * Deliver once and never store for later.
     */
    public function neverStore(): self
    {
        return $this->expiresAt(0);
    }

    /**
     * Merge this notification with earlier ones carrying the same ID (max 64 bytes).
     */
    public function collapseId(string $collapseId): self
    {
        $this->collapseId = $collapseId;

        return $this;
    }

    /**
     * Your own apns-id, a canonical UUID. APNs generates one when omitted.
     */
    public function id(string $id): self
    {
        $this->id = strtolower($id);

        return $this;
    }

    public function withPayload(Payload $payload): self
    {
        $this->payload = $payload;

        return $this;
    }

    public function payload(): Payload
    {
        return $this->payload;
    }

    public function pushType(): PushType
    {
        return $this->pushType;
    }

    public function deviceToken(): string
    {
        return $this->deviceToken;
    }

    public function resolvedTopic(): ?string
    {
        if ($this->topic !== null) {
            return $this->topic;
        }

        return $this->bundleId === null ? null : $this->pushType->topic($this->bundleId);
    }

    public function resolvedPriority(): Priority
    {
        return $this->priority ?? $this->pushType->defaultPriority();
    }

    public function expiration(): ?int
    {
        return $this->expiration;
    }

    public function getCollapseId(): ?string
    {
        return $this->collapseId;
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function platform(): Platform
    {
        return Platform::Apns;
    }

    public function target(): string
    {
        return $this->deviceToken;
    }

    /**
     * The request headers, without authorization.
     *
     * @return array<string, string>
     */
    public function headers(): array
    {
        $headers = [
            'apns-topic' => (string) $this->resolvedTopic(),
            'apns-push-type' => $this->pushType->value,
            'apns-priority' => (string) $this->resolvedPriority()->value,
        ];

        if ($this->expiration !== null) {
            $headers['apns-expiration'] = (string) $this->expiration;
        }

        if ($this->collapseId !== null) {
            $headers['apns-collapse-id'] = $this->collapseId;
        }

        if ($this->id !== null) {
            $headers['apns-id'] = $this->id;
        }

        return $headers;
    }

    /**
     * @throws InvalidMessageException
     */
    public function body(): string
    {
        return $this->payload->toJson();
    }

    /**
     * @throws InvalidMessageException
     */
    public function validate(): void
    {
        if ($this->deviceToken === '' || ! ctype_xdigit($this->deviceToken)) {
            throw new InvalidMessageException('The device token must be a non-empty hexadecimal string.');
        }

        $topic = $this->resolvedTopic();

        if ($topic === null || $topic === '') {
            throw new InvalidMessageException('The message has no topic: set the app with bundleId() or the topic with topic().');
        }

        if ($this->pushType === PushType::Background) {
            if ($this->resolvedPriority() === Priority::Immediate) {
                throw new InvalidMessageException('A background push must use priority 5 or 1; Apple rejects priority 10.');
            }

            if ($this->payload->hasAlert()) {
                throw new InvalidMessageException('A background push must not carry an alert; use Message::alert() for a visible notification.');
            }
        }

        if ($this->pushType === PushType::LiveActivity) {
            $aps = $this->payload->toArray()['aps'];
            $aps = is_object($aps) ? get_object_vars($aps) : [];

            if (! isset($aps['event']) || ! isset($aps['timestamp'])) {
                throw new InvalidMessageException('A Live Activity push needs both event() and timestamp() on its payload.');
            }
        }

        if ($this->expiration !== null && $this->expiration < 0) {
            throw new InvalidMessageException('The expiration must be a UNIX timestamp, or 0 to never store the notification.');
        }

        if ($this->collapseId !== null && strlen($this->collapseId) > 64) {
            throw new InvalidMessageException('The collapse ID must not exceed 64 bytes.');
        }

        if ($this->id !== null && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $this->id) !== 1) {
            throw new InvalidMessageException('The apns-id must be a canonical UUID (8-4-4-4-12 lowercase hex).');
        }

        $size = $this->payload->byteLength();
        $max = $this->pushType->maxPayloadBytes();

        if ($size > $max) {
            throw new InvalidMessageException(sprintf('The payload is %d bytes; APNs allows %d for %s pushes.', $size, $max, $this->pushType->value));
        }
    }
}
