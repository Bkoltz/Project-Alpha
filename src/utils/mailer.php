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
 * @return array [bool ok, string error]
 */
function mailer_send(array $cfg, string $to, string $subject, string $html, string $fromEmail, string $fromName = '', ?string $envelopeFrom = null): array
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
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $html;
        $mail->AltBody = strip_tags(str_replace(['<br>','<br/>','<br />'], "\n", $html));

        $mail->send();
        return [true, ''];
    } catch (PHPMailerException $e) {
        return [false, 'PHPMailer error: ' . $e->getMessage()];
    } catch (Throwable $e) {
        return [false, 'Mailer error: ' . $e->getMessage()];
    }
}