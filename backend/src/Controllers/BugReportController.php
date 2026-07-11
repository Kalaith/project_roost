<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Exceptions\AuthException;
use App\Repositories\BugReportRepository;
use App\Repositories\ProjectRepository;
use App\Services\AuthService;
use App\Services\BugReportGuard;

/**
 * Public intake + admin moderation for player-submitted bug reports.
 *
 * `challenge` and `submit` are intentionally UNAUTHENTICATED (the game pages are open
 * to the internet); they are protected by BugReportGuard instead. `index` and
 * `moderate` are admin-only and are where a report is promoted into the Fix Queue.
 */
final class BugReportController
{
    public function __construct(
        private readonly BugReportRepository $reports = new BugReportRepository(),
        private readonly ProjectRepository $projects = new ProjectRepository(),
        private readonly BugReportGuard $guard = new BugReportGuard(),
        private readonly AuthService $auth = new AuthService()
    ) {}

    /** Public: hand the form a signed challenge token when it opens. */
    public function challenge(Request $request, Response $response): void
    {
        $response->success($this->guard->issueChallenge());
    }

    /** Public: accept a bug report after safety checks; store it for moderation. */
    public function submit(Request $request, Response $response): void
    {
        $payload = $request->all();
        $verdict = $this->guard->inspect($request, $payload);

        // Honeypot hits and hard rejections never reach the database.
        if ($verdict['result'] !== BugReportGuard::RESULT_OK) {
            if ($verdict['status'] >= 400) {
                $response->error($verdict['message'], $verdict['status']);
                return;
            }

            $response->withStatus($verdict['status'])->success(null, $verdict['message']);
            return;
        }

        $data = $verdict['data'];
        $data['project_id'] = $this->reports->projectIdBySlug($data['project_slug']);
        $this->reports->insert($data);

        $response->withStatus(201)->success(null, $verdict['message']);
    }

    /** Admin: list reports, optionally filtered by status (defaults to new). */
    public function index(Request $request, Response $response): void
    {
        if (!$this->requireAdmin($request, $response)) {
            return;
        }

        $status = (string) $request->query('status', 'new');

        $response->success([
            'reports' => $this->reports->list($status !== 'all' ? $status : null),
        ]);
    }

    /**
     * Admin: moderate a report. action=approve promotes it into the Fix Queue as a
     * task; action=reject|spam just records the decision.
     */
    public function moderate(Request $request, Response $response): void
    {
        if (!$this->requireAdmin($request, $response)) {
            return;
        }

        $id = (int) $request->param('id');
        $report = $this->reports->find($id);
        if ($report === null) {
            $response->error('Bug report not found.', 404);
            return;
        }

        $payload = $request->all();
        $action = strtolower((string) ($payload['action'] ?? ''));

        if ($action === 'reject' || $action === 'spam') {
            $this->reports->markReviewed($id, $action);
            $response->success(['report' => $this->reports->find($id)]);
            return;
        }

        if ($action !== 'approve') {
            $response->error('Unknown moderation action.', 422);
            return;
        }

        if ($report['status'] === 'approved') {
            $response->error('This report was already approved.', 409);
            return;
        }

        if ($report['project_id'] === null) {
            $response->error('Link this report to a project before approving.', 422);
            return;
        }

        $task = $this->projects->addTask((string) $report['project_id'], $this->taskFromReport($report, $payload));
        if ($task === null) {
            $response->error('Could not create a task for this report.', 500);
            return;
        }

        $this->reports->markReviewed($id, 'approved', (int) $task['id']);

        $response->withStatus(201)->success([
            'report' => $this->reports->find($id),
            'task' => $task,
        ]);
    }

    /**
     * @param array<string, mixed> $report
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function taskFromReport(array $report, array $payload): array
    {
        $footerParts = [];
        if (!empty($report['game_version'])) {
            $footerParts[] = 'Version: ' . $report['game_version'];
        }
        if (!empty($report['contact'])) {
            $footerParts[] = 'Reporter: ' . $report['contact'];
        }
        if (!empty($report['page_url'])) {
            $footerParts[] = 'Page: ' . $report['page_url'];
        }

        $description = (string) $report['description'];
        if ($footerParts !== []) {
            $description .= "\n\n---\n" . implode("\n", $footerParts);
        }

        return [
            'title' => $report['summary'],
            'description' => $description,
            'type' => 'bug',
            'priority' => in_array(($payload['priority'] ?? ''), ['low', 'medium', 'high'], true)
                ? $payload['priority']
                : 'medium',
            'status' => 'todo',
            'effort' => 'medium',
            'impact' => 'medium',
            'source' => 'player-report',
            'source_hash' => hash('sha256', 'bug-report-' . $report['id']),
        ];
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
