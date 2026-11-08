<?php

declare(strict_types=1);

namespace E258Tech\NexoraSms\Internal;

/**
 * Waits between retries. Injectable so that tests do not wait in real time.
 *
 * @internal
 */
interface Sleeper
{
    public function sleep(int $milliseconds): void;
}
