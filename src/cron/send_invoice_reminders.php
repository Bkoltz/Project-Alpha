<?php
// src/cron/send_invoice_reminders.php
// Standalone cron job to send automated invoice reminders (due-7 and weekly-overdue)
// Can be run independently or alongside generate_recurring_invoices.php
// Usage: php /var/www/src/cron/send_invoice_reminders.php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../utils/mailer.php';
require_once __DIR__ . '/../utils/crypto.php';

$logPrefix = '[send_invoice_reminders]';

// Check if either reminder is enabled
$due7Enabled = !empty($appConfig['invoice_auto_send_due_7days']);
$overdueEnabled = !empty($appConfig['invoice_auto_send_overdue_weekly']);

if (!$due7Enabled && !$overdueEnabled) {
    @error_log("$logPrefix Both reminder types are disabled. Exiting.");
    exit(0);
}

@error_log("$logPrefix Starting invoice reminder run at " . date('Y-m-d H:i:s'));

try {
    // Build SMTP config from app settings
    $smtpPass = '';
    if (!empty($appConfig['smtp_password_enc']) && is_string($appConfig['smtp_password_enc'])) {
        $encVal = $appConfig['smtp_password_enc'];
        if (strpos($encVal, 'plain::') === 0) {
            $smtpPass = substr($encVal, 7);
        } else {
            $pt = crypto_decrypt($encVal);
            if (is_string($pt)) { $smtpPass = $pt; }
        }
    }
    $mailCfg = [
        'host' => (string)($appConfig['smtp_host'] ?? ''),
        'port' => (int)($appConfig['smtp_port'] ?? 587),
        'secure' => strtolower((string)($appConfig['smtp_secure'] ?? 'tls')),
        'username' => (string)($appConfig['smtp_username'] ?? ''),
        'password' => $smtpPass,
    ];
    $fromEmail = (string)($appConfig['from_email'] ?? 'no-reply@localhost');
    $fromName = (string)($appConfig['from_name'] ?? ($appConfig['brand_name'] ?? 'Project Alpha'));

    if ($fromEmail === 'no-reply@localhost' && empty($appConfig['smtp_host'])) {
        @error_log("$logPrefix Warning: No SMTP configured and from_email not set. Reminders may fail to send.");
    }

    // Helper: create a public link for invoice viewing (short-lived)
    $createPublicLink = function(int $invoiceId) use ($pdo, $appConfig) {
        $token = bin2hex(random_bytes(16));
        $days = (int)($appConfig['documents_valid_days'] ?? 14);
        $expiresAt = date('Y-m-d H:i:s', strtotime('+' . max(0, $days) . ' days'));
        $ins = $pdo->prepare('INSERT INTO public_links (document_type, document_id, token, expires_at, revoked, created_at) VALUES (?,?,?,?,0,NOW())');
        $ins->execute(['invoice', $invoiceId, $token, $expiresAt]);
        return $token;
    };

    $remindersSent = 0;
    $remindersSkipped = 0;
    $errors = 0;

    // 1) 7-day due reminders (sent once per invoice)
    if ($due7Enabled) {
        $due7 = date('Y-m-d', strtotime('+7 days'));
        $stmt = $pdo->prepare("
            SELECT i.id, i.doc_number, i.total, i.due_date, c.email, c.name 
            FROM invoices i 
            JOIN clients c ON c.id = i.client_id 
            WHERE i.due_date = ? AND i.status IN ('unpaid', 'partial')
        ");
        $stmt->execute([$due7]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $inv) {
            $iid = (int)$inv['id'];
            
            // Check if already sent
            $chk = $pdo->prepare('SELECT COUNT(*) FROM invoice_notifications WHERE invoice_id = ? AND type = ?');
            $chk->execute([$iid, 'due_7']);
            if ((int)$chk->fetchColumn() > 0) {
                $remindersSkipped++;
                continue;
            }

            $to = (string)$inv['email'];
            if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
                $remindersSkipped++;
                continue;
            }

            try {
                // Create public link
                $token = $createPublicLink($iid);
                $baseUrl = rtrim(($appConfig['base_url'] ?? ''), '/');
                if ($baseUrl === '') $baseUrl = 'http://localhost';
                $link = $baseUrl . '/?page=public_doc&type=invoice&token=' . rawurlencode($token);

                // Build email
                $subject = sprintf('Invoice I-%s due %s', $inv['doc_number'] ?? $iid, date('M j, Y', strtotime($inv['due_date'])));
                $body = '<p>Dear ' . htmlspecialchars($inv['name'] ?? 'Valued Client') . ',</p>';
                $body .= '<p>This is a friendly reminder that invoice <strong>I-' . htmlspecialchars($inv['doc_number'] ?? $iid) . '</strong> ';
                $body .= 'for <strong>$' . number_format((float)$inv['total'], 2) . '</strong> is due on <strong>' . htmlspecialchars($inv['due_date']) . '</strong>.</p>';
                $body .= '<p><a href="' . htmlspecialchars($link) . '">View and pay invoice</a></p>';
                $body .= '<p>If you have already paid, please disregard this message. Thank you!</p>';

                // Send
                [$ok, $err] = mailer_send($mailCfg, $to, $subject, $body, $fromEmail, $fromName, ($mailCfg['username'] ?: $fromEmail));
                
                if ($ok) {
                    $insn = $pdo->prepare('INSERT INTO invoice_notifications (invoice_id, type, sent_at) VALUES (?, ?, NOW())');
                    $insn->execute([$iid, 'due_7']);
                    $remindersSent++;
                    @error_log("$logPrefix Sent due-7 reminder for invoice I-{$inv['doc_number']} to {$to}");
                } else {
                    $errors++;
                    @error_log("$logPrefix Failed to send due-7 reminder for invoice {$iid}: {$err}");
                }
            } catch (Throwable $e) {
                $errors++;
                @error_log("$logPrefix Exception sending due-7 reminder for invoice {$iid}: " . $e->getMessage());
            }
        }
    }

    // 2) Weekly overdue reminders (at most once per 7 days per invoice)
    if ($overdueEnabled) {
        $todayDate = date('Y-m-d');
        $stmt = $pdo->prepare("
            SELECT i.id, i.doc_number, i.total, i.due_date, c.email, c.name 
            FROM invoices i 
            JOIN clients c ON c.id = i.client_id 
            WHERE i.due_date < ? AND i.status IN ('unpaid', 'partial')
        ");
        $stmt->execute([$todayDate]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $inv) {
            $iid = (int)$inv['id'];
            
            // Check last sent timestamp
            $chk = $pdo->prepare('SELECT MAX(sent_at) AS last_sent FROM invoice_notifications WHERE invoice_id = ? AND type = ?');
            $chk->execute([$iid, 'overdue_weekly']);
            $last = $chk->fetchColumn();
            
            $shouldSend = false;
            if ($last === null || $last === false) {
                $shouldSend = true;
            } else {
                $lastTs = strtotime($last);
                if ($lastTs === false || $lastTs <= strtotime('-7 days')) {
                    $shouldSend = true;
                }
            }

            if (!$shouldSend) {
                $remindersSkipped++;
                continue;
            }

            $to = (string)$inv['email'];
            if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
                $remindersSkipped++;
                continue;
            }

            try {
                // Create public link
                $token = $createPublicLink($iid);
                $baseUrl = rtrim(($appConfig['base_url'] ?? ''), '/');
                if ($baseUrl === '') $baseUrl = 'http://localhost';
                $link = $baseUrl . '/?page=public_doc&type=invoice&token=' . rawurlencode($token);

                // Build email
                $subject = sprintf('Action Required: Overdue invoice I-%s', $inv['doc_number'] ?? $iid);
                $body = '<p>Dear ' . htmlspecialchars($inv['name'] ?? 'Valued Client') . ',</p>';
                $body .= '<p>We noticed that invoice <strong>I-' . htmlspecialchars($inv['doc_number'] ?? $iid) . '</strong> ';
                $body .= 'for <strong>$' . number_format((float)$inv['total'], 2) . '</strong> became overdue on <strong>' . htmlspecialchars($inv['due_date']) . '</strong>.</p>';
                $body .= '<p>Please review and pay the invoice at your earliest convenience: <a href="' . htmlspecialchars($link) . '">View invoice</a></p>';
                $body .= '<p>If payment has already been sent, thank you and please disregard this message.</p>';

                // Send
                [$ok, $err] = mailer_send($mailCfg, $to, $subject, $body, $fromEmail, $fromName, ($mailCfg['username'] ?: $fromEmail));
                
                if ($ok) {
                    $insn = $pdo->prepare('INSERT INTO invoice_notifications (invoice_id, type, sent_at) VALUES (?, ?, NOW())');
                    $insn->execute([$iid, 'overdue_weekly']);
                    $remindersSent++;
                    @error_log("$logPrefix Sent overdue-weekly reminder for invoice I-{$inv['doc_number']} to {$to}");
                } else {
                    $errors++;
                    @error_log("$logPrefix Failed to send overdue-weekly reminder for invoice {$iid}: {$err}");
                }
            } catch (Throwable $e) {
                $errors++;
                @error_log("$logPrefix Exception sending overdue-weekly reminder for invoice {$iid}: " . $e->getMessage());
            }
        }
    }

    @error_log("$logPrefix Completed: {$remindersSent} reminders sent, {$remindersSkipped} skipped, {$errors} errors");

    // Update last run timestamp in settings
    $configMount = '/var/www/config';
    $projectConfig = __DIR__ . '/../../config';
    $configDir = is_dir($configMount) ? $configMount : $projectConfig;
    $settingsFile = $configDir . '/settings.json';

    if (is_readable($settingsFile) && is_writable($settingsFile)) {
        $settings = json_decode(file_get_contents($settingsFile), true) ?: [];
        $settings['reminders_last_run'] = date('Y-m-d H:i:s');
        @file_put_contents($settingsFile, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

} catch (Throwable $e) {
    @error_log("$logPrefix Fatal error: " . $e->getMessage());
    exit(1);
}

exit(0);
