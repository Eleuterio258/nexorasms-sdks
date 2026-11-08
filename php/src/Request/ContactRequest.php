<?php

declare(strict_types=1);

namespace E258Tech\NexoraSms\Request;

/**
 * Body of create, upsert and update (PATCH) of a contact. Only the fields that were set are sent.
 * Build it with {@see self::builder()}.
 */
final class ContactRequest
{
    /**
     * @internal Use ContactRequest::builder().
     *
     * @param array<string, mixed> $body
     */
    public function __construct(private readonly array $body)
    {
    }

    public static function builder(): ContactRequestBuilder
    {
        return new ContactRequestBuilder();
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
