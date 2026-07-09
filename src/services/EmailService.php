<?php
// src/services/EmailService.php
// Centralizes SMTP config loading, password decryption, and email sending.

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../utils/crypto.php';
require_once __DIR__ . '/../utils/mailer.php';
require_once __DIR__ . '/../utils/smtp.php';
require_once __DIR__ . '/../utils/email_identity.php';

class EmailService {
    /**
     * Build an SMTP config array from appConfig, decrypting the password if present.
     *
     * @param array $appConfig
     * @return array{host:string,port:int,secure:string,username:string,password:string}
     */
    public static function getSmtpConfig(array $appConfig): array {
        $pass = '';
        if (!empty($appConfig['smtp_password_enc']) && is_string($appConfig['smtp_password_enc'])) {
            $encVal = $appConfig['smtp_password_enc'];
            if (strpos($encVal, 'plain::') === 0) {
                $pass = substr($encVal, 7);
            } else {
                $pt = crypto_decrypt($encVal);
                if (is_string($pt)) { $pass = $pt; }
            }
        }

        $secure = strtolower((string)($appConfig['smtp_secure'] ?? 'tls'));
        if (!in_array($secure, ['tls', 'ssl', 'none'], true)) {
            $secure = 'tls';
        }

        return [
            'host'     => (string)($appConfig['smtp_host'] ?? ''),
            'port'     => (int)($appConfig['smtp_port'] ?? 587),
            'secure'   => $secure,
            'username' => (string)($appConfig['smtp_username'] ?? ''),
            'password' => $pass,
        ];
    }

    /**
     * Determine the from email address using smtp_from_email fallback rules.
     *
     * @param array $appConfig
     * @param array $smtpConfig
     * @return string
     */
    public static function getFromEmail(array $appConfig, array $smtpConfig): string {
        $from = trim((string)($appConfig['smtp_from_email'] ?? ''));
        if ($from === '') {
            $from = trim((string)($appConfig['from_email'] ?? ''));
        }
        if ($from === '' && $smtpConfig['username'] !== '') {
            $from = $smtpConfig['username'];
        }
        if ($from === '') {
            $from = 'no-reply@localhost';
        }
        return $from;
    }

    /**
     * Determine the from name using smtp_from_name fallback rules.
     *
     * @param array $appConfig
     * @return string
     */
    public static function getFromName(array $appConfig): string {
        return pa_email_sender_name($appConfig, true);
    }

    public static function applyAutomatedNotice(string $body, bool $isHtml, array $appConfig): string {
        if (empty($appConfig['email_no_reply_notice_enabled'])) {
            return $body;
        }

        $notice = trim((string)($appConfig['email_no_reply_notice_text'] ?? ''));
        if ($notice === '') {
            $notice = 'This is an automated message. Please do not reply to this email.';
        }

        if ($isHtml) {
            return $body
                . '<hr style="border:0;border-top:1px solid #e5e7eb;margin:24px 0 12px">'
                . '<p style="color:#6b7280;font-size:12px;margin:0">'
                . htmlspecialchars($notice, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '</p>';
        }

        return rtrim($body) . "\n\n" . $notice;
    }

    /**
     * Send an HTML email using the configured SMTP server.
     *
     * Options:
     *   - from_email: override from email
     *   - from_name:  override from name
     *   - envelope_from: override envelope sender
     *   - attachments: array of ['filename','content','mime']
     *   - is_html:     whether body is HTML (default true)
     *
     * @param string $to
     * @param string $subject
     * @param string $body
     * @param array $options
     * @return array{0:bool,1:string} [ok, error]
     */
    public static function sendEmail(string $to, string $subject, string $body, array $options = []): array {
        global $appConfig;

        if (empty($to) || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return [false, 'Invalid recipient email'];
        }

        $cfg        = self::getSmtpConfig($appConfig);
        $fromEmail  = trim((string)($options['from_email'] ?? self::getFromEmail($appConfig, $cfg)));
        $fromName   = trim((string)($options['from_name']  ?? self::getFromName($appConfig)));
        $envelope   = trim((string)($options['envelope_from'] ?? ($cfg['username'] ?: $fromEmail)));
        $attachments = is_array($options['attachments'] ?? null) ? $options['attachments'] : [];
        $isHtml     = (bool)($options['is_html'] ?? true);
        $body       = self::applyAutomatedNotice($body, $isHtml, $appConfig);

        $sent = false;
        $err  = '';

        if (!empty($cfg['host'])) {
            $smtpHostL = strtolower($cfg['host']);
            if ($smtpHostL === 'smtp.gmail.com' && ($cfg['username'] === '' || $cfg['password'] === '')) {
                return [false, 'Gmail SMTP requires username and app password'];
            }

            // PHPMailer (supports attachments)
            [$ok, $msg] = mailer_send($cfg, $to, $subject, $body, $fromEmail, $fromName, $envelope, $attachments);
            if (!$ok) {
                // Fallback minimal SMTP without attachments
                [$ok2, $msg2] = smtp_send($cfg, $to, $subject, $body, $fromEmail, $fromName, $envelope);
                $ok = $ok2;
                $msg = $ok2 ? '' : ($msg2 ?: $msg);
            }
            $sent = $ok;
            $err  = $ok ? '' : ($msg ?: 'SMTP send failed');
        }

        if (!$sent) {
            // Fallback: PHP mail()
            $contentType = $isHtml ? 'text/html' : 'text/plain';
            $headers = "MIME-Version: 1.0\r\n" .
                       "Content-type: {$contentType}; charset=UTF-8\r\n" .
                       "From: " . ($fromName ? ($fromName . ' <' . $fromEmail . '>') : $fromEmail) . "\r\n";
            $mailOk = @mail($to, $subject, $body, $headers);
            $sent   = $mailOk;
            if (!$sent && $err === '') {
                $err = 'Email send failed';
            }
        }

        return [$sent, $err];
    }
}
