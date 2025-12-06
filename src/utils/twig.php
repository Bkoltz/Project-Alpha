<?php
// src/utils/twig.php
// Initialize and configure Twig template engine

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

function get_twig(): Environment {
    static $twig = null;
    
    if ($twig === null) {
        $loader = new FilesystemLoader(__DIR__ . '/../views/templates');
        $twig = new Environment($loader, [
            'cache' => false, // Set to __DIR__ . '/../../var/cache/twig' in production
            'debug' => true,
            'auto_reload' => true,
        ]);
        
        // Add global app config
        require_once __DIR__ . '/../config/app.php';
        $twig->addGlobal('app', $appConfig ?? []);
        
        // Add session data
        if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['user'])) {
            $twig->addGlobal('user', $_SESSION['user']);
        }
        
        // Add custom filters
        $twig->addFilter(new TwigFilter('money', function ($value) {
            return '$' . number_format((float)$value, 2);
        }));
        
        $twig->addFilter(new TwigFilter('date_format', function ($value, $format = 'M j, Y') {
            if (empty($value)) return '';
            return date($format, strtotime($value));
        }));
        
        // Add custom functions
        $twig->addFunction(new TwigFunction('csrf_token', function () {
            require_once __DIR__ . '/../utils/csrf.php';
            return csrf_token();
        }));
        
        $twig->addFunction(new TwigFunction('url', function ($page, $params = []) {
            $query = http_build_query(array_merge(['page' => $page], $params));
            return '/?' . $query;
        }));
    }
    
    return $twig;
}

/**
 * Render a Twig template
 */
function render_template(string $template, array $context = []): string {
    return get_twig()->render($template, $context);
}

/**
 * Render and echo a Twig template
 */
function display_template(string $template, array $context = []): void {
    echo render_template($template, $context);
}
