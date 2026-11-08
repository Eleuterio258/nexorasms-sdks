<?php

declare(strict_types=1);

namespace E258Tech\NexoraSms\Internal;

/**
 * @internal
 */
final class SystemSleeper implements Sleeper
{
    public function sleep(int $milliseconds): void
    {
        if ($milliseconds > 0) {
            usleep($milliseconds * 1000);
        }
    }
}
