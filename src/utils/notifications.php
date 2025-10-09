<?php
// src/utils/notifications.php
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/smtp.php';

/**
 * Notify the first admin user that a client approved/denied a quote.
 * @param PDO $pdo
 * @param array $appConfig
 * @param array $quote  Quote row as associative array
 * @param string $action 'approve'|'deny'
 */
function notify_admin_quote_change(PDO $pdo, array $appConfig, array $quote, string $action): void {
    try {
        $adminEmail = '';
        $adminId = 0;
        try {
            // Prefer user id 1 if present
            $r1 = $pdo->prepare('SELECT id, email FROM users WHERE id=1 LIMIT 1'); $r1->execute(); $u1 = $r1->fetch(PDO::FETCH_ASSOC);
            if ($u1 && !empty($u1['email'])) { $adminId = (int)$u1['id']; $adminEmail = (string)$u1['email']; }
            if ($adminEmail === '') { $adminEmail = (string)($pdo->query("SELECT email FROM users WHERE role='admin' ORDER BY id ASC LIMIT 1")->fetchColumn() ?: ''); }
        } catch (Throwable $e) { /* ignore */ }
        if ($adminEmail === '') { $adminEmail = (string)($appConfig['from_email'] ?? ''); }
        if ($adminEmail === '') { return; }

        $brand = (string)($appConfig['brand_name'] ?? 'Project Alpha');
        $clientName = (string)($quote['client_name'] ?? ($quote['client_name'] ?? 'Client'));
        $docnum = (string)($quote['doc_number'] ?? $quote['id'] ?? '');
        $project = (string)($quote['project_code'] ?? '');
        $verb = $action === 'approve' ? 'approved' : 'denied';
        $subject = sprintf('[%s] Client %s %s quote Q-%s', $brand, $clientName, $verb, $docnum);
        $html = sprintf('<p>Client <strong>%s</strong> has %s quote <strong>Q-%s</strong>%s via the public link.</p><p>See changes in the app.</p>',
            htmlspecialchars($clientName), $verb, htmlspecialchars($docnum), $project !== '' ? (' on project <strong>'.htmlspecialchars($project).'</strong>') : ''
        );

        $fromEmail = (string)($appConfig['from_email'] ?? 'no-reply@localhost');
        $fromName = (string)($appConfig['from_name'] ?? $brand);

        // SMTP config for mailer_send
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
        $cfg = [
            'host' => (string)($appConfig['smtp_host'] ?? ''),
            'port' => (int)($appConfig['smtp_port'] ?? 587),
            'secure' => strtolower((string)($appConfig['smtp_secure'] ?? 'tls')),
            'username' => (string)($appConfig['smtp_username'] ?? ''),
            'password' => $pass,
        ];

        // Try PHPMailer then SMTP then mail()
        if (!empty($cfg['host'])) {
            try {
                [$ok, $err] = mailer_send($cfg, $adminEmail, $subject, $html, $fromEmail, $fromName, ($cfg['username'] ?: $fromEmail));
                if (!$ok) {
                    [$ok2, $err2] = smtp_send($cfg, $adminEmail, $subject, $html, $fromEmail, $fromName, ($cfg['username'] ?: $fromEmail));
                    $ok = $ok2; $err = $ok2 ? '' : ($err2 ?: $err);
                }
                if (!$ok) { app_log('email','admin notify failed',['err'=>$err]); }
            } catch (Throwable $e) { app_log('email','admin notify exception',['ex'=>$e->getMessage()]); }
        } else {
            try {
                $headers = "MIME-Version: 1.0\r\nContent-type: text/html; charset=UTF-8\r\nFrom: ".($fromName?($fromName.' <'.$fromEmail.'>'):$fromEmail)."\r\n";
                @mail($adminEmail, $subject, $html, $headers);
            } catch (Throwable $e) { /* ignore */ }
        }
    } catch (Throwable $e) {
        // Swallow notifications errors but log
        if (function_exists('app_log')) { app_log('notify','admin notify exception',['ex'=>$e->getMessage()]); }
    }
}
