<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class Env
{
    public static function required(string $name): string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);
        $value = is_string($value) ? trim($value) : '';

        if ($value === '') {
            throw new RuntimeException("{$name} environment variable is required.");
        }

        return $value;
    }

    public static function optional(string $name, string $default = ''): string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);
        $value = is_string($value) ? trim($value) : '';

        return $value !== '' ? $value : $default;
    }

    public static function requiredInt(string $name): int
    {
        $value = self::required($name);

        if (!ctype_digit($value)) {
            throw new RuntimeException("{$name} environment variable must be an integer.");
        }

        return (int) $value;
    }
}
