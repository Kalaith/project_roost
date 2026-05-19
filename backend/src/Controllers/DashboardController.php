<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Repositories\DeploymentRepository;
use App\Repositories\ProjectRepository;

final class DashboardController
{
    public function __construct(
        private readonly ProjectRepository $projects = new ProjectRepository(),
        private readonly DeploymentRepository $deployments = new DeploymentRepository()
    ) {}

    public function summary(Request $request, Response $response): void
    {
        $summary = $this->projects->dashboardSummary();
        $summary['deployments'] = $this->deployments->latestByEnvironment();

        $response->success([
            'summary' => $summary,
        ]);
    }

    public function fixQueue(Request $request, Response $response): void
    {
        $response->success([
            'tasks' => $this->projects->fixQueue(),
        ]);
    }
}
