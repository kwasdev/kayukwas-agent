<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Events\Dispatcher;
use Illuminate\Container\Container;
use Dotenv\Dotenv;

// Load environment variables if not loaded
if (file_exists(dirname(__DIR__) . '/.env') && empty($_ENV['DB_DATABASE'])) {
    $dotenv = Dotenv::createImmutable(dirname(__DIR__));
    $dotenv->load();
}

// Define base_path() for standalone Illuminate Database SQLiteConnector
if (!function_exists('base_path')) {
    function base_path($path = '') {
        return dirname(__DIR__) . ($path ? DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR) : '');
    }
}

$capsule = new Capsule;

$databasePath = $_ENV['DB_DATABASE'] ?? '/home/arrafi/work/kayukwas/database/database.sqlite';

$capsule->addConnection([
    'driver'    => 'sqlite',
    'database'  => $databasePath,
    'prefix'    => '',
    'foreign_key_constraints' => true,
]);

$capsule->setEventDispatcher(new Dispatcher(new Container));
$capsule->setAsGlobal();
$capsule->bootEloquent();

return $capsule;
