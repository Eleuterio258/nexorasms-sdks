<?php

declare(strict_types=1);

namespace E258Tech\NexoraSms\Model;

use E258Tech\NexoraSms\Enum\TransactionType;
use E258Tech\NexoraSms\Internal\Json;

/**
 * A wallet movement.
 */
final class WalletTransaction
{
    public function __construct(
        public readonly string $id,
        public readonly ?TransactionType $type,
        public readonly string $typeRaw,
        public readonly float $amount,
        public readonly string $businessReference,
        public readonly ?string $messageId,
        public readonly ?string $reversalOfId,
        public readonly \DateTimeImmutable $createdAt,
    ) {
    }

    /**
     * @internal
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $type = Json::str($data, 'type');

        return new self(
            Json::str($data, 'id'),
            TransactionType::tryFrom($type),
            $type,
            Json::float($data, 'amount'),
            Json::str($data, 'business_reference'),
            Json::optStr($data, 'message_id'),
            Json::optStr($data, 'reversal_of_id'),
            Json::date($data, 'created_at'),
        );
    }
}
