<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Services\BugReportRateLimitStore;
use PDO;

/**
 * Storage for the public bug-report moderation queue (`project_roost_bug_reports`).
 * Kept separate from ProjectRepository so the untrusted intake path stays isolated
 * and that already-large file does not grow further.
 */
final class BugReportRepository implements BugReportRateLimitStore
{
    private const TABLE = 'project_roost_bug_reports';

    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connection();
    }

    /**
     * Resolve a project id from a Rust game slug (e.g. rust_ai_defense). Returns null
     * for slugs that do not match a known project — the report is still stored so an
     * admin can triage it, just without a project link.
     */
    public function projectIdBySlug(string $slug): ?int
    {
        $statement = $this->db->prepare(
            'SELECT project_id FROM `project_roost_profiles` WHERE slug = :slug LIMIT 1'
        );
        $statement->execute(['slug' => $slug]);
        $value = $statement->fetchColumn();

        return $value !== false ? (int) $value : null;
    }

    public function countRecentByIpHash(string $ipHash, string $since): int
    {
        $statement = $this->db->prepare(
            'SELECT COUNT(*) FROM `' . self::TABLE . '` WHERE ip_hash = :ip_hash AND created_at >= :since'
        );
        $statement->execute(['ip_hash' => $ipHash, 'since' => $since]);

        return (int) $statement->fetchColumn();
    }

    /**
     * @param array<string, mixed> $data Normalised, validated payload from BugReportGuard.
     */
    public function insert(array $data): int
    {
        $now = date('Y-m-d H:i:s');
        $statement = $this->db->prepare(
            'INSERT INTO `' . self::TABLE . '`
                (project_id, project_slug, summary, description, contact, game_version,
                 page_url, user_agent, ip_hash, status, created_at)
             VALUES
                (:project_id, :project_slug, :summary, :description, :contact, :game_version,
                 :page_url, :user_agent, :ip_hash, :status, :created_at)'
        );

        $statement->execute([
            'project_id' => $data['project_id'] ?? null,
            'project_slug' => $data['project_slug'],
            'summary' => $data['summary'],
            'description' => $data['description'],
            'contact' => $data['contact'] ?? null,
            'game_version' => $data['game_version'] ?? null,
            'page_url' => $data['page_url'] ?? null,
            'user_agent' => $data['user_agent'] ?? null,
            'ip_hash' => $data['ip_hash'] ?? null,
            'status' => 'new',
            'created_at' => $now,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function list(?string $status = null, int $limit = 100): array
    {
        $limit = max(1, min($limit, 500));
        $sql = 'SELECT * FROM `' . self::TABLE . '`';
        $params = [];

        if ($status !== null && $status !== '') {
            $sql .= ' WHERE status = :status';
            $params['status'] = $status;
        }

        $sql .= ' ORDER BY created_at DESC, id DESC LIMIT ' . $limit;

        $statement = $this->db->prepare($sql);
        $statement->execute($params);

        return array_map([$this, 'row'], $statement->fetchAll());
    }

    public function find(int $id): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM `' . self::TABLE . '` WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return is_array($row) ? $this->row($row) : null;
    }

    public function markReviewed(int $id, string $status, ?int $promotedTaskId = null): void
    {
        $statement = $this->db->prepare(
            'UPDATE `' . self::TABLE . '`
             SET status = :status, promoted_task_id = :task_id, reviewed_at = :reviewed_at
             WHERE id = :id'
        );
        $statement->execute([
            'status' => $status,
            'task_id' => $promotedTaskId,
            'reviewed_at' => date('Y-m-d H:i:s'),
            'id' => $id,
        ]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function row(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'project_id' => $row['project_id'] !== null ? (int) $row['project_id'] : null,
            'project_slug' => (string) $row['project_slug'],
            'summary' => (string) $row['summary'],
            'description' => (string) $row['description'],
            'contact' => $row['contact'] !== null ? (string) $row['contact'] : null,
            'game_version' => $row['game_version'] !== null ? (string) $row['game_version'] : null,
            'page_url' => $row['page_url'] !== null ? (string) $row['page_url'] : null,
            'user_agent' => $row['user_agent'] !== null ? (string) $row['user_agent'] : null,
            'status' => (string) $row['status'],
            'promoted_task_id' => $row['promoted_task_id'] !== null ? (int) $row['promoted_task_id'] : null,
            'created_at' => $row['created_at'] !== null ? (string) $row['created_at'] : null,
            'reviewed_at' => $row['reviewed_at'] !== null ? (string) $row['reviewed_at'] : null,
        ];
    }
}
