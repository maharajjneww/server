<?php

declare(strict_types=1);

date_default_timezone_set('Asia/Kolkata');

// PSR-4 Autoloader
spl_autoload_register(function ($class) {
    $prefix = 'App\\';
    $baseDir = __DIR__ . '/../app/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

\App\Core\Database::loadEnv();

use App\Core\Request;
use App\Core\Response;
use App\Core\Router;

// Enable CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

// Global Exception Handler (Section 41 — Never expose backend/DB errors)
set_exception_handler(function (\Throwable $e) {
    error_log("[API EXCEPTION] " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    Response::error('Server temporarily unavailable. Please try again later.', 500);
});

// Initialize Router & Dispatch
$router = new Router();
$routesRegister = require __DIR__ . '/../routes/api.php';
$routesRegister($router);

$request = new Request();
$router->dispatch($request);
