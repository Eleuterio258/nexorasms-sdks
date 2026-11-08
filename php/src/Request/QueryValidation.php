<?php

declare(strict_types=1);

namespace E258Tech\NexoraSms\Request;

/**
 * @internal
 */
final class QueryValidation
{
    public static function page(int $page): int
    {
        if ($page < 1) {
            throw new \InvalidArgumentException('page deve ser um inteiro a partir de 1.');
        }

        return $page;
    }

    public static function pageSize(int $pageSize): int
    {
        if ($pageSize < 1 || $pageSize > 200) {
            throw new \InvalidArgumentException('pageSize deve ser um inteiro entre 1 e 200.');
        }

        return $pageSize;
    }
}
