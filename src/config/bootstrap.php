<?php
// src/config/bootstrap.php

/*  
    Bootstrap is responsible for loading all core function of the server and all previous database rendering logic has been delegated to db.php
*/

use Twig\Environment;
use App\config\Container;
use App\config\Router;
use App\Config\Renderer;

require_once BASE_PATH . '/vendor/autoload.php';
require_once __DIR__ . '/db.php';

// Secure session cookies and start session
$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => $secure,
    'httponly' => true,
    'samesite' => 'Lax',
]);

//Load twig
const TWIG_PATH = BASE_PATH . '/src/views';
$loader = new \Twig\Loader\FilesystemLoader(TWIG_PATH);
$twig = new \Twig\Environment($loader, [
    'cache' => false,
    'auto_reload' => true
]);

$twig->addFilter(new \Twig\TwigFilter('json_decode', function (string $json) {
    return json_decode($json, true) ?? [];
}));

$container = new Container();

$container->registerSingleton(Router::class); 
$container->registerSingleton(Renderer::class);
$container->registerSingleton(Environment::class, $twig);
$container->registerSingleton(PDO::class, $pdo);


// Basic security headers (safe defaults for current app)
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: no-referrer-when-downgrade');
header("Content-Security-Policy: script-src 'self' https://cdn.jsdelivr.net 'unsafe-inline'; default-src 'self'; img-src 'self' data: blob:; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com;");

// CSRF setup
require_once __DIR__ . '/../../src/utils/csrf.php';
csrf_init();

// Error logging into error log file stored in /var/log/error_log.txt
error_reporting(E_ALL);
ini_set("display_errors", 1);

function error_handler($errorno, $errorstr, $errorfile, $errorline)
{
    $errorMessage = "Error[$errorno]: $errorstr ($errorfile:$errorline)";
    error_log($errorMessage . PHP_EOL, 3,  "/var/www/html/error_log.txt");
    return true;
}

set_error_handler("error_handler");

//Start session
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
