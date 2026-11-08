<?php

declare(strict_types=1);

namespace E258Tech\NexoraSms\Enum;

/**
 * Consent status for a channel.
 *
 * Models keep the raw text next to the enum, so values added later by the API do not break parsing.
 */
enum ConsentStatus: string
{
    case Granted = 'granted';
    case Revoked = 'revoked';
}
