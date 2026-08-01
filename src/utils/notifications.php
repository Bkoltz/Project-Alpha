<?php
// src/utils/notifications.php
// Notification and activity logging functions for public link events

require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/smtp.php';
require_once __DIR__ . '/email_identity.php';
require_once __DIR__ . '/../services/EmailService.php';

/**
 * Log an activity event to the activity_log table
 * @param PDO $pdo
 * @param string $eventType Event type (e.g., 'quote_approved', 'contract_signed', 'invoice_paid')
 * @param string|null $documentType Document type ('quote', 'contract', 'invoice')
 * @param int|null $documentId Document ID
 * @param int|null $clientId Client ID
 * @param string $description Human-readable description
 * @param array $metadata Additional data to store
 */
require_once __DIR__ . '/client_ip.php';

function ensure_activity_log_table(PDO $pdo): void {
    static $done = false;
    if ($done) {
        return;
    }

    $pdo->exec('
        CREATE TABLE IF NOT EXISTS activity_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            event_type VARCHAR(50) NOT NULL,
            document_type VARCHAR(20) NULL,
            document_id INT NULL,
            client_id INT NULL,
            description TEXT NOT NULL,
            ip_address VARCHAR(45) NULL,
            user_agent TEXT NULL,
            metadata JSON NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_activity_type (event_type),
            INDEX idx_activity_doc (document_type, document_id),
            INDEX idx_activity_client (client_id),
            INDEX idx_activity_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ');

    $done = true;
}

function log_activity(PDO $pdo, string $eventType, ?string $documentType, ?int $documentId, ?int $clientId, string $description, array $metadata = []): void {
    try {
        ensure_activity_log_table($pdo);
        $ip = get_client_ip();
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
        $metaJson = !empty($metadata) ? json_encode($metadata) : null;
        
        $stmt = $pdo->prepare('INSERT INTO activity_log (event_type, document_type, document_id, client_id, description, ip_address, user_agent, metadata) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$eventType, $documentType, $documentId, $clientId, $description, $ip, $ua, $metaJson]);
    } catch (Throwable $e) {
        @error_log('[log_activity] Failed to log: ' . $e->getMessage());
    }
}

/**
 * Get SMTP config from app config
 */
function get_smtp_config(array $appConfig): array {
    $pass = '';
    if (!empty($appConfig['smtp_password_enc']) && is_string($appConfig['smtp_password_enc'])) {
        $encVal = $appConfig['smtp_password_enc'];
        if (strpos($encVal, 'plain::') === 0) {
            $pass = substr($encVal, 7);
        } else {
            if (function_exists('crypto_decrypt')) {
                $pt = crypto_decrypt($encVal);
                if (is_string($pt)) { $pass = $pt; }
            }
        }
    }
    return [
        'host' => (string)($appConfig['smtp_host'] ?? ''),
        'port' => (int)($appConfig['smtp_port'] ?? 587),
        'secure' => strtolower((string)($appConfig['smtp_secure'] ?? 'tls')),
        'username' => (string)($appConfig['smtp_username'] ?? ''),
        'password' => $pass,
    ];
}

function notification_setting_enabled(array $appConfig, string $key, bool $default = true): bool {
    if (!array_key_exists($key, $appConfig)) {
        return $default;
    }
    $value = $appConfig[$key];
    if (is_bool($value)) {
        return $value;
    }
    if (is_numeric($value)) {
        return (float)$value !== 0.0;
    }
    return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
}

function admin_notification_email_is_deliverable(mixed $value): bool {
    $email = strtolower(trim((string)$value));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    $domain = substr(strrchr($email, '@') ?: '', 1);
    return $domain !== 'localhost'
        && !str_ends_with($domain, '.localhost')
        && !str_ends_with($domain, '.local');
}

function admin_notification_recipients(PDO $pdo, array $appConfig): array {
    $emails = [];
    $sql = "
            SELECT email
            FROM users
            WHERE role IN ('admin','owner')
              AND is_disabled = 0
              AND email IS NOT NULL
              AND email <> ''
            ORDER BY id ASC
        ";
    try {
        $stmt = $pdo->query($sql);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $email) {
            $email = trim((string)$email);
            if (admin_notification_email_is_deliverable($email)) {
                $emails[strtolower($email)] = $email;
            }
        }
    } catch (Throwable $e) {
        try {
            $stmt = $pdo->query("
                SELECT email
                FROM users
                WHERE role IN ('admin','owner')
                  AND email IS NOT NULL
                  AND email <> ''
                ORDER BY id ASC
            ");
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $email) {
                $email = trim((string)$email);
                if (admin_notification_email_is_deliverable($email)) {
                    $emails[strtolower($email)] = $email;
                }
            }
        } catch (Throwable $fallbackError) {
            @error_log('[admin_notification_recipients] Error: ' . $fallbackError->getMessage());
        }
    }
    return array_values($emails);
}

/**
 * Backward-compatible helper for older callers/tests that expect one admin email.
 */
function get_admin_email(PDO $pdo, array $appConfig): string {
    $recipients = admin_notification_recipients($pdo, $appConfig);
    return $recipients[0] ?? '';
}

/**
 * Send admin notification email to every enabled admin/owner except the built-in local account.
 */
function send_admin_notification(PDO $pdo, array $appConfig, string $subject, string $html): bool {
    try {
        $adminEmails = admin_notification_recipients($pdo, $appConfig);
        if (!$adminEmails) { return false; }

        $sent = 0;

        foreach ($adminEmails as $adminEmail) {
            [$ok, $error] = EmailService::sendEmail($adminEmail, $subject, $html, [
                'document_type' => 'notification',
                'message_key' => 'admin-notification:' . hash('sha256', $subject . '|' . $html . '|' . strtolower($adminEmail)),
            ]);
            if (!$ok) {
                @error_log('[send_admin_notification] Failed for ' . $adminEmail . ': ' . $error);
                continue;
            }
            $sent++;
        }

        return $sent > 0;
    } catch (Throwable $e) {
        @error_log('[send_admin_notification] Error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Returns the list of email addresses that should be notified when an invoice
 * is paid by an automatic processor (Stripe, etc.).
 *
 * - Admin/owner accounts: included when they have opted in (default: opted in).
 * - All other accounts: included only when the invoice was created by them AND
 *   they have opted in. This prevents non-admins from seeing all invoice traffic.
 *
 * Manual payments never reach this function; it is called only from webhook
 * and processor-payment handlers.
 *
 * @return string[]  Deduplicated, deliverable email addresses.
 */
function invoice_payment_notification_recipients(PDO $pdo, int $invoiceId): array
{
    // Resolve the invoice creator so non-admin users are scoped to their own
    // invoices only.
    $creatorStmt = $pdo->prepare('SELECT created_by FROM invoices WHERE id=? LIMIT 1');
    $creatorStmt->execute([$invoiceId]);
    $createdBy = (int)($creatorStmt->fetchColumn() ?: 0);

    try {
        $stmt = $pdo->query(
            "SELECT u.id, u.email, u.role,
                    COALESCE(np.notify_processor_invoice_paid, 1) AS notify
             FROM users u
             LEFT JOIN user_notification_preferences np ON np.user_id = u.id
             WHERE u.is_disabled = 0
               AND u.deleted_at IS NULL
               AND u.email IS NOT NULL
               AND u.email <> ''
               AND COALESCE(np.notify_processor_invoice_paid, 1) = 1
             ORDER BY u.id ASC"
        );
    } catch (Throwable $e) {
        @error_log('[invoice_payment_notification_recipients] Query failed: ' . $e->getMessage());
        return [];
    }

    $emails = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $isAdmin   = in_array((string)($row['role'] ?? ''), ['admin', 'owner'], true);
        $isCreator = $createdBy > 0 && (int)$row['id'] === $createdBy;

        // Admins and owners receive notifications for all invoices.
        // Everyone else only receives a notification for invoices they created.
        if (!$isAdmin && !$isCreator) {
            continue;
        }

        $email = trim((string)$row['email']);
        if (admin_notification_email_is_deliverable($email)) {
            $emails[strtolower($email)] = $email;
        }
    }

    return array_values($emails);
}

/**
 * Same scoping logic as invoice_payment_notification_recipients but for
 * project invoices. Non-admin users are included only if they created the
 * project invoice.
 *
 * @return string[]
 */
function project_invoice_payment_notification_recipients(PDO $pdo, int $projectInvoiceId): array
{
    $creatorStmt = $pdo->prepare('SELECT created_by FROM project_invoices WHERE id=? LIMIT 1');
    $creatorStmt->execute([$projectInvoiceId]);
    $createdBy = (int)($creatorStmt->fetchColumn() ?: 0);

    try {
        $stmt = $pdo->query(
            "SELECT u.id, u.email, u.role,
                    COALESCE(np.notify_processor_invoice_paid, 1) AS notify
             FROM users u
             LEFT JOIN user_notification_preferences np ON np.user_id = u.id
             WHERE u.is_disabled = 0
               AND u.deleted_at IS NULL
               AND u.email IS NOT NULL
               AND u.email <> ''
               AND COALESCE(np.notify_processor_invoice_paid, 1) = 1
             ORDER BY u.id ASC"
        );
    } catch (Throwable $e) {
        @error_log('[project_invoice_payment_notification_recipients] Query failed: ' . $e->getMessage());
        return [];
    }

    $emails = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $isAdmin   = in_array((string)($row['role'] ?? ''), ['admin', 'owner'], true);
        $isCreator = $createdBy > 0 && (int)$row['id'] === $createdBy;

        if (!$isAdmin && !$isCreator) {
            continue;
        }

        $email = trim((string)$row['email']);
        if (admin_notification_email_is_deliverable($email)) {
            $emails[strtolower($email)] = $email;
        }
    }

    return array_values($emails);
}

function admin_invoice_paid_notification_enabled(array $appConfig, array $invoice): bool {
    if (!notification_setting_enabled($appConfig, 'notify_invoice_paid', true)) {
        return false;
    }
    $type = strtolower((string)($invoice['invoice_type'] ?? 'regular'));
    if ($type === 'long_term') {
        return notification_setting_enabled($appConfig, 'notify_invoice_paid_long_term', true);
    }
    if ($type === 'on_demand') {
        return notification_setting_enabled($appConfig, 'notify_invoice_paid_on_demand', true);
    }
    return notification_setting_enabled($appConfig, 'notify_invoice_paid_regular', true);
}

/**
 * Notify admin users that a client approved/denied a quote.
 * @param PDO $pdo
 * @param array $appConfig
 * @param array $quote  Quote row as associative array
 * @param string $action 'approve'|'deny'
 */
function notify_admin_quote_change(PDO $pdo, array $appConfig, array $quote, string $action): void {
    try {
        $brand = (string)($appConfig['brand_name'] ?? 'Project Alpha');
        $clientName = (string)($quote['client_name'] ?? 'Client');
        $docnum = (string)($quote['doc_number'] ?? $quote['id'] ?? '');
        $project = (string)($quote['project_code'] ?? '');
        $verb = $action === 'approve' ? 'approved' : 'denied';
        
        $subject = sprintf('[%s] Client %s %s quote Q-%s', $brand, $clientName, $verb, $docnum);
        $html = sprintf('<p>Client <strong>%s</strong> has %s quote <strong>Q-%s</strong>%s via the public link.</p><p>See changes in the app.</p>',
            htmlspecialchars($clientName), $verb, htmlspecialchars($docnum), $project !== '' ? (' on project <strong>'.htmlspecialchars($project).'</strong>') : ''
        );
        
        send_admin_notification($pdo, $appConfig, $subject, $html);
        
        // Log the activity
        $eventType = $action === 'approve' ? 'quote_approved' : 'quote_denied';
        log_activity($pdo, $eventType, 'quote', (int)($quote['id'] ?? 0), (int)($quote['client_id'] ?? 0), 
            "Client $clientName $verb quote Q-$docnum via public link",
            ['project_code' => $project, 'action' => $action]
        );
    } catch (Throwable $e) {
        @error_log('[notify_admin_quote_change] Error: ' . $e->getMessage());
    }
}

/**
 * Notify admin that a client signed a contract via public link
 * @param PDO $pdo
 * @param array $appConfig
 * @param array $contract Contract row as associative array
 * @param string $clientName Client name
 */
function notify_admin_contract_signed(PDO $pdo, array $appConfig, array $contract, string $clientName): void {
    try {
        $brand = (string)($appConfig['brand_name'] ?? 'Project Alpha');
        $docnum = (string)($contract['doc_number'] ?? $contract['id'] ?? '');
        $project = (string)($contract['project_code'] ?? '');

        if (notification_setting_enabled($appConfig, 'notify_signed_contract_uploaded', true)) {
            $subject = sprintf('[%s] Client %s signed contract C-%s', $brand, $clientName, $docnum);
            $html = sprintf('<p>Client <strong>%s</strong> has uploaded a signed copy of contract <strong>C-%s</strong>%s via the public link.</p><p>The contract is now active. See changes in the app.</p>',
                htmlspecialchars($clientName), htmlspecialchars($docnum), $project !== '' ? (' on project <strong>'.htmlspecialchars($project).'</strong>') : ''
            );

            send_admin_notification($pdo, $appConfig, $subject, $html);
        }

        // Log the activity
        log_activity($pdo, 'contract_signed', 'contract', (int)($contract['id'] ?? 0), (int)($contract['client_id'] ?? 0), 
            "Client $clientName signed contract C-$docnum via public link",
            ['project_code' => $project]
        );
    } catch (Throwable $e) {
        @error_log('[notify_admin_contract_signed] Error: ' . $e->getMessage());
    }
}

/**
 * Notify opted-in users that an invoice was paid via an automatic processor
 * (Stripe checkout, PaymentIntent, etc.). Manual payments never call this.
 *
 * Recipients are determined by invoice_payment_notification_recipients:
 * admin/owner users who have opted in receive all processor payments;
 * non-admin users who have opted in receive only payments on invoices they
 * created. The global notify_invoice_paid app-config key acts as a master
 * switch; turn it off to silence all processor-payment emails.
 *
 * @param PDO    $pdo
 * @param array  $appConfig
 * @param int    $invoiceId
 * @param float  $amount
 * @param string $status   'paid' or 'partial'
 */
function notify_admin_invoice_paid(PDO $pdo, array $appConfig, int $invoiceId, float $amount, string $status): void {
    try {
        if ($status === 'paid') {
            require_once __DIR__ . '/workforce_compensation.php';
            workforce_release_invoice_paid($pdo, $invoiceId, (int)($_SESSION['user']['id'] ?? 0));
        }

        $stmt = $pdo->prepare(
            'SELECT i.*, c.name AS client_name
             FROM invoices i
             JOIN clients c ON c.id = i.client_id
             WHERE i.id = ?'
        );
        $stmt->execute([$invoiceId]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$invoice) { return; }

        // Log the activity regardless of email opt-in.
        $invoiceLabel = pa_invoice_label_from_row($invoice + ['id' => $invoiceId]);
        $project      = (string)($invoice['project_code'] ?? '');
        $total        = (float)($invoice['total'] ?? 0);
        $statusText   = $status === 'paid' ? 'paid in full' : 'partially paid';

        log_activity(
            $pdo, 'invoice_paid', 'invoice', $invoiceId, (int)($invoice['client_id'] ?? 0),
            "Invoice $invoiceLabel $statusText via processor payment (\$$amount)",
            ['project_code' => $project, 'amount' => $amount, 'status' => $status, 'total' => $total]
        );

        // Master switch: if the global setting is off, skip all emails.
        if (!admin_invoice_paid_notification_enabled($appConfig, $invoice)) {
            return;
        }

        $recipients = invoice_payment_notification_recipients($pdo, $invoiceId);
        if (!$recipients) {
            return;
        }

        $brand      = (string)($appConfig['brand_name'] ?? 'Project Alpha');
        $clientName = (string)($invoice['client_name'] ?? 'Client');
        $subject = sprintf('[%s] Invoice %s %s ($%.2f)', $brand, $invoiceLabel, $statusText, $amount);
        $html = sprintf(
            '<p>Invoice <strong>%s</strong> for client <strong>%s</strong>%s has been %s via Stripe.</p>'
            . '<p>Payment amount: <strong>$%.2f</strong></p>'
            . '<p>Invoice total: <strong>$%.2f</strong></p>'
            . '<p>See details in the app.</p>',
            htmlspecialchars($invoiceLabel),
            htmlspecialchars($clientName),
            $project !== '' ? (' on project <strong>' . htmlspecialchars($project) . '</strong>') : '',
            $statusText, $amount, $total
        );

        foreach ($recipients as $recipientEmail) {
            [$ok, $error] = EmailService::sendEmail($recipientEmail, $subject, $html, [
                'document_type' => 'notification',
                'message_key'   => 'invoice-paid-processor:' . $invoiceId . ':' . hash('sha256', $recipientEmail . $subject),
            ]);
            if (!$ok) {
                @error_log('[notify_admin_invoice_paid] Failed for ' . $recipientEmail . ': ' . $error);
            }
        }
    } catch (Throwable $e) {
        @error_log('[notify_admin_invoice_paid] Error: ' . $e->getMessage());
    }
}

function notify_admin_project_invoice_paid(PDO $pdo, array $appConfig, int $projectInvoiceId, float $amount, string $status): void {
    try {
        $stmt = $pdo->prepare(
            'SELECT pi.*, p.name AS project_name, c.name AS client_name
             FROM project_invoices pi
             JOIN projects p ON p.id = pi.project_id
             LEFT JOIN clients c ON c.id = pi.primary_client_id
             WHERE pi.id = ?'
        );
        $stmt->execute([$projectInvoiceId]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$invoice) { return; }

        $statusText  = $status === 'paid' ? 'paid in full' : 'partially paid';
        $docnum      = (string)($invoice['doc_number'] ?? $projectInvoiceId);
        $projectName = (string)($invoice['project_name'] ?? 'Project');
        $total       = (float)($invoice['total'] ?? 0);

        // Always log the activity.
        log_activity(
            $pdo, 'project_invoice_paid', 'project_invoice', $projectInvoiceId,
            (int)($invoice['primary_client_id'] ?? 0),
            "Project invoice PI-$docnum $statusText via processor payment (\$$amount)",
            ['project_id' => (int)($invoice['project_id'] ?? 0), 'amount' => $amount, 'status' => $status, 'total' => $total]
        );

        // Master switch: both global settings must be on.
        if (!notification_setting_enabled($appConfig, 'notify_invoice_paid', true)
            || !notification_setting_enabled($appConfig, 'notify_invoice_paid_project', true)) {
            return;
        }

        $recipients = project_invoice_payment_notification_recipients($pdo, $projectInvoiceId);
        if (!$recipients) {
            return;
        }

        $brand      = (string)($appConfig['brand_name'] ?? 'Project Alpha');
        $clientName = (string)($invoice['client_name'] ?? 'Client');
        $subject = sprintf('[%s] Project invoice PI-%s %s ($%.2f)', $brand, $docnum, $statusText, $amount);
        $html = sprintf(
            '<p>Project invoice <strong>PI-%s</strong> for <strong>%s</strong> has been %s via Stripe.</p>'
            . '<p>Primary client: <strong>%s</strong></p>'
            . '<p>Payment amount: <strong>$%.2f</strong></p>'
            . '<p>Invoice total: <strong>$%.2f</strong></p>'
            . '<p>See details in the app.</p>',
            htmlspecialchars($docnum), htmlspecialchars($projectName), $statusText,
            htmlspecialchars($clientName), $amount, $total
        );

        foreach ($recipients as $recipientEmail) {
            [$ok, $error] = EmailService::sendEmail($recipientEmail, $subject, $html, [
                'document_type' => 'notification',
                'message_key'   => 'project-invoice-paid-processor:' . $projectInvoiceId . ':' . hash('sha256', $recipientEmail . $subject),
            ]);
            if (!$ok) {
                @error_log('[notify_admin_project_invoice_paid] Failed for ' . $recipientEmail . ': ' . $error);
            }
        }
    } catch (Throwable $e) {
        @error_log('[notify_admin_project_invoice_paid] Error: ' . $e->getMessage());
    }
}
