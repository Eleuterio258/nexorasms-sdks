<?php

declare(strict_types=1);

namespace E258Tech\NexoraSms\Model;

use E258Tech\NexoraSms\Enum\Channel;
use E258Tech\NexoraSms\Enum\MessageStatus;
use E258Tech\NexoraSms\Internal\Json;

/**
 * A message to one destination, with its delivery attempts.
 */
final class Message
{
    /**
     * @param list<MessageAttempt> $attempts
     */
    public function __construct(
        public readonly string $id,
        public readonly string $campaignId,
        public readonly ?Channel $channel,
        public readonly string $channelRaw,
        public readonly string $destination,
        public readonly ?MessageStatus $status,
        public readonly string $statusRaw,
        public readonly float $cost,
        public readonly int $parts,
        public readonly \DateTimeImmutable $createdAt,
        public readonly ?\DateTimeImmutable $acceptedAt,
        public readonly ?\DateTimeImmutable $deliveredAt,
        public readonly array $attempts,
        public readonly ?string $campaignName,
        public readonly ?string $sender,
        public readonly ?string $subject,
        public readonly ?string $content,
        public readonly ?string $template,
        public readonly ?string $language,
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
            Json::str($data, 'campaign_id'),
            Channel::tryFrom($channel),
            $channel,
            Json::str($data, 'destination'),
            MessageStatus::tryFrom($status),
            $status,
            Json::float($data, 'cost'),
            Json::int($data, 'parts'),
            Json::date($data, 'created_at'),
            Json::optDate($data, 'accepted_at'),
            Json::optDate($data, 'delivered_at'),
            array_map(MessageAttempt::fromArray(...), Json::objList($data, 'attempts')),
            Json::optStr($data, 'campaign_name'),
            Json::optStr($data, 'sender'),
            Json::optStr($data, 'subject'),
            Json::optStr($data, 'content'),
            Json::optStr($data, 'template'),
            Json::optStr($data, 'language'),
        );
    }
}
