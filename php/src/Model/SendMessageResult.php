<?php

declare(strict_types=1);

namespace E258Tech\NexoraSms\Model;

use E258Tech\NexoraSms\Internal\Json;

/**
 * Result of messages()->send(): one campaign per channel, invalid recipients, and the Idempotency-Key used.
 */
final class SendMessageResult
{
    /**
     * @param list<SentChannel> $channels
     * @param list<string>      $invalid  Recipients the API rejected (bad phone/email format).
     * @param string            $idempotencyKey The key sent (yours or generated).
     * @param bool              $replayed True when the API returned the result of an earlier identical request.
     */
    public function __construct(
        public readonly array $channels,
        public readonly array $invalid,
        public readonly string $idempotencyKey,
        public readonly bool $replayed,
    ) {
    }

    /**
     * @internal
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data, string $idempotencyKey, bool $replayed): self
    {
        return new self(
            array_map(SentChannel::fromArray(...), Json::objList($data, 'channels')),
            Json::strList($data, 'invalid'),
            $idempotencyKey,
            $replayed,
        );
    }
}
