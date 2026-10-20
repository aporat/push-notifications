<?php

declare(strict_types=1);

namespace Aporat\PushNotifications\Apns;

/**
 * The apns-priority header.
 */
enum Priority: int
{
    /** Deliver immediately. The default for every push type but background. */
    case Immediate = 10;

    /** Deliver when the device's power state allows. Required for background pushes. */
    case Conserve = 5;

    /** Put the device's power first and never wake it. */
    case Low = 1;
}
