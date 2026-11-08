<?php

declare(strict_types=1);

namespace E258Tech\NexoraSms\Enum;

/**
 * Type of a wallet transaction.
 *
 * Models keep the raw text next to the enum, so values added later by the API do not break parsing.
 */
enum TransactionType: string
{
    case Credit = 'credit';
    case Debit = 'debit';
    case Reserve = 'reserve';
    case Release = 'release';
    case Refund = 'refund';
    case Withdrawal = 'withdrawal';
}
