<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;
use RuntimeException;

final class ProjectRepository
{
    private const PROFILES_TABLE = 'project_roost_profiles';
    private const REVIEWS_TABLE = 'project_roost_review_snapshots';
    private const RISKS_TABLE = 'project_roost_risk_assessments';
    private const TASKS_TABLE = 'project_roost_tasks';
    private const IMPORTS_TABLE = 'project_roost_imports';

    private readonly PDO $db;
    private readonly string $projectsTable;

    public function __construct()
    {
        $this->db = Database::connection();
        $this->projectsTable = Database::requiredTableName('PROJECTS_TABLE');
    }

    public function listProjects(array $filters): array
    {
        $projects = array_map(
            fn (array $row): array => $this->rowToProject($row),
            $this->db->query($this->baseProjectSql() . ' ORDER BY p.title ASC')->fetchAll()
        );

        return $this->sortProjects($this->filterProjects($projects, $filters), (string) ($filters['sort'] ?? 'lowest_overall'));
    }

    public function getProject(string $id): ?array
    {
        $projectId = $this->resolveProjectId($id);
        if ($projectId === null) {
            return null;
        }

        $statement = $this->db->prepare($this->baseProjectSql() . ' WHERE p.id = :project_id');
        $statement->execute(['project_id' => $projectId]);
        $row = $statement->fetch();

        return is_array($row) ? $this->rowToProject($row) : null;
    }

    public function createProject(array $payload): array
    {
        $record = $this->payloadToRecord($payload);

        $this->db->beginTransaction();
        try {
            $projectId = $this->insertProject($record['project']);
            $this->upsertProfile($projectId, $record['profile']);

            if ($record['risk'] !== null) {
                $this->upsertRisk($projectId, $record['risk']);
            }

            $this->db->commit();
        } catch (\Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }

        $project = $this->getProject((string) $projectId);
        if ($project === null) {
            throw new RuntimeException('Project was created but could not be reloaded.');
        }

        return $project;
    }

    public function updateProject(string $id, array $payload): ?array
    {
        $projectId = $this->resolveProjectId($id);
        if ($projectId === null) {
            return null;
        }

        $projectUpdates = $this->projectUpdates($payload);
        $profileUpdates = $this->profileUpdates($payload);
        $riskUpdates = $this->riskUpdates($payload);

        $this->db->beginTransaction();
        try {
            if ($projectUpdates !== []) {
                $this->updateProjectRow($projectId, $projectUpdates);
            }

            if ($profileUpdates !== []) {
                $current = $this->getProfile($projectId);
                $this->upsertProfile($projectId, array_merge($current, $profileUpdates));
            }

            if ($riskUpdates !== []) {
                $current = $this->getRisk($projectId);
                $this->upsertRisk($projectId, array_merge($current, $riskUpdates));
            }

            $this->db->commit();
        } catch (\Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }

        return $this->getProject((string) $projectId);
    }

    public function deleteProject(string $id): bool
    {
        $projectId = $this->resolveProjectId($id);
        if ($projectId === null) {
            return false;
        }

        $this->db->beginTransaction();
        try {
            foreach ([self::REVIEWS_TABLE, self::RISKS_TABLE, self::TASKS_TABLE, self::PROFILES_TABLE] as $table) {
                $statement = $this->db->prepare('DELETE FROM ' . $this->q($table) . ' WHERE project_id = :project_id');
                $statement->execute(['project_id' => $projectId]);
            }

            // The shared projects row stays (other apps reference it), but it must be
            // hidden or reconciliation will recreate a profile and resurrect the project.
            $statement = $this->db->prepare(
                'UPDATE ' . $this->projectTable() . '
                SET hidden = 1, show_on_homepage = 0, updated_at = :updated_at
                WHERE id = :id'
            );
            $statement->execute(['id' => $projectId, 'updated_at' => date('Y-m-d H:i:s')]);

            $this->db->commit();
        } catch (\Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }

        return true;
    }

    public function getReviews(string $id): ?array
    {
        $projectId = $this->resolveProjectId($id);
        if ($projectId === null) {
            return null;
        }

        $statement = $this->db->prepare(
            'SELECT * FROM ' . $this->q(self::REVIEWS_TABLE) . ' WHERE project_id = :project_id ORDER BY reviewed_at DESC, id DESC'
        );
        $statement->execute(['project_id' => $projectId]);

        return array_map(fn (array $row): array => $this->reviewRow($row), $statement->fetchAll());
    }

    public function addReview(string $id, array $payload): ?array
    {
        $projectId = $this->resolveProjectId($id);
        if ($projectId === null) {
            return null;
        }

        $review = $this->normalizeReview($payload);
        $reviewId = $this->insertReview($projectId, $review);

        $statement = $this->db->prepare('SELECT * FROM ' . $this->q(self::REVIEWS_TABLE) . ' WHERE id = :id');
        $statement->execute(['id' => $reviewId]);
        $row = $statement->fetch();

        return is_array($row) ? $this->reviewRow($row) : null;
    }

    public function getTasks(string $id): ?array
    {
        $projectId = $this->resolveProjectId($id);
        if ($projectId === null) {
            return null;
        }

        $statement = $this->db->prepare(
            'SELECT * FROM ' . $this->q(self::TASKS_TABLE) . ' WHERE project_id = :project_id ORDER BY FIELD(priority, "high", "medium", "low"), id DESC'
        );
        $statement->execute(['project_id' => $projectId]);

        return array_map(fn (array $row): array => $this->taskRow($row), $statement->fetchAll());
    }

    public function addTask(string $id, array $payload): ?array
    {
        $projectId = $this->resolveProjectId($id);
        if ($projectId === null) {
            return null;
        }

        $taskId = $this->insertTask($projectId, $this->normalizeTask($payload));

        return $this->getTaskById($taskId);
    }

    public function updateTask(string $id, array $payload): ?array
    {
        if (!ctype_digit($id)) {
            return null;
        }

        $task = $this->getTaskById((int) $id);
        if ($task === null) {
            return null;
        }

        $updates = [];
        foreach (['title', 'description', 'type', 'priority', 'status', 'effort', 'impact'] as $field) {
            if (array_key_exists($field, $payload)) {
                $updates[$field] = trim((string) $payload[$field]);
            }
        }

        if ($updates === []) {
            return $task;
        }

        $updates['updated_at'] = date('Y-m-d H:i:s');
        $assignments = [];
        foreach (array_keys($updates) as $field) {
            $assignments[] = $this->q($field) . ' = :' . $field;
        }

        $updates['id'] = (int) $id;
        $statement = $this->db->prepare(
            'UPDATE ' . $this->q(self::TASKS_TABLE) . ' SET ' . implode(', ', $assignments) . ' WHERE id = :id'
        );
        $statement->execute($updates);

        return $this->getTaskById((int) $id);
    }

    public function importBatch(array $batch): array
    {
        $projectCount = 0;
        $reviewCount = 0;
        $taskCount = 0;

        $this->db->beginTransaction();
        try {
            foreach ($batch['records'] as $record) {
                $projectId = $this->upsertImportedProject($record);
                $this->upsertReview($projectId, $record['review']);
                $this->upsertRisk($projectId, $record['risk']);

                if (isset($record['task']) && is_array($record['task'])) {
                    $this->upsertTask($projectId, $record['task']);
                    $taskCount++;
                }

                $projectCount++;
                $reviewCount++;
            }

            $this->recordImport($batch, $projectCount, $reviewCount, $taskCount);
            $this->db->commit();
        } catch (\Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }

        return [
            'source' => $batch['source'],
            'source_hash' => $batch['source_hash'],
            'project_count' => $projectCount,
            'review_count' => $reviewCount,
            'task_count' => $taskCount,
        ];
    }

    public function dashboardSummary(): array
    {
        $projects = $this->listProjects([]);
        $total = count($projects);
        $scores = array_values(array_filter(
            array_map(fn (array $project): ?float => $project['latest_review']['overall_score'] ?? null, $projects),
            fn (?float $score): bool => $score !== null
        ));

        $categories = [];
        $highRisk = 0;
        foreach ($projects as $project) {
            $categories[$project['category']] = ($categories[$project['category']] ?? 0) + 1;
            if (($project['risk']['severity'] ?? '') === 'high') {
                $highRisk++;
            }
        }

        $taskStatement = $this->db->query(
            'SELECT status, COUNT(*) AS total FROM ' . $this->q(self::TASKS_TABLE) . ' GROUP BY status'
        );
        $tasks = [];
        foreach ($taskStatement->fetchAll() as $row) {
            $tasks[(string) $row['status']] = (int) $row['total'];
        }

        return [
            'total_projects' => $total,
            'average_overall' => count($scores) > 0 ? round(array_sum($scores) / count($scores), 1) : null,
            'high_risk_projects' => $highRisk,
            'categories' => $categories,
            'tasks' => $tasks,
        ];
    }

    public function fixQueue(): array
    {
        $sql = '
            SELECT
                t.*,
                p.title AS project_title,
                pr.slug AS project_slug,
                pr.display_name AS project_display_name,
                pr.category AS project_category,
                risk.severity AS risk_severity,
                latest.overall_score
            FROM ' . $this->q(self::TASKS_TABLE) . ' t
            INNER JOIN ' . $this->projectTable() . ' p ON p.id = t.project_id
            INNER JOIN ' . $this->q(self::PROFILES_TABLE) . ' pr ON pr.project_id = p.id
            LEFT JOIN ' . $this->q(self::RISKS_TABLE) . ' risk ON risk.project_id = p.id
            LEFT JOIN ' . $this->q(self::REVIEWS_TABLE) . ' latest
                ON latest.id = (
                    SELECT r2.id
                    FROM ' . $this->q(self::REVIEWS_TABLE) . ' r2
                    WHERE r2.project_id = p.id
                    ORDER BY r2.reviewed_at DESC, r2.id DESC
                    LIMIT 1
                )
            WHERE t.status NOT IN ("done", "ignored")
        ';

        $tasks = [];
        foreach ($this->db->query($sql)->fetchAll() as $row) {
            $task = $this->taskRow($row);
            $task['project'] = [
                'id' => (int) $row['project_id'],
                'slug' => (string) $row['project_slug'],
                'name' => (string) $row['project_title'],
                'display_name' => $this->displayName((string) ($row['project_display_name'] ?? ''), (string) $row['project_title']),
                'category' => (string) $row['project_category'],
            ];
            $task['risk_severity'] = (string) ($row['risk_severity'] ?? 'low');
            $task['overall_score'] = $row['overall_score'] !== null ? (float) $row['overall_score'] : null;
            $task['priority_score'] = $this->priorityScore($task);
            $tasks[] = $task;
        }

        usort($tasks, fn (array $a, array $b): int => $b['priority_score'] <=> $a['priority_score']);

        return $tasks;
    }

    private function baseProjectSql(): string
    {
        return '
            SELECT
                p.id AS project_id,
                p.title,
                p.path,
                p.description,
                p.stage,
                p.status,
                p.version,
                p.group_name,
                p.repository_type,
                p.repository_url,
                p.hidden,
                p.show_on_homepage,
                p.created_at AS project_created_at,
                p.updated_at AS project_updated_at,
                pr.slug,
                pr.display_name,
                pr.category,
                pr.shape,
                pr.summary,
                pr.preview_url,
                pr.production_url,
                pr.source AS profile_source,
                latest.id AS review_id,
                latest.reviewed_at,
                latest.source AS review_source,
                latest.frontend_score,
                latest.backend_score,
                latest.security_score,
                latest.overall_score,
                latest.notes AS review_notes,
                latest.priority_fix,
                risk.severity,
                risk.auth_risk,
                risk.data_risk,
                risk.env_risk,
                risk.ownership_risk,
                risk.notes AS risk_notes
            FROM ' . $this->projectTable() . ' p
            INNER JOIN ' . $this->q(self::PROFILES_TABLE) . ' pr ON pr.project_id = p.id
            LEFT JOIN ' . $this->q(self::RISKS_TABLE) . ' risk ON risk.project_id = p.id
            LEFT JOIN ' . $this->q(self::REVIEWS_TABLE) . ' latest
                ON latest.id = (
                    SELECT r2.id
                    FROM ' . $this->q(self::REVIEWS_TABLE) . ' r2
                    WHERE r2.project_id = p.id
                    ORDER BY r2.reviewed_at DESC, r2.id DESC
                    LIMIT 1
                )
        ';
    }

    private function rowToProject(array $row): array
    {
        $repository = $this->repositoryMetadata($row);

        return [
            'id' => (int) $row['project_id'],
            'slug' => (string) $row['slug'],
            'name' => (string) $row['title'],
            'display_name' => $this->displayName((string) ($row['display_name'] ?? ''), (string) $row['title']),
            'category' => (string) $row['category'],
            'status' => $this->normalizeProjectStatus((string) $row['status']),
            'stage' => (string) $row['stage'],
            'shape' => (string) $row['shape'],
            'summary' => (string) ($row['summary'] ?: $row['description'] ?: ''),
            'repo_path' => $row['path'],
            'preview_url' => $row['preview_url'],
            'production_url' => $row['production_url'],
            'version' => (string) $row['version'],
            'group_name' => (string) $row['group_name'],
            'repository_type' => $repository['repository_type'],
            'repository_url' => $repository['repository_url'],
            'hidden' => (bool) $row['hidden'],
            'show_on_homepage' => (bool) $row['show_on_homepage'],
            'created_at' => $row['project_created_at'],
            'updated_at' => $row['project_updated_at'],
            'latest_review' => $row['review_id'] !== null ? [
                'id' => (int) $row['review_id'],
                'reviewed_at' => $row['reviewed_at'],
                'source' => $row['review_source'],
                'frontend_score' => $this->nullableFloat($row['frontend_score']),
                'backend_score' => $this->nullableFloat($row['backend_score']),
                'security_score' => $this->nullableFloat($row['security_score']),
                'overall_score' => $this->nullableFloat($row['overall_score']),
                'notes' => $row['review_notes'],
                'priority_fix' => $row['priority_fix'],
            ] : null,
            'risk' => [
                'severity' => (string) ($row['severity'] ?? 'low'),
                'auth_risk' => (string) ($row['auth_risk'] ?? 'standard'),
                'data_risk' => (string) ($row['data_risk'] ?? 'standard'),
                'env_risk' => (string) ($row['env_risk'] ?? 'standard'),
                'ownership_risk' => (string) ($row['ownership_risk'] ?? 'standard'),
                'notes' => (string) ($row['risk_notes'] ?? ''),
            ],
        ];
    }

    private function repositoryMetadata(array $row): array
    {
        $metadata = [
            'repository_type' => $row['repository_type'],
            'repository_url' => $row['repository_url'],
        ];

        if ($metadata['repository_url'] !== null && trim((string) $metadata['repository_url']) !== '') {
            return $metadata;
        }

        $fallback = $this->repositoryMetadataBySlug(
            (string) $row['slug'],
            (int) $row['project_id']
        );

        return $fallback ?? $metadata;
    }

    private function repositoryMetadataBySlug(string $slug, int $excludeProjectId): ?array
    {
        $normalizedSlug = $this->slug($slug);
        if ($normalizedSlug === '') {
            return null;
        }

        $statement = $this->db->prepare(
            'SELECT repository_type, repository_url
            FROM ' . $this->projectTable() . '
            WHERE id <> :project_id
                AND repository_url IS NOT NULL
                AND repository_url <> ""
                AND (
                    LOWER(REPLACE(REPLACE(title, " ", "_"), "-", "_")) = :slug
                    OR LOWER(TRIM(TRAILING "/" FROM REPLACE(path, CHAR(92), "/"))) LIKE :path_suffix
                )
            ORDER BY id ASC
            LIMIT 1'
        );
        $statement->execute([
            'project_id' => $excludeProjectId,
            'slug' => $normalizedSlug,
            'path_suffix' => '%/' . $normalizedSlug,
        ]);

        $row = $statement->fetch();

        return is_array($row) ? [
            'repository_type' => $row['repository_type'],
            'repository_url' => $row['repository_url'],
        ] : null;
    }

    private function filterProjects(array $projects, array $filters): array
    {
        $search = strtolower(trim((string) ($filters['search'] ?? '')));

        return array_values(array_filter($projects, function (array $project) use ($filters, $search): bool {
            if ($search !== '') {
                $haystack = strtolower(implode(' ', [
                    $project['name'],
                    $project['display_name'],
                    $project['slug'],
                    $project['summary'],
                    $project['latest_review']['notes'] ?? '',
                    $project['latest_review']['priority_fix'] ?? '',
                ]));

                if (!str_contains($haystack, $search)) {
                    return false;
                }
            }

            foreach (['category', 'status', 'shape'] as $field) {
                $value = trim((string) ($filters[$field] ?? ''));
                if ($value !== '' && $value !== 'all' && $project[$field] !== $value) {
                    return false;
                }
            }

            $risk = trim((string) ($filters['risk'] ?? ''));
            if ($risk !== '' && $risk !== 'all' && ($project['risk']['severity'] ?? '') !== $risk) {
                return false;
            }

            $overall = $project['latest_review']['overall_score'] ?? null;
            if (isset($filters['max_score']) && $filters['max_score'] !== '' && $overall !== null && $overall > (float) $filters['max_score']) {
                return false;
            }

            if (isset($filters['min_score']) && $filters['min_score'] !== '' && $overall !== null && $overall < (float) $filters['min_score']) {
                return false;
            }

            return true;
        }));
    }

    private function sortProjects(array $projects, string $sort): array
    {
        usort($projects, function (array $a, array $b) use ($sort): int {
            return match ($sort) {
                'highest_risk' => $this->riskRank($b['risk']['severity']) <=> $this->riskRank($a['risk']['severity']),
                'recently_reviewed' => strcmp((string) ($b['latest_review']['reviewed_at'] ?? ''), (string) ($a['latest_review']['reviewed_at'] ?? '')),
                'name' => strcmp((string) ($a['display_name'] ?? $a['name']), (string) ($b['display_name'] ?? $b['name'])),
                default => ($a['latest_review']['overall_score'] ?? 99) <=> ($b['latest_review']['overall_score'] ?? 99),
            };
        });

        return $projects;
    }

    private function upsertImportedProject(array $record): int
    {
        $slug = (string) $record['profile']['slug'];
        $projectId = $this->projectIdBySlug($slug);

        if ($projectId === null) {
            $projectId = $this->projectIdByPathOrTitle((string) $record['project']['path'], (string) $record['project']['title']);
        }

        if ($projectId === null) {
            $projectId = $this->insertProject($record['project']);
        } else {
            $this->updateProjectRow($projectId, $this->importProjectUpdates($record['project']));
        }

        $this->upsertProfile($projectId, $record['profile']);

        return $projectId;
    }

    private function importProjectUpdates(array $project): array
    {
        $updates = $project;
        if (!isset($updates['repository_url']) || trim((string) $updates['repository_url']) === '') {
            unset($updates['repository_type'], $updates['repository_url']);
        }

        return $updates;
    }

    private function insertProject(array $project): int
    {
        $now = date('Y-m-d H:i:s');
        $data = [
            'title' => trim((string) $project['title']),
            'path' => $project['path'] ?? null,
            'description' => $project['description'] ?? null,
            'stage' => trim((string) $project['stage']),
            'status' => $this->normalizeProjectStatus((string) $project['status']),
            'version' => trim((string) $project['version']),
            'group_name' => trim((string) $project['group_name']),
            'repository_type' => $project['repository_type'] ?? null,
            'repository_url' => $project['repository_url'] ?? null,
            'hidden' => (int) ($project['hidden'] ?? 0),
            'show_on_homepage' => (int) ($project['show_on_homepage'] ?? 1),
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $sql = '
            INSERT INTO ' . $this->projectTable() . '
                (title, path, description, stage, status, version, group_name, repository_type, repository_url, hidden, show_on_homepage, created_at, updated_at)
            VALUES
                (:title, :path, :description, :stage, :status, :version, :group_name, :repository_type, :repository_url, :hidden, :show_on_homepage, :created_at, :updated_at)
        ';
        $statement = $this->db->prepare($sql);
        $statement->execute($data);

        return (int) $this->db->lastInsertId();
    }

    private function updateProjectRow(int $projectId, array $updates): void
    {
        $allowed = ['title', 'path', 'description', 'stage', 'status', 'version', 'group_name', 'repository_type', 'repository_url', 'hidden', 'show_on_homepage'];
        $data = [];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $updates)) {
                if ($field === 'status') {
                    $data[$field] = $this->normalizeProjectStatus((string) $updates[$field]);
                } else {
                    $data[$field] = in_array($field, ['hidden', 'show_on_homepage'], true) ? (int) $updates[$field] : $updates[$field];
                }
            }
        }

        if ($data === []) {
            return;
        }

        $data['updated_at'] = date('Y-m-d H:i:s');
        $assignments = [];
        foreach (array_keys($data) as $field) {
            $assignments[] = $this->q($field) . ' = :' . $field;
        }

        $data['id'] = $projectId;
        $statement = $this->db->prepare(
            'UPDATE ' . $this->projectTable() . ' SET ' . implode(', ', $assignments) . ' WHERE id = :id'
        );
        $statement->execute($data);
    }

    private function upsertProfile(int $projectId, array $profile): void
    {
        $data = [
            'project_id' => $projectId,
            'slug' => trim((string) $profile['slug']),
            'display_name' => trim((string) ($profile['display_name'] ?? '')) ?: null,
            'category' => trim((string) $profile['category']),
            'shape' => trim((string) $profile['shape']),
            'summary' => $profile['summary'] ?? null,
            'preview_url' => $profile['preview_url'] ?? null,
            'production_url' => $profile['production_url'] ?? null,
            'source' => $profile['source'] ?? null,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        $sql = '
            INSERT INTO ' . $this->q(self::PROFILES_TABLE) . '
                (project_id, slug, display_name, category, shape, summary, preview_url, production_url, source, created_at, updated_at)
            VALUES
                (:project_id, :slug, :display_name, :category, :shape, :summary, :preview_url, :production_url, :source, :created_at, :updated_at)
            ON DUPLICATE KEY UPDATE
                slug = VALUES(slug),
                display_name = VALUES(display_name),
                category = VALUES(category),
                shape = VALUES(shape),
                summary = VALUES(summary),
                preview_url = VALUES(preview_url),
                production_url = VALUES(production_url),
                source = VALUES(source),
                updated_at = VALUES(updated_at)
        ';
        $this->db->prepare($sql)->execute($data);
    }

    private function upsertReview(int $projectId, array $review): void
    {
        $data = ['project_id' => $projectId] + $this->normalizeReview($review);
        $sql = '
            INSERT INTO ' . $this->q(self::REVIEWS_TABLE) . '
                (project_id, reviewed_at, source, source_hash, frontend_score, backend_score, security_score, overall_score, notes, priority_fix, created_at)
            VALUES
                (:project_id, :reviewed_at, :source, :source_hash, :frontend_score, :backend_score, :security_score, :overall_score, :notes, :priority_fix, :created_at)
            ON DUPLICATE KEY UPDATE
                reviewed_at = VALUES(reviewed_at),
                frontend_score = VALUES(frontend_score),
                backend_score = VALUES(backend_score),
                security_score = VALUES(security_score),
                overall_score = VALUES(overall_score),
                notes = VALUES(notes),
                priority_fix = VALUES(priority_fix)
        ';
        $this->db->prepare($sql)->execute($data);
    }

    private function insertReview(int $projectId, array $review): int
    {
        $data = ['project_id' => $projectId] + $review;
        $statement = $this->db->prepare('
            INSERT INTO ' . $this->q(self::REVIEWS_TABLE) . '
                (project_id, reviewed_at, source, source_hash, frontend_score, backend_score, security_score, overall_score, notes, priority_fix, created_at)
            VALUES
                (:project_id, :reviewed_at, :source, :source_hash, :frontend_score, :backend_score, :security_score, :overall_score, :notes, :priority_fix, :created_at)
        ');
        $statement->execute($data);

        return (int) $this->db->lastInsertId();
    }

    private function upsertRisk(int $projectId, array $risk): void
    {
        $data = [
            'project_id' => $projectId,
            'severity' => trim((string) ($risk['severity'] ?? 'low')),
            'auth_risk' => trim((string) ($risk['auth_risk'] ?? 'standard')),
            'data_risk' => trim((string) ($risk['data_risk'] ?? 'standard')),
            'env_risk' => trim((string) ($risk['env_risk'] ?? 'standard')),
            'ownership_risk' => trim((string) ($risk['ownership_risk'] ?? 'standard')),
            'notes' => $risk['notes'] ?? null,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        $sql = '
            INSERT INTO ' . $this->q(self::RISKS_TABLE) . '
                (project_id, severity, auth_risk, data_risk, env_risk, ownership_risk, notes, created_at, updated_at)
            VALUES
                (:project_id, :severity, :auth_risk, :data_risk, :env_risk, :ownership_risk, :notes, :created_at, :updated_at)
            ON DUPLICATE KEY UPDATE
                severity = VALUES(severity),
                auth_risk = VALUES(auth_risk),
                data_risk = VALUES(data_risk),
                env_risk = VALUES(env_risk),
                ownership_risk = VALUES(ownership_risk),
                notes = VALUES(notes),
                updated_at = VALUES(updated_at)
        ';
        $this->db->prepare($sql)->execute($data);
    }

    private function upsertTask(int $projectId, array $task): void
    {
        $data = ['project_id' => $projectId] + $this->normalizeTask($task);
        $sql = '
            INSERT INTO ' . $this->q(self::TASKS_TABLE) . '
                (project_id, title, description, type, priority, status, effort, impact, source, source_hash, created_at, updated_at)
            VALUES
                (:project_id, :title, :description, :type, :priority, :status, :effort, :impact, :source, :source_hash, :created_at, :updated_at)
            ON DUPLICATE KEY UPDATE
                title = VALUES(title),
                description = VALUES(description),
                type = VALUES(type),
                priority = VALUES(priority),
                status = IF(status IN ("done", "ignored"), status, VALUES(status)),
                effort = VALUES(effort),
                impact = VALUES(impact),
                updated_at = VALUES(updated_at)
        ';
        $this->db->prepare($sql)->execute($data);
    }

    private function insertTask(int $projectId, array $task): int
    {
        $data = ['project_id' => $projectId] + $task;
        $statement = $this->db->prepare('
            INSERT INTO ' . $this->q(self::TASKS_TABLE) . '
                (project_id, title, description, type, priority, status, effort, impact, source, source_hash, created_at, updated_at)
            VALUES
                (:project_id, :title, :description, :type, :priority, :status, :effort, :impact, :source, :source_hash, :created_at, :updated_at)
        ');
        $statement->execute($data);

        return (int) $this->db->lastInsertId();
    }

    private function recordImport(array $batch, int $projectCount, int $reviewCount, int $taskCount): void
    {
        $statement = $this->db->prepare('
            INSERT INTO ' . $this->q(self::IMPORTS_TABLE) . '
                (source, source_hash, imported_at, project_count, review_count, task_count, created_at)
            VALUES
                (:source, :source_hash, :imported_at, :project_count, :review_count, :task_count, :created_at)
            ON DUPLICATE KEY UPDATE
                imported_at = VALUES(imported_at),
                project_count = VALUES(project_count),
                review_count = VALUES(review_count),
                task_count = VALUES(task_count)
        ');
        $statement->execute([
            'source' => $batch['source'],
            'source_hash' => $batch['source_hash'],
            'imported_at' => date('Y-m-d H:i:s'),
            'project_count' => $projectCount,
            'review_count' => $reviewCount,
            'task_count' => $taskCount,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function payloadToRecord(array $payload): array
    {
        $name = trim((string) ($payload['name'] ?? $payload['title'] ?? ''));
        if ($name === '') {
            throw new RuntimeException('Project name is required.');
        }

        $slug = $this->slug((string) ($payload['slug'] ?? $name));
        $displayName = trim((string) ($payload['display_name'] ?? $name));
        if ($displayName === '') {
            $displayName = $name;
        }
        $summary = trim((string) ($payload['summary'] ?? $payload['description'] ?? ''));
        $category = trim((string) ($payload['category'] ?? 'app'));
        $groupName = trim((string) ($payload['group_name'] ?? $this->groupNameForCategory($category)));

        return [
            'project' => [
                'title' => $name,
                'path' => $payload['repo_path'] ?? $payload['path'] ?? null,
                'description' => $summary,
                'stage' => trim((string) ($payload['stage'] ?? 'Concept')),
                'status' => $this->normalizeProjectStatus((string) ($payload['status'] ?? 'Concept')),
                'version' => trim((string) ($payload['version'] ?? '0.1.0')),
                'group_name' => $groupName,
                'repository_type' => $payload['repository_type'] ?? 'local',
                'repository_url' => $payload['repository_url'] ?? null,
                'hidden' => (int) ($payload['hidden'] ?? 0),
                'show_on_homepage' => (int) ($payload['show_on_homepage'] ?? 1),
            ],
            'profile' => [
                'slug' => $slug,
                'display_name' => $displayName,
                'category' => $category,
                'shape' => trim((string) ($payload['shape'] ?? 'frontend+backend')),
                'summary' => $summary,
                'preview_url' => $payload['preview_url'] ?? null,
                'production_url' => $payload['production_url'] ?? null,
                'source' => 'manual',
            ],
            'risk' => isset($payload['risk']) && is_array($payload['risk']) ? $payload['risk'] : null,
        ];
    }

    private function projectUpdates(array $payload): array
    {
        $updates = [];
        $map = [
            'name' => 'title',
            'title' => 'title',
            'repo_path' => 'path',
            'path' => 'path',
            'summary' => 'description',
            'description' => 'description',
            'stage' => 'stage',
            'status' => 'status',
            'version' => 'version',
            'group_name' => 'group_name',
            'repository_type' => 'repository_type',
            'repository_url' => 'repository_url',
            'hidden' => 'hidden',
            'show_on_homepage' => 'show_on_homepage',
        ];

        foreach ($map as $input => $field) {
            if (array_key_exists($input, $payload)) {
                $updates[$field] = $payload[$input];
            }
        }

        return $updates;
    }

    private function profileUpdates(array $payload): array
    {
        $updates = [];
        foreach (['slug', 'display_name', 'category', 'shape', 'summary', 'preview_url', 'production_url'] as $field) {
            if (array_key_exists($field, $payload)) {
                $updates[$field] = match ($field) {
                    'slug' => $this->slug((string) $payload[$field]),
                    'display_name' => trim((string) $payload[$field]) ?: null,
                    default => $payload[$field],
                };
            }
        }

        return $updates;
    }

    private function riskUpdates(array $payload): array
    {
        if (!isset($payload['risk']) || !is_array($payload['risk'])) {
            return [];
        }

        return array_intersect_key($payload['risk'], array_flip([
            'severity',
            'auth_risk',
            'data_risk',
            'env_risk',
            'ownership_risk',
            'notes',
        ]));
    }

    private function getProfile(int $projectId): array
    {
        $statement = $this->db->prepare('SELECT * FROM ' . $this->q(self::PROFILES_TABLE) . ' WHERE project_id = :project_id');
        $statement->execute(['project_id' => $projectId]);
        $row = $statement->fetch();

        if (!is_array($row)) {
            throw new RuntimeException('Project profile was not found.');
        }

        return $row;
    }

    private function getRisk(int $projectId): array
    {
        $statement = $this->db->prepare('SELECT * FROM ' . $this->q(self::RISKS_TABLE) . ' WHERE project_id = :project_id');
        $statement->execute(['project_id' => $projectId]);
        $row = $statement->fetch();

        return is_array($row) ? $row : [
            'severity' => 'low',
            'auth_risk' => 'standard',
            'data_risk' => 'standard',
            'env_risk' => 'standard',
            'ownership_risk' => 'standard',
            'notes' => '',
        ];
    }

    private function normalizeReview(array $payload): array
    {
        return [
            'reviewed_at' => trim((string) ($payload['reviewed_at'] ?? date('Y-m-d H:i:s'))),
            'source' => trim((string) ($payload['source'] ?? 'manual')),
            'source_hash' => $payload['source_hash'] ?? null,
            'frontend_score' => $this->nullablePayloadFloat($payload['frontend_score'] ?? null),
            'backend_score' => $this->nullablePayloadFloat($payload['backend_score'] ?? null),
            'security_score' => $this->nullablePayloadFloat($payload['security_score'] ?? null),
            'overall_score' => $this->nullablePayloadFloat($payload['overall_score'] ?? null),
            'notes' => $payload['notes'] ?? '',
            'priority_fix' => $payload['priority_fix'] ?? '',
            'created_at' => date('Y-m-d H:i:s'),
        ];
    }

    private function normalizeTask(array $payload): array
    {
        return [
            'title' => trim((string) ($payload['title'] ?? 'Untitled task')),
            'description' => $payload['description'] ?? '',
            'type' => trim((string) ($payload['type'] ?? 'backend')),
            'priority' => trim((string) ($payload['priority'] ?? 'medium')),
            'status' => trim((string) ($payload['status'] ?? 'todo')),
            'effort' => trim((string) ($payload['effort'] ?? 'medium')),
            'impact' => trim((string) ($payload['impact'] ?? 'medium')),
            'source' => $payload['source'] ?? null,
            'source_hash' => $payload['source_hash'] ?? null,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];
    }

    private function reviewRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'project_id' => (int) $row['project_id'],
            'reviewed_at' => $row['reviewed_at'],
            'source' => $row['source'],
            'frontend_score' => $this->nullableFloat($row['frontend_score']),
            'backend_score' => $this->nullableFloat($row['backend_score']),
            'security_score' => $this->nullableFloat($row['security_score']),
            'overall_score' => $this->nullableFloat($row['overall_score']),
            'notes' => $row['notes'],
            'priority_fix' => $row['priority_fix'],
        ];
    }

    private function taskRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'project_id' => (int) $row['project_id'],
            'title' => (string) $row['title'],
            'description' => (string) ($row['description'] ?? ''),
            'type' => (string) $row['type'],
            'priority' => (string) $row['priority'],
            'status' => (string) $row['status'],
            'effort' => (string) $row['effort'],
            'impact' => (string) $row['impact'],
            'source' => $row['source'] ?? null,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    private function getTaskById(int $id): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM ' . $this->q(self::TASKS_TABLE) . ' WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return is_array($row) ? $this->taskRow($row) : null;
    }

    private function resolveProjectId(string $id): ?int
    {
        if (ctype_digit($id)) {
            $statement = $this->db->prepare('SELECT project_id FROM ' . $this->q(self::PROFILES_TABLE) . ' WHERE project_id = :project_id');
            $statement->execute(['project_id' => (int) $id]);
            $value = $statement->fetchColumn();

            return $value !== false ? (int) $value : null;
        }

        return $this->projectIdBySlug($id);
    }

    private function projectIdBySlug(string $slug): ?int
    {
        $statement = $this->db->prepare('SELECT project_id FROM ' . $this->q(self::PROFILES_TABLE) . ' WHERE slug = :slug');
        $statement->execute(['slug' => $slug]);
        $value = $statement->fetchColumn();

        return $value !== false ? (int) $value : null;
    }

    private function projectIdByPathOrTitle(string $path, string $title): ?int
    {
        $slug = $this->slug($title);
        $statement = $this->db->prepare(
            'SELECT id
            FROM ' . $this->projectTable() . '
            WHERE path = :path
                OR title = :title
                OR LOWER(REPLACE(REPLACE(title, " ", "_"), "-", "_")) = :slug
                OR LOWER(TRIM(TRAILING "/" FROM REPLACE(path, CHAR(92), "/"))) LIKE :path_suffix
            ORDER BY (repository_url IS NOT NULL AND repository_url <> "") DESC, id ASC
            LIMIT 1'
        );
        $statement->execute([
            'path' => $path,
            'title' => $title,
            'slug' => $slug,
            'path_suffix' => '%/' . $slug,
        ]);
        $value = $statement->fetchColumn();

        return $value !== false ? (int) $value : null;
    }

    private function slug(string $value): string
    {
        $slug = strtolower(trim($value));
        $slug = preg_replace('/[^a-z0-9_]+/', '_', $slug) ?? '';
        $slug = trim($slug, '_');

        return $slug !== '' ? $slug : 'untitled_project';
    }

    private function normalizeProjectStatus(string $status): string
    {
        $rawStatus = trim($status);
        $normalized = strtolower($rawStatus);

        return match ($normalized) {
            'mvp', 'strong' => 'MVP',
            'complete', 'completed', 'fully-working', 'fully working', 'published', 'production' => 'Complete',
            'concept', 'prototype', 'watch', 'risk', 'planning', 'in development', 'non-working', '' => 'Concept',
            default => $rawStatus,
        };
    }

    private function groupNameForCategory(string $category): string
    {
        return match ($category) {
            'app' => 'apps',
            'game' => 'games',
            'rust-game' => 'rust_games',
            'template' => 'templates',
            default => $category !== '' ? $category : 'other',
        };
    }

    private function displayName(string $displayName, string $name): string
    {
        $displayName = trim($displayName);

        return $displayName !== '' ? $displayName : $name;
    }

    private function priorityScore(array $task): int
    {
        return ($this->rank((string) $task['impact']) * 2)
            - $this->rank((string) $task['effort'])
            + $this->riskRank((string) $task['risk_severity'])
            + $this->rank((string) $task['priority']);
    }

    private function rank(string $value): int
    {
        return match ($value) {
            'high', 'large' => 3,
            'medium' => 2,
            'low', 'small' => 1,
            'tiny' => 0,
            default => 1,
        };
    }

    private function riskRank(string $value): int
    {
        return match ($value) {
            'critical' => 4,
            'high' => 3,
            'medium' => 2,
            default => 1,
        };
    }

    private function nullableFloat(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }

    private function nullablePayloadFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return round((float) $value, 1);
    }

    private function projectTable(): string
    {
        return $this->q($this->projectsTable);
    }

    private function q(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}
