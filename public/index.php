<?php
require_once __DIR__ . '/../vendor/autoload.php';
// Secure session cookies and start session
$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on')
          || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => $secure,
    'httponly' => true,
    'samesite' => 'Lax',
]);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Basic security headers (safe defaults for current app)
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: no-referrer-when-downgrade');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob:; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; script-src 'self' 'unsafe-inline'");

// Resolve requested page
$page = isset($_GET['page']) ? preg_replace('/[^a-z0-9\-]/i','', $_GET['page']) : 'home';

// CSRF setup
require_once __DIR__ . '/../src/utils/csrf.php';
csrf_init();

// First, bootstrap database structures required for auth
require_once __DIR__ . '/../src/config/bootstrap.php';

// API routing (stateless, header auth)
$apiEnabled = filter_var(getenv('APP_API_ENABLED') !== false ? getenv('APP_API_ENABLED') : 'true', FILTER_VALIDATE_BOOLEAN);
if ($apiEnabled && substr($page, 0, 4) === 'api-' && $page !== 'api-keys') { // exclude UI page 'api-keys'
    require_once __DIR__ . '/../src/utils/api_auth.php';
    // Require API key (default scope: full)
    $apiKey = api_require_key(['full']);

    // Map API endpoints
    if ($page === 'api-clients-search') {
        require_once __DIR__ . '/../src/controllers/clients_search.php';
        exit;
    }

    // Unknown API endpoint
    header('Content-Type: application/json');
    http_response_code(404);
    echo json_encode(['error' => 'Not found']);
    exit;
}

// Handle logout early
if ($page === 'logout') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', $params['secure'] ?? false, $params['httponly'] ?? true);
    }
    // Clear remember-me cookie
    setcookie('remember', '', time() - 3600, '/', '', $secure, true);
    session_destroy();
    header('Location: /?page=login');
    exit;
}

// Allow unauthenticated access only to explicit public pages
$publicPages = ['login', 'serve-upload', 'reset-password', 'reset-verify', 'reset-new', 'reset-request', 'reset-update', 'public-doc', 'public-quote-action'];

// Toggle to disable auth checks in development/testing
$authDisabled = filter_var(getenv('AUTH_DISABLED') ?: getenv('APP_AUTH_DISABLED') ?: '', FILTER_VALIDATE_BOOLEAN);

// Allow POST to auth handler without prior login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $page === 'auth') {
    require_once __DIR__ . '/../src/controllers/auth_handler.php';
    exit;
}

// Attempt remember-me auto login before enforcing auth (temporarily disabled)
if (false && empty($_SESSION['user']) && isset($_COOKIE['remember'])) {
    require_once __DIR__ . '/../src/utils/crypto.php';
    require_once __DIR__ . '/../src/config/db.php';
    $raw = (string)$_COOKIE['remember'];
    $parts = explode('|', $raw);
    if (count($parts) === 3) {
        [$uidStr, $expStr, $hmacB64] = $parts;
        $uid = (int)$uidStr; $exp = (int)$expStr;
        $key = crypto_get_key();
        if ($uid > 0 && $exp > time() && $key !== '') {
            $data = $uid . '|' . $exp;
            $calc = base64_encode(hash_hmac('sha256', $data, $key, true));
            if (hash_equals($calc, $hmacB64)) {
                try {
                    $st = $pdo->prepare('SELECT id, email, role FROM users WHERE id=?');
                    $st->execute([$uid]);
                    $u = $st->fetch(PDO::FETCH_ASSOC);
                    if ($u) {
                        $_SESSION['user'] = ['id'=>(int)$u['id'], 'email'=>$u['email'], 'role'=>$u['role']];
                    }
                } catch (Throwable $e) { /* ignore */ }
            }
        }
    }
}

// Enforce authentication for everything else (unless disabled)
if (!$authDisabled && empty($_SESSION['user']) && !in_array($page, $publicPages, true)) {
    header('Location: /?page=login');
    exit;
}

// API/GET endpoints that should bypass layout (still require auth by default)
if ($page === 'clients-search') {
    require_once __DIR__ . '/../src/controllers/clients_search.php';
    exit;
}
if ($page === 'project-notes') {
    require_once __DIR__ . '/../src/controllers/project_notes.php';
    exit;
}
// If someone lands on email-test via GET (e.g., CSRF redirect), send them back to Settings -> System (email section)
if ($page === 'email-test' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $suffix = '';
    if (!empty($_GET['error'])) { $suffix = '&email_err=' . rawurlencode((string)$_GET['error']); }
    header('Location: /?page=settings&tab=system' . $suffix);
    exit;
}
if ($page === 'serveupload' || $page === 'serve-upload') {
    require_once __DIR__ . '/../src/controllers/serve_upload.php';
    exit;
}
if ($page === 'contract-pdf') {
    require_once __DIR__ . '/../src/controllers/contract_pdf.php';
    exit;
}
if ($page === 'quote-pdf') {
    require_once __DIR__ . '/../src/controllers/quote_pdf.php';
    exit;
}
if ($page === 'invoice-pdf') {
    require_once __DIR__ . '/../src/controllers/invoice_pdf.php';
    exit;
}

// Handle POST actions (PRG pattern)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Enforce CSRF on most POST endpoints, but allow controllers with their own CSRF/validation
    $skipCsrfFor = ['auth','reset-request','reset-verify','reset-update','public-quote-action'];
    if (!in_array($page, $skipCsrfFor, true)) { csrf_verify_post_or_redirect($page); }

    if ($page === 'settings') {
        require_once __DIR__ . '/../src/controllers/settings_handler.php';
        exit;
    }
    if ($page === 'reset-request') {
        require_once __DIR__ . '/../src/controllers/reset_request.php';
        exit;
    }
    if ($page === 'reset-verify') {
        require_once __DIR__ . '/../src/controllers/reset_verify.php';
        exit;
    }
    if ($page === 'reset-update') {
        require_once __DIR__ . '/../src/controllers/reset_update.php';
        exit;
    }
    if ($page === 'public-quote-action') {
        require_once __DIR__ . '/../src/controllers/public_quote_action.php';
        exit;
    }
    if ($page === 'api-keys-create') {
        require_once __DIR__ . '/../src/controllers/api_keys_create.php';
        exit;
    }
    if ($page === 'api-keys-revoke') {
        require_once __DIR__ . '/../src/controllers/api_keys_revoke.php';
        exit;
    }
    if ($page === 'clients-create') {
        require_once __DIR__ . '/../src/controllers/clients_create.php';
        exit;
    }
    if ($page === 'quotes-create') {
        require_once __DIR__ . '/../src/controllers/quotes_create.php';
        exit;
    }
    if ($page === 'quote-approve') {
        require_once __DIR__ . '/../src/controllers/quote_approve.php';
        exit;
    }
    if ($page === 'contract-sign') {
        require_once __DIR__ . '/../src/controllers/contract_sign.php';
        exit;
    }
    if ($page === 'contract-complete') {
        require_once __DIR__ . '/../src/controllers/contract_complete.php';
        exit;
    }
    if ($page === 'contract-void') {
        require_once __DIR__ . '/../src/controllers/contract_void.php';
        exit;
    }
    if ($page === 'contract-deny') { // legacy
        require_once __DIR__ . '/../src/controllers/contract_deny.php';
        exit;
    }
    if ($page === 'invoices-mark-paid') {
        require_once __DIR__ . '/../src/controllers/invoices_mark_paid.php';
        exit;
    }
    if ($page === 'payments-create') {
        require_once __DIR__ . '/../src/controllers/payments_create.php';
        exit;
    }
    if ($page === 'quotes-update') {
        require_once __DIR__ . '/../src/controllers/quotes_update.php';
        exit;
    }
    if ($page === 'clients-update') {
        require_once __DIR__ . '/../src/controllers/clients_update.php';
        exit;
    }
    if ($page === 'clients-delete') {
        require_once __DIR__ . '/../src/controllers/clients_delete.php';
        exit;
    }
    if ($page === 'clients-restore') {
        require_once __DIR__ . '/../src/controllers/clients_restore.php';
        exit;
    }
    if ($page === 'clients-purge') {
        require_once __DIR__ . '/../src/controllers/clients_purge.php';
        exit;
    }
    if ($page === 'contracts-create') {
        require_once __DIR__ . '/../src/controllers/contracts_create.php';
        exit;
    }
    if ($page === 'contracts-update') {
        require_once __DIR__ . '/../src/controllers/contracts_update.php';
        exit;
    }
    if ($page === 'invoices-create') {
        require_once __DIR__ . '/../src/controllers/invoices_create.php';
        exit;
    }
    if ($page === 'invoices-update') {
        require_once __DIR__ . '/../src/controllers/invoices_update.php';
        exit;
    }
    if ($page === 'quote-reject') {
        require_once __DIR__ . '/../src/controllers/quote_reject.php';
        exit;
    }
    if ($page === 'email-send') {
        require_once __DIR__ . '/../src/controllers/email_send.php';
        exit;
    }
    if ($page === 'email-test') {
        require_once __DIR__ . '/../src/controllers/email_test.php';
        exit;
    }
    if ($page === 'project-notes-update') {
        require_once __DIR__ . '/../src/controllers/project_notes_update.php';
        exit;
    }
    if ($page === 'account-update') {
        require_once __DIR__ . '/../src/controllers/account_update.php';
        exit;
    }
}

// Standalone login and reset pages use a minimal top header
if ($page === 'login') {
    require_once __DIR__ . '/../src/views/partials/auth_header.php';
    require_once __DIR__ . '/../src/views/pages/login.php';
    exit;
}
if ($page === 'reset-password') {
    require_once __DIR__ . '/../src/views/partials/auth_header.php';
    require_once __DIR__ . '/../src/views/pages/reset-password.php';
    exit;
}
if ($page === 'reset-verify' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    require_once __DIR__ . '/../src/views/partials/auth_header.php';
    require_once __DIR__ . '/../src/views/pages/reset-verify.php';
    exit;
}
if ($page === 'reset-new') {
    require_once __DIR__ . '/../src/views/partials/auth_header.php';
    require_once __DIR__ . '/../src/views/pages/reset-new.php';
    exit;
}
if ($page === 'public-doc') {
    require_once __DIR__ . '/../src/views/partials/auth_header.php';
    require_once __DIR__ . '/../src/controllers/public_doc.php';
    exit;
}

// Check if this is an AJAX request for client-side navigation
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if ($isAjax) {
    // For AJAX requests, return only the main content
    echo '<main class="main-content" role="main">';
    
    $view = __DIR__ . '/../src/views/pages/' . $page . '.php';
    if (!is_file($view)) {
        $view = __DIR__ . '/../src/views/pages/home.php';
    }
    if (basename($view) === 'calendar.php') {
        $view = __DIR__ . '/../src/views/pages/home.php';
    }
    require $view;
    
    echo '</main>';
} else {
    // Default layout for full page loads
    require_once __DIR__ . '/../src/views/partials/header.php';
    
    $view = __DIR__ . '/../src/views/pages/' . $page . '.php';
    if (!is_file($view)) {
        $view = __DIR__ . '/../src/views/pages/home.php';
    }
    if (basename($view) === 'calendar.php') {
        $view = __DIR__ . '/../src/views/pages/home.php';
    }
    require $view;
    
    require_once __DIR__ . '/../src/views/partials/footer.php';
}
