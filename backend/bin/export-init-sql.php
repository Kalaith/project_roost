<?php

declare(strict_types=1);

$databaseDir = realpath(__DIR__ . '/../database');
if ($databaseDir === false) {
    fwrite(STDERR, "Database directory not found.\n");
    exit(1);
}

$seedPath = $databaseDir . DIRECTORY_SEPARATOR . 'seeds' . DIRECTORY_SEPARATOR . '900_seed_project_roost_from_summaries.sql';
if (!is_file($seedPath)) {
    $seedExporter = __DIR__ . DIRECTORY_SEPARATOR . 'export-seed-sql.php';
    passthru('php ' . escapeshellarg($seedExporter), $exitCode);
    if ($exitCode !== 0 || !is_file($seedPath)) {
        fwrite(STDERR, "Could not generate seed SQL.\n");
        exit(1);
    }
}

$outputPath = $argv[1] ?? ($databaseDir . DIRECTORY_SEPARATOR . 'project_roost_init.sql');
$migrations = array_values(array_filter(
    glob($databaseDir . DIRECTORY_SEPARATOR . '*.sql') ?: [],
    fn (string $path): bool => preg_match('/^\d+_.*\.sql$/', basename($path)) === 1
));
sort($migrations);

$postSeedParts = array_values(array_filter(
    $migrations,
    fn (string $path): bool => str_contains(basename($path), '_clean_')
));

$parts = array_merge($migrations, [$seedPath], $postSeedParts);

$sql = [];
$sql[] = '-- Project Roost full SQL initializer.';
$sql[] = '-- Requires the shared `projects` table to already exist.';
$sql[] = '-- Safe to rerun: schema uses IF NOT EXISTS and seed data uses idempotent upserts.';
$sql[] = 'SET FOREIGN_KEY_CHECKS = 1;';
$sql[] = '';

foreach ($parts as $part) {
    if (!is_file($part)) {
        fwrite(STDERR, "Required SQL file missing: {$part}\n");
        exit(1);
    }

    $sql[] = '-- -----------------------------------------------------------------------------';
    $sql[] = '-- ' . basename($part);
    $sql[] = '-- -----------------------------------------------------------------------------';
    $sql[] = rtrim((string) file_get_contents($part));
    $sql[] = '';
}

file_put_contents($outputPath, implode(PHP_EOL, $sql));
echo "Wrote {$outputPath}\n";
