<?php
// src/utils/notifications.php
// Updated: uses unified system_audit table instead of activity_log
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/smtp.php';

/**
 * Log an activity event to the unified system_audit table
 * @param PDO $pdo
 * @param string $eventType Event type (e.g., 'quote_approved', 'contract_signed', 'invoice_paid')
 * @param string|null $documentType Document type ('quote', 'contract', 'invoice')
 * @param int|null $documentId Document ID
 * @param int|null $clientId Client ID
 * @param string $description Human-readable description
 * @param array $metadata Additional data to store
 */
function log_activity(PDO $pdo, string $eventType, ?string $documentType, ?int $documentId, ?int $clientId, string $description, array $metadata = []): void {
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
        $metaJson = !empty($metadata) ? json_encode($metadata) : null;
        
        // Use unified system_audit table (activity_log was merged into it)
        $stmt = $pdo->prepare('INSERT INTO system_audit (level, category, actor_type, actor_id, ip, message, payload, document_type, document_id, client_id, description, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            'info',
            $eventType,
            'user',
            null,
            $ip,
            $description,
            $metaJson,
            $documentType,
            $documentId,
            $clientId,
            $description,
            $ua
        ]);
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

/**
 * Get admin email address
 */
function get_admin_email(PDO $pdo, array $appConfig): string {
    $adminEmail = '';
    try {
        $r1 = $pdo->prepare('SELECT id, email FROM users WHERE id=1 LIMIT 1'); 
        $r1->execute(); 
        $u1 = $r1->fetch(PDO::FETCH_ASSOC);
        if ($u1 && !empty($u1['email'])) { 
            $adminEmail = (string)$u1['email']; 
        }
        if ($adminEmail === '') { 
            $adminEmail = (string)($pdo->query("SELECT email FROM users WHERE role='admin' ORDER BY id ASC LIMIT 1")->fetchColumn() ?: ''); 
        }
    } catch (Throwable $e) { /* ignore */ }
    if ($adminEmail === '') { 
        $adminEmail = (string)($appConfig['from_email'] ?? ''); 
    }
    return $adminEmail;
}

/**
 * Send admin notification email
 */
function send_admin_notification(PDO $pdo, array $appConfig, string $subject, string $html): bool {
    try {
        $adminEmail = get_admin_email($pdo, $appConfig);
        if ($adminEmail === '') { return false; }
        
        $brand = (string)($appConfig['brand_name'] ?? 'Project Alpha');
        $fromEmail = (string)($appConfig['from_email'] ?? 'no-reply@localhost');
        $fromName = (string)($appConfig['from_name'] ?? $brand);
        $cfg = get_smtp_config($appConfig);
        
        if (!empty($cfg['host'])) {
            [$ok, $err] = mailer_send($cfg, $adminEmail, $subject, $html, $fromEmail, $fromName, ($cfg['username'] ?: $fromEmail));
            if (!$ok) {
                [$ok, $err] = smtp_send($cfg, $adminEmail, $subject, $html, $fromEmail, $fromName, ($cfg['username'] ?: $fromEmail));
            }
            return $ok;
        } else {
            $headers = "MIME-Version: 1.0\r\nContent-type: text/html; charset=UTF-8\r\nFrom: ".($fromName?($fromName.' <'.$fromEmail.'>'):$fromEmail)."\r\n";
            return @mail($adminEmail, $subject, $html, $headers);
        }
    } catch (Throwable $e) {
        @error_log('[send_admin_notification] Error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Notify the first admin user that a client approved/denied a quote.
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
        
        $subject = sprintf('[%s] Client %s signed contract C-%s', $brand, $clientName, $docnum);
        $html = sprintf('<p>Client <strong>%s</strong> has uploaded a signed copy of contract <strong>C-%s</strong>%s via the public link.</p><p>The contract is now active. See changes in the app.</p>',
            htmlspecialchars($clientName), htmlspecialchars($docnum), $project !== '' ? (' on project <strong>'.htmlspecialchars($project).'</strong>') : ''
        );
        
        send_admin_notification($pdo, $appConfig, $subject, $html);
        
        log_activity($pdo, 'contract_signed', 'contract', (int)($contract['id'] ?? 0), (int)($contract['client_id'] ?? 0), 
            "Client $clientName signed contract C-$docnum via public link",
            ['project_code' => $project]
        );
    } catch (Throwable $e) {
        @error_log('[notify_admin_contract_signed] Error: ' . $e->getMessage());
    }
}

/**
 * Notify admin that an invoice was paid via public link (Stripe)
 * @param PDO $pdo
 * @param array $appConfig
 * @param int $invoiceId
 * @param float $amount
 * @param string $status 'paid' or 'partial'
 */
function notify_admin_invoice_paid(PDO $pdo, array $appConfig, int $invoiceId, float $amount, string $status): void {
    try {
        $stmt = $pdo->prepare('SELECT i.*, c.name as client_name FROM invoices i JOIN clients c ON c.id = i.client_id WHERE i.id = ?');
        $stmt->execute([$invoiceId]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$invoice) { return; }
        
        $brand = (string)($appConfig['brand_name'] ?? 'Project Alpha');
        $clientName = (string)($invoice['client_name'] ?? 'Client');
        $docnum = (string)($invoice['doc_number'] ?? $invoiceId);
        $project = (string)($invoice['project_code'] ?? '');
        $total = (float)($invoice['total'] ?? 0);
        
        $statusText = $status === 'paid' ? 'paid in full' : 'partially paid';
        $subject = sprintf('[%s] Invoice I-%s %s ($%.2f)', $brand, $docnum, $statusText, $amount);
        $html = sprintf('<p>Invoice <strong>I-%s</strong> for client <strong>%s</strong>%s has been %s.</p><p>Payment amount: <strong>$%.2f</strong></p><p>Invoice total: <strong>$%.2f</strong></p><p>See details in the app.</p>',
            htmlspecialchars($docnum), htmlspecialchars($clientName), 
            $project !== '' ? (' on project <strong>'.htmlspecialchars($project).'</strong>') : '',
            $statusText, $amount, $total
        );
        
        send_admin_notification($pdo, $appConfig, $subject, $html);
        
        log_activity($pdo, 'invoice_paid', 'invoice', $invoiceId, (int)($invoice['client_id'] ?? 0), 
            "Invoice I-$docnum $statusText via public link (\$$amount)",
            ['project_code' => $project, 'amount' => $amount, 'status' => $status, 'total' => $total]
        );
    } catch (Throwable $e) {
        @error_log('[notify_admin_invoice_paid] Error: ' . $e->getMessage());
    }
}
