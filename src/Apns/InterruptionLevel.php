<?php

declare(strict_types=1);

namespace Aporat\PushNotifications\Apns;

/**
 * The aps "interruption-level" key (iOS 15+).
 */
enum InterruptionLevel: string
{
    case Passive = 'passive';
    case Active = 'active';
    case TimeSensitive = 'time-sensitive';
    case Critical = 'critical';
}
