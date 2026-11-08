<?php

declare(strict_types=1);

namespace E258Tech\NexoraSms\Enum;

/**
 * Status of a contact.
 *
 * Models keep the raw text next to the enum, so values added later by the API do not break parsing.
 */
enum ContactStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
}
