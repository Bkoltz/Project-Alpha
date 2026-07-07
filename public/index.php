<?php
require_once __DIR__ . '/../vendor/autoload.php';
// Secure session cookies and start session
$isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on')
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
    || (!empty($_SERVER['HTTP_CF_VISITOR']) && strpos((string)$_SERVER['HTTP_CF_VISITOR'], 'https') !== false)
    || (!empty($_SERVER['HTTP_X_SCHEME']) && strtolower((string)$_SERVER['HTTP_X_SCHEME']) === 'https');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => $isSecure,
    'httponly' => true,
    'samesite' => 'Lax',
]);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
ob_start();

// Security headers
require_once __DIR__ . '/../src/utils/security_headers.php';
send_security_headers();

// Resolve requested page (allow letters, numbers, dashes, and slashes)
// Be defensive: some clients may accidentally URL-encode the entire query
// into the `page` parameter (e.g. page=contract%2Fcontracts-edit%26id%3D3).
// Split on any stray '&' and recover additional params into $_GET so
// the router sees the intended `page` and other GET values like `id`.
$pageRaw = isset($_GET['page']) ? (string)$_GET['page'] : 'home';
$isAjaxEarly = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
    && strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'])) === 'xmlhttprequest';
if (strpos($pageRaw, '&') !== false) {
    [$pagePart, $rest] = explode('&', $pageRaw, 2);
    // Merge any parsed params into $_GET if they're not already present
    parse_str($rest, $parsedExtra);
    foreach ($parsedExtra as $k => $v) {
        if (!isset($_GET[$k])) {
            $_GET[$k] = $v;
        }
    }
    $page = preg_replace('#[^a-z0-9/\-]#i', '', $pagePart);
} else {
    $page = preg_replace('#[^a-z0-9/\-]#i', '', $pageRaw);
}

$pageAliases = [
    'public_doc' => 'public-doc',
    'public_redirect' => 'public-redirect',
];
$page = $pageAliases[$page] ?? $page;

// Helper: Resolve view path with case-insensitive subfolder checks
function resolve_view_path(string $page): string
{
    $base = __DIR__ . '/../src/views/pages/';
    $candidates = [];

    // Special case: accounts is in auth folder
    if ($page === 'accounts') {
        $candidates[] = $base . 'auth/accounts.php';
    }
    if ($page === 'account') {
        $candidates[] = $base . 'auth/account.php';
    }
    if ($page === 'account-edit') {
        $candidates[] = $base . 'auth/account-edit.php';
    }
    // GDPR/CCPA account pages are in account/ subdirectory
    if ($page === 'account-deleted') {
        $candidates[] = $base . 'account/account-deleted.php';
    }

    // As-provided
    $candidates[] = $base . $page . '.php';

    // Try ucfirst for first segment (e.g., jobs -> Jobs)
    $parts = explode('/', $page);
    if (count($parts) >= 2) {
        $parts_ucfirst = $parts;
        $parts_ucfirst[0] = ucfirst($parts_ucfirst[0]);
        $candidates[] = $base . implode('/', $parts_ucfirst) . '.php';

        // Try uppercasing all segments (maybe folders/filenames are TitleCase)
        $parts_uc = array_map(function ($p) {
            return ucfirst($p);
        }, $parts);
        $candidates[] = $base . implode('/', $parts_uc) . '.php';
    }

    // Try basename only
    $candidates[] = $base . basename($page) . '.php';

    foreach ($candidates as $c) {
        if (is_file($c)) {
            return $c;
        }
    }
    return $base . 'home.php';
}

// Error logging — NEVER display errors to end users in production
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Log to a file OUTSIDE the public web root
$errorLogDir = '/var/www/config/logs/system';
if (!is_dir($errorLogDir)) {
    $fallbackLogDir = __DIR__ . '/../config/logs/system';
    $errorLogDir = $fallbackLogDir;
}
if (!is_dir($errorLogDir)) { @mkdir($errorLogDir, 0750, true); }
ini_set('error_log', $errorLogDir . '/error_log.txt');

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

// CSRF setup
require_once __DIR__ . '/../src/utils/csrf.php';
csrf_init();

// First, bootstrap database structures required for auth
require_once __DIR__ . '/../src/config/bootstrap.php';

// CORS for API endpoints. Note: stateless API routes use the 'api-' prefix
// (e.g. api-clients-search); 'api-keys' is a UI page, not an API endpoint.
// Slash-prefixed 'settings/' routes are AJAX/JSON handlers.
$isApiEndpoint = (str_starts_with($page, 'api-') && !str_starts_with($page, 'api-keys'))
    || str_starts_with($page, 'settings/');
if ($isApiEndpoint) {
    $allowedOrigins = getenv('ALLOWED_ORIGINS') ? explode(',', getenv('ALLOWED_ORIGINS')) : [];
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '' && in_array($origin, $allowedOrigins, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key, X-CSRF-Token');
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

// API routing (stateless, header auth)
$apiEnabled = filter_var(getenv('APP_API_ENABLED') !== false ? getenv('APP_API_ENABLED') : 'true', FILTER_VALIDATE_BOOLEAN);
if ($apiEnabled && substr($page, 0, 4) === 'api-' && !str_starts_with($page, 'api-keys')) { // exclude UI page 'api-keys'
    require_once __DIR__ . '/../src/utils/api_auth.php';
    $apiEndpointScopes = api_scope_endpoint_map();
    $requiredApiScope = $apiEndpointScopes[$page] ?? null;
    if ($requiredApiScope === null) {
        header('Content-Type: application/json');
        http_response_code(404);
        echo json_encode(['error' => 'Unknown API endpoint']);
        exit;
    }
    $apiKey = api_require_key([$requiredApiScope]);

    // Map API endpoints
    $dashboardPages = ['api-dashboard-summary', 'api-financial-summary', 'api-invoices', 'api-quotes', 'api-projects', 'api-clients'];
    if (in_array($page, $dashboardPages, true)) {
        $map = [
            'api-dashboard-summary'   => __DIR__ . '/../src/controllers/api/dashboard_summary.php',
            'api-financial-summary'   => __DIR__ . '/../src/controllers/api/financial_summary.php',
            'api-invoices'              => __DIR__ . '/../src/controllers/api/invoices_list.php',
            'api-quotes'                => __DIR__ . '/../src/controllers/api/quotes_list.php',
            'api-projects'              => __DIR__ . '/../src/controllers/api/projects_list.php',
            'api-clients'               => __DIR__ . '/../src/controllers/api/clients_list.php',
        ];
        require_once $map[$page];
        exit;
    }

    if ($page === 'api-clients-search') {
        require_once __DIR__ . '/../src/controllers/client/clients_search.php';
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
    // Start session if not already started
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    
    // Audit the logout before clearing session
    if (!empty($_SESSION['user']['id'])) {
        try {
            require_once __DIR__ . '/../src/config/db.php';
            require_once __DIR__ . '/../src/utils/audit.php';
            audit_log($pdo, 'auth.logout', 'user', (int)$_SESSION['user']['id']);
        } catch (Throwable $e) { /* never block logout */ }
    }
    
    // Clear session data
    $_SESSION = [];
    
    // Delete session cookie
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', $params['secure'] ?? false, $params['httponly'] ?? true);
    }
    
    // Clear remember-me cookie
    setcookie('remember', '', [
        'expires' => time() - 3600,
        'path' => '/',
        'domain' => '',
        'secure' => $isSecure,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    
    // Destroy the session
    session_destroy();
    
    // Redirect to logout confirmation (which will start a fresh session)
    header('Location: /?page=logout-confirm');
    exit;
}

// Allow unauthenticated access only to explicit public pages
// NOTE: serve-upload enforces granular access itself (public images/logos only; PDFs & subdirs require auth)
$publicPages = ['login', 'session-status', 'serve-upload', 'reset-password', 'reset-verify', 'reset-new', 'reset-request', 'reset-update', 'public-doc', 'public-doc-pdf', 'public-redirect', 'payment-receipt', 'client-onboarding', 'client-onboarding-send-code', 'client-onboarding-verify', 'client-onboarding-submit', 'public-quote-action', 'public-contract-sign', 'stripe-checkout', 'stripe-success', 'stripe-webhook', 'stripe-webhook-legacy', 'legal/terms-of-service', 'legal/privacy-policy', 'legal/acceptable-use-policy', 'legal/dmca-policy', 'legal/data-retention-policy', 'account-deleted'];

// Toggle to disable auth checks in development/testing
$authDisabled = filter_var(getenv('AUTH_DISABLED') ?: getenv('APP_AUTH_DISABLED') ?: '', FILTER_VALIDATE_BOOLEAN);
$appEnv = strtolower(trim((string)(getenv('APP_ENV') ?: 'production')));
$authBypassAllowed = in_array($appEnv, ['development', 'dev', 'local', 'test', 'testing'], true);
if ($authDisabled && !$authBypassAllowed) {
    error_log('[security] AUTH_DISABLED ignored because APP_ENV is production or not explicitly development/test');
    $authDisabled = false;
}

// Allow POST to auth handler without prior login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $page === 'auth') {
    require_once __DIR__ . '/../src/controllers/auth/auth_handler.php';
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
        $uid = (int)$uidStr;
        $exp = (int)$expStr;
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
                        $_SESSION['user'] = ['id' => (int)$u['id'], 'email' => $u['email'], 'role' => $u['role']];
                    }
                } catch (Throwable $e) { /* ignore */
                }
            }
        }
    }
}

// Development/test auth bypass: create a real session for the first active admin
// so the application behaves normally without weakening production deployments.
if ($authDisabled && empty($_SESSION['user']) && !in_array($page, $publicPages, true)) {
    try {
        require_once __DIR__ . '/../src/config/db.php';
        $stmt = $pdo->query('
            SELECT id, email, role
            FROM users
            WHERE COALESCE(is_disabled, 0) = 0
            ORDER BY (role = "admin") DESC, id ASC
            LIMIT 1
        ');
        $bypassUser = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
        if ($bypassUser) {
            $activeOrgId = 0;
            try {
                $orgStmt = $pdo->prepare('SELECT organization_id FROM user_organizations WHERE user_id = ? ORDER BY id ASC LIMIT 1');
                $orgStmt->execute([(int)$bypassUser['id']]);
                $activeOrgId = (int)($orgStmt->fetchColumn() ?: 0);
            } catch (Throwable $e) {
                $activeOrgId = 0;
            }
            if ($activeOrgId <= 0) {
                try {
                    $activeOrgId = (int)($pdo->query('SELECT id FROM organizations ORDER BY id ASC LIMIT 1')->fetchColumn() ?: 0);
                } catch (Throwable $e) {
                    $activeOrgId = 0;
                }
            }
            session_regenerate_id(true);
            $_SESSION['user'] = [
                'id' => (int)$bypassUser['id'],
                'email' => (string)$bypassUser['email'],
                'role' => (string)$bypassUser['role'],
                'active_org_id' => $activeOrgId,
                'auth_bypass' => true,
            ];
            $_SESSION['last_activity'] = time();
            if (empty($_SESSION['auth_bypass_logged'])) {
                error_log('[security] Development auth bypass signed in user id ' . (int)$bypassUser['id']);
                $_SESSION['auth_bypass_logged'] = 1;
            }
        }
    } catch (Throwable $e) {
        error_log('[security] AUTH_DISABLED could not create a development session: ' . $e->getMessage());
    }
}

// Enforce authentication for everything else (unless disabled)
if (!$authDisabled && empty($_SESSION['user']) && !in_array($page, $publicPages, true)) {
    header('Location: /?page=login');
    exit;
}

// Session timeout: expire sessions after 8 hours of inactivity
if (!empty($_SESSION['user'])) {
    $sessionTimeout = 8 * 60 * 60; // 8 hours
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $sessionTimeout) {
        $_SESSION = [];
        session_destroy();
        if ($page === 'session-status') {
            require_once __DIR__ . '/../src/controllers/auth/session_status.php';
            exit;
        }
        header('Location: /?page=login&error=' . urlencode('Session expired. Please log in again.'));
        exit;
    }
    // Passive status polling must not keep an otherwise inactive session alive.
    if ($page !== 'session-status') {
        $_SESSION['last_activity'] = time();
    }
}

// Enforce force-password-reset: lock user to account page until they change it
if (!empty($_SESSION['user']) && !in_array($page, $publicPages, true)) {
    $allowedForForceReset = ['account', 'account-update', 'logout'];
    if (!in_array($page, $allowedForForceReset, true)) {
        try {
            require_once __DIR__ . '/../src/config/db.php';
            $fpStmt = $pdo->prepare('SELECT force_password_reset FROM users WHERE id = ?');
            $fpStmt->execute([(int)$_SESSION['user']['id']]);
            if ((int)$fpStmt->fetchColumn() === 1) {
                header('Location: /?page=account&force=1');
                exit;
            }
        } catch (Throwable $e) { /* allow through if check fails */ }
    }
}

// Evaluate 2FA policy for administrators and privileged operators. Enforcement is
// intentionally non-blocking: the layout renders a dismissible warning instead
// of redirecting users away from their work.
if (!empty($_SESSION['user']) && !in_array($page, $publicPages, true)) {
    try {
        require_once __DIR__ . '/../src/config/db.php';
        require_once __DIR__ . '/../src/utils/two_factor_policy.php';
        $_SESSION['two_factor_warning_required'] = two_factor_warning_needed($pdo, $page) ? 1 : 0;
    } catch (Throwable $e) {
        // Do not lock users out if the policy check cannot be evaluated during
        // installation/recovery. The production readiness check warns loudly
        // when schema/configuration is incomplete.
        error_log('[security] 2FA policy check failed: ' . $e->getMessage());
    }
}

// Global error/exception/shutdown handlers: route PHP errors to Monolog with error_log() fallback
require_once __DIR__ . '/../src/utils/logger.php';
$errLogger = app_logger('error');
set_error_handler(function ($severity, $message, $file, $line) use ($errLogger) {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    try {
        $errLogger->error('PHP error', [
            'severity' => $severity,
            'message'  => $message,
            'file'     => $file,
            'line'     => $line,
        ]);
    } catch (Throwable $e) {
        error_log(sprintf('[PHP error] severity=%d message=%s file=%s line=%d', $severity, $message, $file, $line));
    }
    return true;
});
set_exception_handler(function ($e) use ($errLogger) {
    try {
        $errLogger->error('Uncaught exception', [
            'exception' => get_class($e),
            'message'   => $e->getMessage(),
            'file'      => $e->getFile(),
            'line'      => $e->getLine(),
            'trace'     => $e->getTraceAsString(),
        ]);
    } catch (Throwable $e2) {
        error_log('[Uncaught exception] ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    }
});
register_shutdown_function(function () use ($errLogger) {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        try {
            $errLogger->error('Fatal shutdown', [
                'type'    => $e['type'],
                'message' => $e['message'],
                'file'    => $e['file'],
                'line'    => $e['line'],
            ]);
        } catch (Throwable $_e) {
            error_log(sprintf('[Fatal shutdown] type=%d message=%s file=%s line=%d', $e['type'], $e['message'], $e['file'], $e['line']));
        }
    }
});

// Audit middleware: guarantees baseline audit rows for sensitive actions
// (payments, 2FA changes, API keys, exports, deletes) even when the routed
// controller doesn't call audit_log() itself. See src/utils/audit_middleware.php.
try {
    require_once __DIR__ . '/../src/config/db.php';
    require_once __DIR__ . '/../src/utils/audit_middleware.php';
    audit_middleware($pdo, $page);
} catch (Throwable $e) {
    @error_log('[audit] middleware init failed: ' . $e->getMessage());
}

// ACL middleware — permission check after login, before controller dispatch
try {
    require_once __DIR__ . '/../src/utils/acl_middleware.php';
    if (!empty($_SESSION['user']) && $page === 'home') {
        $dashboardUserId = (int)($_SESSION['user']['id'] ?? 0);
        if ($dashboardUserId > 0 && ($_SESSION['user']['role'] ?? '') !== 'admin'
            && !user_can($pdo, $dashboardUserId, 'financial.view', get_active_org_id())) {
            $page = 'user-dashboard';
        }
    }
    acl_middleware($pdo, $page);
} catch (Throwable $e) {
    @error_log('[acl] middleware failed: ' . $e->getMessage());
}

if ($page === 'settings/dropbox-oauth') {
    require_once __DIR__ . '/../src/controllers/settings/dropbox_oauth.php';
    exit;
}

// API/GET endpoints that should bypass layout (still require auth by default)
if ($page === 'clients-search') {
    require_once __DIR__ . '/../src/controllers/client/clients_search.php';
    exit;
}
if ($page === 'projects-search-autocomplete') {
    require_once __DIR__ . '/../src/controllers/project/projects_search_autocomplete.php';
    exit;
}
if ($page === 'projects-search') {
    require_once __DIR__ . '/../src/controllers/project/projects_search.php';
    exit;
}
// Organization search for client creation (AJAX)
if ($page === 'org-search' || $page === 'organization/org-search') {
    require_once __DIR__ . '/../src/controllers/organization/org_search.php';
    exit;
}
if ($page === 'organization/organization-departments-options') {
    require_once __DIR__ . '/../src/controllers/organization/organization_departments_options.php';
    exit;
}
if ($page === 'project/client-options') {
    require_once __DIR__ . '/../src/controllers/project/project_client_options.php';
    exit;
}
if ($page === 'time-tracking/unbilled') {
    require_once __DIR__ . '/../src/controllers/time-tracking/time_entries_unbilled.php';
    exit;
}
if ($page === 'time-tracking/options') {
    require_once __DIR__ . '/../src/controllers/time-tracking/time_entry_options.php';
    exit;
}
if ($page === 'financial/financial-api') {
    require_once __DIR__ . '/../src/controllers/financial/financial_api.php';
    exit;
}
if ($page === 'settings/item-library-search') {
    require_once __DIR__ . '/../src/controllers/settings/item_library_search.php';
    exit;
}
if ($page === 'settings/logs') {
    require_once __DIR__ . '/../src/views/pages/settings/logs.php';
    exit;
}
if ($page === 'settings/logs-handler' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    require_once __DIR__ . '/../src/controllers/settings/logs_handler.php';
    exit;
}
if ($page === 'settings/permissions') {
    require_once __DIR__ . '/../src/views/pages/settings/permissions.php';
    exit;
}
if ($page === 'settings/permissions-handler') {
    require_once __DIR__ . '/../src/controllers/settings/permissions_handler.php';
    exit;
}
if ($page === 'custom-fields-ajax') {
    require_once __DIR__ . '/../src/controllers/api/custom_fields_ajax.php';
    exit;
}
// Document custom fields handler (GET for fetching field data)
if ($page === 'settings/document-custom-fields-handler' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    require_once __DIR__ . '/../src/controllers/settings/document-custom-fields-handler.php';
    exit;
}
if ($isAjaxEarly && $page === 'project-notes') {
    require_once __DIR__ . '/../src/controllers/project_notes.php';
    exit;
}
if ($isAjaxEarly && $page === 'project/projects-list') {
    require_once resolve_view_path($page);
    exit;
}
if ($isAjaxEarly && $page === 'project/projects-create') {
    require_once resolve_view_path($page);
    exit;
}
if ($isAjaxEarly && ($page === 'jobs/jobs-list' || $page === 'jobs-list')) {
    require_once resolve_view_path($page);
    exit;
}
if ($isAjaxEarly && $page === 'jobs/job-details') {
    require_once resolve_view_path($page);
    exit;
}
if ($isAjaxEarly) {
    if ($page === 'quote/quotes-edit' || $page === 'quotes-edit') {
        require_once __DIR__ . '/../src/views/pages/quote/quotes-edit.php';
        exit;
    }
    if ($page === 'contract/contracts-edit' || $page === 'contracts-edit') {
        require_once __DIR__ . '/../src/views/pages/contract/contracts-edit.php';
        exit;
    }
    if ($page === 'invoice/invoices-edit' || $page === 'invoices-edit') {
        require_once __DIR__ . '/../src/views/pages/invoice/invoices-edit.php';
        exit;
    }
}
// If someone lands on email-test via GET (e.g., CSRF redirect), send them back to Settings -> System (email section)
if ($page === 'email-test' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $suffix = '';
    if (!empty($_GET['error'])) {
        $suffix = '&email_err=' . rawurlencode((string)$_GET['error']);
    }
    header('Location: /?page=settings&tab=system' . $suffix);
    exit;
}
if ($page === 'serveupload' || $page === 'serve-upload') {
    require_once __DIR__ . '/../src/controllers/serve_upload.php';
    exit;
}
// Handle PDF generation routes - only -pdf pages, not -print pages
if (in_array($page, ['contract/contract-pdf', 'contract-pdf'])) {
    require_once __DIR__ . '/../src/controllers/contract/contract_pdf.php';
    exit;
}
if (in_array($page, ['quote/quote-pdf', 'quote-pdf'])) {
    require_once __DIR__ . '/../src/controllers/quote/quote_pdf.php';
    exit;
}
if (in_array($page, ['invoice/invoice-pdf', 'invoice-pdf'])) {
    require_once __DIR__ . '/../src/controllers/invoice/invoice_pdf.php';
    exit;
}
if ($page === 'project/project-invoice-pdf') {
    require_once __DIR__ . '/../src/controllers/project/project_invoice_pdf.php';
    exit;
}
if ($page === 'project/project-file-download') {
    require_once __DIR__ . '/../src/controllers/project/project_file_download.php';
    exit;
}
if (in_array($page, ['quote/long-term-quote-pdf', 'long-term-quote-pdf'])) {
    require_once __DIR__ . '/../src/controllers/quote/quote_pdf.php';
    exit;
}
if (in_array($page, ['contract/long-term-contract-pdf', 'long-term-contract-pdf'])) {
    require_once __DIR__ . '/../src/controllers/contract/contract_pdf.php';
    exit;
}
if ($page === 'settings/dropbox-oauth') {
    require_once __DIR__ . '/../src/controllers/settings/dropbox_oauth.php';
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Add a global per-IP rate-limit gate before routing. Endpoint-specific limits
    // (e.g. public links) may use tighter checks in their own controllers.
    require_once __DIR__ . '/../src/config/db.php';
    require_once __DIR__ . '/../src/utils/client_ip.php';

    // Global POST rate limiter: block only per-IP, not per-page, and skip for
    // legitimate authenticated actions that naturally chain POSTs.
    $skipGlobalRateLimitFor = [
        // Settings & system
        'settings',
        'settings-backup',
        'settings/backup-download',
        'settings/tax-rates-handler',
        'settings/tax-import-handler',
        'settings/links-handler',
        'settings/permissions-handler',
        'settings/custom-fields-handler',
        'settings/item-library-handler',
        'settings/document-customization-save',
        'settings/document-custom-fields-handler',
        'settings/link-test-connection',
        'settings/stripe-net-backfill',
        'settings/stripe-import-payments',
        'settings/dropbox-oauth',
        'settings/link-resolver-run',

        // User accounts / auth management
        'auth/account-edit',
        'account-update',
        'account-revoke-device',
        'account/delete',
        'accounts-create',
        'accounts-update',
        'accounts-delete',
        'accounts-reset-password',
        '2fa-setup-action',
        '2fa-verify-action',
        '2fa-admin-disable',
        '2fa-warning-dismiss',

        // API keys
        'api-keys-create',
        'api-keys-update',
        'api-keys-revoke',

        // Clients
        'client/client-create',
        'client/clients-create',
        'clients-create',
        'client/client-update',
        'client/clients-update',
        'clients-update',
        'client/clients-delete',
        'clients-delete',
        'client/clients-restore',
        'clients-restore',
        'client/clients-purge',
        'clients-purge',

        // Projects
        'project/project-create',
        'project/projects-create',
        'project/project-update',
        'project/projects-update',
        'project/projects-delete',
        'project/project-add-document',
        'project/project-remove-document',
        'project/project-files',
        'project/project-invoice-generate',
        'project/project-invoice-email',
        'project/project-invoice-payment',
        'project/projects-update-status',
        'project-notes-update',

        // Quotes
        'quote/quotes-create',
        'quotes-create',
        'quote/quotes-update',
        'quotes-update',
        'quote/quote-approve',
        'quote/quote-decline',
        'quote/quote-reject',
        'quote-reject',
        'quote/email-send',

        // Contracts
        'contract/contract-action',
        'contract/contract-create',
        'contract/contracts-create',
        'contracts-create',
        'contract/contracts-update',
        'contracts-update',
        'contract/contract-sign',
        'contract/contract-complete',
        'contract/contract-void',
        'contract/contract-deposit-received',
        'contract/contract-deny',
        'contract/email-send',
        'long-term-contracts-create',
        'contract/long-term-contracts-create',
        'long-term-contract-activate',
        'long-term-contract-pause',
        'long-term-contract-resume',
        'long-term-contract-terminate',
        'on-demand-contract-activate',
        'on-demand-contract-pause',
        'on-demand-contract-resume',
        'on-demand-contract-terminate',
        'on-demand-invoice-generate',

        // Invoices / payments
        'invoice/invoice-create',
        'invoice/invoice-action',
        'invoice/invoices-create',
        'invoices-create',
        'invoice/invoices-update',
        'invoices-update',
        'invoice/invoices-mark-paid',
        'invoice/invoice-finalize',
        'invoice/invoice-reopen',
        'invoice/email-send',
        'payments/payments-create',

        // Documents / forms / receipts
        'document-reenable',
        'document-date-update',
        'receipts-handler',
        'forms-handler',

        // Organizations
        'organization/org-create',
        'organization/organizations-create',
        'organization/organizations-update',
        'organization/organizations-delete',
        'organization/organization-add-client',
        'organization/organization-update-notes',
        'organization-update-notes',
        'organization/organization-remove-client',
        'organization/organization-departments',
        'organization/organizations_upload',
        'organization/organizations-upload',

        // Links / public links
        'public-link-create',
        'public-link-revoke',
        'links/link-management',
        'links/manual-link-handler',

        // Financial
        'financial/audit-export',
        'financial/audit-schedule-handler',
        'financial/mileage-handler',
        'financial/vendor-handler',
        'financial/category-handler',
        'financial/asset-handler',
        'financial/expense-handler',
        'financial/expense_handler',
        'financial/csv-import',

        // Time Tracking
        'time-tracking/create',
        'time-tracking/update',
        'time-tracking/delete',
        'time-tracking/start-timer',
        'time-tracking/stop-timer',

        // Email / legal / other
        'email-send',
        'email-test',
        'legal/tos-accept',
        'stripe-charge',
    ];
    if (!in_array($page, $skipGlobalRateLimitFor, true)) {
        $clientIp = get_client_ip();
        $globalPostKey = 'global_post_' . md5($clientIp);
        if (!rate_limit_check($pdo, $globalPostKey, 300, 60, false)) {
            error_log('Rate limit exceeded: global_post for IP ' . $clientIp . ' page=' . $page);
            http_response_code(429);
            header('Content-Type: text/plain');
            echo 'Too many requests. Please slow down.';
            exit;
        }
    }

    // Enforce CSRF on most POST endpoints, but allow controllers with their own CSRF/validation.
    // Reasons for bypasses:
    //   auth                         - controller validates CSRF (csrf_sf_is_valid 'auth')
    //   reset-request                - controller validates CSRF (csrf_sf_is_valid 'reset_request')
    //   reset-verify                 - controller validates CSRF (csrf_sf_is_valid 'reset_verify')
    //   reset-update                 - controller validates CSRF (csrf_sf_is_valid 'reset_update')
    //   public-quote-action          - controller validates CSRF (csrf_sf_is_valid 'public_quote_action')
    //   public-contract-sign         - controller validates CSRF (csrf_sf_is_valid 'public_contract_sign')
    //   public-contract-action       - controller validates CSRF (csrf_sf_is_valid 'public_contract_action')
    //   organization/org-create      - controller validates CSRF (legacy session hash_equals)
    //   organization/organization-update-notes - controller validates CSRF (csrf_validate)
    //   stripe-webhook               - tokenless: Stripe webhook uses signature verification (HMAC + replay protection)
    //   stripe-webhook-legacy        - tokenless: legacy Stripe webhook uses signature verification (HMAC + replay protection)
    //   settings/link-test-connection - controller validates CSRF (csrf_validate)
    //   settings/link-resolver-run    - controller validates CSRF (csrf_validate)
    //   legal/tos-accept             - controller validates CSRF (csrf_sf_verify_or_redirect 'auth')
    $skipCsrfFor = ['auth', 'reset-request', 'reset-verify', 'reset-update', 'public-quote-action', 'public-contract-sign', 'public-contract-action', 'organization/org-create', 'organization/organization-update-notes', 'time-tracking/create', 'time-tracking/update', 'time-tracking/delete', 'time-tracking/start-timer', 'time-tracking/stop-timer', 'stripe-webhook', 'stripe-webhook-legacy', 'settings/link-test-connection', 'settings/link-resolver-run', 'legal/tos-accept'];
    if (!in_array($page, $skipCsrfFor, true)) {
        csrf_verify_post_or_redirect($page);
    }

    if ($page === 'settings') {
        require_once __DIR__ . '/../src/controllers/settings_handler.php';
        exit;
    }
    if ($page === 'settings-backup') {
        require_once __DIR__ . '/../src/controllers/backup_handler.php';
        exit;
    }
    if ($page === 'settings/tax-rates-handler') {
        require_once __DIR__ . '/../src/controllers/settings/tax-rates-handler.php';
        exit;
    }
    if ($page === 'settings/tax-import-handler') {
        require_once __DIR__ . '/../src/controllers/settings/tax-import-handler.php';
        exit;
    }
    if ($page === 'settings/links-handler') {
        require_once __DIR__ . '/../src/controllers/settings/links_handler.php';
        exit;
    }
    if ($page === 'settings/permissions-handler') {
        require_once __DIR__ . '/../src/controllers/settings/permissions_handler.php';
        exit;
    }
    if ($page === 'settings/logs-handler') {
        require_once __DIR__ . '/../src/controllers/settings/logs_handler.php';
        exit;
    }
    if ($page === 'settings/stripe-net-backfill') {
        require_once __DIR__ . '/../src/controllers/settings/stripe_net_backfill.php';
        exit;
    }
    if ($page === 'settings/stripe-import-payments') {
        require_once __DIR__ . '/../src/controllers/settings/stripe_import_payments.php';
        exit;
    }
    if ($page === 'settings/custom-fields-handler') {
        require_once __DIR__ . '/../src/controllers/settings/custom_fields_handler.php';
        exit;
    }
    if ($page === 'accounts-create') {
        require_once __DIR__ . '/../src/controllers/accounts/accounts_create.php';
        exit;
    }
    if ($page === 'accounts-update') {
        require_once __DIR__ . '/../src/controllers/accounts/accounts_update.php';
        exit;
    }
    if ($page === 'accounts-delete') {
        require_once __DIR__ . '/../src/controllers/accounts/accounts_delete.php';
        exit;
    }
    if ($page === 'accounts-reset-password') {
        require_once __DIR__ . '/../src/controllers/accounts/accounts_reset_password.php';
        exit;
    }
    if ($page === '2fa-setup-action') {
        require_once __DIR__ . '/../src/controllers/auth/two_factor_setup.php';
        exit;
    }
    if ($page === '2fa-verify-action') {
        require_once __DIR__ . '/../src/controllers/auth/two_factor_verify.php';
        exit;
    }
    if ($page === '2fa-admin-disable') {
        require_once __DIR__ . '/../src/controllers/auth/admin_2fa_disable.php';
        exit;
    }
    if ($page === '2fa-warning-dismiss') {
        require_once __DIR__ . '/../src/controllers/auth/two_factor_warning_dismiss.php';
        exit;
    }
    if ($page === 'reset-request') {
        require_once __DIR__ . '/../src/controllers/auth/reset_request.php';
        exit;
    }
    if ($page === 'reset-verify') {
        require_once __DIR__ . '/../src/controllers/auth/reset_verify.php';
        exit;
    }
    if ($page === 'reset-update') {
        require_once __DIR__ . '/../src/controllers/auth/reset_update.php';
        exit;
    }
    if ($page === 'public-quote-action') {
        require_once __DIR__ . '/../src/controllers/public_view/public_quote_action.php';
        exit;
    }
    if ($page === 'public-contract-sign') {
        require_once __DIR__ . '/../src/controllers/public_view/public_contract_sign.php';
        exit;
    }
    if ($page === 'api-keys-create') {
        require_once __DIR__ . '/../src/controllers/api_keys_create.php';
        exit;
    }
    if ($page === 'api-keys-update') {
        require_once __DIR__ . '/../src/controllers/api_keys_update.php';
        exit;
    }
    if ($page === 'api-keys-revoke') {
        require_once __DIR__ . '/../src/controllers/api_keys_revoke.php';
        exit;
    }
    if ($page === 'client/clients-create' || $page === 'clients-create') {
        require_once __DIR__ . '/../src/controllers/client/clients_create.php';
        exit;
    }
    if ($page === 'client/onboarding-invite') {
        require_once __DIR__ . '/../src/controllers/client/client_onboarding_invite.php';
        exit;
    }
    if ($page === 'client/onboarding-review') {
        require_once __DIR__ . '/../src/controllers/client/client_onboarding_review.php';
        exit;
    }
    if ($page === 'client-onboarding-send-code') {
        require_once __DIR__ . '/../src/controllers/public_view/client_onboarding_send_code.php';
        exit;
    }
    if ($page === 'client-onboarding-verify') {
        require_once __DIR__ . '/../src/controllers/public_view/client_onboarding_verify.php';
        exit;
    }
    if ($page === 'client-onboarding-submit') {
        require_once __DIR__ . '/../src/controllers/public_view/client_onboarding_submit.php';
        exit;
    }
    if ($page === 'project/projects-create') {
        require_once __DIR__ . '/../src/controllers/project/projects_create.php';
        exit;
    }
    if ($page === 'project/projects-update') {
        require_once __DIR__ . '/../src/controllers/project/projects_update.php';
        exit;
    }
    if ($page === 'project/projects-delete') {
        require_once __DIR__ . '/../src/controllers/project/projects_delete.php';
        exit;
    }
    if ($page === 'project/project-add-document') {
        require_once __DIR__ . '/../src/controllers/project/project_add_document.php';
        exit;
    }
    if ($page === 'project/project-remove-document') {
        require_once __DIR__ . '/../src/controllers/project/project_remove_document.php';
        exit;
    }
    if ($page === 'project/project-files') {
        require_once __DIR__ . '/../src/controllers/project/project_files_handler.php';
        exit;
    }
    if ($page === 'settings/backup-download') {
        require_once __DIR__ . '/../src/controllers/settings/backup_download.php';
        exit;
    }
    if ($page === 'project/project-invoice-generate') {
        require_once __DIR__ . '/../src/controllers/project/project_invoice_generate.php';
        exit;
    }
    if ($page === 'project/project-invoice-email') {
        require_once __DIR__ . '/../src/controllers/project/project_invoice_email.php';
        exit;
    }
    if ($page === 'project/project-invoice-payment') {
        require_once __DIR__ . '/../src/controllers/project/project_invoice_payment.php';
        exit;
    }
    if ($page === 'project/projects-update-status') {
        require_once __DIR__ . '/../src/controllers/project/projects_update_status.php';
        exit;
    }
    if ($page === 'quote/quotes-create' || $page === 'quotes-create') {
        require_once __DIR__ . '/../src/controllers/quote/quotes_create.php';
        exit;
    }
    if ($page === 'quote/quote-approve') {
        require_once __DIR__ . '/../src/controllers/quote/quote_approve.php';
        exit;
    }
    if ($page === 'contract/contract-sign') {
        require_once __DIR__ . '/../src/controllers/contract/contract_sign.php';
        exit;
    }
    if ($page === 'contract/contract-complete') {
        require_once __DIR__ . '/../src/controllers/contract/contract_complete.php';
        exit;
    }
    if ($page === 'contract/contract-void') {
        require_once __DIR__ . '/../src/controllers/contract/contract_void.php';
        exit;
    }
    if ($page === 'contract/contract-deposit-received') {
        require_once __DIR__ . '/../src/controllers/contract/contract_deposit_received.php';
        exit;
    }
    if ($page === 'long-term-contract-activate') {
        require_once __DIR__ . '/../src/controllers/contract/long_term_contract_activate.php';
        exit;
    }
    if ($page === 'long-term-contract-start-billing') {
        require_once __DIR__ . '/../src/controllers/contract/long_term_contract_start_billing.php';
        exit;
    }
    if ($page === 'on-demand-contract-activate') {
        require_once __DIR__ . '/../src/controllers/contract/on_demand_contract_activate.php';
        exit;
    }
    if ($page === 'on-demand-invoice-generate') {
        require_once __DIR__ . '/../src/controllers/contract/on_demand_invoice_generate.php';
        exit;
    }
    if ($page === 'on-demand-contract-pause') {
        require_once __DIR__ . '/../src/controllers/contract/on_demand_contract_pause.php';
        exit;
    }
    if ($page === 'on-demand-contract-resume') {
        require_once __DIR__ . '/../src/controllers/contract/on_demand_contract_resume.php';
        exit;
    }
    if ($page === 'on-demand-contract-terminate') {
        require_once __DIR__ . '/../src/controllers/contract/on_demand_contract_terminate.php';
        exit;
    }
    if ($page === 'long-term-contract-pause') {
        require_once __DIR__ . '/../src/controllers/contract/long_term_contract_pause.php';
        exit;
    }
    if ($page === 'long-term-contract-resume') {
        require_once __DIR__ . '/../src/controllers/contract/long_term_contract_resume.php';
        exit;
    }
    if ($page === 'long-term-contract-terminate') {
        require_once __DIR__ . '/../src/controllers/contract/long_term_contract_terminate.php';
        exit;
    }
    if ($page === 'document-reenable') {
        require_once __DIR__ . '/../src/controllers/document_reenable_handler.php';
        exit;
    }
    if ($page === 'document-date-update') {
        require_once __DIR__ . '/../src/controllers/document_date_update_handler.php';
        exit;
    }
    if ($page === 'contract/contract-deny') { // legacy
        require_once __DIR__ . '/../src/controllers/contract/contract_deny.php';
        exit;
    }
    if ($page === 'invoice/invoices-mark-paid') {
        require_once __DIR__ . '/../src/controllers/invoice/invoices_mark_paid.php';
        exit;
    }
    if ($page === 'invoice/invoice-finalize') {
        require_once __DIR__ . '/../src/controllers/invoice/invoice_finalize.php';
        exit;
    }
    if ($page === 'invoice/invoice-reopen') {
        require_once __DIR__ . '/../src/controllers/invoice/invoice_reopen.php';
        exit;
    }
    if ($page === 'payments/payments-create') {
        require_once __DIR__ . '/../src/controllers/payments_create.php';
        exit;
    }
    if ($page === 'payments/payment-refund') {
        require_once __DIR__ . '/../src/controllers/payments_refund.php';
        exit;
    }
    if ($page === 'quote/quotes-update' || $page === 'quotes-update') {
        require_once __DIR__ . '/../src/controllers/quote/quotes_update.php';
        exit;
    }
    if ($page === 'client/clients-update' || $page === 'clients-update') {
        require_once __DIR__ . '/../src/controllers/client/clients_update.php';
        exit;
    }
    if ($page === 'client/clients-delete' || $page === 'clients-delete') {
        require_once __DIR__ . '/../src/controllers/client/clients_delete.php';
        exit;
    }
    if ($page === 'client/clients-restore' || $page === 'clients-restore') {
        require_once __DIR__ . '/../src/controllers/client/clients_restore.php';
        exit;
    }
    if ($page === 'client/clients-purge' || $page === 'clients-purge') {
        require_once __DIR__ . '/../src/controllers/client/clients_purge.php';
        exit;
    }
    if ($page === 'contract/contracts-create' || $page === 'contracts-create') {
        require_once __DIR__ . '/../src/controllers/contract/contracts_create.php';
        exit;
    }
    if ($page === 'long-term-contracts-create' || $page === 'contract/long-term-contracts-create') {
        require_once __DIR__ . '/../src/controllers/contract/long_term_contracts_create.php';
        exit;
    }
    if ($page === 'contract/contracts-update' || $page === 'contracts-update') {
        require_once __DIR__ . '/../src/controllers/contract/contracts_update.php';
        exit;
    }
    if ($page === 'invoice/invoices-create' || $page === 'invoices-create') {
        require_once __DIR__ . '/../src/controllers/invoice/invoices_create.php';
        exit;
    }
    if ($page === 'invoice/invoices-update' || $page === 'invoices-update') {
        require_once __DIR__ . '/../src/controllers/invoice/invoices_update.php';
        exit;
    }
    if ($page === 'quote/quote-reject' || $page === 'quote-reject') {
        require_once __DIR__ . '/../src/controllers/quote/quote_reject.php';
        exit;
    }
    if ($page === 'quote/email-send' || $page === 'contract/email-send' || $page === 'invoice/email-send' || $page === 'email-send') {
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
        require_once __DIR__ . '/../src/controllers/auth/account_update.php';
        exit;
    }
    if ($page === 'account-revoke-device') {
        require_once __DIR__ . '/../src/controllers/account_revoke_device.php';
        exit;
    }
    if ($page === 'account/delete') {
        require_once __DIR__ . '/../src/controllers/account/account_delete.php';
        exit;
    }
    if ($page === 'financial/audit-export') {
        require_once __DIR__ . '/../src/controllers/financial/audit_export.php';
        exit;
    }
    if ($page === 'financial/audit-schedule-handler') {
        require_once __DIR__ . '/../src/controllers/financial/audit_schedule_handler.php';
        exit;
    }
    if ($page === 'organization/org-create') {
        require_once __DIR__ . '/../src/controllers/organization/org_create.php';
        exit;
    }
    if ($page === 'organization/organizations-create') {
        require_once __DIR__ . '/../src/controllers/organization/organizations_create.php';
        exit;
    }
    if ($page === 'organization/organizations-update') {
        require_once __DIR__ . '/../src/controllers/organization/organizations_update.php';
        exit;
    }
    if ($page === 'organization/organizations-delete') {
        require_once __DIR__ . '/../src/controllers/organization/organizations_delete.php';
        exit;
    }
    if ($page === 'organization/organization-add-client') {
        require_once __DIR__ . '/../src/controllers/organization/organization_add_client.php';
        exit;
    }
    if ($page === 'organization/organization-update-notes' || $page === 'organization-update-notes') {
        require_once __DIR__ . '/../src/controllers/organization/organization-update-notes.php';
        exit;
    }
    if ($page === 'organization/organization-remove-client') {
        require_once __DIR__ . '/../src/controllers/organization/organization_remove_client.php';
        exit;
    }
    if ($page === 'organization/organization-departments') {
        require_once __DIR__ . '/../src/controllers/organization/organization_departments.php';
        exit;
    }
    if ($page === 'organization/organizations_upload' || $page === 'organization/organizations-upload') {
        require_once __DIR__ . '/../src/controllers/organization/organizations_upload.php';
        exit;
    }
    if ($page === 'settings/item-library-handler') {
        require_once __DIR__ . '/../src/controllers/settings/item_library_handler.php';
        exit;
    }
    if ($page === 'settings/document-customization-save') {
        require_once __DIR__ . '/../src/controllers/settings/document-customization-save.php';
        exit;
    }
    if ($page === 'settings/document-custom-fields-handler') {
        require_once __DIR__ . '/../src/controllers/settings/document-custom-fields-handler.php';
        exit;
    }
    if ($page === 'settings/link-test-connection') {
        require_once __DIR__ . '/../src/controllers/settings/link_test_connection.php';
        exit;
    }
    if ($page === 'settings/link-resolver-run') {
        require_once __DIR__ . '/../src/controllers/settings/link_resolver_run.php';
        exit;
    }
    if ($page === 'links/link-management') {
        require_once __DIR__ . '/../src/controllers/links/link_management.php';
        exit;
    }
    if ($page === 'links/manual-link-handler') {
        require_once __DIR__ . '/../src/controllers/links/manual_link_handler.php';
        exit;
    }
    if ($page === 'settings/dropbox-oauth') {
        require_once __DIR__ . '/../src/controllers/settings/dropbox_oauth.php';
        exit;
    }
    if ($page === '2fa-setup-action') {
        require_once __DIR__ . '/../src/controllers/auth/two_factor_setup.php';
        exit;
    }
    if ($page === '2fa-verify-action') {
        require_once __DIR__ . '/../src/controllers/auth/two_factor_verify.php';
        exit;
    }
    if ($page === '2fa-warning-dismiss') {
        require_once __DIR__ . '/../src/controllers/auth/two_factor_warning_dismiss.php';
        exit;
    }
    if ($page === 'receipts-handler') {
        require_once __DIR__ . '/../src/controllers/receipts_handler.php';
        exit;
    }
    if ($page === 'forms-handler') {
        require_once __DIR__ . '/../src/controllers/forms_handler.php';
        exit;
    }
    if ($page === 'financial/mileage-handler') {
        require_once __DIR__ . '/../src/controllers/financial/mileage_handler.php';
        exit;
    }
    if ($page === 'financial/vendor-handler') {
        require_once __DIR__ . '/../src/controllers/financial/vendor_handler.php';
        exit;
    }
    if ($page === 'financial/category-handler') {
        require_once __DIR__ . '/../src/controllers/financial/category_handler.php';
        exit;
    }
    if ($page === 'financial/asset-handler') {
        require_once __DIR__ . '/../src/controllers/financial/asset_handler.php';
        exit;
    }
    if ($page === 'financial/expense-handler' || $page === 'financial/expense_handler') {
        require_once __DIR__ . '/../src/controllers/financial/expense_handler.php';
        exit;
    }
    if ($page === 'financial/csv-import') {
        require_once __DIR__ . '/../src/controllers/financial/csv_import.php';
        exit;
    }
    if ($page === 'time-tracking/create') {
        require_once __DIR__ . '/../src/controllers/time-tracking/time_entry_create.php';
        exit;
    }
    if ($page === 'time-tracking/update') {
        require_once __DIR__ . '/../src/controllers/time-tracking/time_entry_update.php';
        exit;
    }
    if ($page === 'time-tracking/delete') {
        require_once __DIR__ . '/../src/controllers/time-tracking/time_entry_delete.php';
        exit;
    }
    if ($page === 'time-tracking/start-timer') {
        require_once __DIR__ . '/../src/controllers/time-tracking/time_entry_start_timer.php';
        exit;
    }
    if ($page === 'time-tracking/stop-timer') {
        require_once __DIR__ . '/../src/controllers/time-tracking/time_entry_stop_timer.php';
        exit;
    }
    if ($page === 'public-link-create') {
        require_once __DIR__ . '/../src/controllers/public_link_create.php';
        exit;
    }
    if ($page === 'public-link-revoke') {
        require_once __DIR__ . '/../src/controllers/public_link_revoke.php';
        exit;
    }
    if ($page === 'stripe-webhook') {
        // Route to new future-proof webhook handler
        require_once __DIR__ . '/../src/controllers/webhook/stripe_webhooks.php';
        exit;
    }
    // Legacy webhook endpoint (kept for backward compatibility)
    if ($page === 'stripe-webhook-legacy') {
        require_once __DIR__ . '/../src/controllers/webhook/stripe_webhooks.php';
        exit;
    }
}

if ($page === 'settings/backup-download') {
    require_once __DIR__ . '/../src/controllers/settings/backup_download.php';
    exit;
}

// Stripe charge endpoint (supports both GET and POST)
if ($page === 'stripe-charge') {
    require_once __DIR__ . '/../src/controllers/stripe/stripe_charge.php';
    exit;
}

if ($page === 'session-status') {
    require_once __DIR__ . '/../src/controllers/auth/session_status.php';
    exit;
}

// Standalone login and reset pages use a minimal top header
if ($page === 'login') {
    require_once __DIR__ . '/../src/views/partials/auth_header.php';
    require_once __DIR__ . '/../src/views/pages/auth/login.php';
    exit;
}
if ($page === 'reset-password') {
    require_once __DIR__ . '/../src/views/partials/auth_header.php';
    require_once __DIR__ . '/../src/views/pages/auth/reset-password.php';
    exit;
}
if ($page === 'reset-verify' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    require_once __DIR__ . '/../src/views/partials/auth_header.php';
    require_once __DIR__ . '/../src/views/pages/auth/reset-verify.php';
    exit;
}
if ($page === 'reset-new') {
    require_once __DIR__ . '/../src/views/partials/auth_header.php';
    require_once __DIR__ . '/../src/views/pages/auth/reset-new.php';
    exit;
}
if ($page === 'logout-confirm') {
    if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
    require_once __DIR__ . '/../src/views/partials/auth_header.php';
    require_once __DIR__ . '/../src/views/pages/auth/logout.php';
    exit;
}
if ($page === 'public-doc') {
    require_once __DIR__ . '/../src/views/partials/auth_header.php';
    require_once __DIR__ . '/../src/controllers/public_view/public_doc.php';
    exit;
}
if ($page === 'public-doc-pdf') {
    require_once __DIR__ . '/../src/controllers/public_view/public_doc_pdf.php';
    exit;
}
if ($page === 'payment-receipt') {
    require_once __DIR__ . '/../src/controllers/public_view/payment_receipt.php';
    exit;
}
if ($page === 'client-onboarding') {
    require_once __DIR__ . '/../src/views/partials/auth_header.php';
    require_once __DIR__ . '/../src/controllers/public_view/client_onboarding.php';
    exit;
}
if ($page === 'public-redirect') {
    require_once __DIR__ . '/../src/views/partials/auth_header.php';
    require_once __DIR__ . '/../src/controllers/public_view/public_redirect.php';
    exit;
}
if ($page === 'legal/tos-accept') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_once __DIR__ . '/../src/controllers/legal/tos_accept.php';
    } else {
        require_once __DIR__ . '/../src/views/partials/header.php';
        require_once __DIR__ . '/../src/views/pages/legal/tos-accept.php';
        require_once __DIR__ . '/../src/views/partials/footer.php';
    }
    exit;
}
if (str_starts_with($page, 'legal/')) {
    $legalView = resolve_view_path($page);
    if (is_file($legalView)) {
        require_once $legalView;
        exit;
    }
}
if ($page === 'stripe-checkout') {
    require_once __DIR__ . '/../src/controllers/stripe/stripe_checkout.php';
    exit;
}
if ($page === 'stripe-success') {
    require_once __DIR__ . '/../src/views/partials/auth_header.php';
    require_once __DIR__ . '/../src/controllers/stripe/stripe_success.php';
    exit;
}
if ($page === '2fa-verify') {
    require_once __DIR__ . '/../src/views/partials/auth_header.php';
    require_once __DIR__ . '/../src/views/pages/auth/two_factor_verify.php';
    exit;
}
if ($page === '2fa-setup') {
    require_once __DIR__ . '/../src/views/partials/header.php';
    require_once __DIR__ . '/../src/views/pages/auth/two_factor_setup.php';
    require_once __DIR__ . '/../src/views/partials/footer.php';
    exit;
}

// Check if this is an AJAX request
// OUTDATED $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

//Load header
require_once __DIR__ . '/../src/views/partials/header.php';

//Load page content
$view = resolve_view_path($page);
require $view;

//Load footer
require_once __DIR__ . '/../src/views/partials/footer.php';
