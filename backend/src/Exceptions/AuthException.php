<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class AuthException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $statusCode,
        private readonly array $extra = []
    ) {
        parent::__construct($message);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public function extra(): array
    {
        return $this->extra;
    }
}
