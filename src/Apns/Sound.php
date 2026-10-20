<?php

declare(strict_types=1);

namespace Aporat\PushNotifications\Apns;

/**
 * The aps "sound" key: a sound file name, or the critical-alert dictionary.
 */
final readonly class Sound
{
    private function __construct(
        public string $name,
        public bool $critical,
        public ?float $volume,
    ) {}

    public static function default(): self
    {
        return new self('default', false, null);
    }

    public static function named(string $name): self
    {
        return new self($name, false, null);
    }

    /**
     * A critical alert, which plays even when the device is muted or in
     * Do Not Disturb. Needs Apple's critical alerts entitlement.
     *
     * @param  float|null  $volume  0.0 to 1.0.
     */
    public static function critical(string $name = 'default', ?float $volume = null): self
    {
        return new self($name, true, $volume === null ? null : max(0.0, min(1.0, $volume)));
    }

    /**
     * @return string|array{name: string, critical: int, volume?: float}
     */
    public function toValue(): string|array
    {
        if (! $this->critical) {
            return $this->name;
        }

        $value = ['name' => $this->name, 'critical' => 1];

        if ($this->volume !== null) {
            $value['volume'] = $this->volume;
        }

        return $value;
    }
}
