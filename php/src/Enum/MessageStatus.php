<?php

declare(strict_types=1);

namespace E258Tech\NexoraSms\Enum;

/**
 * Status of a message.
 *
 * Models keep the raw text next to the enum, so values added later by the API do not break parsing.
 */
enum MessageStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Accepted = 'accepted';
    case Delivered = 'delivered';
    case Failed = 'failed';
    case Unknown = 'unknown';
    case Cancelled = 'cancelled';
}
