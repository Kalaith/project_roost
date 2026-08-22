<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Env;

final class ProjectDiscoveryService
{
    private const PREVIEW_BASE_URL = 'http://127.0.0.1';
    private const PRODUCTION_BASE_URL = 'https://webhatchery.au';

    /**
     * @param array<int, array<string, mixed>> $existingProjects
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function discover(array $existingProjects, array $filters = []): array
    {
        $requestedSource = strtolower(trim((string)($filters['source'] ?? 'all')));
        $search = strtolower(trim((string)($filters['search'] ?? '')));
        $existingKeys = $this->existingKeys($existingProjects);

        $candidates = [];
        foreach ($this->sourceRoots() as $source => $root) {
            if (!$this->sourceAllowed($requestedSource, $source)) {
                continue;
            }

            foreach ($this->scanSource($source, $root) as $candidate) {
                if ($this->isKnown($candidate, $existingKeys)) {
                    continue;
                }

                if ($search !== '' && !$this->matchesSearch($candidate, $search)) {
                    continue;
                }

                $candidates[] = $candidate;
            }
        }

        usort($candidates, function (array $a, array $b): int {
            $sourceCompare = strcmp((string)$a['source'], (string)$b['source']);
            if ($sourceCompare !== 0) {
                return $sourceCompare;
            }

            return strcmp((string)$a['display_name'], (string)$b['display_name']);
        });

        return $candidates;
    }

    /**
     * @return array<string, string>
     */
    private function sourceRoots(): array
    {
        $roots = [];

        try {
            $roots['apps'] = dirname(Env::required('APPS_SUMMARY_PATH'));
        } catch (\Throwable) {
        }

        try {
            $roots['games'] = dirname(Env::required('GAME_APPS_SUMMARY_PATH'));
        } catch (\Throwable) {
        }

        try {
            $roots['rust-games'] = Env::required('RUST_GAMES_ROOT');
        } catch (\Throwable) {
        }

        return array_filter(
            $roots,
            static fn (string $root): bool => is_dir($root)
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function scanSource(string $source, string $root): array
    {
        $entries = scandir($root);
        if ($entries === false) {
            return [];
        }

        $candidates = [];
        foreach ($entries as $entry) {
            if ($this->shouldSkipEntry($entry)) {
                continue;
            }

            $path = rtrim($root, '\\/') . DIRECTORY_SEPARATOR . $entry;
            if (!is_dir($path)) {
                continue;
            }

            $candidate = match ($source) {
                'apps' => $this->appCandidate($entry, $path),
                'games' => $this->gameCandidate($entry, $path),
                'rust-games' => $this->rustGameCandidate($entry, $path),
                default => null,
            };

            if ($candidate !== null) {
                $candidates[] = $candidate;
            }
        }

        return $candidates;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function appCandidate(string $entry, string $path): ?array
    {
        $slug = $this->slug($entry);
        $package = $this->packageManifest($path);
        $category = $slug === 'app_template' || str_contains($slug, 'template') ? 'template' : 'app';
        $displayName = $this->projectName($path, $package['name'] ?? null, $slug);

        return $this->candidate([
            'source' => 'apps',
            'name' => $slug,
            'display_name' => $displayName,
            'slug' => $slug,
            'category' => $category,
            'stage' => $this->appStage($path),
            'shape' => is_dir($path . DIRECTORY_SEPARATOR . 'backend') ? 'frontend+backend' : 'frontend-only',
            'group_name' => $category === 'template' ? 'templates' : 'apps',
            'repo_path' => $path,
            'summary' => $this->summary($path, $package['description'] ?? null),
            'version' => (string)($package['version'] ?? '0.1.0'),
            'preview_path' => '/' . $slug . '/',
            'production_path' => '/' . $slug . '/',
            'confidence' => $this->confidence($path, ['package.json', 'frontend', 'backend', 'publish.ps1']),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function gameCandidate(string $entry, string $path): ?array
    {
        $slug = $this->slug($entry);
        $package = $this->packageManifest($path);
        $displayName = $this->projectName($path, $package['name'] ?? null, $slug);

        return $this->candidate([
            'source' => 'games',
            'name' => $slug,
            'display_name' => $displayName,
            'slug' => $slug,
            'category' => 'game',
            'stage' => 'Game',
            'shape' => is_dir($path . DIRECTORY_SEPARATOR . 'backend') ? 'frontend+backend' : 'frontend-only',
            'group_name' => 'games',
            'repo_path' => $path,
            'summary' => $this->summary($path, $package['description'] ?? null),
            'version' => (string)($package['version'] ?? '0.1.0'),
            'preview_path' => '/' . $slug . '/',
            'production_path' => '/' . $slug . '/',
            'confidence' => $this->confidence($path, ['package.json', 'frontend', 'backend', 'publish.ps1']),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function rustGameCandidate(string $entry, string $path): ?array
    {
        $cargoPath = $path . DIRECTORY_SEPARATOR . 'Cargo.toml';
        if (!is_file($cargoPath)) {
            return null;
        }

        $manifest = $this->cargoManifest((string)file_get_contents($cargoPath));
        if (($manifest['name'] ?? '') === 'macroquad-toolkit') {
            return null;
        }

        $directorySlug = $this->slug($entry);
        $displayName = $this->projectName($path, $manifest['name'] ?? null, $directorySlug) . ' (Rust)';

        return $this->candidate([
            'source' => 'rust-games',
            'name' => 'rust_' . $directorySlug,
            'display_name' => $displayName,
            'slug' => 'rust_' . $directorySlug,
            'category' => 'rust-game',
            'stage' => 'Rust',
            'shape' => $this->hasServerComponent($path) ? 'rust+webgl+server' : 'rust+webgl',
            'group_name' => 'rust_games',
            'repo_path' => $path,
            'summary' => $this->summary($path, $manifest['description'] ?? null),
            'version' => (string)($manifest['version'] ?? '0.1.0'),
            'preview_path' => '/games/' . $directorySlug . '/',
            'production_path' => '/games/' . $directorySlug . '/',
            'confidence' => $this->confidence($path, ['Cargo.toml', 'index.html', 'publish.ps1', 'README.md']),
        ]);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function candidate(array $data): array
    {
        $summary = trim((string)($data['summary'] ?? ''));
        if ($summary === '') {
            $summary = (string)$data['display_name'] . ' in the WebHatchery ' . (string)$data['source'] . ' workspace.';
        }

        return [
            'source' => $data['source'],
            'name' => $data['name'],
            'display_name' => $data['display_name'],
            'slug' => $data['slug'],
            'category' => $data['category'],
            'status' => 'Concept',
            'stage' => $data['stage'],
            'shape' => $data['shape'],
            'group_name' => $data['group_name'],
            'summary' => $summary,
            'repo_path' => $data['repo_path'],
            'preview_url' => self::PREVIEW_BASE_URL . rtrim((string)$data['preview_path'], '/'),
            'production_url' => self::PRODUCTION_BASE_URL . rtrim((string)$data['production_path'], '/'),
            'version' => $data['version'],
            'repository_type' => 'local',
            'repository_url' => null,
            'hidden' => false,
            'show_on_homepage' => true,
            'risk' => [
                'severity' => 'low',
            ],
            'confidence' => $data['confidence'],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $projects
     * @return array<string, bool>
     */
    private function existingKeys(array $projects): array
    {
        $keys = [];
        foreach ($projects as $project) {
            $slug = $this->slug((string)($project['slug'] ?? ''));
            $name = $this->slug((string)($project['name'] ?? ''));
            $displayName = $this->slug((string)($project['display_name'] ?? ''));
            $path = $this->normalizePath((string)($project['repo_path'] ?? ''));

            if ($slug !== '') {
                $keys['slug:' . $slug] = true;
                if (str_starts_with($slug, 'rust_')) {
                    $keys['slug:' . substr($slug, 5)] = true;
                }
            }
            if ($name !== '') {
                $keys['name:' . $name] = true;
            }
            if ($displayName !== '') {
                $keys['display_name:' . $displayName] = true;
            }
            if ($path !== '') {
                $keys['path:' . $path] = true;
            }
        }

        return $keys;
    }

    /**
     * @param array<string, mixed> $candidate
     * @param array<string, bool> $existingKeys
     */
    private function isKnown(array $candidate, array $existingKeys): bool
    {
        $slug = $this->slug((string)$candidate['slug']);
        $name = $this->slug((string)$candidate['name']);
        $displayName = $this->slug((string)$candidate['display_name']);
        $path = $this->normalizePath((string)$candidate['repo_path']);

        return isset($existingKeys['slug:' . $slug])
            || isset($existingKeys['name:' . $name])
            || isset($existingKeys['display_name:' . $displayName])
            || isset($existingKeys['path:' . $path]);
    }

    /**
     * @param array<string, mixed> $candidate
     */
    private function matchesSearch(array $candidate, string $search): bool
    {
        $haystack = strtolower(implode(' ', [
            $candidate['name'] ?? '',
            $candidate['display_name'] ?? '',
            $candidate['slug'] ?? '',
            $candidate['source'] ?? '',
            $candidate['category'] ?? '',
            $candidate['summary'] ?? '',
            $candidate['repo_path'] ?? '',
            $candidate['production_url'] ?? '',
        ]));

        return str_contains($haystack, $search);
    }

    private function sourceAllowed(string $requestedSource, string $source): bool
    {
        return $requestedSource === '' || $requestedSource === 'all' || $requestedSource === $source;
    }

    private function shouldSkipEntry(string $entry): bool
    {
        if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
            return true;
        }

        return in_array(strtolower($entry), [
            'archive',
            'build',
            'dist',
            'node_modules',
            'tools',
            'vendor',
        ], true);
    }

    /**
     * @return array<string, string>
     */
    private function packageManifest(string $path): array
    {
        foreach ([
            $path . DIRECTORY_SEPARATOR . 'package.json',
            $path . DIRECTORY_SEPARATOR . 'frontend' . DIRECTORY_SEPARATOR . 'package.json',
        ] as $packagePath) {
            if (!is_file($packagePath)) {
                continue;
            }

            $decoded = json_decode((string)file_get_contents($packagePath), true);
            if (is_array($decoded)) {
                return array_filter([
                    'name' => isset($decoded['name']) ? (string)$decoded['name'] : null,
                    'version' => isset($decoded['version']) ? (string)$decoded['version'] : null,
                    'description' => isset($decoded['description']) ? (string)$decoded['description'] : null,
                ]);
            }
        }

        return [];
    }

    /**
     * @return array<string, string>
     */
    private function cargoManifest(string $toml): array
    {
        $package = [];
        $inPackage = false;
        foreach (preg_split('/\R/', $toml) ?: [] as $line) {
            $line = trim((string)$line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (preg_match('/^\[([^\]]+)\]$/', $line, $section)) {
                $inPackage = $section[1] === 'package';
                continue;
            }

            if ($inPackage && preg_match('/^([A-Za-z0-9_-]+)\s*=\s*"([^"]*)"/', $line, $match)) {
                $package[(string)$match[1]] = (string)$match[2];
            }
        }

        return $package;
    }

    private function appStage(string $path): string
    {
        if (is_dir($path . DIRECTORY_SEPARATOR . 'backend') && is_dir($path . DIRECTORY_SEPARATOR . 'frontend')) {
            return 'Fullstack';
        }

        if (is_dir($path . DIRECTORY_SEPARATOR . 'frontend') || is_file($path . DIRECTORY_SEPARATOR . 'package.json')) {
            return 'React';
        }

        if (is_file($path . DIRECTORY_SEPARATOR . 'composer.json')) {
            return 'API';
        }

        return 'Static';
    }

    private function projectName(string $path, ?string $manifestName, string $slug): string
    {
        $readmeTitle = $this->readmeTitle($path);
        if ($readmeTitle !== null) {
            return $readmeTitle;
        }

        $name = trim((string)$manifestName);
        if ($name !== '') {
            return $this->titleFromSlug($name);
        }

        return $this->titleFromSlug($slug);
    }

    private function summary(string $path, ?string $manifestDescription): string
    {
        $description = trim((string)$manifestDescription);
        if ($description !== '') {
            return $description;
        }

        $readme = $this->readmeParagraph($path);

        return $readme ?? '';
    }

    private function readmeTitle(string $path): ?string
    {
        foreach ($this->readmePaths($path) as $readmePath) {
            $lines = file($readmePath, FILE_IGNORE_NEW_LINES);
            if ($lines === false) {
                continue;
            }

            foreach ($lines as $line) {
                $line = trim((string)$line);
                if (preg_match('/^#\s+(.+)$/', $line, $match)) {
                    return trim((string)$match[1]);
                }
            }
        }

        return null;
    }

    private function readmeParagraph(string $path): ?string
    {
        foreach ($this->readmePaths($path) as $readmePath) {
            $lines = file($readmePath, FILE_IGNORE_NEW_LINES);
            if ($lines === false) {
                continue;
            }

            $paragraph = [];
            foreach ($lines as $line) {
                $text = trim((string)$line);
                if ($text === '') {
                    if ($paragraph !== []) {
                        break;
                    }

                    continue;
                }

                if ($paragraph === [] && str_starts_with($text, '#')) {
                    continue;
                }

                $paragraph[] = $text;
            }

            if ($paragraph !== []) {
                return $this->truncate(implode(' ', $paragraph), 320);
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function readmePaths(string $path): array
    {
        return array_values(array_filter([
            $path . DIRECTORY_SEPARATOR . 'README.md',
            $path . DIRECTORY_SEPARATOR . 'frontend' . DIRECTORY_SEPARATOR . 'README.md',
        ], static fn (string $readmePath): bool => is_file($readmePath)));
    }

    /**
     * @param array<int, string> $signals
     */
    private function confidence(string $path, array $signals): int
    {
        $score = 45;
        foreach ($signals as $signal) {
            if (is_file($path . DIRECTORY_SEPARATOR . $signal) || is_dir($path . DIRECTORY_SEPARATOR . $signal)) {
                $score += 12;
            }
        }

        return min(95, $score);
    }

    private function hasServerComponent(string $path): bool
    {
        $entries = scandir($path);
        if ($entries === false) {
            return false;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            if (str_contains(strtolower($entry), 'server') && is_dir($path . DIRECTORY_SEPARATOR . $entry)) {
                return true;
            }
        }

        return false;
    }

    private function titleFromSlug(string $slug): string
    {
        $title = str_replace(['_', '-'], ' ', $slug);

        return ucwords(trim($title));
    }

    private function slug(string $value): string
    {
        $slug = strtolower(trim($value));
        $slug = preg_replace('/[^a-z0-9_]+/', '_', $slug) ?? '';
        $slug = trim($slug, '_');

        return $slug;
    }

    private function normalizePath(string $path): string
    {
        return strtolower(trim(str_replace('\\', '/', $path), '/'));
    }

    private function truncate(string $value, int $length): string
    {
        if (strlen($value) <= $length) {
            return $value;
        }

        return rtrim(substr($value, 0, $length - 3)) . '...';
    }
}
