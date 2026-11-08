<?php

declare(strict_types=1);

namespace E258Tech\NexoraSms\Exception;

/**
 * HTTP 429: too many requests (see $retryAfter).
 */
class RateLimitException extends NexoraSmsException
{
}
