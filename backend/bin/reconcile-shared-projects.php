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
    fwrite(STDERR, "Autoloader not found. Run composer install before reconciling shared projects.\n");
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

use App\Services\SharedProjectReconciliationService;
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

$result = (new SharedProjectReconciliationService())->reconcile();

foreach ($result as $key => $value) {
    echo $key . '=' . $value . PHP_EOL;
}
