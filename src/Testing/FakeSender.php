<?php

declare(strict_types=1);

namespace Aporat\PushNotifications\Testing;

use Aporat\PushNotifications\Contracts\Message;
use Aporat\PushNotifications\Contracts\Sender;
use Aporat\PushNotifications\Outcome;
use Aporat\PushNotifications\Response;
use Closure;

/**
 * A sender that records every message instead of delivering it, answering
 * with a fixed outcome or whatever a callback decides per message.
 */
final class FakeSender implements Sender
{
    /** @var list<Message> */
    private array $sent = [];

    /** @var Closure(Message): (Response|Outcome) */
    private Closure $responder;

    /**
     * @param  Outcome|Closure(Message): (Response|Outcome)|null  $respondWith
     */
    public function __construct(Outcome|Closure|null $respondWith = null)
    {
        $this->respondWith($respondWith ?? Outcome::Delivered);
    }

    /**
     * @param  Outcome|Closure(Message): (Response|Outcome)  $respondWith
     */
    public function respondWith(Outcome|Closure $respondWith): self
    {
        $this->responder = $respondWith instanceof Closure
            ? $respondWith
            : static fn (Message $message): Outcome => $respondWith;

        return $this;
    }

    public function send(Message $message): Response
    {
        $message->validate();
        $this->sent[] = $message;

        $answer = ($this->responder)($message);

        return $answer instanceof Response ? $answer : self::response($message, $answer);
    }

    public function sendMany(iterable $messages): array
    {
        $responses = [];

        foreach ($messages as $key => $message) {
            $responses[$key] = $this->send($message);
        }

        return $responses;
    }

    /**
     * Every message sent, in order.
     *
     * @return list<Message>
     */
    public function sent(): array
    {
        return $this->sent;
    }

    /**
     * The messages sent to one target.
     *
     * @return list<Message>
     */
    public function sentTo(string $target): array
    {
        return array_values(array_filter($this->sent, static fn (Message $message): bool => $message->target() === $target));
    }

    public function count(): int
    {
        return count($this->sent);
    }

    public function reset(): void
    {
        $this->sent = [];
    }

    /**
     * A plausible response for an outcome, with the status the service
     * would most likely have used.
     */
    public static function response(Message $message, Outcome $outcome): Response
    {
        $status = match ($outcome) {
            Outcome::Delivered => 200,
            Outcome::Unregistered => 410,
            Outcome::InvalidTarget, Outcome::InvalidMessage, Outcome::Failed => 400,
            Outcome::Unauthorized => 403,
            Outcome::RateLimited => 429,
            Outcome::Unavailable => 503,
        };

        return new Response(
            platform: $message->platform(),
            target: $message->target(),
            outcome: $outcome,
            statusCode: $status,
            reason: $outcome === Outcome::Delivered ? null : 'Fake'.ucfirst($outcome->name),
            id: $outcome === Outcome::Delivered ? 'fake-'.bin2hex(random_bytes(8)) : null,
        );
    }
}
