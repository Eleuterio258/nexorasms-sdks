<?php

declare(strict_types=1);

namespace E258Tech\NexoraSms\Model;

use E258Tech\NexoraSms\Internal\Json;

/**
 * Wallet balance, in the wallet's currency.
 */
final class Wallet
{
    public function __construct(
        public readonly string $currency,
        public readonly float $balance,
        public readonly float $reserved,
        public readonly float $available,
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
            Json::str($data, 'currency'),
            Json::float($data, 'balance'),
            Json::float($data, 'reserved'),
            Json::float($data, 'available'),
        );
    }
}
