<?php

use App\Middlewares\CorsMiddleware;
use App\Middlewares\JsonBodyParserMiddleware;
use Dotenv\Dotenv;
use Slim\Factory\AppFactory;

require __DIR__ . '/../vendor/autoload.php';

// Load environment variables
if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();
}

// Initialize database
require __DIR__ . '/../config/database.php';

// Create Slim App
$app = AppFactory::create();

// Add essential middlewares
$app->add(new JsonBodyParserMiddleware());
$app->add(new CorsMiddleware());
$app->addRoutingMiddleware();

// Error middleware
$errorMiddleware = $app->addErrorMiddleware(
    filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN),
    true,
    true
);

// Register routes
$routes = require __DIR__ . '/../src/Routes/api.php';
$routes($app);

// Run application
$app->run();
