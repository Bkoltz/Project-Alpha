<?php
// src/utils/crypto.php
// AES-256-GCM encryption helpers. Uses a persistent key if available.

function crypto_load_persistent_key(): string {
    // Try primary config settings.json field 'encryption_key' (base64)
    $paths = [
        '/var/www/config/settings.json',
        __DIR__ . '/../config/settings.json',
        __DIR__ . '/../config/../../config/settings.json', // project config/settings.json
    ];
    foreach ($paths as $p) {
        if (@is_readable($p)) {
            $j = @file_get_contents($p);
            if ($j !== false) {
                $data = json_decode($j, true);
                if (is_array($data) && !empty($data['encryption_key'])) {
                    $ek = (string)$data['encryption_key'];
                    $raw = base64_decode($ek, true);
                    if ($raw !== false && strlen($raw) === 32) { return $ek; }
                    // if not base64, derive
                    return base64_encode(hash('sha256', $ek, true));
                }
            }
        }
    }
    return '';
}

function crypto_get_key(): string {
    // Prefer env var for power users
    $k = getenv('APP_ENCRYPTION_KEY') ?: '';
    if ($k !== '') {
        return hash('sha256', $k, true);
    }
    // Else load persistent key from settings.json
    $ekB64 = crypto_load_persistent_key();
    if ($ekB64 !== '') {
        $raw = base64_decode($ekB64, true);
        if ($raw !== false && strlen($raw) === 32) return $raw;
    }
    return '';
}

function crypto_encrypt(string $plaintext): ?string {
    $key = crypto_get_key();
    if ($key === '') return null;
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipher === false) return null;
    return 'enc::' . base64_encode($iv) . ':' . base64_encode($tag) . ':' . base64_encode($cipher);
}

function crypto_decrypt(string $blob): ?string {
    if (strpos($blob, 'enc::') !== 0) return null;
    $key = crypto_get_key();
    if ($key === '') return null;
    $parts = explode(':', substr($blob, 5), 3);
    if (count($parts) !== 3) return null;
    [$ivB64, $tagB64, $ctB64] = $parts;
    $iv = base64_decode($ivB64, true);
    $tag = base64_decode($tagB64, true);
    $ct = base64_decode($ctB64, true);
    if ($iv === false || $tag === false || $ct === false) return null;
    $pt = openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return $pt === false ? null : $pt;
}
