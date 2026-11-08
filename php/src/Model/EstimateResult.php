<?php

declare(strict_types=1);

namespace E258Tech\NexoraSms\Model;

use E258Tech\NexoraSms\Internal\Json;

/**
 * Result of messages()->estimate(): cost per channel, total and available balance. Nothing is sent.
 */
final class EstimateResult
{
    /**
     * @param list<EstimatedChannel> $channels
     * @param list<string>           $invalid
     */
    public function __construct(
        public readonly array $channels,
        public readonly float $total,
        public readonly float $available,
        public readonly array $invalid,
    ) {
    }

    /**
     * @internal
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            array_map(EstimatedChannel::fromArray(...), Json::objList($data, 'channels')),
            Json::float($data, 'total'),
            Json::float($data, 'available'),
            Json::strList($data, 'invalid'),
        );
    }
}
