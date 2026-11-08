<?php

declare(strict_types=1);

namespace E258Tech\NexoraSms\Http;

/**
 * An HTTP response returned by a {@see Transport}. Header names are stored in lower case.
 */
final class HttpResponse
{
    /** @var array<string, string> */
    public readonly array $headers;

    /**
     * @param array<string, string> $headers Header name => value (any case; normalised to lower case).
     */
    public function __construct(
        public readonly int $status,
        array $headers = [],
        public readonly string $body = '',
    ) {
        $normalised = [];
        foreach ($headers as $name => $value) {
            $normalised[strtolower((string) $name)] = $value;
        }
        $this->headers = $normalised;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
