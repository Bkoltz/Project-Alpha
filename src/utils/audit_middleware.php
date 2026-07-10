<?php
// src/utils/audit_middleware.php
// Router-level audit middleware — guarantees baseline audit coverage for
// sensitive actions without requiring every controller to call audit_log().
//
// How it works:
//   audit_middleware($pdo, $page) is called from public/index.php after auth
//   enforcement and before routing. If the page matches the sensitive-action
//   map below, a shutdown function is registered that writes the audit row
//   AFTER the controller finishes (works even when controllers exit;/die;).
//
// Controllers may still call audit_log() directly for richer detail —
// duplicate baseline rows are suppressed via $GLOBALS['__audit_logged'].
// Set $GLOBALS['__audit_logged'] = true in a controller after a manual
// audit_log() call to skip the middleware's baseline row for that request.

require_once __DIR__ . '/audit.php';

/**
 * Pattern-based fallback for audit coverage.
 *
 * Returns [action, entity_type] for routes that follow common create/update/delete/export
 * naming conventions. The caller must still enforce request-method restrictions:
 * write/lifecycle patterns are only applied on POST; export patterns may fire on any method.
 */
function audit_matches_pattern(string $page): ?array
{
    // Destructive operations
    if (preg_match('/(-delete|\/clients-delete|\/projects-delete|\/organizations-delete)$/', $page)) {
        return ['record.delete', 'record'];
    }
    if (preg_match('/(-purge|\/clients-purge)$/', $page)) {
        return ['record.purge', 'record'];
    }
    // Write operations (POST only — checked by caller)
    if (preg_match('/(-update|-edit|-create)$/', $page)) {
        return ['record.write', 'record'];
    }
    // Void/complete/sign lifecycle
    if (preg_match('/(-void|-complete|-sign|-deny|-approve|-reject)$/', $page)) {
        return ['record.lifecycle', 'record'];
    }
    // Exports
    if (str_contains($page, 'export')) {
        return ['data.export', 'record'];
    }
    return null;
}

/**
 * Map of page => [action, entity_type].
 * POST-only entries fire only on POST; the '*' list fires on any method.
 */
function audit_sensitive_map(): array
{
    return [
        'POST' => [
            // Payments / Stripe
            'stripe-charge'                  => ['payment.stripe_charge', 'payment'],
            'stripe-checkout'                => ['payment.stripe_checkout', 'payment'],
            'stripe-webhook'                 => ['payment.stripe_webhook', 'payment'],
            'stripe-webhook-legacy'          => ['payment.stripe_webhook', 'payment'],
            'payments-create'                => ['payment.create', 'payment'],
            'payments/payment-refund'        => ['payment.refund', 'payment'],
            'payments/payment-reverse'       => ['payment.manual_entry_reversed', 'payment'],
            'payments/payment-correct'       => ['payment.allocation_corrected', 'payment'],
            'invoice/invoices-mark-paid'     => ['invoice.mark_paid', 'invoice'],
            'invoices-mark-paid'             => ['invoice.mark_paid', 'invoice'],
            'invoice/invoice-void'            => ['invoice.void', 'invoice'],
            'invoice/invoice-reenable'        => ['invoice.reenable', 'invoice'],

            // Security state
            'two-factor-setup'               => ['auth.2fa_setup', 'user'],
            'two-factor-verify'              => ['auth.2fa_verify', 'user'],
            'admin-2fa-disable'              => ['auth.2fa_admin_disable', 'user'],
            'api-keys-create'                => ['api_key.create', 'api_key'],
            'api-keys-update'                => ['api_key.update', 'api_key'],
            'api-keys-revoke'                => ['api_key.revoke', 'api_key'],
            'reset-request'                  => ['auth.password_reset_request', 'user'],
            'reset-verify'                   => ['auth.password_reset_verify', 'user'],

            // Public (unauthenticated) document actions
            'public-quote-action'            => ['public.quote_action', 'quote'],
            'public-contract-sign'           => ['public.contract_sign', 'contract'],
            'public-link-create'             => ['public_link.create', 'public_link'],
            'public-link-revoke'             => ['public_link.revoke', 'public_link'],

            // Destructive operations
            'client/clients-delete'          => ['client.delete', 'client'],
            'clients-delete'                 => ['client.delete', 'client'],
            'client/clients-purge'           => ['client.purge', 'client'],
            'clients-purge'                  => ['client.purge', 'client'],
            'organization/organizations-delete' => ['organization.delete', 'organization'],
            'organizations-delete'           => ['organization.delete', 'organization'],
            'project/projects-delete'        => ['project.delete', 'project'],
            'projects-delete'                => ['project.delete', 'project'],
            'contract/contract-void'         => ['contract.void', 'contract'],
            'contract-void'                  => ['contract.void', 'contract'],

            // Contract lifecycle
            'contract/contract-sign'         => ['contract.sign', 'contract'],
            'contract-sign'                  => ['contract.sign', 'contract'],
            'contract/contract-complete'     => ['contract.complete', 'contract'],
            'contract-complete'              => ['contract.complete', 'contract'],
            'long-term-recurring-service-save' => ['contract.recurring_service_save', 'contract'],
            'long-term-recurring-service-action' => ['contract.recurring_service_action', 'contract'],

            // Email
            'email-send'                     => ['email.send', 'email'],
        ],
        // Any-method (GET data exports / downloads)
        '*' => [
            'contract/contract-pdf'          => ['export.contract_pdf', 'contract'],
            'contract-pdf'                   => ['export.contract_pdf', 'contract'],
            'quote/quote-pdf'                => ['export.quote_pdf', 'quote'],
            'quote-pdf'                      => ['export.quote_pdf', 'quote'],
            'invoice/invoice-pdf'            => ['export.invoice_pdf', 'invoice'],
            'invoice-pdf'                    => ['export.invoice_pdf', 'invoice'],
        ],
    ];
}

/**
 * Register the baseline audit hook for the current request if it matches the
 * sensitive-action map. Call once from public/index.php before routing.
 */
function audit_middleware(PDO $pdo, string $page): void
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $map = audit_sensitive_map();

    $match = null;
    if ($method === 'POST' && isset($map['POST'][$page])) {
        $match = $map['POST'][$page];
    } elseif (isset($map['*'][$page])) {
        $match = $map['*'][$page];
    }

    // Fallback: pattern-based coverage for POST create/update/delete/lifecycle
    // and for any-method export routes.
    if ($match === null) {
        $pattern = audit_matches_pattern($page);
        if ($pattern !== null) {
            $isPostOnlyPattern = !str_contains($page, 'export');
            if (!$isPostOnlyPattern || $method === 'POST') {
                $match = $pattern;
            }
        }
    }

    if ($match === null) {
        return;
    }

    [$action, $entityType] = $match;

    // Capture request context now; session may be gone at shutdown time.
    $userId = (!empty($_SESSION['user']['id'])) ? (int)$_SESSION['user']['id'] : null;
    $entityId = null;
    foreach (['id', 'invoice_id', 'contract_id', 'quote_id', 'client_id', 'project_id', 'user_id', 'key_id'] as $k) {
        $v = $_POST[$k] ?? $_GET[$k] ?? null;
        if ($v !== null && is_numeric($v)) { $entityId = (int)$v; break; }
    }
    $details = [
        'page'   => $page,
        'method' => $method,
        'via'    => 'middleware',
    ];

    register_shutdown_function(static function () use ($pdo, $action, $entityType, $entityId, $details, $userId) {
        // Skip if the controller already wrote a richer audit row.
        if (!empty($GLOBALS['__audit_logged'])) {
            return;
        }
        $details['http_status'] = http_response_code() ?: null;
        audit_log($pdo, $action, $entityType, $entityId, $details, $userId);
    });
}
