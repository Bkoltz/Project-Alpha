<?php

namespace App\config;

use App\controllers\quote\QuotesDataController;
use App\Controllers\Quote\QuotesDetailsController;
use App\controllers\quote\QuotesListController;

class Router
{
    public static $routes = [
        'GET' => [
            'quote/quote-details' => [QuotesDetailsController::class, 'load'],
            'quote/quote-create' => [QuotesDataController::class, 'load'],
            'quote/quote-edit' => [QuotesDataController::class, 'load'],
            'quote/quote-pdf' => [QuotesDetailsController::class, 'toPDF'],
            'quote/regular-quote-list' => [QuotesListController::class, 'loadRegular'],
            'quote/on-demand-quote-list' => [QuotesListController::class, 'loadOnDemand'],
            'quote/long-term-quote-list' => [QuotesListController::class, 'loadLongTerm'],
        ],
        'POST' => [
            'quote/quote-create' => [QuotesDataController::class, 'create'],
            'quote/quote-edit' => [QuotesDataController::class, 'edit'],
            'quote/quote-reject' => [QuotesDetailsController::class, 'reject'],
            'quote/quote-approve' => [QuotesDetailsController::class, 'approve']
        ]
    ];

    public static $view_routes = [];

    public static $post_routes = [
        'invoice/invoice-pdf' => '/src/controllers/invoice/invoice_pdf.php',
        'invoice-pdf' => '/src/controllers/invoice/invoice_pdf.php',
        'contract/contract-pdf' => '/src/controllers/contract/contract_pdf.php',
        'contract-pdf' => '/src/controllers/contract/contract_pdf.php',
        'serve-upload' => '/src/controllers/serve_upload.php',
        'project-notes' => '/src/controllers/project_notes.php',
        'settings/document-custom-fields-handler' => '/src/controllers/settings/document-custom-fields-handler.php',
        'auth' => '/src/controllers/auth/auth_handler.php',
        'settings' => '/src/controllers/settings_handler.php',
        'settings/tax-rates-handler' => '/src/controllers/settings/tax-rates-handler.php',
        'settings/custom-fields-handler' => '/src/controllers/settings/custom_fields_handler.php',
        'accounts-create' => '/src/controllers/accounts/accounts_create.php',
        'accounts-update' =>  '/src/controllers/accounts/accounts_update.php',
        'accounts-delete' => '/src/controllers/accounts/accounts_delete.php',
        'accounts-reset-password' => '/src/controllers/accounts/accounts_reset_password.php',
        'reset-request' =>  '/src/controllers/auth/reset_request.php',
        'reset-verify' =>  '/src/controllers/auth/reset_verify.php',
        'reset-update' =>  '/src/controllers/auth/reset_update.php',
        'public-quote-action' => '/src/controllers/public_view/public_quote_action.php',
        'api-keys-create' =>  '/src/controllers/api_keys_create.php',
        'api-keys-revoke' => '/src/controllers/api_keys_revoke.php',
        'client/clients-create' => '/src/controllers/client/clients_create.php',
        'clients-create' => '/src/controllers/client/clients_create.php',
        'project/projects-create' => '/src/controllers/project/projects_create.php',
        'project/projects-update' => '/src/controllers/project/projects_update.php',
        'project/projects-delete' => '/src/controllers/project/projects_delete.php',
        'project/project-add-document' =>  '/src/controllers/project/project_add_document.php',
        'project/project-remove-document' => '/src/controllers/project/project_remove_document.php',
        'project/projects-update-status' => '/src/controllers/project/projects_update_status.php',
        'quote/quote-approve' => '/src/controllers/quote/quote_approve.php',
        'contract/contract-sign' => '/src/controllers/contract/contract_sign.php',
        'contract/contract-complete' => '/src/controllers/contract/contract_complete.php',
        'contract/contract-void' => '/src/controllers/contract/contract_void.php',
        'contract/contract-deposit-received' => '/src/controllers/contract/contract_deposit_received.php',
        'document-reenable' => '/src/controllers/document_reenable_handler.php',
        'document-date-update' => '/src/controllers/document_date_update_handler.php',
        'contract/contract-deny' => '/src/controllers/contract/contract_deny.php',
        'invoice/invoices-mark-paid' => '/src/controllers/invoice/invoices_mark_paid.php',
        'payments/payments-create' => '/src/controllers/payments_create.php',
        'client/clients-update' => '/src/controllers/client/clients_update.php',
        'clients-update' => '/src/controllers/client/clients_update.php',
        'client/clients-delete' => '/src/controllers/client/clients_delete.php',
        'clients-delete' => '/src/controllers/client/clients_delete.php',
        'client/clients-restore' => '/src/controllers/client/clients_restore.php',
        'clients-restore' => '/src/controllers/client/clients_restore.php',
        'client/clients-purge' => '/src/controllers/client/clients_purge.php',
        'clients-purge' => '/src/controllers/client/clients_purge.php',
        'contract/contracts-create' => '/src/controllers/contract/contracts_create.php',
        'contracts-create' => '/src/controllers/contract/contracts_create.php',
        'long-term-contracts-create' => '/src/controllers/contract/long_term_contracts_create.php',
        'contract/long-term-contracts-create' => '/src/controllers/contract/long_term_contracts_create.php',
        'contract/contracts-update' => '/src/controllers/contract/contracts_update.php',
        'contracts-update' => '/src/controllers/contract/contracts_update.php',
        'invoice/invoices-create' => '/src/controllers/invoice/invoices_create.php',
        'invoices-create' => '/src/controllers/invoice/invoices_create.php',
        'invoice/invoices-update' => '/src/controllers/invoice/invoices_update.php',
        'invoices-update' => '/src/controllers/invoice/invoices_update.php',
        'quote/email-send' => '/src/controllers/email_send.php',
        'contract/email-send' => '/src/controllers/email_send.php',
        'invoice/email-send' => '/src/controllers/email_send.php',
        'email-send' => '/src/controllers/email_send.php',
        'email-test' => '/src/controllers/email_test.php',
        'project-notes-update' => '/src/controllers/project_notes_update.php',
        'account-update' => '/src/controllers/auth/account_update.php',
        'financial/audit-export' => '/src/controllers/financial/audit_export.php',
        'financial/audit-schedule-handler' => '/src/controllers/financial/audit_schedule_handler.php',
        'organization/org-create' => '/src/controllers/organization/org_create.php',
        'organization/organizations-create' => '/src/controllers/organization/organizations_create.php',
        'organization/organizations-update' => '/src/controllers/organization/organizations_update.php',
        'organization/organizations-delete' => '/src/controllers/organization/organizations_delete.php',
        'organization/organization-add-client' => '/src/controllers/organization/organization_add_client.php',
        'organization/organization-remove-client' => '/src/controllers/organization/organization_remove_client.php',
        'organization/organizations_upload' => '/src/controllers/organization/organizations_upload.php',
        'organization/organizations-upload' => '/src/controllers/organization/organizations_upload.php',
        'settings/item-library-handler' => '/src/controllers/settings/item_library_handler.php',
        'settings/document-customization-save' => '/src/controllers/settings/document-customization-save.php',
        'settings/document-custom-fields-handler' => '/src/controllers/settings/document-custom-fields-handler.php',
        'settings/link-test-connection' => '/src/controllers/settings/link_test_connection.php',
        'receipts-handler' => '/src/controllers/receipts_handler.php',
        'forms-handler' =>  '/src/controllers/forms_handler.php',
        'projects-search-autocomplete' => '/src/controllers/project/projects_search_autocomplete.php',
        'financial/financial-api' => '/src/controllers/financial/financial_api.php',
    ];

    public static $get_routes = [
        'clients-search' => '/src/controllers/client/clients_search.php',
        'projects-search-autocomplete' => '/src/controllers/project/projects_search_autocomplete.php',
        'projects-search' => '/src/controllers/project/projects_search.php',
        'org-search' => '/src/controllers/organization/org_search.php',
        'organization/org-search' => '/src/controllers/organization/org_search.php',
        'financial/financial-api' => '/src/controllers/financial/financial_api.php',
        'settings/item-library-search' => '/src/controllers/settings/item_library_search.php',
        'custom-fields-ajax' => '/src/controllers/api/custom_fields_ajax.php',
    ];

    public static function routePage(): ?string
    {
        $pageRaw = isset($_GET['page']) ? (string)$_GET['page'] : 'home';
        $page = null;
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

        return $page;
    }

    static function resolveViewPathTwig(string $page): string
    {
        $canidates = [];
        $canidates[] = $page . '.twig';

        foreach ($canidates as $canidate) {
            $updated_canidate = '/pages/' . $canidate;
            if (is_file(TWIG_PATH . $updated_canidate))
                return $updated_canidate;
        }

        return '';
    }

    static function resolveViewPath(string $page): string
    {
        $base = BASE_PATH . '/src/views/pages/';
        $canidates = [];

        // Special case: accounts is in auth folder
        if ($page === 'accounts') {
            $canidates[] = $base . 'auth/accounts.php';
        }

        // As-provided
        $canidates[] = $base . $page . '.php';

        // Try ucfirst for first segment (e.g., jobs -> Jobs)
        $parts = explode('/', $page);
        if (count($parts) >= 2) {
            $parts_ucfirst = $parts;
            $parts_ucfirst[0] = ucfirst($parts_ucfirst[0]);
            $canidates[] = $base . implode('/', $parts_ucfirst) . '.php';

            // Try uppercasing all segments (maybe folders/filenames are TitleCase)
            $parts_uc = array_map(function ($p) {
                return ucfirst($p);
            }, $parts);
            $canidates[] = $base . implode('/', $parts_uc) . '.php';
        }

        // Try basename only
        $canidates[] = $base . basename($page) . '.php';

        foreach ($canidates as $c) {
            if (is_file($c)) {
                return $c;
            }
        }

        return $base . 'home.php';
    }
}
