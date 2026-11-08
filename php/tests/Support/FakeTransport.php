<?php

declare(strict_types=1);

namespace E258Tech\NexoraSms\Tests\Support;

use E258Tech\NexoraSms\Http\HttpRequest;
use E258Tech\NexoraSms\Http\HttpResponse;
use E258Tech\NexoraSms\Http\Transport;
use E258Tech\NexoraSms\Http\TransportException;

/**
 * Transport that replays queued responses and records every request.
 */
final class FakeTransport implements Transport
{
    /** @var list<HttpResponse|TransportException> */
    private array $replies = [];
    /** @var list<HttpRequest> */
    public array $requests = [];
    /** @var list<float> */
    public array $timeouts = [];

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string>     $headers
     */
    public function reply(int $status, ?array $body = [], array $headers = []): self
    {
        $this->replies[] = new HttpResponse(
            $status,
            ['Content-Type' => 'application/json'] + $headers,
            $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR),
        );

        return $this;
    }

    public function fail(TransportException $error): self
    {
        $this->replies[] = $error;

        return $this;
    }

    public function send(HttpRequest $request, float $timeout): HttpResponse
    {
        $this->requests[] = $request;
        $this->timeouts[] = $timeout;
        $reply = array_shift($this->replies) ?? new HttpResponse(200, [], '{}');
        if ($reply instanceof TransportException) {
            throw $reply;
        }

        return $reply;
    }

    public function last(): HttpRequest
    {
        $last = end($this->requests);
        if ($last === false) {
            throw new \LogicException('No requests.');
        }

        return $last;
    }

    /**
     * Path and query of a recorded request (without the base URL).
     */
    public function target(int $index): string
    {
        $parts = parse_url($this->requests[$index]->url);

        return ($parts['path'] ?? '') . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }

    /**
     * @return mixed Decoded JSON body of a recorded request.
     */
    public function body(int $index): mixed
    {
        $body = $this->requests[$index]->body;

        return $body === null ? null : json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    }
}
