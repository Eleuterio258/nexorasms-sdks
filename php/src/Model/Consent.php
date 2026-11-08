<?php

declare(strict_types=1);

namespace E258Tech\NexoraSms\Model;

use E258Tech\NexoraSms\Enum\Channel;
use E258Tech\NexoraSms\Enum\ConsentStatus;
use E258Tech\NexoraSms\Internal\Json;

/**
 * Consent of a contact for one channel.
 */
final class Consent
{
    public function __construct(
        public readonly ?Channel $channel,
        public readonly string $channelRaw,
        public readonly ?ConsentStatus $status,
        public readonly string $statusRaw,
        public readonly string $source,
        public readonly \DateTimeImmutable $recordedAt,
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
        $status = Json::str($data, 'status');

        return new self(
            Channel::tryFrom($channel),
            $channel,
            ConsentStatus::tryFrom($status),
            $status,
            Json::str($data, 'source'),
            Json::date($data, 'recorded_at'),
        );
    }
}
