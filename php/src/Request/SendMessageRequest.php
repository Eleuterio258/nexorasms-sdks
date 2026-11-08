<?php

declare(strict_types=1);

namespace E258Tech\NexoraSms\Request;

/**
 * A validated direct send (body of POST /api/v1/messages and /estimate). Build it with {@see self::builder()}.
 */
final class SendMessageRequest
{
    /**
     * @internal Use SendMessageRequest::builder().
     *
     * @param array<string, mixed> $body
     */
    public function __construct(
        private readonly array $body,
        public readonly ?string $idempotencyKey = null,
    ) {
    }

    public static function builder(): SendMessageRequestBuilder
    {
        return new SendMessageRequestBuilder();
    }

    /**
     * The JSON body, in the API's snake_case.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->body;
    }
}
