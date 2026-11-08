<?php

declare(strict_types=1);

namespace E258Tech\NexoraSms\Internal;

use E258Tech\NexoraSms\Http\HttpResponse;

/**
 * @internal
 */
final class ApiResponse
{
    /**
     * @param array<string, mixed> $data Decoded JSON object (snake_case, as sent by the API).
     */
    public function __construct(
        public readonly HttpResponse $http,
        public readonly array $data,
    ) {
    }
}
