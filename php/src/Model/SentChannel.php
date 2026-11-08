<?php

declare(strict_types=1);

namespace E258Tech\NexoraSms\Model;

use E258Tech\NexoraSms\Enum\CampaignStatus;
use E258Tech\NexoraSms\Enum\Channel;
use E258Tech\NexoraSms\Internal\Json;

/**
 * One channel of a send: the campaign created for it, recipients, cost and status.
 */
final class SentChannel
{
    public function __construct(
        public readonly ?Channel $channel,
        public readonly string $channelRaw,
        public readonly string $campaignId,
        public readonly int $recipients,
        public readonly float $cost,
        public readonly ?CampaignStatus $status,
        public readonly string $statusRaw,
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
            Json::str($data, 'campaign_id'),
            Json::int($data, 'recipients'),
            Json::float($data, 'cost'),
            CampaignStatus::tryFrom($status),
            $status,
        );
    }
}
