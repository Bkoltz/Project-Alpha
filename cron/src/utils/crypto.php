<?php
// cron/src/utils/crypto.php (mirror of src/utils/crypto.php)
// AES-256-GCM encryption helpers.
//
// SECURITY: The encryption key is loaded ONLY from the APP_ENCRYPTION_KEY
// environment variable. The legacy plaintext-key-in-settings.json fallback
// was removed (the repo is public; a committed key must be treated as burned).
// To rotate from the legacy key, run: php tools/rotate_encryption_key.php

function crypto_get_key(): string {
    $k = getenv('APP_ENCRYPTION_KEY') ?: '';
    if ($k === '') {
        @error_log('[crypto] APP_ENCRYPTION_KEY is not set; encryption/decryption unavailable');
        return '';
    }
    // Accept a base64-encoded 32-byte key directly; otherwise derive via SHA-256
    // (matches the legacy env-var behaviour so existing env-based deployments keep working).
    $raw = base64_decode($k, true);
    if ($raw !== false && strlen($raw) === 32 && base64_encode($raw) === $k) {
        return $raw;
    }
    return hash('sha256', $k, true);
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
