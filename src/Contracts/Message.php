<?php

declare(strict_types=1);

namespace Aporat\PushNotifications\Contracts;

use Aporat\PushNotifications\Exceptions\InvalidMessageException;
use Aporat\PushNotifications\Platform;
use Aporat\PushNotifications\Response;

/**
 * A fully built, platform-specific push message.
 */
interface Message
{
    public function platform(): Platform;

    /**
     * The device token, topic or condition this message is addressed to,
     * as it will be reported on the {@see Response}.
     */
    public function target(): string;

    /**
     * Check the message against the service's rules before it is sent.
     *
     * @throws InvalidMessageException
     */
    public function validate(): void;
}
