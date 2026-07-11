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

    /**
     * Best-effort client IP for rate-limiting/abuse controls. Only REMOTE_ADDR is
     * trusted by default; proxy headers are spoofable and are ignored unless the
     * deployment sits behind a trusted proxy that overwrites REMOTE_ADDR itself.
     */
    public function clientIp(): string
    {
        $remote = $_SERVER['REMOTE_ADDR'] ?? '';

        return is_string($remote) ? $remote : '';
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

        // Under Apache mod_php (e.g. local XAMPP), the Authorization header is not
        // exposed via $_SERVER at all; getallheaders() reads Apache's raw request
        // headers directly. Same fallback the main WebHatchery API uses.
        if (function_exists('getallheaders')) {
            foreach (getallheaders() as $headerName => $value) {
                if (strcasecmp($headerName, $name) === 0 && is_string($value)) {
                    return $value;
                }
            }
        }

        return null;
    }
}
