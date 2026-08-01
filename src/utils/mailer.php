<?php
// src/utils/mailer.php
// Wrapper for sending email via PHPMailer when available, with fallback handled by callers.

// Attempt to load Composer autoloader if present
$autoloadPaths = [
    __DIR__ . '/../../vendor/autoload.php',
    __DIR__ . '/../../../vendor/autoload.php',
];
foreach ($autoloadPaths as $auto) {
    if (is_file($auto)) {
        require_once $auto;
        break;
    }
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * Send an HTML email using PHPMailer if available.
 *
 * @param array $cfg SMTP config: host, port, secure('tls'|'ssl'|'none'), username, password
 * @param string $to Recipient email
 * @param string $subject Subject line
 * @param string $html HTML body
 * @param string $fromEmail Envelope/from email
 * @param string $fromName From name
 * @param string|null $envelopeFrom Optional envelope sender override
 * @param bool $isHtml Whether the supplied body is HTML
 * @param string $messageId Optional stable RFC 5322 Message-ID
 * @param int $timeoutSeconds SMTP connect and command timeout
 * @return array [bool ok, string error]
 */
function mailer_send(
    array $cfg,
    string $to,
    string $subject,
    string $html,
    string $fromEmail,
    string $fromName = '',
    ?string $envelopeFrom = null,
    array $attachments = [], // each: ['filename'=>string,'content'=>string,'mime'=>string]
    bool $isHtml = true,
    string $messageId = '',
    int $timeoutSeconds = 300
): array
{
    if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
        return [false, 'PHPMailer not installed'];
    }

    try {
        $mail = new PHPMailer(true);

        // Transport
        if (!empty($cfg['host'])) {
            $mail->isSMTP();
            $mail->Host = (string)$cfg['host'];
            $mail->Port = (int)($cfg['port'] ?? 587);
            $secure = strtolower((string)($cfg['secure'] ?? 'tls'));
            if ($secure === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($secure === 'tls') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            } else {
                $mail->SMTPSecure = false; // no encryption
            }
            $mail->SMTPAuth = !empty($cfg['username']);
            $mail->Timeout = max(5, min(300, $timeoutSeconds));
            $mail->Timelimit = $mail->Timeout;
            if ($mail->SMTPAuth) {
                $mail->Username = (string)($cfg['username'] ?? '');
                $mail->Password = (string)($cfg['password'] ?? '');
            }
        }

        // From / to
        $fromEmail = $fromEmail !== '' ? $fromEmail : 'no-reply@localhost';
        $mail->setFrom($fromEmail, $fromName ?: '');
        $mail->addAddress($to);
        if ($envelopeFrom) {
            $mail->Sender = $envelopeFrom; // return-path / envelope sender
        }

        // Content
        $mail->isHTML($isHtml);
        $mail->Subject = $subject;
        $mail->Body = $html;
        if ($messageId !== '' && preg_match('/^<[A-Za-z0-9._+-]+@[A-Za-z0-9.-]+>$/', $messageId)) {
            $mail->MessageID = $messageId;
        }
        if ($isHtml) {
            $mail->AltBody = strip_tags(str_replace(['<br>','<br/>','<br />'], "\n", $html));
        }

        // Attachments
        foreach ($attachments as $att) {
            if (!is_array($att)) continue;
            $filename = (string)($att['filename'] ?? 'attachment');
            $content = (string)($att['content'] ?? '');
            if ($content === '') continue;
            $mime = (string)($att['mime'] ?? 'application/octet-stream');
            $mail->addStringAttachment($content, $filename, 'base64', $mime);
        }

        $mail->send();
        return [true, ''];
    } catch (PHPMailerException $e) {
        return [false, 'PHPMailer error: ' . $e->getMessage()];
    } catch (Throwable $e) {
        return [false, 'Mailer error: ' . $e->getMessage()];
    }
}
