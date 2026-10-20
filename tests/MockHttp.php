<?php

declare(strict_types=1);

namespace Aporat\PushNotifications\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Throwable;

/**
 * A Guzzle client over a scripted queue that records every request.
 */
final class MockHttp
{
    public readonly MockHandler $handler;

    public readonly Client $client;

    /** @var list<array{request: RequestInterface, options: array<string, mixed>}> */
    public array $history = [];

    /**
     * @param  list<Response|Throwable>  $queue
     */
    public function __construct(array $queue = [], string $baseUri = 'https://example.test')
    {
        $this->handler = new MockHandler($queue);
        $stack = HandlerStack::create($this->handler);
        $stack->push(Middleware::history($this->history));
        $this->client = new Client(['handler' => $stack, 'base_uri' => $baseUri, 'http_errors' => false]);
    }

    /**
     * @param  array<string, string>  $headers
     */
    public function queue(int $status, string|array $body = '', array $headers = []): void
    {
        $this->handler->append(new Response($status, $headers, is_array($body) ? (string) json_encode($body) : $body));
    }

    public function queueFailure(Throwable $e): void
    {
        $this->handler->append($e);
    }

    public function request(int $index = 0): RequestInterface
    {
        return $this->history[$index]['request'];
    }

    public function count(): int
    {
        return count($this->history);
    }
}
