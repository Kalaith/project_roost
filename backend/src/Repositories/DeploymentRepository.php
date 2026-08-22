<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

final class DeploymentRepository
{
    private const DEPLOYMENTS_TABLE = 'project_roost_deployments';
    private const PROFILES_TABLE = 'project_roost_profiles';
    private const VISIBLE_ENVIRONMENT_SQL = "'preview', 'production'";

    private readonly PDO $db;
    private readonly string $projectsTable;

    public function __construct()
    {
        $this->db = Database::connection();
        $this->projectsTable = Database::requiredTableName('PROJECTS_TABLE');
    }

    public function record(array $payload): array
    {
        $projectSlug = $this->slug((string) ($payload['project'] ?? $payload['project_slug'] ?? 'unknown_project'));
        $projectId = $this->ensureProjectProfile($projectSlug, $payload);
        $this->updateProfileDeploymentUrl($projectId, $projectSlug, $payload);
        if (array_key_exists('archived', $payload)) {
            $this->updateProfileArchived($projectId, (bool) $payload['archived']);
        }
        $deployedAt = trim((string) ($payload['deployed_at'] ?? ''));
        if ($deployedAt === '') {
            $deployedAt = date('Y-m-d H:i:s');
        }

        $data = [
            'project_id' => $projectId,
            'project_slug' => $projectSlug,
            'environment' => $this->clean((string) ($payload['environment'] ?? 'preview'), 50),
            'target_type' => $this->clean((string) ($payload['target_type'] ?? 'filesystem'), 50),
            'status' => $this->clean((string) ($payload['status'] ?? 'success'), 50),
            'frontend_deployed' => (int) (bool) ($payload['frontend_deployed'] ?? false),
            'backend_deployed' => (int) (bool) ($payload['backend_deployed'] ?? false),
            'destination_path' => $this->nullableString($payload['destination_path'] ?? null, 500),
            'remote_path' => $this->nullableString($payload['remote_path'] ?? null, 500),
            'source_path' => $this->nullableString($payload['source_path'] ?? null, 500),
            'publish_mode' => $this->clean((string) ($payload['publish_mode'] ?? 'preview'), 100),
            'git_commit' => $this->nullableString($payload['git_commit'] ?? null, 64),
            'actor' => $this->nullableString($payload['actor'] ?? null, 191),
            'notes' => $payload['notes'] ?? null,
            'deployed_at' => $deployedAt,
            'created_at' => date('Y-m-d H:i:s'),
        ];

        $statement = $this->db->prepare('
            INSERT INTO ' . $this->q(self::DEPLOYMENTS_TABLE) . '
                (project_id, project_slug, environment, target_type, status, frontend_deployed, backend_deployed, destination_path, remote_path, source_path, publish_mode, git_commit, actor, notes, deployed_at, created_at)
            VALUES
                (:project_id, :project_slug, :environment, :target_type, :status, :frontend_deployed, :backend_deployed, :destination_path, :remote_path, :source_path, :publish_mode, :git_commit, :actor, :notes, :deployed_at, :created_at)
        ');
        $statement->execute($data);
        $deploymentId = (int) $this->db->lastInsertId();
        $this->backfillDeploymentProjectIds($projectSlug, $projectId);

        return $this->getById($deploymentId);
    }

    public function latestByEnvironment(?string $projectSlug = null): array
    {
        $params = [];
        $where = 'WHERE environment IN (' . self::VISIBLE_ENVIRONMENT_SQL . ')';
        if ($projectSlug !== null && trim($projectSlug) !== '') {
            $where .= ' AND project_slug = :project_slug';
            $params['project_slug'] = $this->slug($projectSlug);
        }

        $sql = '
            SELECT d.*
            FROM ' . $this->q(self::DEPLOYMENTS_TABLE) . ' d
            INNER JOIN (
                SELECT environment, MAX(id) AS id
                FROM ' . $this->q(self::DEPLOYMENTS_TABLE) . '
                ' . $where . '
                GROUP BY environment
            ) latest ON latest.id = d.id
        ';

        $deployments = [
            'preview' => null,
            'production' => null,
        ];

        $statement = $this->db->prepare($sql);
        $statement->execute($params);

        foreach ($statement->fetchAll() as $row) {
            $environment = (string) $row['environment'];
            if (!array_key_exists($environment, $deployments)) {
                continue;
            }

            $deployments[$environment] = $this->row($row);
        }

        return $deployments;
    }

    public function history(int $limit = 50, ?string $projectSlug = null): array
    {
        $params = [];
        $where = ' WHERE environment IN (' . self::VISIBLE_ENVIRONMENT_SQL . ')';
        if ($projectSlug !== null && trim($projectSlug) !== '') {
            $where .= ' AND project_slug = :project_slug';
            $params['project_slug'] = $this->slug($projectSlug);
        }

        $statement = $this->db->prepare(
            'SELECT * FROM ' . $this->q(self::DEPLOYMENTS_TABLE) . $where . ' ORDER BY deployed_at DESC, id DESC LIMIT :limit'
        );
        $statement->bindValue(':limit', max(1, min(200, $limit)), PDO::PARAM_INT);
        foreach ($params as $key => $value) {
            $statement->bindValue(':' . $key, $value);
        }
        $statement->execute();

        return array_map(fn (array $row): array => $this->row($row), $statement->fetchAll());
    }

    private function getById(int $id): array
    {
        $statement = $this->db->prepare('SELECT * FROM ' . $this->q(self::DEPLOYMENTS_TABLE) . ' WHERE id = :id');
        $statement->execute(['id' => $id]);

        return $this->row($statement->fetch());
    }

    private function ensureProjectProfile(string $slug, array $payload): int
    {
        $profileProjectId = $this->projectIdByProfileSlug($slug);
        if ($profileProjectId !== null) {
            return $profileProjectId;
        }

        $sourcePath = $this->nullableString($payload['source_path'] ?? null, 500);
        $projectId = $this->projectIdBySourcePathOrSlug($slug, $sourcePath);
        if ($projectId === null) {
            $projectId = $this->insertProjectFromDeployment($slug, $payload, $sourcePath);
        }

        if (!$this->projectHasProfile($projectId)) {
            $this->insertProfileFromDeployment($projectId, $slug, $payload, $sourcePath);
        }

        return $projectId;
    }

    private function projectIdByProfileSlug(string $slug): ?int
    {
        $statement = $this->db->prepare('SELECT project_id FROM ' . $this->q(self::PROFILES_TABLE) . ' WHERE slug = :slug');
        $statement->execute(['slug' => $slug]);
        $value = $statement->fetchColumn();

        return $value !== false ? (int) $value : null;
    }

    private function projectIdBySourcePathOrSlug(string $slug, ?string $sourcePath): ?int
    {
        $statement = $this->db->prepare(
            'SELECT id
            FROM ' . $this->q($this->projectsTable) . '
            WHERE title = :slug
                OR title = :display_title
                OR LOWER(REPLACE(REPLACE(title, " ", "_"), "-", "_")) = :normalized_slug
                OR path = :source_path
                OR LOWER(TRIM(TRAILING "/" FROM REPLACE(path, CHAR(92), "/"))) LIKE :path_suffix
            ORDER BY id ASC
            LIMIT 1'
        );
        $statement->execute([
            'slug' => $slug,
            'normalized_slug' => $slug,
            'display_title' => $this->displayNameFromSlug($slug),
            'source_path' => $sourcePath ?? '',
            'path_suffix' => '%/' . $slug,
        ]);
        $value = $statement->fetchColumn();

        return $value !== false ? (int) $value : null;
    }

    private function updateProfileArchived(int $projectId, bool $archived): void
    {
        $statement = $this->db->prepare(
            'UPDATE ' . $this->q(self::PROFILES_TABLE) . '
            SET archived = :archived, updated_at = :updated_at
            WHERE project_id = :project_id'
        );
        $statement->execute([
            'archived' => (int) $archived,
            'updated_at' => date('Y-m-d H:i:s'),
            'project_id' => $projectId,
        ]);
    }

    private function updateProfileDeploymentUrl(int $projectId, string $slug, array $payload): void
    {
        $environment = $this->clean((string) ($payload['environment'] ?? 'preview'), 50);
        $sourcePath = $this->nullableString($payload['source_path'] ?? null, 500);
        $isRustGame = $this->isRustGame($slug, $sourcePath);

        if ($environment === 'preview') {
            $column = 'preview_url';
            $url = $this->previewUrlFromSlug($slug, $isRustGame);
        } elseif ($environment === 'production' || $environment === 'local_production') {
            $column = 'production_url';
            $url = $this->productionUrlFromRemotePath(
                $payload['remote_path'] ?? null,
                $slug,
                $isRustGame
            );
        } else {
            return;
        }

        $statement = $this->db->prepare(
            'UPDATE ' . $this->q(self::PROFILES_TABLE) . '
            SET ' . $column . ' = :url, updated_at = :updated_at
            WHERE project_id = :project_id'
        );
        $statement->execute([
            'url' => $url,
            'updated_at' => date('Y-m-d H:i:s'),
            'project_id' => $projectId,
        ]);
    }

    private function projectHasProfile(int $projectId): bool
    {
        $statement = $this->db->prepare('SELECT 1 FROM ' . $this->q(self::PROFILES_TABLE) . ' WHERE project_id = :project_id');
        $statement->execute(['project_id' => $projectId]);

        return $statement->fetchColumn() !== false;
    }

    private function insertProjectFromDeployment(string $slug, array $payload, ?string $sourcePath): int
    {
        $metadata = $this->deploymentProjectMetadata($slug, $payload, $sourcePath);
        $now = date('Y-m-d H:i:s');

        $statement = $this->db->prepare('
            INSERT INTO ' . $this->q($this->projectsTable) . '
                (title, path, description, stage, status, version, group_name, repository_type, repository_url, hidden, show_on_homepage, created_at, updated_at)
            VALUES
                (:title, :path, :description, :stage, :status, :version, :group_name, :repository_type, :repository_url, :hidden, :show_on_homepage, :created_at, :updated_at)
        ');
        $statement->execute([
            'title' => $slug,
            'path' => $sourcePath,
            'description' => $metadata['summary'],
            'stage' => 'mvp',
            'status' => 'MVP',
            'version' => '0.1.0',
            'group_name' => $metadata['group_name'],
            'repository_type' => 'local',
            'repository_url' => null,
            'hidden' => 0,
            'show_on_homepage' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $this->db->lastInsertId();
    }

    private function insertProfileFromDeployment(int $projectId, string $slug, array $payload, ?string $sourcePath): void
    {
        $metadata = $this->deploymentProjectMetadata($slug, $payload, $sourcePath);
        $now = date('Y-m-d H:i:s');

        $statement = $this->db->prepare('
            INSERT INTO ' . $this->q(self::PROFILES_TABLE) . '
                (project_id, slug, display_name, category, shape, summary, preview_url, production_url, source, created_at, updated_at)
            VALUES
                (:project_id, :slug, :display_name, :category, :shape, :summary, :preview_url, :production_url, :source, :created_at, :updated_at)
            ON DUPLICATE KEY UPDATE
                display_name = VALUES(display_name),
                category = VALUES(category),
                shape = VALUES(shape),
                summary = VALUES(summary),
                preview_url = VALUES(preview_url),
                production_url = VALUES(production_url),
                source = VALUES(source),
                updated_at = VALUES(updated_at)
        ');
        $statement->execute([
            'project_id' => $projectId,
            'slug' => $slug,
            'display_name' => $metadata['display_name'],
            'category' => $metadata['category'],
            'shape' => $metadata['shape'],
            'summary' => $metadata['summary'],
            'preview_url' => $metadata['preview_url'],
            'production_url' => $metadata['production_url'],
            'source' => 'deployment',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function deploymentProjectMetadata(string $slug, array $payload, ?string $sourcePath): array
    {
        $displayName = $this->displayNameFromSlug($slug);
        $category = $this->categoryFromSourcePath($sourcePath);
        $hasBackend = (bool) ($payload['backend_deployed'] ?? false);

        return [
            'display_name' => $displayName,
            'category' => $category,
            'shape' => $category === 'rust-game' ? 'rust+webgl' : ($hasBackend ? 'frontend+backend' : 'frontend-only'),
            'summary' => $displayName . ' is a WebHatchery project deployed through the shared publisher.',
            'preview_url' => $this->previewUrlFromSlug($slug, $this->isRustGame($slug, $sourcePath)),
            'production_url' => $this->productionUrlFromRemotePath(
                $payload['remote_path'] ?? null,
                $slug,
                $this->isRustGame($slug, $sourcePath)
            ),
            'group_name' => $this->groupNameForCategory($category),
        ];
    }

    private function backfillDeploymentProjectIds(string $slug, int $projectId): void
    {
        $statement = $this->db->prepare(
            'UPDATE ' . $this->q(self::DEPLOYMENTS_TABLE) . '
            SET project_id = :project_id
            WHERE project_slug = :project_slug AND project_id IS NULL'
        );
        $statement->execute([
            'project_id' => $projectId,
            'project_slug' => $slug,
        ]);
    }

    private function displayNameFromSlug(string $slug): string
    {
        $isRust = str_starts_with($slug, 'rust_');
        $baseSlug = $isRust ? substr($slug, 5) : $slug;
        $words = array_filter(explode('_', $baseSlug), fn (string $word): bool => $word !== '');
        $displayName = implode(' ', array_map(
            fn (string $word): string => ucfirst($word),
            $words
        ));

        if ($displayName === '') {
            $displayName = 'Unknown Project';
        }

        return $isRust ? $displayName . ' (Rust)' : $displayName;
    }

    private function categoryFromSourcePath(?string $sourcePath): string
    {
        $path = strtolower(str_replace('/', '\\', (string) $sourcePath));
        if (str_contains($path, '\\rustgames\\') || str_contains($path, '\\adultgames\\')) {
            return 'rust-game';
        }

        if (str_contains($path, '\\game_apps\\')) {
            return 'game';
        }

        return 'app';
    }

    private function groupNameForCategory(string $category): string
    {
        return match ($category) {
            'game' => 'game_apps',
            'rust-game' => 'rust_games',
            default => 'apps',
        };
    }

    private function publicPathFromSlug(string $slug): string
    {
        return str_starts_with($slug, 'rust_') ? substr($slug, 5) : $slug;
    }

    private function previewUrlFromSlug(string $slug, bool $isRustGame): string
    {
        $path = $isRustGame ? 'games/' . $this->publicPathFromSlug($slug) : $this->publicPathFromSlug($slug);

        return 'http://127.0.0.1/' . $path . '/';
    }

    private function productionUrlFromRemotePath(mixed $remotePath, string $slug, bool $isRustGame): string
    {
        $path = trim(str_replace('\\', '/', (string) $remotePath));
        $path = preg_replace('#^/public_html/?#', '/', $path) ?? '';
        $path = trim($path, '/');
        if ($path === '' || ($isRustGame && !str_starts_with($path, 'games/'))) {
            $path = $isRustGame
                ? 'games/' . $this->publicPathFromSlug($slug)
                : $this->publicPathFromSlug($slug);
        }

        return 'https://webhatchery.au/' . $path . '/';
    }

    private function isRustGame(string $slug, ?string $sourcePath): bool
    {
        $normalizedSource = strtolower(str_replace('/', '\\', (string) $sourcePath));

        return str_starts_with($slug, 'rust_') || str_contains($normalizedSource, '\\rustgames\\');
    }

    private function row(array|false $row): array
    {
        if (!is_array($row)) {
            return [];
        }

        return [
            'id' => (int) $row['id'],
            'project_id' => $row['project_id'] !== null ? (int) $row['project_id'] : null,
            'project_slug' => (string) $row['project_slug'],
            'environment' => (string) $row['environment'],
            'target_type' => (string) $row['target_type'],
            'status' => (string) $row['status'],
            'frontend_deployed' => (bool) $row['frontend_deployed'],
            'backend_deployed' => (bool) $row['backend_deployed'],
            'destination_path' => $row['destination_path'],
            'remote_path' => $row['remote_path'],
            'source_path' => $row['source_path'],
            'publish_mode' => (string) $row['publish_mode'],
            'git_commit' => $row['git_commit'],
            'actor' => $row['actor'],
            'notes' => $row['notes'],
            'deployed_at' => $row['deployed_at'],
            'created_at' => $row['created_at'],
        ];
    }

    private function slug(string $value): string
    {
        $slug = strtolower(trim($value));
        $slug = preg_replace('/[^a-z0-9_]+/', '_', $slug) ?? '';
        $slug = trim($slug, '_');

        return $slug !== '' ? $slug : 'unknown_project';
    }

    private function clean(string $value, int $maxLength): string
    {
        $value = trim($value);
        if ($value === '') {
            $value = 'unknown';
        }

        return substr($value, 0, $maxLength);
    }

    private function nullableString(mixed $value, int $maxLength): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        return substr($text, 0, $maxLength);
    }

    private function q(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}
