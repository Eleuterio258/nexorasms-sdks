<?php

declare(strict_types=1);

namespace E258Tech\NexoraSms\Model;

use E258Tech\NexoraSms\Enum\Channel;
use E258Tech\NexoraSms\Enum\Encoding;
use E258Tech\NexoraSms\Internal\Json;

/**
 * Estimate for one channel.
 */
final class EstimatedChannel
{
    public function __construct(
        public readonly ?Channel $channel,
        public readonly string $channelRaw,
        public readonly int $recipients,
        public readonly int $segments,
        public readonly ?Encoding $encoding,
        public readonly string $encodingRaw,
        public readonly float $unitPrice,
        public readonly float $cost,
    ) {
    }

    /**
     * @internal
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $channel = Json::str($data, 'channel');
        $encoding = Json::str($data, 'encoding');

        return new self(
            Channel::tryFrom($channel),
            $channel,
            Json::int($data, 'recipients'),
            Json::int($data, 'segments'),
            Encoding::tryFrom($encoding),
            $encoding,
            Json::float($data, 'unit_price'),
            Json::float($data, 'cost'),
        );
    }
}
