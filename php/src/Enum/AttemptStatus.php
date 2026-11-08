<?php

declare(strict_types=1);

namespace E258Tech\NexoraSms\Enum;

/**
 * Status of one provider attempt for a message part.
 *
 * Models keep the raw text next to the enum, so values added later by the API do not break parsing.
 */
enum AttemptStatus: string
{
    case InFlight = 'in_flight';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Failed = 'failed';
    case Unknown = 'unknown';
}
