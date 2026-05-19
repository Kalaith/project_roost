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
$parts = [
    $databaseDir . DIRECTORY_SEPARATOR . '001_create_project_roost_tables.sql',
    $databaseDir . DIRECTORY_SEPARATOR . '002_create_project_roost_deployments.sql',
    $databaseDir . DIRECTORY_SEPARATOR . '003_add_project_roost_display_name.sql',
    $seedPath,
];

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
