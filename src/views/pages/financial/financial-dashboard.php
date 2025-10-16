<?php
require_once __DIR__ . '/../../../../vendor/autoload.php';

// Load settings.json
$settingsPath = __DIR__ . '/../../../../config/settings.json';
$settings = json_decode(file_get_contents($settingsPath), true);

// Fallback to 6 if not set
$months_to_show = $settings['dashboard']['months_to_show'] ?? 6;

$loader = new \Twig\Loader\FilesystemLoader(__DIR__ . '/templates');
$twig = new \Twig\Environment($loader);

echo $twig->render('dashboard.html.twig', [
    'api_url' => "http://localhost:5000/api/income-data",
    'months_to_show' => $months_to_show
]);