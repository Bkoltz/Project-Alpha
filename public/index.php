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
header("Content-Security-Policy: script-src 'self' https://cdn.jsdelivr.net 'unsafe-inline'; default-src 'self'; img-src 'self' data: blob:; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com;");

// Resolve requested page (allow letters, numbers, dashes, and slashes)
// Be defensive: some clients may accidentally URL-encode the entire query
// into the `page` parameter (e.g. page=contract%2Fcontracts-edit%26id%3D3).
// Split on any stray '&' and recover additional params into $_GET so
// the router sees the intended `page` and other GET values like `id`.
$pageRaw = isset($_GET['page']) ? (string)$_GET['page'] : 'home';
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

    // Determine if request is AJAX early so we can selectively return minimal view content
    $isAjaxEarly = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'])) === 'xmlhttprequest';
}

// Helper: Resolve view path with case-insensitive subfolder checks
function resolve_view_path(string $page): string
{
    $base = __DIR__ . '/../src/views/pages/';
    $candidates = [];

    // Special case: accounts is in auth folder
    if ($page === 'accounts') {
        $candidates[] = $base . 'auth/accounts.php';
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

// Error logging into error log file stored in /var/log/error_log.txt
error_reporting(E_ALL);
ini_set("display_errors", 1);

function error_handler($errorno, $errorstr, $errorfile, $errorline)
{
    $errorMessage = "Error[$errorno]: $errorstr ($errorfile:$errorline)";
    error_log($errorMessage . PHP_EOL, 3,  __DIR__ . "/error_log.txt");
    return true;
}

set_error_handler("error_handler");

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

// API routing (stateless, header auth)
$apiEnabled = filter_var(getenv('APP_API_ENABLED') !== false ? getenv('APP_API_ENABLED') : 'true', FILTER_VALIDATE_BOOLEAN);
if ($apiEnabled && substr($page, 0, 4) === 'api-' && $page !== 'api-keys') { // exclude UI page 'api-keys'
    require_once __DIR__ . '/../src/utils/api_auth.php';
    // Require API key (default scope: full)
    $apiKey = api_require_key(['full']);

    // Map API endpoints
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
    
    // Clear session data
    $_SESSION = [];
    
    // Delete session cookie
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', $params['secure'] ?? false, $params['httponly'] ?? true);
    }
    
    // Clear remember-me cookie
    setcookie('remember', '', time() - 3600, '/', '', $secure, true);
    
    // Destroy the session
    session_destroy();
    
    // Redirect to logout confirmation (which will start a fresh session)
    header('Location: /?page=logout-confirm');
    exit;
}

// Allow unauthenticated access only to explicit public pages
$publicPages = ['login', 'serve-upload', 'reset-password', 'reset-verify', 'reset-new', 'reset-request', 'reset-update', 'public-doc', 'public-quote-action', 'stripe-checkout', 'stripe-success', 'stripe-webhook'];

// Toggle to disable auth checks in development/testing
$authDisabled = filter_var(getenv('AUTH_DISABLED') ?: getenv('APP_AUTH_DISABLED') ?: '', FILTER_VALIDATE_BOOLEAN);

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

// Enforce authentication for everything else (unless disabled)
if (!$authDisabled && empty($_SESSION['user']) && !in_array($page, $publicPages, true)) {
    header('Location: /?page=login');
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
if ($page === 'financial/financial-api') {
    require_once __DIR__ . '/../src/controllers/financial/financial_api.php';
    exit;
}
if ($page === 'settings/item-library-search') {
    require_once __DIR__ . '/../src/controllers/settings/item_library_search.php';
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
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Enforce CSRF on most POST endpoints, but allow controllers with their own CSRF/validation
    $skipCsrfFor = ['auth', 'reset-request', 'reset-verify', 'reset-update', 'public-quote-action', 'public-contract-sign', 'organization/org-create', 'stripe-webhook'];
    if (!in_array($page, $skipCsrfFor, true)) {
        csrf_verify_post_or_redirect($page);
    }

    if ($page === 'settings') {
        require_once __DIR__ . '/../src/controllers/settings_handler.php';
        exit;
    }
    if ($page === 'settings/tax-rates-handler') {
        require_once __DIR__ . '/../src/controllers/settings/tax-rates-handler.php';
        exit;
    }
    if ($page === 'settings/tax-rates-import-handler') {
        require_once __DIR__ . '/../src/controllers/settings/tax-rates-import-handler.php';
        exit;
    }
    if ($page === 'settings/fips-import-handler') {
        require_once __DIR__ . '/../src/controllers/settings/fips-import-handler.php';
        exit;
    }
    if ($page === 'settings/rates-import-handler') {
        require_once __DIR__ . '/../src/controllers/settings/rates-import-handler.php';
        exit;
    }
    if ($page === 'settings/boundaries-import-handler') {
        require_once __DIR__ . '/../src/controllers/settings/boundaries-import-handler.php';
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
    if ($page === 'api-keys-revoke') {
        require_once __DIR__ . '/../src/controllers/api_keys_revoke.php';
        exit;
    }
    if ($page === 'client/clients-create' || $page === 'clients-create') {
        require_once __DIR__ . '/../src/controllers/client/clients_create.php';
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
    if ($page === 'payments/payments-create') {
        require_once __DIR__ . '/../src/controllers/payments_create.php';
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
    if ($page === 'organization/organization-remove-client') {
        require_once __DIR__ . '/../src/controllers/organization/organization_remove_client.php';
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
    if ($page === 'receipts-handler') {
        require_once __DIR__ . '/../src/controllers/receipts_handler.php';
        exit;
    }
    if ($page === 'forms-handler') {
        require_once __DIR__ . '/../src/controllers/forms_handler.php';
        exit;
    }
    if ($page === 'public-link-create') {
        require_once __DIR__ . '/../src/controllers/public_link_create.php';
        exit;
    }
    if ($page === 'stripe-charge') {
        require_once __DIR__ . '/../src/controllers/stripe_charge.php';
        exit;
    }
    if ($page === 'stripe-webhook') {
        require_once __DIR__ . '/../src/controllers/stripe_webhook.php';
        exit;
    }
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
if ($page === 'stripe-checkout') {
    require_once __DIR__ . '/../src/controllers/public_view/stripe_checkout.php';
    exit;
}
if ($page === 'stripe-success') {
    require_once __DIR__ . '/../src/views/partials/auth_header.php';
    require_once __DIR__ . '/../src/controllers/public_view/stripe_success.php';
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
