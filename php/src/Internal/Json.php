<?php

declare(strict_types=1);

namespace E258Tech\NexoraSms\Internal;

/**
 * Lenient readers for decoded JSON objects. Missing or mistyped required fields become neutral values
 * ('' / 0 / 0.0 / Unix epoch) so that API changes do not break parsing; unknown fields are ignored.
 *
 * @internal
 */
final class Json
{
    /**
     * @return array<string, mixed>
     */
    public static function decodeObject(string $text): array
    {
        if ($text === '') {
            return [];
        }
        try {
            $value = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($value) ? self::object($value) : [];
    }

    public static function encode(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<array-key, mixed> $value
     *
     * @return array<string, mixed>
     */
    public static function object(array $value): array
    {
        $result = [];
        foreach ($value as $key => $inner) {
            $result[(string) $key] = $inner;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public static function obj(array $data, string $key): array
    {
        $value = $data[$key] ?? null;

        return is_array($value) ? self::object($value) : [];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function str(array $data, string $key): string
    {
        return self::optStr($data, $key) ?? '';
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function optStr(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function int(array $data, string $key): int
    {
        return self::optInt($data, $key) ?? 0;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function optInt(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value) || (is_string($value) && is_numeric($value))) {
            return (int) $value;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function float(array $data, string $key): float
    {
        $value = $data[$key] ?? null;
        if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
            return (float) $value;
        }

        return 0.0;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function bool(array $data, string $key): bool
    {
        return ($data[$key] ?? false) === true;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function date(array $data, string $key): \DateTimeImmutable
    {
        return self::optDate($data, $key) ?? new \DateTimeImmutable('@0');
    }

    /**
     * Parses ISO 8601 (with up to 7 fractional digits, as sent by .NET) into a DateTimeImmutable.
     *
     * @param array<string, mixed> $data
     */
    public static function optDate(array $data, string $key): ?\DateTimeImmutable
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || $value === '') {
            return null;
        }
        // PHP keeps microseconds (6 digits): trim longer fractions.
        $value = (string) preg_replace('/(\.\d{6})\d+/', '$1', $value);
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    public static function strList(array $data, string $key): array
    {
        $result = [];
        foreach (self::list($data, $key) as $item) {
            if (is_scalar($item)) {
                $result[] = (string) $item;
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<array<string, mixed>>
     */
    public static function objList(array $data, string $key): array
    {
        $result = [];
        foreach (self::list($data, $key) as $item) {
            if (is_array($item)) {
                $result[] = self::object($item);
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, int>
     */
    public static function intMap(array $data, string $key): array
    {
        $result = [];
        foreach (self::obj($data, $key) as $name => $value) {
            if (is_int($value) || is_float($value)) {
                $result[$name] = (int) $value;
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, string|null>
     */
    public static function strMap(array $data, string $key): array
    {
        $result = [];
        foreach (self::obj($data, $key) as $name => $value) {
            $result[$name] = is_scalar($value) ? (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value) : null;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<mixed>
     */
    private static function list(array $data, string $key): array
    {
        $value = $data[$key] ?? null;

        return is_array($value) ? array_values($value) : [];
    }
}
