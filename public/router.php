<?php
/**
 * PHP 内置服务器专用路由脚本
 * 解决 PUT/PATCH/DELETE 请求体丢失问题 + PHP 8.3 兼容
 */

// Polyfill: request_parse_body() 是 PHP 8.4+ 的函数
// Symfony 7.x 对 PUT/PATCH/DELETE 会调用此函数，PHP 8.3 需要手动提供
if (!function_exists('request_parse_body')) {
    function request_parse_body(?array $options = null): array {
        return [$_POST, $_FILES];
    }
}

$uri = $_SERVER['REQUEST_URI'];
$path = parse_url($uri, PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

// 静态文件直接返回
$publicDir = __DIR__;
$filePath = $publicDir . $path;

if ($path !== '/' && is_file($filePath)) {
    return false;
}

// 将请求转发给 Laravel
require __DIR__ . '/index.php';