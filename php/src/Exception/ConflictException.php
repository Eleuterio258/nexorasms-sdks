<?php

declare(strict_types=1);

namespace E258Tech\NexoraSms\Exception;

/**
 * HTTP 409: conflict (e.g. same Idempotency-Key with a different body).
 */
class ConflictException extends NexoraSmsException
{
}
