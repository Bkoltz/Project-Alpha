<?php
// src/controllers/settings_handler.php
// Save settings and handle logo upload, then redirect (PRG)

$configDir = __DIR__ . '/../../public/assests';
$uploadsDir = $configDir . '/uploads';
if (!is_dir($uploadsDir)) {
    @mkdir($uploadsDir, 0775, true);
}
$settingsFile = $configDir . '/settings.json';

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
];
if (is_readable($settingsFile)) {
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
    $allowed = [
        'image/png'       => '.png',
        'image/jpeg'      => '.jpg',
        'image/svg+xml'   => '.svg',
        'image/webp'      => '.webp',
    ];
    $mime = @mime_content_type($f['tmp_name']);
    if (isset($allowed[$mime])) {
        try {
            $ext = $allowed[$mime];
            $name = 'logo_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . $ext;
            $dest = $uploadsDir . '/' . $name;
            if (@move_uploaded_file($f['tmp_name'], $dest)) {
                $settings['logo_path'] = '/assests/uploads/' . $name;
            }
        } catch (Throwable $e) {
            // ignore upload errors; keep prior settings
        }
    }
}

@file_put_contents($settingsFile, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

header('Location: /?page=settings&saved=1');
exit;
