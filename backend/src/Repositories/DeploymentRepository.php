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
        $projectId = $this->projectIdBySlug($projectSlug);
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

        return $this->getById((int) $this->db->lastInsertId());
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

    private function projectIdBySlug(string $slug): ?int
    {
        $statement = $this->db->prepare('SELECT project_id FROM ' . $this->q(self::PROFILES_TABLE) . ' WHERE slug = :slug');
        $statement->execute(['slug' => $slug]);
        $value = $statement->fetchColumn();
        if ($value !== false) {
            return (int) $value;
        }

        $statement = $this->db->prepare(
            'SELECT id FROM ' . $this->q($this->projectsTable) . ' WHERE title = :title OR path LIKE :path ORDER BY id ASC LIMIT 1'
        );
        $statement->execute([
            'title' => str_replace('_', ' ', $slug),
            'path' => '%' . $slug,
        ]);
        $value = $statement->fetchColumn();

        return $value !== false ? (int) $value : null;
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
