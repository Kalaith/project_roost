<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Env;

final class HealthController
{
    public function check(Request $request, Response $response): void
    {
        $response->json([
            'status' => 'ok',
            'timestamp' => date('c'),
            'service' => Env::required('APP_NAME'),
            'version' => Env::required('APP_VERSION'),
        ]);
    }
}
