<?php

declare(strict_types=1);

namespace App\Core;

final class Request
{
    public function __construct(
        private readonly array $routeParams = []
    ) {}

    public function all(): array
    {
        $body = file_get_contents('php://input');
        $decoded = json_decode($body ?: '[]', true);

        return is_array($decoded) ? $decoded : [];
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $_GET[$key] ?? $default;
    }

    public function queryAll(): array
    {
        return $_GET;
    }

    public function param(string $key, mixed $default = null): mixed
    {
        return $this->routeParams[$key] ?? $default;
    }

    public function getHeader(string $name): ?string
    {
        $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));

        if (isset($_SERVER[$serverKey]) && is_string($_SERVER[$serverKey])) {
            return $_SERVER[$serverKey];
        }

        if (strtolower($name) === 'authorization') {
            foreach (['REDIRECT_HTTP_AUTHORIZATION', 'Authorization'] as $key) {
                if (isset($_SERVER[$key]) && is_string($_SERVER[$key])) {
                    return $_SERVER[$key];
                }
            }
        }

        return null;
    }
}
