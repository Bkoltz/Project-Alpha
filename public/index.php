<?php

use App\config\Router;

define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . "/src/config/bootstrap.php";

$page = Router::RoutePage();

// Temporary debug logging: record incoming page parsing to server error log
// (remove or narrow this later once the issue is fixed)
// error_log('DEBUG incoming pageRaw=' . $pageRaw . ' parsed_page=' . $page . ' GET=' . json_encode($_GET));
// Whitelist of allowed pages
// $allowedPages = [
//     'home',
//     'projects-list',
//     'settings',
//     'financial/financial-dashboard',
//     'financial/audit',
// ];

// // If not in whitelist, force to home (or show error)
// if (!in_array($page, $allowedPages, true)) {
//     $page = 'home';
// }

// API routing (stateless, header auth)
$apiEnabled = filter_var(getenv('APP_API_ENABLED') !== false ? getenv('APP_API_ENABLED') : 'true', FILTER_VALIDATE_BOOLEAN);
if ($apiEnabled && substr($page, 0, 4) === 'api-' && $page !== 'api-keys') { // exclude UI page 'api-keys'
    require_once BASE_PATH . '/src/utils/api_auth.php';
    // Require API key (default scope: full)
    $apiKey = api_require_key(['full']);

    // Unknown API endpoint
    header('Content-Type: application/json');
    http_response_code(404);
    echo json_encode(['error' => 'Not found']);
    exit;
}

// Allow unauthenticated access only to explicit public pages
$publicPages = ['login', 'serve-upload', 'reset-password', 'reset-verify', 'reset-new', 'reset-request', 'reset-update', 'public-doc', 'public-quote-action'];

// Toggle to disable auth checks in development/testing
$authDisabled = filter_var(getenv('AUTH_DISABLED') ?: getenv('APP_AUTH_DISABLED') ?: '', FILTER_VALIDATE_BOOLEAN);

require_once BASE_PATH . '/src/config/account_logging.php';

// Enforce authentication for everything else (unless disabled)
if (!$authDisabled && empty($_SESSION['user']) && !in_array($page, $publicPages, true)) {
    header('Location: /?page=login');
    exit;
}

// if ($_SERVER['REQUEST_METHOD'] === 'POST') {
//     // Enforce CSRF on most POST endpoints, but allow controllers with their own CSRF/validation
//     $skipCsrfFor = ['auth', 'reset-request', 'reset-verify', 'reset-update', 'public-quote-action', 'organization/org-create'];
//     if (!in_array($page, $skipCsrfFor, true)) {
//         csrf_verify_post_or_redirect($page);
//     }
// }

// If someone lands on email-test via GET (e.g., CSRF redirect), send them back to Settings -> System (email section)
if ($page === 'email-test' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $suffix = '';
    if (!empty($_GET['error'])) {
        $suffix = '&email_err=' . rawurlencode((string)$_GET['error']);
    }
    header('Location: /?page=settings&tab=system' . $suffix);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset(Router::$get_routes[$page])) {
    require_once BASE_PATH . Router::$get_routes[$page];
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset(Router::$post_routes[$page])) {
    require_once BASE_PATH .  Router::$post_routes[$page];
    exit;
}

// Standalone login and reset pages use a minimal top header
if ($page === 'login') {
    require_once BASE_PATH . '/src/views/partials/auth_header.php';
    require_once BASE_PATH . '/src/views/pages/auth/login.php';
    exit;
}
if ($page === 'reset-password') {
    require_once BASE_PATH . '/src/views/partials/auth_header.php';
    require_once BASE_PATH . '/src/views/pages/auth/reset-password.php';
    exit;
}
if ($page === 'reset-verify' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    require_once BASE_PATH . '/src/views/partials/auth_header.php';
    require_once BASE_PATH . '/src/views/pages/auth/reset-verify.php';
    exit;
}
if ($page === 'reset-new') {
    require_once BASE_PATH . '/src/views/partials/auth_header.php';
    require_once BASE_PATH . '/src/views/pages/auth/reset-new.php';
    exit;
}
if ($page === 'public-doc') {
    require_once BASE_PATH . '/src/views/partials/auth_header.php';
    require_once BASE_PATH . '/src/controllers/public_view/public_doc.php';
    exit;
}

$twigFile = Router::resolveViewPathTwig($page);
$request = $_SERVER['REQUEST_METHOD'];

$bodyView = null;

if (isset(Router::$routes[$request][$page])) {
    $className = Router::$routes[$request][$page][0];
    $method = Router::$routes[$request][$page][1];

    $bodyView = $container->get($className)->$method();
} elseif (empty($twigFile)) {
    $bodyView = Router::resolveViewPath($page);
}

require_once BASE_PATH . '/src/views/partials/header.php';

if ($bodyView) {
    if (!empty($twigFile)) {
        echo $twig->render($bodyView[0], $bodyView[1]);
    } else {
        require $bodyView;
    }
}

require_once BASE_PATH . '/src/views/partials/footer.php';
