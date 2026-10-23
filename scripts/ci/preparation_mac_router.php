<?php

// Test/lab router only: never registered by the production application.
if (getenv('CANOVIA_MAC_LAB_FIXTURE') !== '1' || getenv('DB_CONNECTION') !== 'sqlite'
    || getenv('APP_ENV') !== 'local' || ! in_array(getenv('APP_URL'), ['http://127.0.0.1:18803', 'http://127.0.0.1:18804'], true)
    || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1'
    || ($_SERVER['HTTP_HOST'] ?? '') !== parse_url(getenv('APP_URL'), PHP_URL_HOST).':'.parse_url(getenv('APP_URL'), PHP_URL_PORT)) {
    http_response_code(403);
    exit;
}
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/__preparation_lab_ready') {
    header('Cache-Control: no-store');
    echo getenv('CANOVIA_MAC_LAB_READY');
    exit;
}
// No browser provider requests or service-worker registrations in this lab.
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self' data:; connect-src 'self'; form-action 'self'; frame-src 'none'; worker-src 'none'");
$file = realpath(__DIR__.'/../../public/'.rawurldecode($path ?? ''));
$public = realpath(__DIR__.'/../../public');
if ($file && str_starts_with($file, $public.DIRECTORY_SEPARATOR) && is_file($file)
    && in_array(pathinfo($file, PATHINFO_EXTENSION), ['css', 'js', 'png', 'jpg', 'jpeg', 'webp', 'svg', 'ico', 'woff', 'woff2'], true)) {
    return false;
}
define('LARAVEL_START', microtime(true));
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->booted(fn () => \Illuminate\Support\Facades\Http::preventStrayRequests());
$app->handleRequest(\Illuminate\Http\Request::capture());
