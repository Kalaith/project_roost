<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Exceptions\AuthException;
use App\Repositories\DeploymentRepository;
use App\Services\AuthService;

final class DeploymentController
{
    public function __construct(
        private readonly DeploymentRepository $deployments = new DeploymentRepository(),
        private readonly AuthService $auth = new AuthService()
    ) {}

    public function index(Request $request, Response $response): void
    {
        $limit = (int) $request->query('limit', 50);
        $project = trim((string) $request->query('project', ''));
        $projectSlug = $project !== '' ? $project : null;

        $response->success([
            'deployments' => $this->deployments->history($limit, $projectSlug),
            'latest' => $this->deployments->latestByEnvironment($projectSlug),
        ]);
    }

    public function publish(Request $request, Response $response): void
    {
        try {
            $this->auth->requireAdminOrPublishToken($request);
        } catch (AuthException $exception) {
            $response->error($exception->getMessage(), $exception->statusCode(), $exception->extra());
            return;
        }

        $response->withStatus(201)->success([
            'deployment' => $this->deployments->record($request->all()),
        ]);
    }
}
