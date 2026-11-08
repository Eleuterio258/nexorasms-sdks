<?php

declare(strict_types=1);

namespace E258Tech\NexoraSms\Model;

use E258Tech\NexoraSms\Enum\AttemptStatus;
use E258Tech\NexoraSms\Internal\Json;

/**
 * One delivery attempt of a message part at a provider.
 */
final class MessageAttempt
{
    public function __construct(
        public readonly int $partNumber,
        public readonly int $attemptNumber,
        public readonly ?AttemptStatus $status,
        public readonly string $statusRaw,
        public readonly ?string $providerMessageId,
        public readonly ?string $errorCode,
        public readonly \DateTimeImmutable $startedAt,
        public readonly ?\DateTimeImmutable $completedAt,
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
            Json::int($data, 'part_number'),
            Json::int($data, 'attempt_number'),
            AttemptStatus::tryFrom($status),
            $status,
            Json::optStr($data, 'provider_message_id'),
            Json::optStr($data, 'error_code'),
            Json::date($data, 'started_at'),
            Json::optDate($data, 'completed_at'),
        );
    }
}
