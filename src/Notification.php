<?php

declare(strict_types=1);

namespace Aporat\PushNotifications;

use Aporat\PushNotifications\Apns\Alert;
use Aporat\PushNotifications\Apns\Message as ApnsMessage;
use Aporat\PushNotifications\Apns\Payload;
use Aporat\PushNotifications\Apns\Priority as ApnsPriority;
use Aporat\PushNotifications\Apns\Sound;
use Aporat\PushNotifications\Fcm\AndroidPriority;
use Aporat\PushNotifications\Fcm\Message as FcmMessage;
use Aporat\PushNotifications\Fcm\Target;
use Closure;

/**
 * A user-facing notification described once and rendered for either
 * service: an APNs alert push, or an FCM message.
 *
 * Only what both services share lives here. Anything platform-specific is
 * set through {@see apns()} and {@see fcm()}, which hand you the rendered
 * platform message with its full API; nothing is hidden behind a lowest
 * common denominator.
 */
final class Notification
{
    private ?string $title = null;

    private ?string $subtitle = null;

    private ?string $body = null;

    private ?int $badge = null;

    private ?string $sound = null;

    private ?string $image = null;

    /** @var array<string, mixed> */
    private array $data = [];

    private ?string $collapseId = null;

    private Priority $priority = Priority::High;

    private ?int $ttl = null;

    private bool $contentAvailable = false;

    private bool $dataOnly = false;

    /** @var list<Closure(ApnsMessage): void> */
    private array $apnsCustomizers = [];

    /** @var list<Closure(FcmMessage): void> */
    private array $fcmCustomizers = [];

    public static function create(?string $title = null, ?string $body = null): self
    {
        $notification = new self;
        $notification->title = $title;
        $notification->body = $body;

        return $notification;
    }

    public function title(?string $title): self
    {
        $this->title = $title;

        return $this;
    }

    /**
     * APNs shows a subtitle under the title; FCM has no field for it, so it
     * is dropped there.
     */
    public function subtitle(?string $subtitle): self
    {
        $this->subtitle = $subtitle;

        return $this;
    }

    public function body(?string $body): self
    {
        $this->body = $body;

        return $this;
    }

    /**
     * The app icon badge count.
     */
    public function badge(?int $badge): self
    {
        $this->badge = $badge;

        return $this;
    }

    /**
     * A sound file name, or "default".
     */
    public function sound(?string $sound): self
    {
        $this->sound = $sound;

        return $this;
    }

    /**
     * An image URL shown with the notification. FCM only; APNs needs a
     * Notification Service Extension to attach media, so set it there
     * through {@see apns()} as a custom key.
     */
    public function image(?string $image): self
    {
        $this->image = $image;

        return $this;
    }

    /**
     * Custom keys delivered to the app: top-level keys beside "aps" on
     * APNs, the "data" map on FCM.
     *
     * @param  array<string, mixed>  $data
     */
    public function data(array $data): self
    {
        $this->data = $data + $this->data;

        return $this;
    }

    /**
     * Replace earlier notifications carrying the same ID: apns-collapse-id
     * on APNs, android.collapse_key on FCM.
     */
    public function collapseId(?string $collapseId): self
    {
        $this->collapseId = $collapseId;

        return $this;
    }

    public function priority(Priority $priority): self
    {
        $this->priority = $priority;

        return $this;
    }

    /**
     * How long the service keeps the notification for an offline device,
     * in seconds. Null leaves each service's default.
     */
    public function ttl(?int $seconds): self
    {
        $this->ttl = $seconds;

        return $this;
    }

    /**
     * APNs: also set content-available so the app wakes to fetch content.
     * The push stays an alert at its priority. FCM data messages wake the
     * app regardless, so this changes nothing there.
     */
    public function contentAvailable(bool $contentAvailable = true): self
    {
        $this->contentAvailable = $contentAvailable;

        return $this;
    }

    /**
     * FCM: send a data-only message, with the title, body and badge inside
     * "data", instead of a "notification" block. The app's messaging
     * service then runs in every state and posts the notification itself,
     * which is the closest FCM comes to an APNs alert with content-available.
     * APNs rendering is unchanged.
     */
    public function dataOnly(bool $dataOnly = true): self
    {
        $this->dataOnly = $dataOnly;

        return $this;
    }

    /**
     * Adjust the rendered APNs message: any header, any aps key.
     *
     * @param  Closure(ApnsMessage): void  $customizer
     */
    public function apns(Closure $customizer): self
    {
        $this->apnsCustomizers[] = $customizer;

        return $this;
    }

    /**
     * Adjust the rendered FCM message: android, apns and webpush configs.
     *
     * @param  Closure(FcmMessage): void  $customizer
     */
    public function fcm(Closure $customizer): self
    {
        $this->fcmCustomizers[] = $customizer;

        return $this;
    }

    /**
     * Render as an APNs alert push for one device of one app.
     */
    public function toApns(string $deviceToken, string $bundleId): ApnsMessage
    {
        $payload = Payload::create()
            ->alert(Alert::create($this->title, $this->body)->subtitle($this->subtitle))
            ->badge($this->badge)
            ->sound($this->sound === null ? null : Sound::named($this->sound))
            ->contentAvailable($this->contentAvailable)
            ->customAll($this->data);

        $message = ApnsMessage::alert($deviceToken, $payload)
            ->bundleId($bundleId)
            ->priority($this->priority === Priority::High ? ApnsPriority::Immediate : ApnsPriority::Conserve);

        if ($this->collapseId !== null) {
            $message->collapseId($this->collapseId);
        }

        if ($this->ttl !== null) {
            $message->expiresIn($this->ttl);
        }

        foreach ($this->apnsCustomizers as $customizer) {
            $customizer($message);
        }

        return $message;
    }

    /**
     * Render as an FCM message for a device, topic or condition.
     */
    public function toFcm(Target|string $target): FcmMessage
    {
        $message = FcmMessage::to($target)
            ->priority($this->priority === Priority::High ? AndroidPriority::High : AndroidPriority::Normal);

        if ($this->dataOnly) {
            $message->data($this->data + array_filter([
                'title' => $this->title,
                'body' => $this->body,
                'badge' => $this->badge,
                'image' => $this->image,
            ], static fn (mixed $v): bool => $v !== null));
        } else {
            $message->notification($this->title, $this->body, $this->image)->data($this->data);

            if ($this->badge !== null) {
                $message->badge($this->badge);
            }

            if ($this->sound !== null) {
                $message->sound($this->sound);
            }
        }

        if ($this->collapseId !== null) {
            $message->collapseKey($this->collapseId);
        }

        if ($this->ttl !== null) {
            $message->ttl($this->ttl);
        }

        foreach ($this->fcmCustomizers as $customizer) {
            $customizer($message);
        }

        return $message;
    }
}
