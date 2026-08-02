<?php
/**
 * Local development server router.
 *   Run from the project root:  php -S localhost:8000 server.php
 * In production, point Apache/Nginx's document root at /public instead.
 */
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = __DIR__ . '/public' . $path;
if ($path !== '/' && is_file($file)) {
    $mime = ['css'=>'text/css','js'=>'application/javascript','png'=>'image/png',
             'svg'=>'image/svg+xml','ico'=>'image/x-icon','woff2'=>'font/woff2','json'=>'application/json'];
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    if (isset($mime[$ext])) header('Content-Type: ' . $mime[$ext]);
    readfile($file);
    return true;
}
require __DIR__ . '/public/index.php';
