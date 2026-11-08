<?php

declare(strict_types=1);

namespace E258Tech\NexoraSms\Exception;

/**
 * A webhook whose signature, timestamp or body could not be verified. Answer such requests with 401/400.
 */
class WebhookVerificationException extends \RuntimeException
{
}
