<?php

declare(strict_types=1);

namespace Aporat\PushNotifications;

/**
 * The push service a message travels through.
 */
enum Platform: string
{
    case Apns = 'apns';
    case Fcm = 'fcm';
}
