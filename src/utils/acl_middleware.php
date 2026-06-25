<?php
// src/utils/acl_middleware.php
// Central permission gate. Maps ?page=X → permission key, checks user_can().

require_once __DIR__ . '/acl.php';
require_once __DIR__ . '/audit.php';

function page_permission_map(): array
{
    static $map = null;
    if ($map !== null) return $map;

    $map = [
        // Core authenticated pages (low bar)
        'home'                => 'profile.view',
        'dashboard'           => 'profile.view',
        'account'             => 'profile.edit',
        'account-update'      => 'profile.edit',
        'account/delete'      => 'users.manage',
        'account-revoke-device' => 'profile.edit',
        'auth'                => null,
        'logout'              => null,
        'logout-confirm'      => null,
        'serveupload'         => null,

        // Settings module
        'settings'                         => 'settings.view',
        'settings-backup'                  => 'settings.manage',
        'settings/custom-fields-handler'     => 'settings.manage',
        'settings/document-custom-fields-handler' => 'settings.manage',
        'settings/document-customization-save'    => 'settings.manage',
        'settings/dropbox-oauth'             => 'settings.manage',
        'settings/item-library-handler'      => 'settings.manage',
        'settings/item-library-search'       => 'settings.view',
        'settings/links-handler'             => 'settings.manage',
        'settings/logs'                      => 'settings.manage',
        'settings/logs-handler'                => 'settings.manage',
        'settings/permissions'                 => 'settings.manage',
        'settings/permissions-handler'         => 'settings.manage',
        'settings/link-test-connection'      => 'settings.manage',
        'settings/tax-import-handler'        => 'settings.manage',
        'settings/tax-rates-handler'         => 'settings.manage',

        // Accounts / users
        'accounts'               => 'users.manage',
        'accounts-create'        => 'users.manage',
        'accounts-delete'        => 'users.manage',
        'accounts-reset-password' => 'users.reset_password',
        'accounts-update'        => 'users.manage',
        'account-edit'           => 'users.manage',
        '2fa-admin-disable'      => '2fa.manage',
        '2fa-setup'              => 'profile.edit',
        '2fa-setup-action'       => 'profile.edit',
        '2fa-verify'             => null,
        '2fa-verify-action'      => null,

        // API keys
        'api-keys'          => 'api_keys.manage',
        'api-keys-create'   => 'api_keys.manage',
        'api-keys-revoke'   => 'api_keys.manage',
        'api-clients-search' => 'api_keys.view',

        // Quotes module
        'quote/quotes-list'   => 'quotes.view',
        'quote/quotes-create' => 'quotes.create',
        'quote/quotes-edit'   => 'quotes.edit',
        'quote/quotes-update' => 'quotes.edit',
        'quote/quote-details' => 'quotes.view',
        'quote/quote-approve' => 'quotes.approve',
        'quote/quote-reject'  => 'quotes.reject',
        'quote/email-send'    => 'quotes.send',
        'quote-reject'        => 'quotes.reject',
        'quotes-create'       => 'quotes.create',
        'quotes-edit'         => 'quotes.edit',
        'quotes-update'       => 'quotes.edit',

        // Generic email (admin/settings only)
        'email-send'          => 'settings.manage',
        'email-test'          => 'settings.manage',

        // Contracts module
        'contract/contracts-list'   => 'contracts.view',
        'contract/contracts-create' => 'contracts.create',
        'contract/contracts-edit'   => 'contracts.edit',
        'contract/contract-details' => 'contracts.view',
        'contract/contract-sign'    => 'contracts.sign',
        'contract/contract-complete' => 'contracts.complete',
        'contract/contract-deny'    => 'contracts.edit',
        'contract/contract-deposit-received' => 'contracts.edit',
        'contract/contract-void'    => 'contracts.void',
        'contract/contracts-update' => 'contracts.edit',
        'contract/email-send'       => 'contracts.send',
        'contract/long-term-contracts-create' => 'contracts.create',
        'long-term-contracts-create'          => 'contracts.create',
        'contracts-create'          => 'contracts.create',
        'contracts-edit'            => 'contracts.edit',
        'contracts-update'          => 'contracts.edit',
        'contract-void'           => 'contracts.void',
        'contract-sign'           => 'contracts.sign',
        'contract-complete'         => 'contracts.complete',
        'public-contract-sign'    => null,
        'long-term-contract-activate' => 'contracts.edit',
        'long-term-contract-pause'    => 'contracts.edit',
        'long-term-contract-resume'   => 'contracts.edit',
        'long-term-contract-terminate' => 'contracts.delete',
        'on-demand-contract-activate'  => 'contracts.edit',
        'on-demand-contract-pause'     => 'contracts.edit',
        'on-demand-contract-resume'    => 'contracts.edit',
        'on-demand-contract-terminate' => 'contracts.delete',

        // Invoices module
        'invoice/invoices-list'     => 'invoices.view',
        'invoice/invoices-create'   => 'invoices.create',
        'invoice/invoices-edit'     => 'invoices.edit',
        'invoice/invoices-update'   => 'invoices.edit',
        'invoice/invoice-details'   => 'invoices.view',
        'invoice/invoices-mark-paid' => 'invoices.mark_paid',
        'invoice/email-send'        => 'invoices.send',
        'invoices-create'           => 'invoices.create',
        'invoices-edit'             => 'invoices.edit',
        'invoices-update'           => 'invoices.edit',
        'invoices-mark-paid'        => 'invoices.mark_paid',
        'on-demand-invoice-generate' => 'invoices.create',
        'payments/payments-create'  => 'invoices.mark_paid',

        // Clients module
        'client/clients-list'    => 'clients.view',
        'client/clients-create'  => 'clients.create',
        'client/clients-edit'    => 'clients.edit',
        'client/clients-update'  => 'clients.edit',
        'client/clients-delete'  => 'clients.delete',
        'client/clients-purge'   => 'clients.purge',
        'client/clients-restore' => 'clients.restore',
        'clients-create'         => 'clients.create',
        'clients-delete'         => 'clients.delete',
        'clients-purge'          => 'clients.purge',
        'clients-restore'        => 'clients.restore',
        'clients-search'         => 'clients.view',
        'clients-update'         => 'clients.edit',

        // Projects module
        'project/projects-list'         => 'projects.view',
        'project/projects-create'       => 'projects.create',
        'project/projects-update'         => 'projects.edit',
        'project/projects-update-status' => 'projects.edit',
        'project/projects-delete'        => 'projects.delete',
        'project/project-add-document'   => 'projects.edit',
        'project/project-remove-document' => 'projects.edit',
        'project-notes'                  => 'projects.view',
        'project-notes-update'           => 'projects.edit',
        'projects-search'                => 'projects.search',
        'projects-search-autocomplete'   => 'projects.search',

        // Jobs module
        'jobs/jobs-list'   => 'jobs.view',
        'jobs/job-details' => 'jobs.view',
        'jobs-list'        => 'jobs.view',

        // Organizations module
        'organization/organizations-create'      => 'organizations.manage',
        'organization/organizations-update'      => 'organizations.manage',
        'organization/organizations-delete'      => 'organizations.delete',
        'organization/organization-add-client'     => 'organizations.manage',
        'organization/organization-remove-client'  => 'organizations.manage',
        'organization/organization-update-notes'   => 'organizations.manage',
        'organization/organizations_upload'        => 'organizations.manage',
        'organization/org-create'                => 'organizations.manage',
        'organization/org-search'                => 'organizations.view',
        'organizations-create'                   => 'organizations.manage',
        'organizations-delete'                   => 'organizations.delete',
        'organizations-update'                   => 'organizations.manage',
        'organization-update-notes'              => 'organizations.manage',
        'org-search'                             => 'organizations.view',
        'org-create'                             => 'organizations.manage',

        // Financial module
        'financial/financial-dashboard'    => 'financial.view',
        'financial/expenses-list'          => 'financial.view',
        'financial/expense-handler'        => 'financial.manage',
        'financial/csv-import'             => 'financial.manage',
        'financial/mileage-handler'        => 'financial.manage',
        'financial/vendor-handler'         => 'financial.manage',
        'financial/category-handler'       => 'financial.manage',
        'financial/audit'                  => 'financial.audit',
        'financial/audit-export'           => 'financial.export',
        'financial/audit-schedule-handler' => 'financial.audit',
        'financial/financial-api'          => 'financial.view',

        // Time tracking
        'time-tracking/create'   => 'time_tracking.manage',
        'time-tracking/delete'   => 'time_tracking.manage',
        'time-tracking/start-timer' => 'time_tracking.manage',
        'time-tracking/stop-timer' => 'time_tracking.manage',
        'time-tracking/unbilled'   => 'time_tracking.view',
        'time-tracking/update'     => 'time_tracking.manage',

        // Public links
        'public-link-create'  => 'public_links.create',
        'public-link-revoke'  => 'public_links.revoke',

        // Legal / misc
        'legal/tos-accept' => 'profile.edit',
        'links/link-management' => 'settings.manage',
        'links/manual-link-handler' => 'settings.manage',
        'forms-handler' => 'settings.manage',
        'custom-fields-ajax' => 'settings.manage',
        'receipts-handler' => 'financial.manage',

        // Documents
        'document-date-update' => 'settings.manage',
        'document-reenable'    => 'settings.manage',

        // Billing / Stripe
        'stripe-charge'          => 'billing.manage',
        'stripe-success'         => 'billing.view',
        'stripe-webhook'         => null,
        'stripe-webhook-legacy'  => null,
        'stripe-checkout'        => null,
    ];
    return $map;
}

function acl_middleware(PDO $pdo, string $page): void
{
    $publicPages = ['login', 'serve-upload', 'reset-password', 'reset-verify', 'reset-new', 'reset-request', 'reset-update', 'public-doc', 'public-quote-action', 'stripe-checkout', 'stripe-success', 'stripe-webhook', 'stripe-webhook-legacy', 'legal/terms-of-service', 'legal/privacy-policy', 'legal/acceptable-use-policy', 'legal/dmca-policy', 'legal/data-retention-policy', 'account-deleted'];
    if (in_array($page, $publicPages, true)) return;

    $userId = (int)($_SESSION['user']['id'] ?? 0);
    if ($userId === 0) return; // auth gate handles redirect

    if (($_SESSION['user']['role'] ?? '') === 'admin') return; // super-admin bypass

    // Session staleness check
    $activeOrgId = get_active_org_id();
    if (!empty($_SESSION['user']['permissions_hash'])) {
        $expected = compute_permissions_hash($pdo, $userId, $activeOrgId);
        if ($_SESSION['user']['permissions_hash'] !== $expected) {
            audit_log($pdo, 'acl.session_stale', 'permission', null, ['page' => $page, 'user_id' => $userId]);
            session_destroy();
            header('Location: /?page=login&error=' . urlencode('Session expired. Please log in again.'));
            exit;
        }
    }

    $map = page_permission_map();
    $perm = $map[$page] ?? null;

    if ($perm === null) {
        audit_log($pdo, 'acl.denied_unmapped', 'permission', null, ['page' => $page, 'user_id' => $userId]);
        deny_response($page);
    }

    if (user_can($pdo, $userId, $perm, $activeOrgId)) return;

    audit_log($pdo, 'acl.denied', 'permission', null, ['page' => $page, 'required' => $perm, 'user_id' => $userId]);
    deny_response($page);
}

function is_ajax_request(): bool
{
    $requestedWith = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '');
    if ($requestedWith === 'xmlhttprequest') return true;
    if (($_GET['ajax'] ?? '') === '1') return true;
    if (($_POST['ajax'] ?? '') === '1') return true;
    return false;
}

function deny_response(string $page): void
{
    if (is_ajax_request()) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Permission denied']);
        exit;
    }
    header('Location: /?page=home&error=' . urlencode('You do not have permission to access that page.'));
    exit;
}
