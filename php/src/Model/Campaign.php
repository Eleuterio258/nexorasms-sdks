<?php

declare(strict_types=1);

namespace E258Tech\NexoraSms\Model;

use E258Tech\NexoraSms\Enum\CampaignStatus;
use E258Tech\NexoraSms\Enum\Channel;
use E258Tech\NexoraSms\Internal\Json;

/**
 * A campaign (each direct send creates one per channel).
 */
final class Campaign
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly ?Channel $channel,
        public readonly string $channelRaw,
        public readonly ?CampaignStatus $status,
        public readonly string $statusRaw,
        public readonly ?string $templateId,
        public readonly ?string $subject,
        public readonly string $content,
        public readonly int $recipients,
        public readonly ?\DateTimeImmutable $scheduledAt,
        public readonly \DateTimeImmutable $createdAt,
        public readonly bool $ignoreQuietHours,
        public readonly ?string $pauseReason,
        public readonly ?string $sender,
        public readonly ?int $messages,
        public readonly ?int $delivered,
        public readonly ?int $failed,
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
            Json::str($data, 'id'),
            Json::str($data, 'name'),
            Channel::tryFrom($channel),
            $channel,
            CampaignStatus::tryFrom($status),
            $status,
            Json::optStr($data, 'template_id'),
            Json::optStr($data, 'subject'),
            Json::str($data, 'content'),
            Json::int($data, 'recipients'),
            Json::optDate($data, 'scheduled_at'),
            Json::date($data, 'created_at'),
            Json::bool($data, 'ignore_quiet_hours'),
            Json::optStr($data, 'pause_reason'),
            Json::optStr($data, 'sender'),
            Json::optInt($data, 'messages'),
            Json::optInt($data, 'delivered'),
            Json::optInt($data, 'failed'),
        );
    }
}
