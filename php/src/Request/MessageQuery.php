<?php

declare(strict_types=1);

namespace E258Tech\NexoraSms\Request;

/**
 * Filters for messages()->list(). Build it with {@see self::builder()}.
 */
final class MessageQuery
{
    /**
     * @internal Use MessageQuery::builder().
     *
     * @param array<string, string|int> $params
     */
    public function __construct(private readonly array $params)
    {
    }

    public static function builder(): MessageQueryBuilder
    {
        return new MessageQueryBuilder();
    }

    /**
     * Query string parameters (snake_case), without the ones that were not set.
     *
     * @return array<string, string|int>
     */
    public function toArray(): array
    {
        return $this->params;
    }
}
