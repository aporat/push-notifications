<?php

declare(strict_types=1);

namespace Aporat\PushNotifications\Fcm;

use Aporat\PushNotifications\Contracts\Message as MessageContract;
use Aporat\PushNotifications\Exceptions\InvalidMessageException;
use Aporat\PushNotifications\Platform;

/**
 * One FCM v1 message. Build either a "notification" message, which the
 * system displays when the app is in the background, or a data-only
 * message, which always wakes the app's messaging service and leaves the
 * display to the app; or both.
 */
final class Message implements MessageContract
{
    public const int MAX_BYTES = 4096;

    /** @var array<string, string>|null */
    private ?array $notification = null;

    /** @var array<string, string> */
    private array $data = [];

    /** @var array<string, mixed> */
    private array $android = [];

    /** @var array<string, mixed> */
    private array $apns = [];

    /** @var array<string, mixed> */
    private array $webpush = [];

    /** @var array<string, mixed> */
    private array $fcmOptions = [];

    private bool $validateOnly = false;

    private function __construct(private readonly Target $target) {}

    /**
     * @param  Target|string  $target  A {@see Target}, or a registration token.
     */
    public static function to(Target|string $target): self
    {
        return new self(is_string($target) ? Target::token($target) : $target);
    }

    /**
     * The system-displayed notification. On Android this is posted by the
     * system while the app is in the background; the app's handler runs
     * only in the foreground.
     */
    public function notification(?string $title, ?string $body = null, ?string $image = null): self
    {
        $notification = array_filter(['title' => $title, 'body' => $body, 'image' => $image], static fn (?string $v): bool => $v !== null);
        $this->notification = $notification === [] ? null : $notification;

        return $this;
    }

    /**
     * Custom key-value pairs delivered to the app. FCM requires string
     * values: scalars are cast, booleans become "true"/"false", nulls are
     * dropped and anything else is JSON-encoded.
     *
     * @param  array<array-key, mixed>  $data
     */
    public function data(array $data): self
    {
        foreach ($data as $key => $value) {
            $key = (string) $key;

            if ($key === '' || $value === null) {
                continue;
            }

            $this->data[$key] = match (true) {
                is_bool($value) => $value ? 'true' : 'false',
                is_scalar($value) => (string) $value,
                default => (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            };
        }

        return $this;
    }

    public function priority(AndroidPriority $priority): self
    {
        $this->android['priority'] = $priority->value;

        return $this;
    }

    /**
     * How long FCM keeps the message for an offline device, in seconds (0 to 2419200).
     */
    public function ttl(int $seconds): self
    {
        $this->android['ttl'] = max(0, $seconds).'s';

        return $this;
    }

    /**
     * Only the latest message with this key is kept for an offline device.
     */
    public function collapseKey(string $collapseKey): self
    {
        $this->android['collapse_key'] = $collapseKey;

        return $this;
    }

    public function restrictedPackageName(string $packageName): self
    {
        $this->android['restricted_package_name'] = $packageName;

        return $this;
    }

    /**
     * Fields of android.notification: channel_id, sound, icon, color, tag,
     * click_action, notification_count, ticker, visibility and the rest.
     *
     * @param  array<string, mixed>  $fields
     */
    public function androidNotification(array $fields): self
    {
        $current = is_array($this->android['notification'] ?? null) ? $this->android['notification'] : [];
        $this->android['notification'] = array_replace($current, $fields);

        return $this;
    }

    public function channelId(string $channelId): self
    {
        return $this->androidNotification(['channel_id' => $channelId]);
    }

    /**
     * The app icon badge: android.notification.notification_count, and
     * aps.badge for an iOS device reached through FCM.
     */
    public function badge(int $badge): self
    {
        $this->androidNotification(['notification_count' => $badge]);
        $this->apnsAps(['badge' => $badge]);

        return $this;
    }

    /**
     * The notification sound: android.notification.sound and aps.sound.
     */
    public function sound(string $sound): self
    {
        $this->androidNotification(['sound' => $sound]);
        $this->apnsAps(['sound' => $sound]);

        return $this;
    }

    /**
     * Any field of the android config, merged over what is set.
     *
     * @param  array<string, mixed>  $fields
     */
    public function android(array $fields): self
    {
        $this->android = array_replace_recursive($this->android, $fields);

        return $this;
    }

    /**
     * The apns override used when FCM delivers to an iOS device: "headers"
     * and "payload", merged over what is set.
     *
     * @param  array<string, mixed>  $fields
     */
    public function apns(array $fields): self
    {
        $this->apns = array_replace_recursive($this->apns, $fields);

        return $this;
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    public function webpush(array $fields): self
    {
        $this->webpush = array_replace_recursive($this->webpush, $fields);

        return $this;
    }

    /**
     * The label FCM aggregates delivery analytics under (up to 50 characters).
     */
    public function analyticsLabel(string $label): self
    {
        $this->fcmOptions['analytics_label'] = $label;

        return $this;
    }

    /**
     * Ask FCM to validate the message without delivering it.
     */
    public function validateOnly(bool $validateOnly = true): self
    {
        $this->validateOnly = $validateOnly;

        return $this;
    }

    public function getTarget(): Target
    {
        return $this->target;
    }

    public function hasNotification(): bool
    {
        return $this->notification !== null;
    }

    /**
     * @return array<string, string>
     */
    public function getData(): array
    {
        return $this->data;
    }

    public function isValidateOnly(): bool
    {
        return $this->validateOnly;
    }

    public function platform(): Platform
    {
        return Platform::Fcm;
    }

    public function target(): string
    {
        return $this->target->describe();
    }

    /**
     * The "message" object of the request body.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $message = $this->target->toArray();

        if ($this->notification !== null) {
            $message['notification'] = $this->notification;
        }

        if ($this->data !== []) {
            $message['data'] = $this->data;
        }

        if ($this->android !== []) {
            $message['android'] = $this->android;
        }

        if ($this->apns !== []) {
            $message['apns'] = $this->apns;
        }

        if ($this->webpush !== []) {
            $message['webpush'] = $this->webpush;
        }

        if ($this->fcmOptions !== []) {
            $message['fcm_options'] = $this->fcmOptions;
        }

        return $message;
    }

    /**
     * The whole request body.
     *
     * @return array<string, mixed>
     */
    public function toRequest(): array
    {
        $request = ['message' => $this->toArray()];

        if ($this->validateOnly) {
            $request['validate_only'] = true;
        }

        return $request;
    }

    /**
     * @throws InvalidMessageException
     */
    public function body(): string
    {
        $json = json_encode($this->toRequest(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            throw new InvalidMessageException('The message could not be encoded as JSON: '.json_last_error_msg());
        }

        return $json;
    }

    /**
     * @throws InvalidMessageException
     */
    public function validate(): void
    {
        $this->target->validate();

        if ($this->notification === null && $this->data === []) {
            throw new InvalidMessageException('The message carries neither a notification nor data.');
        }

        foreach (array_keys($this->data) as $key) {
            if (in_array($key, ['from', 'notification', 'message_type'], true) || str_starts_with($key, 'google') || str_starts_with($key, 'gcm')) {
                throw new InvalidMessageException(sprintf('"%s" is a reserved FCM data key.', $key));
            }
        }

        $size = strlen((string) json_encode($this->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        if ($size > self::MAX_BYTES) {
            throw new InvalidMessageException(sprintf('The message is %d bytes; FCM allows %d.', $size, self::MAX_BYTES));
        }
    }

    /**
     * @param  array<string, mixed>  $aps
     */
    private function apnsAps(array $aps): void
    {
        $this->apns(['payload' => ['aps' => $aps]]);
    }
}
