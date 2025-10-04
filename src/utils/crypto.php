<?php
// src/utils/crypto.php
// Simple AES-256-GCM encryption helpers using APP_ENCRYPTION_KEY

function crypto_get_key(): string {
    $k = getenv('APP_ENCRYPTION_KEY') ?: '';
    if ($k === '') { return ''; }
    // Normalize to 32 bytes using SHA-256
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