<?php

declare(strict_types=1);

namespace Aporat\PushNotifications\Apns;

use Aporat\PushNotifications\Exceptions\InvalidMessageException;
use DateTimeInterface;

/**
 * The JSON body of a push: the "aps" dictionary plus your own keys beside it.
 *
 * Every aps key Apple documents has a method here, including the Live
 * Activity keys. Nothing is inferred from what you set: the push type and
 * priority live on the {@see Message}.
 */
final class Payload
{
    /** @var array<string, mixed> */
    private array $aps = [];

    /** @var array<string, mixed> */
    private array $custom = [];

    public static function create(): self
    {
        return new self;
    }

    public function alert(Alert|string|null $alert): self
    {
        if ($alert instanceof Alert) {
            $this->aps['alert'] = $alert->isEmpty() ? null : $alert->toArray();
        } else {
            $this->aps['alert'] = $alert;
        }

        return $this;
    }

    public function badge(?int $badge): self
    {
        $this->aps['badge'] = $badge;

        return $this;
    }

    public function sound(Sound|string|null $sound): self
    {
        $this->aps['sound'] = $sound instanceof Sound ? $sound->toValue() : $sound;

        return $this;
    }

    public function threadId(?string $threadId): self
    {
        $this->aps['thread-id'] = $threadId;

        return $this;
    }

    public function category(?string $category): self
    {
        $this->aps['category'] = $category;

        return $this;
    }

    /**
     * Wake the app to fetch content. Combined with an alert this is a
     * "hybrid" push and stays an alert at priority 10; the push type is
     * never changed behind your back.
     */
    public function contentAvailable(bool $contentAvailable = true): self
    {
        $this->aps['content-available'] = $contentAvailable ? 1 : null;

        return $this;
    }

    /**
     * Route the notification through a Notification Service Extension.
     */
    public function mutableContent(bool $mutableContent = true): self
    {
        $this->aps['mutable-content'] = $mutableContent ? 1 : null;

        return $this;
    }

    public function targetContentId(?string $targetContentId): self
    {
        $this->aps['target-content-id'] = $targetContentId;

        return $this;
    }

    public function interruptionLevel(?InterruptionLevel $level): self
    {
        $this->aps['interruption-level'] = $level?->value;

        return $this;
    }

    /**
     * @param  float|null  $score  0.0 to 1.0; sorts notifications in a summary.
     */
    public function relevanceScore(?float $score): self
    {
        $this->aps['relevance-score'] = $score === null ? null : max(0.0, min(1.0, $score));

        return $this;
    }

    public function filterCriteria(?string $criteria): self
    {
        $this->aps['filter-criteria'] = $criteria;

        return $this;
    }

    /**
     * Live Activity: "start", "update" or "end".
     */
    public function event(?string $event): self
    {
        $this->aps['event'] = $event;

        return $this;
    }

    /**
     * Live Activity: the activity's dynamic data, matching its ContentState.
     *
     * @param  array<string, mixed>|null  $state
     */
    public function contentState(?array $state): self
    {
        $this->aps['content-state'] = $state;

        return $this;
    }

    /**
     * Live Activity: when this update was produced. Required on updates.
     */
    public function timestamp(DateTimeInterface|int|null $timestamp): self
    {
        $this->aps['timestamp'] = $timestamp instanceof DateTimeInterface ? $timestamp->getTimestamp() : $timestamp;

        return $this;
    }

    /**
     * Live Activity: when the content should be shown as stale.
     */
    public function staleDate(DateTimeInterface|int|null $staleDate): self
    {
        $this->aps['stale-date'] = $staleDate instanceof DateTimeInterface ? $staleDate->getTimestamp() : $staleDate;

        return $this;
    }

    /**
     * Live Activity: when an ended activity leaves the Lock Screen.
     */
    public function dismissalDate(DateTimeInterface|int|null $dismissalDate): self
    {
        $this->aps['dismissal-date'] = $dismissalDate instanceof DateTimeInterface ? $dismissalDate->getTimestamp() : $dismissalDate;

        return $this;
    }

    /**
     * Live Activity start: the ActivityAttributes type name and values.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function attributes(string $type, array $attributes): self
    {
        $this->aps['attributes-type'] = $type;
        $this->aps['attributes'] = $attributes;

        return $this;
    }

    /**
     * Set any aps key directly, for keys newer than this library.
     */
    public function aps(string $key, mixed $value): self
    {
        $this->aps[$key] = $value;

        return $this;
    }

    /**
     * Your own top-level key beside "aps".
     *
     * @throws InvalidMessageException
     */
    public function custom(string $key, mixed $value): self
    {
        if ($key === 'aps') {
            throw new InvalidMessageException('Custom keys cannot be named "aps".');
        }

        $this->custom[$key] = $value;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $values
     *
     * @throws InvalidMessageException
     */
    public function customAll(array $values): self
    {
        foreach ($values as $key => $value) {
            $this->custom((string) $key, $value);
        }

        return $this;
    }

    public function hasAlert(): bool
    {
        return isset($this->aps['alert']);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $aps = array_filter($this->aps, static fn (mixed $value): bool => $value !== null);

        return ['aps' => (object) $aps] + $this->custom;
    }

    /**
     * @throws InvalidMessageException
     */
    public function toJson(): string
    {
        $json = json_encode($this->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            throw new InvalidMessageException('The payload could not be encoded as JSON: '.json_last_error_msg());
        }

        return $json;
    }

    /**
     * @throws InvalidMessageException
     */
    public function byteLength(): int
    {
        return strlen($this->toJson());
    }
}
