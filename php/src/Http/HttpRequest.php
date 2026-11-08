<?php

declare(strict_types=1);

namespace E258Tech\NexoraSms\Http;

/**
 * An HTTP request prepared by the SDK, handed to a {@see Transport}.
 */
final class HttpRequest
{
    /**
     * @param array<string, string> $headers Header name => value.
     */
    public function __construct(
        public readonly string $method,
        public readonly string $url,
        public readonly array $headers,
        public readonly ?string $body = null,
    ) {
    }
}
