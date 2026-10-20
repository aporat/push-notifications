<?php

declare(strict_types=1);

namespace Aporat\PushNotifications\Apns;

/**
 * The aps "alert" dictionary.
 */
final class Alert
{
    private ?string $title = null;

    private ?string $subtitle = null;

    private ?string $body = null;

    private ?string $launchImage = null;

    private ?string $titleLocKey = null;

    /** @var list<string>|null */
    private ?array $titleLocArgs = null;

    private ?string $subtitleLocKey = null;

    /** @var list<string>|null */
    private ?array $subtitleLocArgs = null;

    private ?string $locKey = null;

    /** @var list<string>|null */
    private ?array $locArgs = null;

    public static function create(?string $title = null, ?string $body = null): self
    {
        $alert = new self;
        $alert->title = $title;
        $alert->body = $body;

        return $alert;
    }

    public function title(?string $title): self
    {
        $this->title = $title;

        return $this;
    }

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

    public function launchImage(?string $launchImage): self
    {
        $this->launchImage = $launchImage;

        return $this;
    }

    /**
     * @param  list<string>|null  $args
     */
    public function titleLocalized(string $key, ?array $args = null): self
    {
        $this->titleLocKey = $key;
        $this->titleLocArgs = $args;

        return $this;
    }

    /**
     * @param  list<string>|null  $args
     */
    public function subtitleLocalized(string $key, ?array $args = null): self
    {
        $this->subtitleLocKey = $key;
        $this->subtitleLocArgs = $args;

        return $this;
    }

    /**
     * @param  list<string>|null  $args
     */
    public function bodyLocalized(string $key, ?array $args = null): self
    {
        $this->locKey = $key;
        $this->locArgs = $args;

        return $this;
    }

    public function isEmpty(): bool
    {
        return $this->toArray() === [];
    }

    /**
     * @return array<string, string|list<string>>
     */
    public function toArray(): array
    {
        return array_filter([
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'body' => $this->body,
            'launch-image' => $this->launchImage,
            'title-loc-key' => $this->titleLocKey,
            'title-loc-args' => $this->titleLocArgs,
            'subtitle-loc-key' => $this->subtitleLocKey,
            'subtitle-loc-args' => $this->subtitleLocArgs,
            'loc-key' => $this->locKey,
            'loc-args' => $this->locArgs,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
