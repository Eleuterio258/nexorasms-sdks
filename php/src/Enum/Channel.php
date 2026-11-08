<?php

declare(strict_types=1);

namespace E258Tech\NexoraSms\Enum;

/**
 * Delivery channel.
 *
 * Models keep the raw text next to the enum, so values added later by the API do not break parsing.
 */
enum Channel: string
{
    case Sms = 'sms';
    case Whatsapp = 'whatsapp';
    case Email = 'email';
}
