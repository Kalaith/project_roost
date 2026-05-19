<?php

declare(strict_types=1);

$autoloader = null;
$searchPaths = [
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../../../../vendor/autoload.php',
    __DIR__ . '/../../../vendor/autoload.php',
];

foreach ($searchPaths as $path) {
    if (file_exists($path)) {
        $autoloader = $path;
        break;
    }
}

if ($autoloader === null) {
    fwrite(STDERR, "Autoloader not found.\n");
    exit(1);
}

require_once $autoloader;

spl_autoload_register(function (string $class): void {
    if (strpos($class, 'App\\') !== 0) {
        return;
    }

    $path = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (file_exists($path)) {
        require_once $path;
    }
}, true, true);

use App\Services\SummaryImportService;
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

$outputPath = $argv[1] ?? (__DIR__ . '/../database/seeds/900_seed_project_roost_from_summaries.sql');
$imports = new SummaryImportService();
$batches = [
    $imports->parse('apps'),
    $imports->parse('games'),
    $imports->parse('rust-games'),
];

$sql = [];
$sql[] = '-- Generated Project Roost seed data from current summary reports.';
$sql[] = '-- Run after 001_create_project_roost_tables.sql and 002_create_project_roost_deployments.sql.';
$sql[] = 'START TRANSACTION;';

foreach ($batches as $batch) {
    foreach ($batch['records'] as $record) {
        $project = $record['project'];
        $profile = $record['profile'];
        $review = $record['review'];
        $risk = $record['risk'];
        $task = $record['task'] ?? null;

        $sql[] = '';
        $sql[] = '-- ' . $profile['slug'];
        $sql[] = sprintf(
            'INSERT INTO `projects` (`title`, `path`, `description`, `stage`, `status`, `version`, `group_name`, `repository_type`, `repository_url`, `hidden`, `show_on_homepage`, `created_at`, `updated_at`) SELECT %s, %s, %s, %s, %s, %s, %s, %s, %s, %d, %d, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `projects` WHERE `path` = %s OR `title` = %s);',
            q($project['title']),
            q($project['path']),
            q($project['description']),
            q($project['stage']),
            q($project['status']),
            q($project['version']),
            q($project['group_name']),
            q($project['repository_type']),
            q($project['repository_url']),
            (int) $project['hidden'],
            (int) $project['show_on_homepage'],
            q($project['path']),
            q($project['title'])
        );
        $sql[] = sprintf('SET @project_id := (SELECT `id` FROM `projects` WHERE `path` = %s OR `title` = %s ORDER BY `id` ASC LIMIT 1);', q($project['path']), q($project['title']));
        $sql[] = sprintf(
            'INSERT INTO `project_roost_profiles` (`project_id`, `slug`, `display_name`, `category`, `shape`, `summary`, `preview_url`, `production_url`, `source`, `created_at`, `updated_at`) VALUES (@project_id, %s, %s, %s, %s, %s, %s, %s, %s, NOW(), NOW()) ON DUPLICATE KEY UPDATE `display_name` = VALUES(`display_name`), `category` = VALUES(`category`), `shape` = VALUES(`shape`), `summary` = VALUES(`summary`), `preview_url` = VALUES(`preview_url`), `production_url` = VALUES(`production_url`), `source` = VALUES(`source`), `updated_at` = NOW();',
            q($profile['slug']),
            q($profile['display_name'] ?? $project['title']),
            q($profile['category']),
            q($profile['shape']),
            q($profile['summary']),
            q($profile['preview_url']),
            q($profile['production_url']),
            q($profile['source'])
        );
        $sql[] = sprintf(
            'INSERT INTO `project_roost_review_snapshots` (`project_id`, `reviewed_at`, `source`, `source_hash`, `frontend_score`, `backend_score`, `security_score`, `overall_score`, `notes`, `priority_fix`, `created_at`) VALUES (@project_id, %s, %s, %s, %s, %s, %s, %s, %s, %s, NOW()) ON DUPLICATE KEY UPDATE `reviewed_at` = VALUES(`reviewed_at`), `frontend_score` = VALUES(`frontend_score`), `backend_score` = VALUES(`backend_score`), `security_score` = VALUES(`security_score`), `overall_score` = VALUES(`overall_score`), `notes` = VALUES(`notes`), `priority_fix` = VALUES(`priority_fix`);',
            q($review['reviewed_at']),
            q($review['source']),
            q($review['source_hash']),
            n($review['frontend_score']),
            n($review['backend_score']),
            n($review['security_score']),
            n($review['overall_score']),
            q($review['notes']),
            q($review['priority_fix'])
        );
        $sql[] = sprintf(
            'INSERT INTO `project_roost_risk_assessments` (`project_id`, `severity`, `auth_risk`, `data_risk`, `env_risk`, `ownership_risk`, `notes`, `created_at`, `updated_at`) VALUES (@project_id, %s, %s, %s, %s, %s, %s, NOW(), NOW()) ON DUPLICATE KEY UPDATE `severity` = VALUES(`severity`), `auth_risk` = VALUES(`auth_risk`), `data_risk` = VALUES(`data_risk`), `env_risk` = VALUES(`env_risk`), `ownership_risk` = VALUES(`ownership_risk`), `notes` = VALUES(`notes`), `updated_at` = NOW();',
            q($risk['severity']),
            q($risk['auth_risk']),
            q($risk['data_risk']),
            q($risk['env_risk']),
            q($risk['ownership_risk']),
            q($risk['notes'])
        );

        if (is_array($task)) {
            $sql[] = sprintf(
                'INSERT INTO `project_roost_tasks` (`project_id`, `title`, `description`, `type`, `priority`, `status`, `effort`, `impact`, `source`, `source_hash`, `created_at`, `updated_at`) VALUES (@project_id, %s, %s, %s, %s, %s, %s, %s, %s, %s, NOW(), NOW()) ON DUPLICATE KEY UPDATE `title` = VALUES(`title`), `description` = VALUES(`description`), `type` = VALUES(`type`), `priority` = VALUES(`priority`), `status` = IF(`status` IN ("done", "ignored"), `status`, VALUES(`status`)), `effort` = VALUES(`effort`), `impact` = VALUES(`impact`), `updated_at` = NOW();',
                q($task['title']),
                q($task['description']),
                q($task['type']),
                q($task['priority']),
                q($task['status']),
                q($task['effort']),
                q($task['impact']),
                q($task['source']),
                q($task['source_hash'])
            );
        }
    }

    $taskCount = count(array_filter($batch['records'], fn (array $record): bool => is_array($record['task'] ?? null)));
    $sql[] = sprintf(
        'INSERT INTO `project_roost_imports` (`source`, `source_hash`, `imported_at`, `project_count`, `review_count`, `task_count`, `created_at`) VALUES (%s, %s, NOW(), %d, %d, %d, NOW()) ON DUPLICATE KEY UPDATE `imported_at` = VALUES(`imported_at`), `project_count` = VALUES(`project_count`), `review_count` = VALUES(`review_count`), `task_count` = VALUES(`task_count`);',
        q($batch['source']),
        q($batch['source_hash']),
        count($batch['records']),
        count($batch['records']),
        $taskCount
    );
}

$sql[] = 'COMMIT;';
$sql[] = '';

$outputDirectory = dirname($outputPath);
if (!is_dir($outputDirectory)) {
    mkdir($outputDirectory, 0775, true);
}

file_put_contents($outputPath, implode(PHP_EOL, $sql));
echo "Wrote {$outputPath}\n";

function q(mixed $value): string
{
    if ($value === null) {
        return 'NULL';
    }

    return "'" . str_replace(["\\", "'"], ["\\\\", "''"], (string) $value) . "'";
}

function n(mixed $value): string
{
    if ($value === null || $value === '') {
        return 'NULL';
    }

    return (string) round((float) $value, 1);
}
