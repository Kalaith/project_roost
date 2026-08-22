<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use RuntimeException;

final class SharedProjectReconciliationService
{
    private const PROFILES_TABLE = 'project_roost_profiles';

    private const KNOWN_DISPLAY_NAMES = [
        'adventcon' => 'Adventure Story Generator',
        'anime_prompt_gen' => 'Anime Prompt Generator',
        'isitdoneyet' => 'Is It Done Yet?',
        'kemo_sim' => 'Kemo Simulator',
        'name_generator' => 'Name Generator API',
        'writers_studio' => 'Writers Studio',
    ];

    private const REPLACED_PROJECTS = [
        'litrpg_studio' => 'writers_studio',
    ];

    private readonly PDO $db;
    private readonly string $projectsTable;

    public function __construct(?PDO $db = null, ?string $projectsTable = null)
    {
        $this->db = $db ?? Database::connection();
        $this->projectsTable = $projectsTable ?? Database::requiredTableName('PROJECTS_TABLE');
    }

    /**
     * @return array<string, int>
     */
    public function reconcile(): array
    {
        $result = [
            'profiles_created' => 0,
            'duplicate_projects_hidden' => 0,
            'replaced_projects_hidden' => 0,
            'replacement_projects_visible' => 0,
            'display_names_refreshed' => 0,
            'visible_projects_without_profiles' => 0,
        ];

        $this->db->beginTransaction();

        try {
            $result['display_names_refreshed'] = $this->refreshKnownDisplayNames();
            $result['replacement_projects_visible'] = $this->showReplacementProjects();
            $result['replaced_projects_hidden'] = $this->hideReplacedProjects();

            foreach ($this->visibleProjectsWithoutProfiles() as $project) {
                $profile = self::profileFromProject($project);
                $projectId = (int) $project['id'];

                if ($this->profileExistsOnAnotherProject($profile['slug'], $projectId)) {
                    $result['duplicate_projects_hidden'] += $this->hideProject($projectId);
                    continue;
                }

                $this->insertProfile($projectId, $profile);
                $result['profiles_created']++;
            }

            $result['visible_projects_without_profiles'] = $this->countVisibleProjectsWithoutProfiles();
            $this->db->commit();
        } catch (\Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $project
     * @return array<string, string|null>
     */
    public static function profileFromProject(array $project): array
    {
        $slug = self::slugForProject($project);
        $category = self::categoryForGroup((string) ($project['group_name'] ?? 'other'));
        $publicPath = self::publicPath((string) ($project['path'] ?? ''), $slug, $category);

        return [
            'slug' => $slug,
            'display_name' => self::displayNameForSlug($slug, (string) ($project['title'] ?? '')),
            'category' => $category,
            'shape' => self::shapeForCategory($category),
            'summary' => trim((string) ($project['description'] ?? '')) ?: null,
            'preview_url' => 'http://127.0.0.1' . $publicPath,
            'production_url' => 'https://webhatchery.au' . $publicPath,
            'source' => 'frontpage-shared',
        ];
    }

    /**
     * @param array<string, mixed> $project
     */
    public static function replacementSlugForProject(array $project): ?string
    {
        $candidates = [
            self::slugForProject($project),
            self::slug((string) ($project['title'] ?? '')),
        ];

        foreach ($candidates as $candidate) {
            if (isset(self::REPLACED_PROJECTS[$candidate])) {
                return self::REPLACED_PROJECTS[$candidate];
            }
        }

        return null;
    }

    private function refreshKnownDisplayNames(): int
    {
        $count = 0;
        $statement = $this->db->prepare(
            'UPDATE ' . $this->q(self::PROFILES_TABLE) . '
            SET display_name = :display_name, updated_at = NOW()
            WHERE slug = :slug
                AND (display_name IS NULL OR display_name = "" OR display_name = :generated_name OR display_name = :slug_value)'
        );

        foreach (self::KNOWN_DISPLAY_NAMES as $slug => $displayName) {
            $statement->execute([
                'display_name' => $displayName,
                'generated_name' => self::titleFromSlug($slug),
                'slug' => $slug,
                'slug_value' => $slug,
            ]);
            $count += $statement->rowCount();
        }

        return $count;
    }

    private function showReplacementProjects(): int
    {
        $count = 0;
        foreach (array_unique(array_values(self::REPLACED_PROJECTS)) as $slug) {
            $projectId = $this->projectIdByProfileSlug($slug) ?? $this->projectIdByProjectSlug($slug);
            if ($projectId === null) {
                continue;
            }

            $statement = $this->db->prepare(
                'UPDATE ' . $this->projectTable() . '
                SET hidden = 0, show_on_homepage = 1, updated_at = NOW()
                WHERE id = :id AND (hidden <> 0 OR show_on_homepage <> 1)'
            );
            $statement->execute(['id' => $projectId]);
            $count += $statement->rowCount();
        }

        return $count;
    }

    private function hideReplacedProjects(): int
    {
        $count = 0;
        foreach ($this->allProjects() as $project) {
            $replacementSlug = self::replacementSlugForProject($project);
            if ($replacementSlug === null) {
                continue;
            }

            if ($this->projectIdByProfileSlug($replacementSlug) === null && $this->projectIdByProjectSlug($replacementSlug) === null) {
                continue;
            }

            $count += $this->hideProject((int) $project['id']);
        }

        return $count;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function allProjects(): array
    {
        return $this->db->query('SELECT * FROM ' . $this->projectTable())->fetchAll();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function visibleProjectsWithoutProfiles(): array
    {
        $statement = $this->db->query(
            'SELECT p.*
            FROM ' . $this->projectTable() . ' p
            LEFT JOIN ' . $this->q(self::PROFILES_TABLE) . ' pr ON pr.project_id = p.id
            WHERE pr.project_id IS NULL
                AND COALESCE(p.hidden, 0) = 0
            ORDER BY p.id ASC'
        );

        return $statement->fetchAll();
    }

    private function countVisibleProjectsWithoutProfiles(): int
    {
        $statement = $this->db->query(
            'SELECT COUNT(*)
            FROM ' . $this->projectTable() . ' p
            LEFT JOIN ' . $this->q(self::PROFILES_TABLE) . ' pr ON pr.project_id = p.id
            WHERE pr.project_id IS NULL
                AND COALESCE(p.hidden, 0) = 0'
        );

        return (int) $statement->fetchColumn();
    }

    /**
     * @param array<string, string|null> $profile
     */
    private function insertProfile(int $projectId, array $profile): void
    {
        $statement = $this->db->prepare(
            'INSERT INTO ' . $this->q(self::PROFILES_TABLE) . '
                (project_id, slug, display_name, category, shape, summary, preview_url, production_url, source, created_at, updated_at)
            VALUES
                (:project_id, :slug, :display_name, :category, :shape, :summary, :preview_url, :production_url, :source, NOW(), NOW())'
        );
        $statement->execute([
            'project_id' => $projectId,
            'slug' => $profile['slug'],
            'display_name' => $profile['display_name'],
            'category' => $profile['category'],
            'shape' => $profile['shape'],
            'summary' => $profile['summary'],
            'preview_url' => $profile['preview_url'],
            'production_url' => $profile['production_url'],
            'source' => $profile['source'],
        ]);
    }

    private function profileExistsOnAnotherProject(string $slug, int $projectId): bool
    {
        $statement = $this->db->prepare(
            'SELECT COUNT(*) FROM ' . $this->q(self::PROFILES_TABLE) . ' WHERE slug = :slug AND project_id <> :project_id'
        );
        $statement->execute([
            'slug' => $slug,
            'project_id' => $projectId,
        ]);

        return (int) $statement->fetchColumn() > 0;
    }

    private function projectIdByProfileSlug(string $slug): ?int
    {
        $statement = $this->db->prepare('SELECT project_id FROM ' . $this->q(self::PROFILES_TABLE) . ' WHERE slug = :slug LIMIT 1');
        $statement->execute(['slug' => $slug]);
        $value = $statement->fetchColumn();

        return $value !== false ? (int) $value : null;
    }

    private function projectIdByProjectSlug(string $slug): ?int
    {
        $statement = $this->db->prepare(
            'SELECT id
            FROM ' . $this->projectTable() . '
            WHERE LOWER(REPLACE(REPLACE(title, " ", "_"), "-", "_")) = :slug
                OR LOWER(TRIM(TRAILING "/" FROM REPLACE(path, CHAR(92), "/"))) LIKE :path_suffix
            ORDER BY id ASC
            LIMIT 1'
        );
        $statement->execute([
            'slug' => $slug,
            'path_suffix' => '%/' . $slug,
        ]);
        $value = $statement->fetchColumn();

        return $value !== false ? (int) $value : null;
    }

    private function hideProject(int $projectId): int
    {
        $statement = $this->db->prepare(
            'UPDATE ' . $this->projectTable() . '
            SET hidden = 1, show_on_homepage = 0, updated_at = NOW()
            WHERE id = :id AND (hidden <> 1 OR show_on_homepage <> 0)'
        );
        $statement->execute(['id' => $projectId]);

        return $statement->rowCount();
    }

    /**
     * @param array<string, mixed> $project
     */
    private static function slugForProject(array $project): string
    {
        $pathSlug = self::slugFromPath((string) ($project['path'] ?? ''));

        return $pathSlug ?? self::slug((string) ($project['title'] ?? ''));
    }

    private static function slugFromPath(string $path): ?string
    {
        $parts = self::meaningfulPathParts($path);
        if ($parts === []) {
            return null;
        }

        $roots = ['apps', 'game_apps', 'rustgames', 'rust_games', 'games', 'gdd', 'web'];
        foreach ($parts as $index => $part) {
            if (in_array($part, $roots, true) && isset($parts[$index + 1])) {
                return self::slug($parts[$index + 1]);
            }
        }

        if (count($parts) === 1) {
            return self::slug($parts[0]);
        }

        return self::slug((string) end($parts));
    }

    private static function publicPath(string $path, string $slug, string $category): string
    {
        $trimmed = trim($path);
        if (preg_match('#^https?://#i', $trimmed) === 1) {
            return '/';
        }

        $normalized = str_replace('\\', '/', $trimmed);
        $parts = self::meaningfulPathParts($path);
        $isRustGame = $category === 'rust-game'
            || str_starts_with($slug, 'rust_')
            || in_array('rustgames', $parts, true)
            || in_array('rust_games', $parts, true);
        $publicSlug = $isRustGame ? self::rustGameSlug($slug) : $slug;

        if (str_starts_with($normalized, '/')) {
            if ($isRustGame) {
                return '/games/' . $publicSlug . '/';
            }

            return self::withTrailingSlash($normalized);
        }

        foreach (['rustgames', 'rust_games', 'games'] as $root) {
            if (in_array($root, $parts, true)) {
                return '/games/' . $publicSlug . '/';
            }
        }

        if (in_array('gdd', $parts, true)) {
            return '/gdd/' . $publicSlug . '/';
        }

        if (in_array('web', $parts, true)) {
            return '/web/' . $publicSlug . '/';
        }

        return '/' . $publicSlug . '/';
    }

    private static function rustGameSlug(string $slug): string
    {
        return str_starts_with($slug, 'rust_') ? substr($slug, 5) : $slug;
    }

    /**
     * @return array<int, string>
     */
    private static function meaningfulPathParts(string $path): array
    {
        $normalized = strtolower(str_replace('\\', '/', trim($path)));
        $parts = array_values(array_filter(
            explode('/', trim($normalized, '/')),
            static fn (string $part): bool => $part !== ''
                && !in_array($part, ['frontend', 'backend', 'public'], true)
        ));

        if ($parts !== [] && preg_match('/^[a-z]:$/i', $parts[0]) === 1) {
            array_shift($parts);
        }

        return $parts;
    }

    private static function displayNameForSlug(string $slug, string $title): string
    {
        if (isset(self::KNOWN_DISPLAY_NAMES[$slug])) {
            return self::KNOWN_DISPLAY_NAMES[$slug];
        }

        $title = trim($title);
        if ($title !== '' && !str_contains($title, '_') && !str_contains($title, '-')) {
            return $title;
        }

        return self::titleFromSlug($slug);
    }

    private static function categoryForGroup(string $groupName): string
    {
        return match (strtolower(trim($groupName))) {
            'apps' => 'app',
            'games', 'game_apps', 'game-apps' => 'game',
            'rust_games', 'rust-games', 'rustgames' => 'rust-game',
            'templates' => 'template',
            'game_design', 'game-design', 'gdd' => 'game-design',
            'fiction' => 'fiction',
            'private' => 'private',
            default => 'other',
        };
    }

    private static function shapeForCategory(string $category): string
    {
        return match ($category) {
            'app' => 'frontend+backend',
            'game' => 'frontend-only',
            'rust-game' => 'rust+webgl',
            'template' => 'template',
            'game-design' => 'design',
            'fiction', 'private' => 'static',
            default => 'unknown',
        };
    }

    private static function slug(string $value): string
    {
        $slug = strtolower(trim($value));
        $slug = preg_replace('/[^a-z0-9_]+/', '_', $slug) ?? '';
        $slug = trim($slug, '_');

        return $slug !== '' ? $slug : 'untitled_project';
    }

    private static function titleFromSlug(string $slug): string
    {
        return ucwords(str_replace('_', ' ', $slug));
    }

    private static function withTrailingSlash(string $path): string
    {
        return rtrim($path, '/') . '/';
    }

    private function projectTable(): string
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $this->projectsTable)) {
            throw new RuntimeException('Project table name must contain only letters, numbers, and underscores.');
        }

        return $this->q($this->projectsTable);
    }

    private function q(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}
