<?php

declare(strict_types=1);

namespace Aporat\PushNotifications\Contracts;

use Aporat\PushNotifications\Exceptions\InvalidMessageException;
use Aporat\PushNotifications\Response;

/**
 * Something that delivers messages: a platform client, or a fake in tests.
 */
interface Sender
{
    /**
     * Send one message and wait for the service's answer.
     *
     * Failures the service reports, and connection failures, come back as a
     * {@see Response}; only a message this sender cannot carry throws.
     *
     * @throws InvalidMessageException
     */
    public function send(Message $message): Response;

    /**
     * Send many messages concurrently over the same connection.
     *
     * The result has the same keys as the input, in the same order.
     *
     * @param  iterable<array-key, Message>  $messages
     * @return array<array-key, Response>
     *
     * @throws InvalidMessageException
     */
    public function sendMany(iterable $messages): array;
}
