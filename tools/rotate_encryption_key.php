<?php
// tools/rotate_encryption_key.php
//
// One-time migration: rotate off a legacy (compromised / committed) encryption key
// to a new key supplied via the APP_ENCRYPTION_KEY environment variable.
//
// What it does:
//   1. Reads the OLD key from the legacy location (settings.json 'encryption_key'
//      field) or from the OLD_ENCRYPTION_KEY env var.
//   2. Reads the NEW key from APP_ENCRYPTION_KEY (generate one first:
//        php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"
//      ).
//   3. Decrypts every 'enc::' value in each settings.json with the old key and
//      re-encrypts it with the new key.
//   4. Removes the plaintext 'encryption_key' field from settings.json.
//
// Usage (inside the web container or with PHP CLI on the host):
//   OLD_ENCRYPTION_KEY="<old base64 key>" APP_ENCRYPTION_KEY="<new base64 key>" \
//     php tools/rotate_encryption_key.php [--dry-run]
//
// If OLD_ENCRYPTION_KEY is not set, the script will look for 'encryption_key'
// inside the settings.json files themselves (the legacy storage location).

declare(strict_types=1);

$dryRun = in_array('--dry-run', $argv ?? [], true);

$candidates = [
    '/var/www/config/settings.json',
    __DIR__ . '/../config/settings.json',
    __DIR__ . '/../src/config/settings.json',
];

function key_from_b64_or_derive(string $k): string {
    $raw = base64_decode($k, true);
    if ($raw !== false && strlen($raw) === 32 && base64_encode($raw) === $k) {
        return $raw;
    }
    return hash('sha256', $k, true);
}

function dec(string $blob, string $key): ?string {
    if (strpos($blob, 'enc::') !== 0) return null;
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

function enc(string $plaintext, string $key): string {
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipher === false) {
        fwrite(STDERR, "FATAL: encryption failed\n");
        exit(1);
    }
    return 'enc::' . base64_encode($iv) . ':' . base64_encode($tag) . ':' . base64_encode($cipher);
}

$newKeyB64 = getenv('APP_ENCRYPTION_KEY') ?: '';
if ($newKeyB64 === '') {
    fwrite(STDERR, "FATAL: APP_ENCRYPTION_KEY is not set.\n");
    fwrite(STDERR, "Generate one:  php -r \"echo base64_encode(random_bytes(32)), PHP_EOL;\"\n");
    exit(1);
}
$newKey = key_from_b64_or_derive($newKeyB64);

$oldKeyInput = getenv('OLD_ENCRYPTION_KEY') ?: '';

$totalRotated = 0;
foreach ($candidates as $path) {
    if (!is_readable($path)) continue;
    $real = realpath($path);
    $data = json_decode((string)file_get_contents($real), true);
    if (!is_array($data)) {
        echo "SKIP $real (not valid JSON)\n";
        continue;
    }

    // Resolve old key: env var wins, else legacy field in this file
    $oldKeyB64 = $oldKeyInput !== '' ? $oldKeyInput : (string)($data['encryption_key'] ?? '');
    $oldKey = $oldKeyB64 !== '' ? key_from_b64_or_derive($oldKeyB64) : '';

    $changed = false;
    foreach ($data as $k => $v) {
        if (!is_string($v) || strpos($v, 'enc::') !== 0) continue;
        if ($oldKey === '') {
            echo "WARN $real: '$k' is encrypted but no old key available — leaving as-is\n";
            continue;
        }
        $pt = dec($v, $oldKey);
        if ($pt === null) {
            // Maybe already on the new key?
            if (dec($v, $newKey) !== null) {
                echo "OK   $real: '$k' already decryptable with NEW key — skipping\n";
                continue;
            }
            echo "WARN $real: '$k' failed to decrypt with old key — leaving as-is (re-enter via Settings UI)\n";
            continue;
        }
        $data[$k] = enc($pt, $newKey);
        $changed = true;
        $totalRotated++;
        echo "ROT  $real: '$k' re-encrypted with new key\n";
    }

    if (isset($data['encryption_key'])) {
        unset($data['encryption_key']);
        $changed = true;
        echo "DEL  $real: removed plaintext 'encryption_key' field\n";
    }

    if ($changed && !$dryRun) {
        file_put_contents($real, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        echo "SAVE $real\n";
    } elseif ($changed) {
        echo "DRY  $real (no write)\n";
    } else {
        echo "OK   $real (nothing to do)\n";
    }
}

echo "\nDone. Rotated $totalRotated value(s).\n";
echo "Next steps:\n";
echo "  1. Set APP_ENCRYPTION_KEY in your .env (NOT committed) and restart containers.\n";
echo "  2. Verify Stripe + SMTP secrets still decrypt (Settings page / email test).\n";
echo "  3. Treat the old key as burned — never reuse it.\n";
