<?php

declare(strict_types=1);

namespace Aporat\PushNotifications;

/**
 * Delivery priority of a cross-platform {@see Notification}.
 *
 * High maps to apns-priority 10 and FCM "high"; Normal to apns-priority 5
 * and FCM "normal".
 */
enum Priority: string
{
    case High = 'high';
    case Normal = 'normal';
}
