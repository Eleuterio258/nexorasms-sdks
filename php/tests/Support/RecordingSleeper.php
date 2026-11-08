<?php

declare(strict_types=1);

namespace E258Tech\NexoraSms\Tests\Support;

use E258Tech\NexoraSms\Internal\Sleeper;

final class RecordingSleeper implements Sleeper
{
    /** @var list<int> */
    public array $sleeps = [];

    public function sleep(int $milliseconds): void
    {
        $this->sleeps[] = $milliseconds;
    }
}
