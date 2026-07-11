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
    header('HTTP/1.1 500 Internal Server Error');
    echo "Autoloader not found. Run composer install before starting the backend.";
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

use App\Controllers\BugReportController;
use App\Controllers\DashboardController;
use App\Controllers\DeploymentController;
use App\Controllers\HealthController;
use App\Controllers\ImportController;
use App\Controllers\ProjectController;
use App\Core\Env;
use App\Core\Router;
use App\Core\Response;
use Dotenv\Dotenv;

try {
    $dotenv = Dotenv::createImmutable(__DIR__ . '/../');
    $dotenv->load();
} catch (\Throwable $exception) {
}

try {
    $allowedOrigin = Env::required('CORS_ORIGIN');
} catch (\Throwable $exception) {
    header('HTTP/1.1 500 Internal Server Error');
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => $exception->getMessage()]);
    exit(1);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    header('Access-Control-Allow-Origin: ' . $allowedOrigin);
    header('Access-Control-Allow-Headers: X-Requested-With, Content-Type, Accept, Origin, Authorization');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, PATCH, OPTIONS');
    header('Access-Control-Max-Age: 86400');
    exit(0);
}

header('Access-Control-Allow-Origin: ' . $allowedOrigin);

$router = new Router();
$router->setBasePath(Env::required('API_BASE_PATH'));

$router->get('/health', [HealthController::class, 'check']);
$router->get('/dashboard/summary', [DashboardController::class, 'summary']);
$router->get('/dashboard/fix-queue', [DashboardController::class, 'fixQueue']);
// Public, unauthenticated bug-report intake from game pages (guarded by BugReportGuard).
$router->get('/bug-reports/challenge', [BugReportController::class, 'challenge']);
$router->post('/bug-reports', [BugReportController::class, 'submit']);
// Admin-only moderation.
$router->get('/bug-reports', [BugReportController::class, 'index']);
$router->patch('/bug-reports/{id}', [BugReportController::class, 'moderate']);
$router->get('/deployments', [DeploymentController::class, 'index']);
$router->post('/deployments/publish', [DeploymentController::class, 'publish']);
$router->post('/import/html-summary', [ImportController::class, 'htmlSummary']);
$router->get('/projects', [ProjectController::class, 'index']);
$router->post('/projects', [ProjectController::class, 'store']);
$router->get('/projects/discover', [ProjectController::class, 'discover']);
$router->get('/projects/{id}', [ProjectController::class, 'show']);
$router->patch('/projects/{id}', [ProjectController::class, 'update']);
$router->delete('/projects/{id}', [ProjectController::class, 'destroy']);
$router->get('/projects/{id}/reviews', [ProjectController::class, 'reviews']);
$router->post('/projects/{id}/reviews', [ProjectController::class, 'addReview']);
$router->get('/projects/{id}/tasks', [ProjectController::class, 'tasks']);
$router->post('/projects/{id}/tasks', [ProjectController::class, 'addTask']);
$router->patch('/tasks/{id}', [ProjectController::class, 'updateTask']);

try {
    $router->handle();
} catch (\Throwable $exception) {
    (new Response())->error('Internal Server Error: ' . $exception->getMessage(), 500);
}
