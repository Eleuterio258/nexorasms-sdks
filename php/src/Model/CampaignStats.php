<?php

declare(strict_types=1);

namespace E258Tech\NexoraSms\Model;

use E258Tech\NexoraSms\Enum\CampaignStatus;
use E258Tech\NexoraSms\Internal\Json;

/**
 * Campaign statistics: messages per status, cost and clicks.
 */
final class CampaignStats
{
    /**
     * @param array<string, int> $messages Message status => count.
     */
    public function __construct(
        public readonly string $campaignId,
        public readonly ?CampaignStatus $status,
        public readonly string $statusRaw,
        public readonly int $recipients,
        public readonly array $messages,
        public readonly int $smsSegments,
        public readonly string $currency,
        public readonly float $estimatedCost,
        public readonly float $reserved,
        public readonly float $debited,
        public readonly int $clicks,
        public readonly int $uniqueClicks,
    ) {
    }

    /**
     * @internal
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $status = Json::str($data, 'status');

        return new self(
            Json::str($data, 'campaign_id'),
            CampaignStatus::tryFrom($status),
            $status,
            Json::int($data, 'recipients'),
            Json::intMap($data, 'messages'),
            Json::int($data, 'sms_segments'),
            Json::str($data, 'currency'),
            Json::float($data, 'estimated_cost'),
            Json::float($data, 'reserved'),
            Json::float($data, 'debited'),
            Json::int($data, 'clicks'),
            Json::int($data, 'unique_clicks'),
        );
    }
}
