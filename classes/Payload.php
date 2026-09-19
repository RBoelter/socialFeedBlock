<?php

declare(strict_types=1);

namespace APP\plugins\blocks\socialFeedBlock\classes;

use DateTimeImmutable;
use Exception;

/**
 * Safe reads from decoded JSON of a remote API. The data is untrusted and may
 * be missing keys or carry unexpected types; instead of failing, a value that
 * is absent or of the wrong type reads as "nothing".
 */
final class Payload
{
    /** Follows the keys through nested arrays; null as soon as one step is missing. */
    public static function get(mixed $data, string ...$path): mixed
    {
        foreach ($path as $key) {
            if (!is_array($data) || !array_key_exists($key, $data)) {
                return null;
            }
            $data = $data[$key];
        }

        return $data;
    }

    public static function string(mixed $data, string ...$path): ?string
    {
        $value = self::get($data, ...$path);

        return is_string($value) ? $value : null;
    }

    /**
     * An http or https address. Anything else, javascript: and data: included,
     * reads as nothing, so a hostile server cannot smuggle a script into a link.
     */
    public static function webUrl(mixed $data, string ...$path): ?string
    {
        $value = self::string($data, ...$path);
        if ($value === null || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        return in_array(parse_url($value, PHP_URL_SCHEME), ['http', 'https'], true) ? $value : null;
    }

    /** A date and time in any format PHP understands, null when absent or unparsable. */
    public static function date(mixed $data, string ...$path): ?DateTimeImmutable
    {
        $value = self::string($data, ...$path);
        if ($value === null) {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Exception) {
            return null;
        }
    }

    /** A non-negative whole number, 0 when absent or of another type. */
    public static function count(mixed $data, string ...$path): int
    {
        $value = self::get($data, ...$path);

        return is_int($value) && $value > 0 ? $value : 0;
    }

    /** @return array<mixed> */
    public static function items(mixed $data, string ...$path): array
    {
        $value = self::get($data, ...$path);

        return is_array($value) ? $value : [];
    }
}
