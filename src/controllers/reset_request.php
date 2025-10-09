<?php
// src/controllers/reset_request.php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../utils/crypto.php';
require_once __DIR__ . '/../utils/mailer.php';
require_once __DIR__ . '/../utils/smtp.php';

// CSRF check (Symfony-backed)
require_once __DIR__ . '/../utils/csrf_sf.php';
$submitted = (string)($_POST['_token'] ?? ($_POST['csrf'] ?? ''));
if (!csrf_sf_is_valid('reset_request', $submitted)) {
  header('Location: /?page=reset-password&error=' . urlencode('Invalid request'));
  exit;
}

$email = trim((string)($_POST['email'] ?? ''));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
  header('Location: /?page=reset-password&error=' . urlencode('Enter a valid email'));
  exit;
}

// Always respond with generic message, but only generate token if user exists
$uid = 0;
try {
  $st = $pdo->prepare('SELECT id FROM users WHERE email=?');
  $st->execute([$email]);
  $uid = (int)($st->fetchColumn() ?: 0);
} catch (Throwable $e) { $uid = 0; }

if ($uid > 0) {
  try {
    // Create table if missing (best-effort, idempotent)
    $pdo->exec("CREATE TABLE IF NOT EXISTS password_resets (
      id INT AUTO_INCREMENT PRIMARY KEY,
      user_id INT NOT NULL,
      token VARCHAR(64) NOT NULL,
      expires_at DATETIME NOT NULL,
      attempts TINYINT(1) NOT NULL DEFAULT 0,
      used TINYINT(1) NOT NULL DEFAULT 0,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_resets_user (user_id),
      INDEX idx_resets_token (token),
      CONSTRAINT fk_resets_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    // Ensure attempts column exists in case of older installs
    try { $pdo->exec("ALTER TABLE password_resets ADD COLUMN attempts TINYINT(1) NOT NULL DEFAULT 0"); } catch (Throwable $e) { /* ignore exists */ }
  } catch (Throwable $e) { /* ignore */ }

  try {
    // Invalidate any old tokens for this user
    $pdo->prepare('UPDATE password_resets SET used=1 WHERE user_id=? AND used=0')->execute([$uid]);

    // Generate 6-digit numeric code
    $token = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $exp = date('Y-m-d H:i:s', time() + 10*60); // 10 minutes
    $pdo->prepare('INSERT INTO password_resets (user_id, token, expires_at) VALUES (?,?,?)')->execute([$uid, $token, $exp]);
    // Log masked token creation for debugging (do not log full token in production)
    if (function_exists('app_log')) {
      $masked = substr($token, 0, 2) . '****' . substr($token, -2);
      app_log('auth', 'reset token created', ['user_id'=>$uid, 'token_mask'=>$masked]);
    }

    // Compose email
    $brand = (string)($appConfig['brand_name'] ?? 'Project Alpha');
    $fromEmail = (string)($appConfig['from_email'] ?? 'no-reply@localhost');
    $fromName = (string)($appConfig['from_name'] ?? $brand);

    $scheme = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')) ? 'https' : 'http';
    $link = sprintf('%s://%s/?page=reset-verify&email=%s&token=%s',
      $scheme,
      $_SERVER['HTTP_HOST'] ?? 'localhost',
      rawurlencode($email), rawurlencode($token)
    );

    $subject = $brand . ' password reset';
    $html = '<p>Here is your one-time reset code (valid for 10 minutes):</p>'
          . '<p style="font-size:22px;font-weight:800;letter-spacing:3px">' . htmlspecialchars($token) . '</p>'
          . '<p>Go to the code entry page below and enter the 6-digit code:</p>'
          . '<p><a href="' . htmlspecialchars($link) . '">' . htmlspecialchars($link) . '</a></p>';

    $cfg = [
      'host' => (string)($appConfig['smtp_host'] ?? ''),
      'port' => (int)($appConfig['smtp_port'] ?? 587),
      'secure' => strtolower((string)($appConfig['smtp_secure'] ?? 'tls')),
      'username' => (string)($appConfig['smtp_username'] ?? ''),
      'password' => (string)(isset($appConfig['smtp_password_enc']) && is_string($appConfig['smtp_password_enc']) ? (crypto_decrypt($appConfig['smtp_password_enc']) ?: '') : ''),
    ];

    $envelopeFrom = $fromEmail;
    if (strtolower($cfg['host'] ?? '') === 'smtp.gmail.com' && !empty($cfg['username'])) {
      $envelopeFrom = $cfg['username'];
    }
    $ok = false; $err = '';
    if (!empty($cfg['host'])) {
      [$ok, $err] = mailer_send($cfg, $email, $subject, $html, $fromEmail, $fromName, $envelopeFrom);
      if (!$ok) {
        [$ok2, $err2] = smtp_send($cfg, $email, $subject, $html, $fromEmail, $fromName, $envelopeFrom);
        $ok = $ok2; $err = $ok2 ? '' : ($err2 ?: $err);
      }
    }
    if (!$ok) {
      // Fallback to PHP mail
      $headers = "MIME-Version: 1.0\r\nContent-type: text/html; charset=UTF-8\r\nFrom: ".$fromName.' <'.$fromEmail.'>'."\r\n";
      @mail($email, $subject, $html, $headers);
    }
  } catch (Throwable $e) {
    // do not reveal errors to user
  }
}

header('Location: /?page=reset-verify&email=' . urlencode($email) . '&sent=1');
exit;
