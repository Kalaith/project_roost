<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Exceptions\AuthException;
use App\Repositories\ProjectRepository;
use App\Services\AuthService;
use App\Services\ProjectDiscoveryService;

final class ProjectController
{
    public function __construct(
        private readonly ProjectRepository $projects = new ProjectRepository(),
        private readonly AuthService $auth = new AuthService(),
        private readonly ProjectDiscoveryService $discovery = new ProjectDiscoveryService()
    ) {}

    public function index(Request $request, Response $response): void
    {
        $response->success([
            'projects' => $this->projects->listProjects($request->queryAll()),
        ]);
    }

    public function discover(Request $request, Response $response): void
    {
        if (!$this->requireAdmin($request, $response)) {
            return;
        }

        $response->success([
            'candidates' => $this->discovery->discover(
                $this->projects->listProjects([]),
                $request->queryAll()
            ),
        ]);
    }

    public function show(Request $request, Response $response): void
    {
        $project = $this->projects->getProject((string) $request->param('id'));
        if ($project === null) {
            $response->error('Project not found.', 404);
            return;
        }

        $response->success(['project' => $project]);
    }

    public function store(Request $request, Response $response): void
    {
        if (!$this->requireAdmin($request, $response)) {
            return;
        }

        $response->withStatus(201)->success([
            'project' => $this->projects->createProject($request->all()),
        ]);
    }

    public function update(Request $request, Response $response): void
    {
        if (!$this->requireAdmin($request, $response)) {
            return;
        }

        $project = $this->projects->updateProject((string) $request->param('id'), $request->all());
        if ($project === null) {
            $response->error('Project not found.', 404);
            return;
        }

        $response->success(['project' => $project]);
    }

    public function destroy(Request $request, Response $response): void
    {
        if (!$this->requireAdmin($request, $response)) {
            return;
        }

        if (!$this->projects->deleteProject((string) $request->param('id'))) {
            $response->error('Project not found.', 404);
            return;
        }

        $response->success(null, 'Project removed from Project Roost.');
    }

    public function reviews(Request $request, Response $response): void
    {
        $reviews = $this->projects->getReviews((string) $request->param('id'));
        if ($reviews === null) {
            $response->error('Project not found.', 404);
            return;
        }

        $response->success(['reviews' => $reviews]);
    }

    public function addReview(Request $request, Response $response): void
    {
        if (!$this->requireAdmin($request, $response)) {
            return;
        }

        $review = $this->projects->addReview((string) $request->param('id'), $request->all());
        if ($review === null) {
            $response->error('Project not found.', 404);
            return;
        }

        $response->withStatus(201)->success(['review' => $review]);
    }

    public function tasks(Request $request, Response $response): void
    {
        $tasks = $this->projects->getTasks((string) $request->param('id'));
        if ($tasks === null) {
            $response->error('Project not found.', 404);
            return;
        }

        $response->success(['tasks' => $tasks]);
    }

    public function addTask(Request $request, Response $response): void
    {
        if (!$this->requireAdmin($request, $response)) {
            return;
        }

        $task = $this->projects->addTask((string) $request->param('id'), $request->all());
        if ($task === null) {
            $response->error('Project not found.', 404);
            return;
        }

        $response->withStatus(201)->success(['task' => $task]);
    }

    public function updateTask(Request $request, Response $response): void
    {
        if (!$this->requireAdmin($request, $response)) {
            return;
        }

        $task = $this->projects->updateTask((string) $request->param('id'), $request->all());
        if ($task === null) {
            $response->error('Task not found.', 404);
            return;
        }

        $response->success(['task' => $task]);
    }

    private function requireAdmin(Request $request, Response $response): bool
    {
        try {
            $this->auth->requireAdmin($request);
            return true;
        } catch (AuthException $exception) {
            $response->error($exception->getMessage(), $exception->statusCode(), $exception->extra());
            return false;
        }
    }
}
