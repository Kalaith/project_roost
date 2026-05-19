<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Env;
use InvalidArgumentException;
use RuntimeException;

final class SummaryImportService
{
    private const SOURCE_NAMES = [
        'apps' => 'apps-summary',
        'games' => 'game-apps-summary',
        'rust-games' => 'rust-games-summary',
    ];

    public function parse(string $source, ?string $html = null): array
    {
        $source = strtolower(trim($source));
        if (!array_key_exists($source, self::SOURCE_NAMES)) {
            throw new InvalidArgumentException('Import source must be apps, games, or rust-games.');
        }

        if ($source === 'rust-games') {
            if ($html === null) {
                [$html, $reviewedAt] = $this->rustGamesPayloadFromRoot();
            } else {
                $reviewedAt = date('Y-m-d H:i:s');
            }

            $sourceName = self::SOURCE_NAMES[$source];
            $sourceHash = hash('sha256', $html);

            return [
                'source' => $sourceName,
                'source_hash' => $sourceHash,
                'reviewed_at' => $reviewedAt,
                'records' => $this->parseRustGamesReport($html, $sourceName, $sourceHash, $reviewedAt),
            ];
        }

        $path = $source === 'apps' ? $this->appsReportPath() : $this->gamesReportPath();
        if ($html === null) {
            if (!is_file($path)) {
                throw new RuntimeException('Summary report not found: ' . $path);
            }

            $html = (string) file_get_contents($path);
            $reviewedAt = date('Y-m-d H:i:s', (int) filemtime($path));
        } else {
            $reviewedAt = date('Y-m-d H:i:s');
        }

        $sourceName = self::SOURCE_NAMES[$source];
        $sourceHash = hash('sha256', $html);

        return [
            'source' => $sourceName,
            'source_hash' => $sourceHash,
            'reviewed_at' => $reviewedAt,
            'records' => $source === 'apps'
                ? $this->parseAppsReport($html, $sourceName, $sourceHash, $reviewedAt)
                : $this->parseGamesReport($html, $sourceName, $sourceHash, $reviewedAt),
        ];
    }

    private function parseAppsReport(string $html, string $source, string $sourceHash, string $reviewedAt): array
    {
        preg_match_all('/<tr\s+([^>]*)>(.*?)<\/tr>/is', $html, $rows, PREG_SET_ORDER);

        $records = [];
        foreach ($rows as $row) {
            $attributes = $row[1];
            if (!str_contains($attributes, 'data-backend=')) {
                continue;
            }

            $rowHtml = $row[2];
            $backendShape = $this->attribute($attributes, 'data-backend');
            $reportStatus = strtolower($this->attribute($attributes, 'data-status'));

            if (!preg_match('/class="app-name"[^>]*>\s*([^<]+)\s*(?:<span[^>]*>(.*?)<\/span>)?/is', $rowHtml, $nameMatch)) {
                continue;
            }

            $slug = $this->slug((string) $nameMatch[1]);
            $displayName = $this->displayNameForSlug($slug, trim((string) $nameMatch[1]));
            $shortDescription = $this->cleanText((string) ($nameMatch[2] ?? ''));

            preg_match_all('/<span\s+class="score[^"]*"[^>]*>(.*?)<\/span>/is', $rowHtml, $scoreMatches);
            $scores = array_map(fn (string $score): ?float => $this->score($score), $scoreMatches[1] ?? []);

            preg_match_all('/<td[^>]*class="notes"[^>]*>(.*?)<\/td>/is', $rowHtml, $noteMatches);
            $notes = $this->cleanText((string) ($noteMatches[1][0] ?? ''));
            $priorityFix = $this->cleanText((string) ($noteMatches[1][1] ?? ''));
            $summary = trim($shortDescription . ($notes !== '' ? '. ' . $notes : ''));

            $securityScore = $scores[2] ?? null;
            $severity = $this->severityFromScore($securityScore, $reportStatus);
            $category = $slug === 'app_template' ? 'template' : 'app';

            $records[] = [
                'project' => [
                    'title' => $slug,
                    'path' => 'H:\\WebHatchery\\apps\\' . $slug,
                    'description' => $summary,
                    'stage' => $this->stageFromStatus($reportStatus),
                    'status' => $this->projectStatusFromReport($reportStatus),
                    'version' => '0.1.0',
                    'group_name' => $category === 'template' ? 'templates' : 'apps',
                    'repository_type' => 'local',
                    'repository_url' => null,
                    'hidden' => 0,
                    'show_on_homepage' => 1,
                ],
                'profile' => [
                    'slug' => $slug,
                    'display_name' => $displayName,
                    'category' => $category,
                    'shape' => $this->shapeFromBackend($backendShape),
                    'summary' => $summary,
                    'preview_url' => 'http://127.0.0.1/' . $slug . '/',
                    'production_url' => 'https://webhatchery.au/' . $slug . '/',
                    'source' => $source,
                ],
                'review' => [
                    'reviewed_at' => $reviewedAt,
                    'source' => $source,
                    'source_hash' => $sourceHash,
                    'frontend_score' => $scores[0] ?? null,
                    'backend_score' => $scores[1] ?? null,
                    'security_score' => $securityScore,
                    'overall_score' => $scores[3] ?? null,
                    'notes' => $notes,
                    'priority_fix' => $priorityFix,
                ],
                'risk' => [
                    'severity' => $severity,
                    'auth_risk' => $this->containsAny($notes . ' ' . $priorityFix, ['local auth', 'local login', 'register', 'debug auth']) ? 'needs shared-login migration' : 'standard',
                    'data_risk' => 'standard',
                    'env_risk' => $this->containsAny($notes . ' ' . $priorityFix, ['fallback', 'env']) ? 'verify explicit env' : 'standard',
                    'ownership_risk' => $this->containsAny($notes . ' ' . $priorityFix, ['ownership', 'owner']) ? 'review ownership checks' : 'standard',
                    'notes' => $notes,
                ],
                'task' => $priorityFix !== '' ? [
                    'title' => 'Resolve ' . $displayName . ' priority fix',
                    'description' => $priorityFix,
                    'type' => $this->taskType($priorityFix),
                    'priority' => $severity === 'high' ? 'high' : 'medium',
                    'status' => 'todo',
                    'effort' => 'medium',
                    'impact' => $severity === 'low' ? 'medium' : 'high',
                    'source' => $source,
                    'source_hash' => $sourceHash,
                ] : null,
            ];
        }

        return $records;
    }

    private function parseGamesReport(string $html, string $source, string $sourceHash, string $reviewedAt): array
    {
        if (!preg_match('/<script\s+id="projectData"\s+type="application\/json">\s*(.*?)\s*<\/script>/is', $html, $match)) {
            throw new RuntimeException('Game summary projectData script was not found.');
        }

        $decoded = json_decode(html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5), true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Game summary projectData JSON could not be decoded.');
        }

        $records = [];
        foreach ($decoded as $item) {
            if (!is_array($item) || !isset($item['project'])) {
                continue;
            }

            $slug = $this->slug((string) $item['project']);
            $displayName = $this->titleFromSlug($slug);
            $summary = $this->cleanText((string) ($item['summary'] ?? ''));
            $riskCode = strtolower((string) ($item['risk'] ?? 'no'));
            $riskLabel = $this->cleanText((string) ($item['riskLabel'] ?? ''));
            $securityScore = isset($item['security']) ? (float) $item['security'] : null;
            $overallScore = isset($item['overall']) ? (float) $item['overall'] : null;
            $status = $this->statusFromOverall($overallScore, $riskCode);
            $severity = $this->severityFromGameRisk($riskCode, $securityScore);
            $task = $riskCode !== 'no' || ($securityScore !== null && $securityScore < 7.0);

            $records[] = [
                'project' => [
                    'title' => $slug,
                    'path' => 'H:\\WebHatchery\\game_apps\\' . $slug,
                    'description' => $summary,
                    'stage' => $status === 'MVP' ? 'mvp' : 'prototype',
                    'status' => $status,
                    'version' => '0.1.0',
                    'group_name' => 'game_apps',
                    'repository_type' => 'local',
                    'repository_url' => null,
                    'hidden' => 0,
                    'show_on_homepage' => 1,
                ],
                'profile' => [
                    'slug' => $slug,
                    'display_name' => $displayName,
                    'category' => 'game',
                    'shape' => strtolower((string) ($item['shape'] ?? 'unknown')),
                    'summary' => $summary,
                    'preview_url' => 'http://127.0.0.1/' . $slug . '/',
                    'production_url' => 'https://webhatchery.au/' . $slug . '/',
                    'source' => $source,
                ],
                'review' => [
                    'reviewed_at' => $reviewedAt,
                    'source' => $source,
                    'source_hash' => $sourceHash,
                    'frontend_score' => isset($item['frontend']) ? (float) $item['frontend'] : null,
                    'backend_score' => isset($item['backend']) ? (float) $item['backend'] : null,
                    'security_score' => $securityScore,
                    'overall_score' => $overallScore,
                    'notes' => $summary,
                    'priority_fix' => $task ? 'Review data authority and security posture for ' . $displayName . '.' : '',
                ],
                'risk' => [
                    'severity' => $severity,
                    'auth_risk' => 'standard',
                    'data_risk' => $riskLabel !== '' ? $riskLabel : $riskCode,
                    'env_risk' => 'standard',
                    'ownership_risk' => $riskCode === 'high' ? 'review shared-data boundaries' : 'standard',
                    'notes' => $summary,
                ],
                'task' => $task ? [
                    'title' => 'Review ' . $displayName . ' security posture',
                    'description' => $summary,
                    'type' => 'security',
                    'priority' => $severity === 'high' ? 'high' : 'medium',
                    'status' => 'todo',
                    'effort' => 'medium',
                    'impact' => 'high',
                    'source' => $source,
                    'source_hash' => $sourceHash,
                ] : null,
            ];
        }

        return $records;
    }

    private function parseRustGamesReport(string $payload, string $source, string $sourceHash, string $reviewedAt): array
    {
        $decoded = json_decode($payload, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('RustGames inventory JSON could not be decoded.');
        }

        $items = isset($decoded['projects']) && is_array($decoded['projects']) ? $decoded['projects'] : $decoded;
        $records = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $record = $this->rustGameRecord($item, $source, $sourceHash, $reviewedAt);
            if ($record !== null) {
                $records[] = $record;
            }
        }

        return $records;
    }

    private function rustGameRecord(array $item, string $source, string $sourceHash, string $reviewedAt): ?array
    {
        $directorySlug = $this->slug((string) ($item['slug'] ?? $item['directory'] ?? $item['package_name'] ?? ''));
        if ($directorySlug === 'untitled_project' || $directorySlug === 'macroquad_toolkit') {
            return null;
        }

        $slug = 'rust_' . $directorySlug;
        $displayName = $this->titleFromSlug($directorySlug) . ' (Rust)';
        $summary = $this->cleanText((string) ($item['description'] ?? ''));
        $readmeSummary = $this->cleanText((string) ($item['readme_summary'] ?? ''));
        if ($summary === '') {
            $summary = $readmeSummary;
        }

        if ($summary === '') {
            $summary = $displayName . ' is a Rust game in the WebHatchery RustGames workspace.';
        }

        $version = trim((string) ($item['version'] ?? '0.1.0'));
        if ($version === '') {
            $version = '0.1.0';
        }

        $hasIndex = (bool) ($item['has_index'] ?? false);
        $hasPublishScript = (bool) ($item['has_publish_script'] ?? false);
        $hasAssets = (bool) ($item['has_assets'] ?? false);
        $hasReadme = (bool) ($item['has_readme'] ?? false);
        $hasDescription = trim((string) ($item['description'] ?? '')) !== '' || $readmeSummary !== '';
        $hasServerComponent = (bool) ($item['has_server_component'] ?? false);
        $sourceFiles = max(0, (int) ($item['source_files'] ?? 0));

        $frontendScore = 6.8;
        $frontendScore += $hasIndex ? 0.4 : 0.0;
        $frontendScore += $hasPublishScript ? 0.5 : 0.0;
        $frontendScore += $hasAssets ? 0.3 : 0.0;
        $frontendScore += $hasReadme ? 0.4 : 0.0;
        $frontendScore += $hasDescription ? 0.3 : 0.0;
        $frontendScore += $sourceFiles >= 5 ? 0.3 : 0.0;
        $frontendScore += (bool) ($item['uses_macroquad'] ?? true) ? 0.2 : 0.0;
        $frontendScore = round(min(8.8, $frontendScore), 1);

        $backendScore = $hasServerComponent ? 6.5 : null;
        $securityScore = $hasServerComponent ? 7.2 : 8.0;
        if (!$hasPublishScript) {
            $securityScore -= 0.2;
        }
        $securityScore = round($securityScore, 1);

        $overallScore = round(($frontendScore * 0.65) + ($securityScore * 0.35), 1);
        if ($backendScore !== null) {
            $overallScore = round(($frontendScore * 0.5) + ($backendScore * 0.2) + ($securityScore * 0.3), 1);
        }

        $status = $overallScore >= 8.2 ? 'MVP' : 'Concept';
        $severity = $securityScore < 7.5 ? 'medium' : 'low';
        $signals = [
            'Rust/WebGL inventory',
            $hasIndex ? 'index present' : 'missing index',
            $hasPublishScript ? 'publish script present' : 'missing publish script',
            $hasReadme ? 'README present' : 'README missing',
        ];

        $notes = implode('; ', $signals) . '. ' . $summary;
        $priorityFix = '';
        if ($hasServerComponent) {
            $priorityFix = 'Review Rust server authority, persistence, and deployment boundaries for ' . $displayName . '.';
        } elseif ($overallScore < 8.0) {
            $priorityFix = 'Complete RustGames deployment metadata and review readiness for ' . $displayName . '.';
        }

        $path = trim((string) ($item['path'] ?? ''));
        if ($path === '') {
            $path = 'H:\\WebHatchery\\RustGames\\' . $directorySlug;
        }

        return [
            'project' => [
                'title' => $slug,
                'path' => $path,
                'description' => $summary,
                'stage' => $status === 'MVP' ? 'mvp' : 'prototype',
                'status' => $status,
                'version' => $version,
                'group_name' => 'rust_games',
                'repository_type' => 'local',
                'repository_url' => null,
                'hidden' => 0,
                'show_on_homepage' => 1,
            ],
            'profile' => [
                'slug' => $slug,
                'display_name' => $displayName,
                'category' => 'rust-game',
                'shape' => $hasServerComponent ? 'rust+webgl+server' : 'rust+webgl',
                'summary' => $summary,
                'preview_url' => 'http://127.0.0.1/' . $directorySlug . '/',
                'production_url' => 'https://webhatchery.au/games/' . $directorySlug . '/',
                'source' => $source,
            ],
            'review' => [
                'reviewed_at' => $reviewedAt,
                'source' => $source,
                'source_hash' => $sourceHash,
                'frontend_score' => $frontendScore,
                'backend_score' => $backendScore,
                'security_score' => $securityScore,
                'overall_score' => $overallScore,
                'notes' => $notes,
                'priority_fix' => $priorityFix,
            ],
            'risk' => [
                'severity' => $severity,
                'auth_risk' => 'not applicable to static Rust game',
                'data_risk' => $hasServerComponent ? 'review Rust server authority boundary' : 'client-local game state',
                'env_risk' => $hasServerComponent ? 'review Rust server environment configuration' : 'standard static deploy',
                'ownership_risk' => 'standard',
                'notes' => $notes,
            ],
            'task' => $priorityFix !== '' ? [
                'title' => 'Review ' . $displayName . ' RustGames readiness',
                'description' => $priorityFix,
                'type' => $hasServerComponent ? 'security' : 'deployment',
                'priority' => $severity === 'medium' ? 'medium' : 'low',
                'status' => 'todo',
                'effort' => 'medium',
                'impact' => $hasServerComponent ? 'high' : 'medium',
                'source' => $source,
                'source_hash' => $sourceHash,
            ] : null,
        ];
    }

    private function rustGamesPayloadFromRoot(): array
    {
        $root = Env::required('RUST_GAMES_ROOT');
        if (!is_dir($root)) {
            throw new RuntimeException('RustGames root not found: ' . $root);
        }

        $projects = [];
        $latestMtime = 0;
        $entries = scandir($root);
        if ($entries === false) {
            throw new RuntimeException('RustGames root could not be read: ' . $root);
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
                continue;
            }

            $path = rtrim($root, '\\/') . DIRECTORY_SEPARATOR . $entry;
            if (!is_dir($path)) {
                continue;
            }

            $cargoPath = $path . DIRECTORY_SEPARATOR . 'Cargo.toml';
            $indexPath = $path . DIRECTORY_SEPARATOR . 'index.html';
            if (!is_file($cargoPath) || !is_file($indexPath)) {
                continue;
            }

            $manifest = $this->parseCargoManifest((string) file_get_contents($cargoPath));
            if (($manifest['name'] ?? '') === 'macroquad-toolkit') {
                continue;
            }

            $readmePath = $path . DIRECTORY_SEPARATOR . 'README.md';
            $publishPath = $path . DIRECTORY_SEPARATOR . 'publish.ps1';
            $sourcePath = $path . DIRECTORY_SEPARATOR . 'src';
            $projects[] = [
                'slug' => $entry,
                'directory' => $entry,
                'path' => $path,
                'package_name' => $manifest['name'] ?? $entry,
                'version' => $manifest['version'] ?? '0.1.0',
                'description' => $manifest['description'] ?? '',
                'has_index' => true,
                'has_publish_script' => is_file($publishPath),
                'has_assets' => is_dir($path . DIRECTORY_SEPARATOR . 'assets'),
                'has_readme' => is_file($readmePath),
                'has_server_component' => $this->hasServerComponent($path),
                'uses_macroquad' => $this->containsAny((string) file_get_contents($cargoPath), ['macroquad']),
                'readme_summary' => is_file($readmePath) ? $this->readmeSummary($readmePath) : '',
                'source_files' => is_dir($sourcePath) ? $this->countRustSourceFiles($sourcePath) : 0,
            ];

            foreach ([$cargoPath, $indexPath, $publishPath, $readmePath] as $candidate) {
                if (is_file($candidate)) {
                    $latestMtime = max($latestMtime, (int) filemtime($candidate));
                }
            }
        }

        usort($projects, fn (array $a, array $b): int => strcmp((string) $a['slug'], (string) $b['slug']));

        $payload = json_encode(['projects' => $projects], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($payload)) {
            throw new RuntimeException('RustGames inventory could not be encoded.');
        }

        return [
            $payload,
            $latestMtime > 0 ? date('Y-m-d H:i:s', $latestMtime) : date('Y-m-d H:i:s'),
        ];
    }

    private function appsReportPath(): string
    {
        return Env::required('APPS_SUMMARY_PATH');
    }

    private function gamesReportPath(): string
    {
        return Env::required('GAME_APPS_SUMMARY_PATH');
    }

    private function parseCargoManifest(string $toml): array
    {
        $package = [];
        $inPackage = false;
        foreach (preg_split('/\R/', $toml) ?: [] as $line) {
            $line = trim((string) $line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (preg_match('/^\[([^\]]+)\]$/', $line, $section)) {
                $inPackage = $section[1] === 'package';
                continue;
            }

            if ($inPackage && preg_match('/^([A-Za-z0-9_-]+)\s*=\s*"([^"]*)"/', $line, $match)) {
                $package[(string) $match[1]] = (string) $match[2];
            }
        }

        return $package;
    }

    private function readmeSummary(string $path): string
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return '';
        }

        $paragraph = [];
        foreach ($lines as $line) {
            $text = trim((string) $line);
            if ($text === '') {
                if ($paragraph !== []) {
                    break;
                }

                continue;
            }

            if ($paragraph === [] && (str_starts_with($text, '#') || str_starts_with($text, '>'))) {
                continue;
            }

            $paragraph[] = $text;
        }

        return $this->truncate($this->cleanText(implode(' ', $paragraph)), 320);
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

    private function countRustSourceFiles(string $path): int
    {
        $count = 0;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && strtolower($file->getExtension()) === 'rs') {
                $count++;
            }
        }

        return $count;
    }

    private function truncate(string $value, int $length): string
    {
        if (strlen($value) <= $length) {
            return $value;
        }

        return rtrim(substr($value, 0, $length - 3)) . '...';
    }

    private function attribute(string $attributes, string $name): string
    {
        if (!preg_match('/' . preg_quote($name, '/') . '="([^"]*)"/i', $attributes, $match)) {
            return '';
        }

        return html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5);
    }

    private function cleanText(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5);
        $text = preg_replace('/\s+/', ' ', $text) ?? '';

        return trim($text);
    }

    private function score(string $value): ?float
    {
        $text = strtoupper($this->cleanText($value));
        if ($text === '' || $text === 'N/A') {
            return null;
        }

        return round((float) $text, 1);
    }

    private function slug(string $value): string
    {
        $slug = strtolower(trim($value));
        $slug = preg_replace('/[^a-z0-9_]+/', '_', $slug) ?? '';
        $slug = trim($slug, '_');

        return $slug !== '' ? $slug : 'untitled_project';
    }

    private function titleFromSlug(string $slug): string
    {
        return ucwords(str_replace('_', ' ', $slug));
    }

    private function displayNameForSlug(string $slug, string $fallback): string
    {
        $knownDisplayNames = [
            'adventcon' => 'Adventure Story Generator',
        ];

        $displayName = trim($knownDisplayNames[$slug] ?? '');
        if ($displayName !== '') {
            return $displayName;
        }

        $displayName = trim($fallback);
        if ($displayName === '' || $this->slug($displayName) === $slug) {
            return $this->titleFromSlug($slug);
        }

        return $displayName;
    }

    private function shapeFromBackend(string $backendShape): string
    {
        return match ($backendShape) {
            'full' => 'frontend+backend',
            'none' => 'frontend-only',
            'na' => 'template',
            default => 'unknown',
        };
    }

    private function stageFromStatus(string $status): string
    {
        return match ($status) {
            'strong' => 'mvp',
            'na' => 'internal',
            default => 'prototype',
        };
    }

    private function projectStatusFromReport(string $status): string
    {
        return match ($status) {
            'strong', 'mvp' => 'MVP',
            'complete', 'completed', 'fully-working', 'fully working', 'published', 'production' => 'Complete',
            default => 'Concept',
        };
    }

    private function statusFromOverall(?float $overall, string $riskCode): string
    {
        if ($riskCode === 'high') {
            return 'Concept';
        }

        if ($overall !== null && $overall >= 8.5) {
            return 'MVP';
        }

        return 'Concept';
    }

    private function severityFromScore(?float $score, string $status): string
    {
        if ($status === 'risk') {
            return 'high';
        }

        if ($score === null || $score < 7.0) {
            return 'medium';
        }

        return 'low';
    }

    private function severityFromGameRisk(string $riskCode, ?float $securityScore): string
    {
        if ($riskCode === 'high') {
            return 'high';
        }

        if ($riskCode === 'own' || ($securityScore !== null && $securityScore < 7.0)) {
            return 'medium';
        }

        return 'low';
    }

    private function taskType(string $text): string
    {
        $value = strtolower($text);
        if (str_contains($value, 'auth') || str_contains($value, 'login') || str_contains($value, 'security')) {
            return 'security';
        }

        if (str_contains($value, 'test')) {
            return 'tests';
        }

        if (str_contains($value, 'env') || str_contains($value, 'cors')) {
            return 'deployment';
        }

        return 'backend';
    }

    private function containsAny(string $haystack, array $needles): bool
    {
        $value = strtolower($haystack);
        foreach ($needles as $needle) {
            if (str_contains($value, strtolower((string) $needle))) {
                return true;
            }
        }

        return false;
    }
}
