<?php
// Simple router
$page = isset($_GET['page']) ? preg_replace('/[^a-z0-9\-]/i','', $_GET['page']) : 'home';

// API/GET endpoints that should bypass layout
if ($page === 'clients-search') {
    require_once __DIR__ . '/../src/controllers/clients_search.php';
    exit;
}

// Handle POST actions (PRG pattern)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($page === 'settings') {
        require_once __DIR__ . '/../src/controllers/settings_handler.php';
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
    if ($page === 'contract-deny') {
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
}

require_once __DIR__ . '/../src/views/partials/header.php';

$view = __DIR__ . '/../src/views/pages/' . $page . '.php';
if (!is_file($view)) {
    $view = __DIR__ . '/../src/views/pages/home.php';
}
require $view;

require_once __DIR__ . '/../src/views/partials/footer.php';
