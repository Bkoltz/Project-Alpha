<?php
// src/controllers/settings_handler.php
// Save settings and handle logo upload, then redirect (PRG)

// Prefer dedicated config mount if present
$configMount = '/var/www/config';
$configDir = is_dir($configMount) ? $configMount : (__DIR__ . '/../../public/assets');
$uploadsDir = $configDir . '/uploads';
if (!is_dir($uploadsDir)) {
    @mkdir($uploadsDir, 0775, true);
}
$settingsFile = $configDir . '/settings.json';

// default settings
$settings = [
    'brand_name' => 'Project Alpha',
    'logo_path'  => null,
    'from_name' => null,
    'from_address_line1' => null,
    'from_address_line2' => null,
    'from_city' => null,
    'from_state' => null,
    'from_postal' => null,
    'from_country' => null,
    'from_email' => null,
    'from_phone' => null,
'terms' => null,
    'net_terms_days' => 30,
    'payment_methods' => ['card','cash','bank_transfer'],
    // SMTP configuration (optional)
    'smtp_host' => null,
    'smtp_port' => 587,
    'smtp_secure' => 'tls', // tls|ssl|none
    'smtp_username' => null,
    'smtp_password_enc' => null,
];

// Read current settings. If public file is writable prefer it; if public exists but is not writable prefer internal fallback
$fallbackRead = __DIR__ . '/../config/settings.json';
if (is_readable($settingsFile) && is_writable(dirname($settingsFile))) {
    $data = json_decode(@file_get_contents($settingsFile), true);
    if (is_array($data)) {
        $settings = array_merge($settings, $data);
    }
} elseif (is_readable($fallbackRead)) {
    $data = json_decode(@file_get_contents($fallbackRead), true);
    if (is_array($data)) {
        $settings = array_merge($settings, $data);
    }
} elseif (is_readable($settingsFile)) {
    // last resort: public is readable but not writable and fallback doesn't exist; use public to avoid losing data
    $data = json_decode(@file_get_contents($settingsFile), true);
    if (is_array($data)) {
        $settings = array_merge($settings, $data);
    }
}

if (isset($_POST['brand_name'])) {
    $brand = trim((string)$_POST['brand_name']);
    if ($brand !== '') {
        // Basic length clamp
        $settings['brand_name'] = mb_substr($brand, 0, 100);
    }
}

// From and contact fields
foreach (['from_name','from_address_line1','from_address_line2','from_city','from_state','from_postal','from_country','from_email','from_phone'] as $k) {
    if (isset($_POST[$k])) {
        $val = trim((string)$_POST[$k]);
        $settings[$k] = $val !== '' ? mb_substr($val, 0, 200) : null;
    }
}
// Terms
if (isset($_POST['terms'])) {
    $t = trim((string)$_POST['terms']);
    $settings['terms'] = $t !== '' ? mb_substr($t, 0, 20000) : null;
}
// Billing defaults
if (isset($_POST['net_terms_days'])) {
    $n = (int)$_POST['net_terms_days'];
    if ($n < 0) $n = 0;
    $settings['net_terms_days'] = $n;
}

if (!empty($_FILES['logo']) && is_uploaded_file($_FILES['logo']['tmp_name'])) {
    $f = $_FILES['logo'];
    // Max 5 MB
    if (!empty($f['size']) && $f['size'] > 5 * 1024 * 1024) {
        // too large; ignore upload
    } else {
        $allowed = [
            'image/png'       => '.png',
            'image/jpeg'      => '.jpg',
            'image/webp'      => '.webp',
        ];
        $mime = @mime_content_type($f['tmp_name']);
        if (isset($allowed[$mime])) {
            try {
                $ext = $allowed[$mime];
                $name = 'logo_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . $ext;
                $dest = $uploadsDir . '/' . $name;
                if (@move_uploaded_file($f['tmp_name'], $dest)) {
                    $settings['logo_path'] = '/assets/uploads/' . $name;
                } else {
                    // fallback: try to save to internal src/uploads and serve via controller
                    $internal = __DIR__ . '/../uploads';
                    if (!is_dir($internal)) {
                        @mkdir($internal, 0775, true);
                    }
                    $internalDest = $internal . '/' . $name;
                    // try move, then rename, then copy as a last resort
                    if (@move_uploaded_file($f['tmp_name'], $internalDest) || @rename($f['tmp_name'], $internalDest) || @copy($f['tmp_name'], $internalDest)) {
                        @unlink($f['tmp_name']);
                        // serve through internal controller
                        $settings['logo_path'] = '/?page=serve-upload&file=' . rawurlencode($name);
                    }
                }
            } catch (Throwable $e) {
                // ignore upload errors; keep prior settings
            }
        }
    }
}

// Billing defaults
if (isset($_POST['net_terms_days'])) {
    $nd = (int)$_POST['net_terms_days'];
    if ($nd < 0) $nd = 0;
    $settings['net_terms_days'] = $nd;
}
// Suppress assets warning checkbox
$settings['suppress_assets_warning'] = !empty($_POST['suppress_assets_warning']) ? 1 : 0;
// Payment methods (textarea lines)
if (isset($_POST['payment_methods'])) {
    $lines = preg_split('/\r?\n/', (string)$_POST['payment_methods']);
    $methods = [];
    foreach ($lines as $ln) {
        $m = trim($ln);
        if ($m !== '') { $methods[] = $m; }
    }
    $settings['payment_methods'] = array_values(array_unique($methods));
}

// SMTP settings
if (isset($_POST['smtp_host'])) {
    $settings['smtp_host'] = trim((string)$_POST['smtp_host']) ?: null;
}
if (isset($_POST['smtp_port'])) {
    $settings['smtp_port'] = (int)$_POST['smtp_port'] ?: 587;
}
if (isset($_POST['smtp_secure'])) {
    $sec = strtolower((string)$_POST['smtp_secure']);
    if (!in_array($sec, ['tls','ssl','none'], true)) $sec = 'tls';
    $settings['smtp_secure'] = $sec;
}
if (isset($_POST['smtp_username'])) {
    $settings['smtp_username'] = trim((string)$_POST['smtp_username']) ?: null;
}
if (!empty($_POST['smtp_password'])) {
    require_once __DIR__ . '/../utils/crypto.php';
    $enc = crypto_encrypt((string)$_POST['smtp_password']);
    if ($enc) { $settings['smtp_password_enc'] = $enc; }
}

// Merge with existing file on target before writing to avoid overwriting unrelated fields
$target = $settingsFile;
$existing = [];
if (is_readable($target)) {
    $existing = json_decode(@file_get_contents($target), true) ?: [];
}
$merged = array_merge($existing, $settings);
$payload = json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
$ok = @file_put_contents($target, $payload);
if ($ok === false) {
    // attempt permission fix (best-effort)
    if (is_dir(dirname($settingsFile))) {
        @chmod(dirname($settingsFile), 0775);
    }
    // Try fallback to repo config (src/config/settings.json) and merge similarly
    $fallback = __DIR__ . '/../config/settings.json';
    $existingFb = [];
    if (is_readable($fallback)) {
        $existingFb = json_decode(@file_get_contents($fallback), true) ?: [];
    }
    $mergedFb = array_merge($existingFb, $settings);
    $fbPayload = json_encode($mergedFb, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $fbOk = @file_put_contents($fallback, $fbPayload);
    if ($fbOk !== false) {
        header('Location: /?page=settings&saved=1&fallback=1');
        exit;
    }

    // Redirect back with error flag
    $err = rawurlencode('failed-to-write-settings');
    header('Location: /?page=settings&saved=0&error=' . $err);
    exit;
}

header('Location: /?page=settings&saved=1');
exit;
