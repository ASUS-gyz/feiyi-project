<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Polyfill: request_parse_body() 是 PHP 8.4+ 的函数
// Symfony 7.x 对 PUT/PATCH/DELETE 会调用此函数，PHP 8.3 需要手动提供
if (!function_exists('request_parse_body')) {
    function request_parse_body(?array $options = null): array {
        return [$_POST, $_FILES];
    }
}

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
