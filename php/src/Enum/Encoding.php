<?php

declare(strict_types=1);

namespace E258Tech\NexoraSms\Enum;

/**
 * SMS encoding used in an estimate.
 *
 * Models keep the raw text next to the enum, so values added later by the API do not break parsing.
 */
enum Encoding: string
{
    case Gsm7 = 'gsm7';
    case Ucs2 = 'ucs2';
    case Utf8 = 'utf8';
}
