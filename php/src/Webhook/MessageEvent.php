<?php

declare(strict_types=1);

namespace E258Tech\NexoraSms\Webhook;

use E258Tech\NexoraSms\Enum\Channel;
use E258Tech\NexoraSms\Enum\MessageStatus;
use E258Tech\NexoraSms\Internal\Json;

/**
 * The data of a message.delivered / message.failed event.
 */
final class MessageEvent
{
    public function __construct(
        public readonly string $messageId,
        public readonly string $campaignId,
        public readonly ?string $contactId,
        public readonly ?string $externalId,
        public readonly ?Channel $channel,
        public readonly string $channelRaw,
        public readonly ?MessageStatus $status,
        public readonly string $statusRaw,
        public readonly float $cost,
        public readonly \DateTimeImmutable $occurredAt,
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
            Json::str($data, 'message_id'),
            Json::str($data, 'campaign_id'),
            Json::optStr($data, 'contact_id'),
            Json::optStr($data, 'external_id'),
            Channel::tryFrom($channel),
            $channel,
            MessageStatus::tryFrom($status),
            $status,
            Json::float($data, 'cost'),
            Json::date($data, 'occurred_at'),
        );
    }
}
