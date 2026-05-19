<?php

declare(strict_types=1);

namespace App\Core;

final class Response
{
    private int $statusCode = 200;
    private array $headers = [
        'Content-Type' => 'application/json',
    ];

    public function withStatus(int $code): self
    {
        $this->statusCode = $code;
        return $this;
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function json(array $data): void
    {
        http_response_code($this->statusCode);

        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }

        echo json_encode($data, JSON_PRETTY_PRINT);
    }

    public function success(mixed $data = null, string $message = ''): void
    {
        $response = ['success' => true];

        if ($data !== null) {
            $response['data'] = $data;
        }

        if ($message !== '') {
            $response['message'] = $message;
        }

        $this->json($response);
    }

    public function error(string $message, int $code = 400, array $extra = []): void
    {
        $this->withStatus($code)->json([
            'success' => false,
            'message' => $message,
        ] + $extra);
    }
}
