<?php

declare(strict_types=1);

namespace E258Tech\NexoraSms\Http;

/**
 * Sends one HTTP request. Implementations must return every HTTP response (including 4xx/5xx) and throw
 * {@see TransportException} only when no response was received (network failure, timeout).
 */
interface Transport
{
    /**
     * @param float $timeout Maximum time for this attempt, in seconds.
     *
     * @throws TransportException
     */
    public function send(HttpRequest $request, float $timeout): HttpResponse;
}
