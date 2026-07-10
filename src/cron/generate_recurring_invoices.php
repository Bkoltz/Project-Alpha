<?php
// src/cron/generate_recurring_invoices.php
// Run this script via cron: php /var/www/src/cron/generate_recurring_invoices.php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../utils/cron_state.php';
require_once __DIR__ . '/../utils/recurring_billing.php';
require_once __DIR__ . '/../utils/project_invoice_billing.php';
require_once __DIR__ . '/../utils/email_identity.php';

$logPrefix = '[generate_recurring_invoices]';
$jobName = 'generate_recurring_invoices';

// Check if cron is enabled in settings
if (empty($appConfig['cron_enabled'])) {
    @error_log("$logPrefix Cron is disabled in settings. Skipping invoice generation.");
    cron_state_mark_success($pdo, $jobName, 'Cron disabled');
    exit(0);
}

@error_log("$logPrefix Starting invoice generation run at " . date('Y-m-d H:i:s'));

try {
    $today = date('Y-m-d');
    $invoicesGenerated = 0;
    $errors = 0;
    $catchUpPasses = 0;
    $maxCatchUpPasses = 36;

    do {
    $catchUpPasses++;

    // Refetch after each pass so contracts that remain overdue generate the
    // next missed invoice during the same cron run.
    $query = 'SELECT * FROM contracts
              WHERE status = ?
              AND contract_type = "long_term"
              AND next_invoice_date IS NOT NULL
              AND next_invoice_date <= ?
              AND (signed_pdf_path IS NOT NULL AND signed_pdf_path != \'\')
              ORDER BY next_invoice_date ASC';

    $stmt = $pdo->prepare($query);
    $stmt->execute(['active', $today]);
    $contracts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($contracts as $contract) {
        $invoiceId = generate_recurring_invoice($pdo, $contract, $appConfig);
        if ($invoiceId !== null) {
            $invoicesGenerated++;
            recurring_invoice_send_on_generate_if_enabled($pdo, $invoiceId, $appConfig);
        } elseif ($invoiceId === null) {
            // A null can also mean idempotency guard tripped or no invoice needed.
            // Track actual errors through helper logging rather than here.
            // We only count an error if the helper threw/logged failure; we
            // leave $errors untouched because failures are already logged.
        }
    }

    } while (!empty($contracts) && $catchUpPasses < $maxCatchUpPasses);

    if ($catchUpPasses >= $maxCatchUpPasses && !empty($contracts)) {
        $errors++;
        @error_log("$logPrefix Catch-up stopped at {$maxCatchUpPasses} passes to avoid an infinite loop.");
    }

    @error_log("$logPrefix Completed: $invoicesGenerated invoices generated across $catchUpPasses catch-up pass(es), $errors errors");

    $projectInvoicesGenerated = 0;
    try {
        $projectInvoicesGenerated = project_invoice_generate_due_monthly($pdo, $appConfig);
        @error_log("$logPrefix Project monthly billing generated {$projectInvoicesGenerated} project invoice(s)");
    } catch (Throwable $e) {
        $errors++;
        @error_log("$logPrefix Project monthly billing failed: " . $e->getMessage());
    }

    // Automatic invoice notification deliveries (due reminders & overdue weekly reminders)
    try {
        require_once __DIR__ . '/../utils/mailer.php';
        require_once __DIR__ . '/../utils/crypto.php';

        // helper: build SMTP config from app settings
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
        $fromName = pa_email_sender_name($appConfig);

        // small helper to create a short public link for invoice viewing
        $createPublicLink = function(int $invoiceId) use ($pdo, $appConfig) {
            $token = bin2hex(random_bytes(32));
            $days = (int)($appConfig['documents_valid_days'] ?? 14);
            $expiresAt = date('Y-m-d H:i:s', strtotime('+' . max(0,$days) . ' days'));
            $ins = $pdo->prepare('INSERT INTO public_links (document_type, document_id, token, expires_at, revoked, created_at) VALUES (?,?,?,?,0,NOW())');
            $ins->execute(['invoice', $invoiceId, $token, $expiresAt]);
            return $token;
        };

        // 1) 7-day due reminders
        if (!empty($appConfig['invoice_auto_send_due_7days'])) {
            $due7 = date('Y-m-d', strtotime('+7 days'));
            $stmt = $pdo->prepare("SELECT i.id,i.doc_number,i.invoice_type,i.total,i.due_date,c.email,c.name FROM invoices i JOIN clients c ON c.id=i.client_id WHERE i.due_date = ? AND i.status IN ('unpaid','partial') AND i.finalized_at IS NOT NULL AND i.collection_mode='direct'");
            $stmt->execute([$due7]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $inv) {
                $iid = (int)$inv['id'];
                // skip if we've already sent this reminder
                $chk = $pdo->prepare('SELECT COUNT(*) FROM invoice_notifications WHERE invoice_id=? AND notification_type=?');
                $chk->execute([$iid, 'due_7']);
                if ((int)$chk->fetchColumn() > 0) { continue; }

                // create short-lived public link
                $token = $createPublicLink($iid);
                $link = sprintf('%s/?page=public-doc&type=invoice&token=%s', rtrim(($appConfig['app_host'] ?? ''), '/'), rawurlencode($token));
                if ($link === '/?page=public-doc&type=invoice&token=' . rawurlencode($token)) {
                    // fallback to relative path if app_host not set
                    $link = '/?page=public-doc&type=invoice&token=' . rawurlencode($token);
                }

                $to = (string)$inv['email'];
                if ($to === '') continue;
                $invoiceLabel = pa_invoice_label_from_row($inv + ['id' => $iid]);
                $subject = sprintf('Invoice %s due %s', $invoiceLabel, date('M j, Y', strtotime($inv['due_date'])));
                $body = '<p>Dear ' . htmlspecialchars($inv['name'] ?? '') . ',</p>';
                $body .= '<p>This is a reminder that invoice <strong>' . htmlspecialchars($invoiceLabel) . '</strong> for <strong>$' . number_format((float)$inv['total'],2) . '</strong> is due on <strong>' . htmlspecialchars($inv['due_date']) . '</strong>.</p>';
                $body .= '<p>You can view this invoice here: <a href="' . htmlspecialchars($link) . '">' . htmlspecialchars($link) . '</a></p>';
                $body .= '<p>If you have already paid, please disregard this message.</p>';

                try {
                    [$ok, $err] = mailer_send($mailCfg, $to, $subject, $body, $fromEmail, $fromName, ($mailCfg['username'] ?: $fromEmail));
                    if ($ok) {
                        $insn = $pdo->prepare('INSERT IGNORE INTO invoice_notifications (invoice_id, notification_type, sent_at) VALUES (?,?,NOW())');
                        $insn->execute([$iid, 'due_7']);
                    } else {
                        @error_log("$logPrefix Failed to send due-7 reminder for invoice $iid: $err");
                    }
                } catch (Throwable $e) {
                    @error_log("$logPrefix Exception sending due-7 reminder for invoice $iid: " . $e->getMessage());
                }
            }
        }

        // 2) Weekly overdue reminders (at most once per 7 days for each invoice)
        if (!empty($appConfig['invoice_auto_send_overdue_weekly'])) {
            $todayDate = date('Y-m-d');
            $stmt = $pdo->prepare("SELECT i.id,i.doc_number,i.invoice_type,i.total,i.due_date,c.email,c.name FROM invoices i JOIN clients c ON c.id=i.client_id WHERE i.due_date < ? AND i.status IN ('unpaid','partial') AND i.finalized_at IS NOT NULL AND i.collection_mode='direct'");
            $stmt->execute([$todayDate]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $inv) {
                $iid = (int)$inv['id'];
                // check last sent timestamp
                $chk = $pdo->prepare('SELECT MAX(sent_at) AS last_sent FROM invoice_notifications WHERE invoice_id=? AND notification_type=?');
                $chk->execute([$iid, 'overdue_weekly']);
                $last = $chk->fetchColumn();
                $send = false;
                if ($last === null || $last === false) { $send = true; }
                else {
                    $lastTs = strtotime($last);
                    if ($lastTs === false) { $send = true; }
                    elseif ($lastTs <= strtotime('-7 days')) { $send = true; }
                }
                if (!$send) continue;

                // create public link for convenience
                $token = $createPublicLink($iid);
                $link = sprintf('%s/?page=public-doc&type=invoice&token=%s', rtrim(($appConfig['app_host'] ?? ''), '/'), rawurlencode($token));
                if ($link === '/?page=public-doc&type=invoice&token=' . rawurlencode($token)) {
                    $link = '/?page=public-doc&type=invoice&token=' . rawurlencode($token);
                }

                $to = (string)$inv['email'];
                if ($to === '') continue;
                $invoiceLabel = pa_invoice_label_from_row($inv + ['id' => $iid]);
                $subject = sprintf('Overdue invoice %s', $invoiceLabel);
                $body = '<p>Dear ' . htmlspecialchars($inv['name'] ?? '') . ',</p>';
                $body .= '<p>This is a reminder that invoice <strong>' . htmlspecialchars($invoiceLabel) . '</strong> for <strong>$' . number_format((float)$inv['total'],2) . '</strong> became overdue on <strong>' . htmlspecialchars($inv['due_date']) . '</strong>.</p>';
                $body .= '<p>Please view and pay the invoice here: <a href="' . htmlspecialchars($link) . '">' . htmlspecialchars($link) . '</a></p>';
                $body .= '<p>If you have already paid, please disregard this message.</p>';

                try {
                    [$ok, $err] = mailer_send($mailCfg, $to, $subject, $body, $fromEmail, $fromName, ($mailCfg['username'] ?: $fromEmail));
                    if ($ok) {
                        $insn = $pdo->prepare('INSERT IGNORE INTO invoice_notifications (invoice_id, notification_type, sent_at) VALUES (?,?,NOW())');
                        $insn->execute([$iid, 'overdue_weekly']);
                    } else {
                        @error_log("$logPrefix Failed to send overdue-weekly reminder for invoice $iid: $err");
                    }
                } catch (Throwable $e) {
                    @error_log("$logPrefix Exception sending overdue-weekly reminder for invoice $iid: " . $e->getMessage());
                }
            }
        }

        // 3) Auto-email newly-generated long-term and on-demand invoices (on generation)
        if (!empty($appConfig['invoice_auto_email_on_generate'])) {
            $stmt = $pdo->prepare("SELECT i.id,i.doc_number,i.invoice_type,i.total,i.due_date,c.email,c.name FROM invoices i JOIN clients c ON c.id=i.client_id WHERE i.invoice_type = 'long_term' AND i.status IN ('unpaid','partial') AND i.finalized_at IS NOT NULL AND i.collection_mode='direct' AND NOT EXISTS (SELECT 1 FROM invoice_notifications n WHERE n.invoice_id=i.id AND n.notification_type='on_generate')");
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $inv) {
                $iid = (int)$inv['id'];
                $to = (string)$inv['email'];
                if ($to === '') continue;

                $token = $createPublicLink($iid);
                $link = '/?page=public-doc&type=invoice&token=' . rawurlencode($token);
                $host = rtrim(($appConfig['app_host'] ?? ''), '/');
                if ($host !== '') { $link = $host . $link; }

                $invoiceLabel = pa_invoice_label_from_row($inv + ['id' => $iid]);
                $subject = sprintf('Invoice %s has been generated', $invoiceLabel);
                $body = '<p>Dear ' . htmlspecialchars($inv['name'] ?? '') . ',</p>';
                $body .= '<p>A new invoice <strong>' . htmlspecialchars($invoiceLabel) . '</strong> for <strong>$' . number_format((float)$inv['total'],2) . '</strong> has been generated';
                if (!empty($inv['due_date'])) {
                    $body .= ', due on <strong>' . htmlspecialchars($inv['due_date']) . '</strong>';
                }
                $body .= '.</p>';
                $body .= '<p>You can view and pay the invoice here: <a href="' . htmlspecialchars($link) . '">' . htmlspecialchars($link) . '</a></p>';
                $body .= '<p>Thank you for your business!</p>';

                try {
                    [$ok, $err] = mailer_send($mailCfg, $to, $subject, $body, $fromEmail, $fromName, ($mailCfg['username'] ?: $fromEmail));
                    if ($ok) {
                        $insn = $pdo->prepare('INSERT IGNORE INTO invoice_notifications (invoice_id, notification_type, sent_at) VALUES (?,?,NOW())');
                        $insn->execute([$iid, 'on_generate']);
                    } else {
                        @error_log("$logPrefix Failed to send on-generate email for invoice $iid: $err");
                    }
                } catch (Throwable $e) {
                    @error_log("$logPrefix Exception sending on-generate email for invoice $iid: " . $e->getMessage());
                }
            }
        }
    } catch (Throwable $e) {
        @error_log("$logPrefix Notification pass error: " . $e->getMessage());
    }

    cron_state_mark_success($pdo, $jobName, "Generated {$invoicesGenerated} recurring invoice(s), {$projectInvoicesGenerated} project invoice(s); {$errors} error(s); {$catchUpPasses} catch-up pass(es)");

    // Update last run timestamp in settings (legacy support)
    $configMount = '/var/www/config';
    $projectConfig = __DIR__ . '/../../config';
    $configDir = is_dir($configMount) ? $configMount : $projectConfig;
    $settingsFile = $configDir . '/settings.json';

    if (is_readable($settingsFile) && is_writable($settingsFile)) {
        $settings = json_decode(file_get_contents($settingsFile), true) ?: [];
        $settings['cron_last_run'] = date('Y-m-d H:i:s');
        @file_put_contents($settingsFile, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

} catch (Throwable $e) {
    @error_log("$logPrefix Fatal error: " . $e->getMessage());
    cron_state_mark_failure($pdo, $jobName, $e);
    exit(1);
}

exit(0);
