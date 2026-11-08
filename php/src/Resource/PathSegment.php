<?php

declare(strict_types=1);

namespace E258Tech\NexoraSms\Resource;

/**
 * @internal
 */
final class PathSegment
{
    /**
     * URL-encodes one path segment (RFC 3986).
     *
     * @throws \InvalidArgumentException When empty.
     */
    public static function encode(string $value): string
    {
        if ($value === '') {
            throw new \InvalidArgumentException('Identificador em falta.');
        }

        return rawurlencode($value);
    }
}
