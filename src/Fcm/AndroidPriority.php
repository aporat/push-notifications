<?php

declare(strict_types=1);

namespace Aporat\PushNotifications\Fcm;

/**
 * The android.priority field. High wakes a dozing device and is what lets a
 * data-only message start the app's messaging service in the background.
 */
enum AndroidPriority: string
{
    case High = 'high';
    case Normal = 'normal';
}
