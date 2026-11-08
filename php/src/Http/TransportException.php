<?php

declare(strict_types=1);

namespace E258Tech\NexoraSms\Http;

/**
 * Thrown by a {@see Transport} when no HTTP response was received.
 */
class TransportException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $timedOut = false,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
