<?php
// src/controllers/account/data_export.php
// GDPR/CCPA "Right to Access" data export: downloads all data associated
// with the authenticated user as a single pretty-printed JSON file.

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/csrf.php';
require_once __DIR__ . '/../../utils/audit.php';

// Require authenticated session
if (empty($_SESSION['user']['id'])) {
    header('Location: /?page=login');
    exit;
}

$userId = (int)$_SESSION['user']['id'];

// Require CSRF validation
if (!csrf_validate()) {
    header('Location: /?page=account/data-export&error=' . rawurlencode('Invalid request (CSRF)'));
    exit;
}

// Build the export payload
$export = [
    'exported_at' => date('c'),
    'schema_note' => 'GDPR/CCPA Right to Access export. Contains all personal data associated with the authenticated user.',
    'user'        => null,
    'organizations' => [],
    'audit_trail' => [],
];

try {
    // User account (omit password_hash)
    $stmt = $pdo->prepare('SELECT id, email, role, created_at, tos_accepted_at FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $export['user'] = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    // Organizations the user is linked to
    $orgStmt = $pdo->prepare('SELECT * FROM user_organizations WHERE user_id = ?');
    $orgStmt->execute([$userId]);
    $userOrganizations = $orgStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Organization ID -> organization record, populated below
    $organizationRecords = [];

    foreach ($userOrganizations as $link) {
        $orgId = (int)($link['organization_id'] ?? 0);
        if ($orgId <= 0) {
            continue;
        }

        // Resolve organization name
        $orgName = null;
        try {
            $nameStmt = $pdo->prepare('SELECT id, name FROM organizations WHERE id = ?');
            $nameStmt->execute([$orgId]);
            $orgRecord = $nameStmt->fetch(PDO::FETCH_ASSOC);
            if ($orgRecord) {
                $organizationRecords[$orgId] = $orgRecord;
                $orgName = $orgRecord['name'];
            }
        } catch (Throwable $e) {
            // Continue without name if lookup fails
        }

        // Helper to fetch records by a parameter
        $fetch = static function (string $sql, $param) use ($pdo): array {
            $s = $pdo->prepare($sql);
            $s->execute([$param]);
            return $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
        };

        $orgExport = [
            'organization_id'   => $orgId,
            'organization_name' => $orgName,
            'user_organization_link' => $link,
            'clients'           => [],
            'quotes'            => [],
            'quote_items'       => [],
            'contracts'         => [],
            'contract_items'    => [],
            'invoices'          => [],
            'invoice_items'     => [],
            'payments'          => [],
            'receipts'          => [],
            'form_documents'    => [],
            'projects'          => [],
        ];

        // Clients
        $orgExport['clients'] = $fetch('SELECT * FROM clients WHERE org_id = ?', $orgId);

        // Quotes (all quotes whose client belongs to this org)
        $orgExport['quotes'] = $fetch(
            'SELECT * FROM quotes WHERE client_id IN (SELECT id FROM clients WHERE org_id = ?)',
            $orgId
        );

        // Quote items
        $orgExport['quote_items'] = $fetch(
            'SELECT * FROM quote_items WHERE quote_id IN (
                SELECT id FROM quotes WHERE client_id IN (SELECT id FROM clients WHERE org_id = ?)
            )',
            $orgId
        );

        // Contracts
        $orgExport['contracts'] = $fetch(
            'SELECT * FROM contracts WHERE client_id IN (SELECT id FROM clients WHERE org_id = ?)',
            $orgId
        );

        // Contract items
        $orgExport['contract_items'] = $fetch(
            'SELECT * FROM contract_items WHERE contract_id IN (
                SELECT id FROM contracts WHERE client_id IN (SELECT id FROM clients WHERE org_id = ?)
            )',
            $orgId
        );

        // Invoices
        $orgExport['invoices'] = $fetch(
            'SELECT * FROM invoices WHERE client_id IN (SELECT id FROM clients WHERE org_id = ?)',
            $orgId
        );

        // Invoice items
        $orgExport['invoice_items'] = $fetch(
            'SELECT * FROM invoice_items WHERE invoice_id IN (
                SELECT id FROM invoices WHERE client_id IN (SELECT id FROM clients WHERE org_id = ?)
            )',
            $orgId
        );

        // Payments
        $orgExport['payments'] = $fetch(
            'SELECT * FROM payments WHERE client_id IN (SELECT id FROM clients WHERE org_id = ?)',
            $orgId
        );

        // Receipts
        $orgExport['receipts'] = $fetch('SELECT * FROM receipts WHERE org_id = ?', $orgId);

        // Form documents (via form_categories)
        $orgExport['form_documents'] = $fetch(
            'SELECT * FROM form_documents WHERE category_id IN (SELECT id FROM form_categories WHERE org_id = ?)',
            $orgId
        );

        // Projects
        $orgExport['projects'] = $fetch('SELECT * FROM projects WHERE org_id = ?', $orgId);

        $export['organizations'][] = $orgExport;
    }

    // Audit trail for this user across all organizations
    $auditStmt = $pdo->prepare('SELECT * FROM system_audit WHERE user_id = ? ORDER BY created_at DESC');
    $auditStmt->execute([$userId]);
    $export['audit_trail'] = $auditStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Audit this export itself (set flag to suppress middleware duplicate)
    $GLOBALS['__audit_logged'] = true;
    audit_log($pdo, 'user.data_export', 'user', $userId, [
        'organization_count' => count($export['organizations']),
        'audit_trail_count'  => count($export['audit_trail']),
    ]);

    // Generate download filename with today's date
    $filename = 'pa-data-export-' . date('Y-m-d') . '.json';

    $json = json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new Exception('Failed to encode export data to JSON');
    }

    // Output as attachment
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($json));
    header('Cache-Control: no-store, no-cache, must-revalidate, private');
    header('Pragma: no-cache');

    echo $json;
    exit;
} catch (Throwable $e) {
    @error_log('[data_export] Failed for user ' . $userId . ': ' . $e->getMessage());
    header('Location: /?page=account/data-export&error=' . rawurlencode('Unable to generate data export. Please try again.'));
    exit;
}
