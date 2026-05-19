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
    fwrite(STDERR, "Autoloader not found. Run composer install before initializing the database.\n");
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

use App\Core\Database;
use App\Repositories\ProjectRepository;
use App\Services\SummaryImportService;
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

$migrations = array_values(array_filter(
    glob(__DIR__ . '/../database/*.sql') ?: [],
    fn (string $path): bool => preg_match('/^\d+_.*\.sql$/', basename($path)) === 1
));
sort($migrations);

if ($migrations === []) {
    fwrite(STDERR, "No migrations found.\n");
    exit(1);
}

$database = Database::connection();
foreach ($migrations as $migration) {
    $database->exec((string) file_get_contents($migration));
    echo 'Ran ' . basename($migration) . ".\n";
}

$repository = new ProjectRepository();
$imports = new SummaryImportService();

foreach (['apps', 'games', 'rust-games'] as $source) {
    $result = $repository->importBatch($imports->parse($source));
    echo sprintf(
        "Imported %d %s projects.\n",
        $result['project_count'],
        $source
    );
}

echo "Project Roost tables and seed data are ready.\n";
