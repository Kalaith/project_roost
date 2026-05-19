<?php

declare(strict_types=1);

namespace App\Core;

final class Router
{
    private array $routes = [];
    private string $basePath = '';

    public function setBasePath(string $basePath): void
    {
        $this->basePath = rtrim($basePath, '/');
    }

    public function get(string $path, array|callable $handler): void
    {
        $this->addRoute('GET', $path, $handler);
    }

    public function post(string $path, array|callable $handler): void
    {
        $this->addRoute('POST', $path, $handler);
    }

    public function patch(string $path, array|callable $handler): void
    {
        $this->addRoute('PATCH', $path, $handler);
    }

    public function delete(string $path, array|callable $handler): void
    {
        $this->addRoute('DELETE', $path, $handler);
    }

    private function addRoute(string $method, string $path, array|callable $handler): void
    {
        $pattern = preg_replace('/\{([a-zA-Z0-9_]+)\}/', '(?P<$1>[^/]+)', $path);
        $this->routes[] = [
            'method' => $method,
            'pattern' => '#^' . $pattern . '$#',
            'handler' => $handler,
        ];
    }

    public function handle(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = explode('?', $uri)[0];

        if ($this->basePath !== '' && strpos($path, $this->basePath) === 0) {
            $path = substr($path, strlen($this->basePath));
        }

        if ($path === '') {
            $path = '/';
        }

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            if (!preg_match($route['pattern'], $path, $matches)) {
                continue;
            }

            $routeParams = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
            $request = new Request($routeParams);
            $response = new Response();

            if (is_callable($route['handler'])) {
                ($route['handler'])($request, $response);
                return;
            }

            $factory = new ServiceFactory();
            $controller = $factory->create($route['handler'][0]);
            $methodName = $route['handler'][1];
            $controller->$methodName($request, $response);
            return;
        }

        (new Response())->withStatus(404)->json([
            'success' => false,
            'message' => 'Route not found: ' . $path,
        ]);
    }
}
