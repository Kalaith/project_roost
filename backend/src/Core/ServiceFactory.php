<?php

declare(strict_types=1);

namespace App\Core;

final class ServiceFactory
{
    public function create(string $className): object
    {
        return new $className();
    }
}
