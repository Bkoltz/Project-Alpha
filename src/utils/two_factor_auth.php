<?php
// src/utils/two_factor_auth.php
// Two-Factor Authentication utility using TOTP (Time-based One-Time Password)

namespace App\Utils;

class TwoFactorAuth {
    
    /**
     * Generate a random base32 secret for TOTP
     * @return string Base32 encoded secret
     */
    public static function generateSecret(): string {
        $bytes = random_bytes(20); // 160 bits
        return self::base32Encode($bytes);
    }
    
    /**
     * Generate a TOTP code for the given secret at the current time
     * @param string $secret Base32 encoded secret
     * @param int|null $timestamp Unix timestamp (null for current time)
     * @param int $period Time period in seconds (default: 30)
     * @return string 6-digit code
     */
    public static function generateCode(string $secret, ?int $timestamp = null, int $period = 30): string {
        $timestamp = $timestamp ?? time();
        $timeCounter = floor($timestamp / $period);
        
        $secretKey = self::base32Decode($secret);
        $time = pack('N*', 0) . pack('N*', $timeCounter);
        $hash = hash_hmac('sha1', $time, $secretKey, true);
        
        $offset = ord($hash[19]) & 0xf;
        $code = (
            ((ord($hash[$offset + 0]) & 0x7f) << 24) |
            ((ord($hash[$offset + 1]) & 0xff) << 16) |
            ((ord($hash[$offset + 2]) & 0xff) << 8) |
            (ord($hash[$offset + 3]) & 0xff)
        ) % 1000000;
        
        return str_pad((string)$code, 6, '0', STR_PAD_LEFT);
    }
    
    /**
     * Verify a TOTP code against a secret
     * @param string $code User-provided 6-digit code
     * @param string $secret Base32 encoded secret
     * @param int $window Number of time periods to check before/after (default: 1)
     * @return bool True if code is valid
     */
    public static function verifyCode(string $code, string $secret, int $window = 1): bool {
        $code = preg_replace('/[^0-9]/', '', $code);
        if (strlen($code) !== 6) {
            return false;
        }
        
        $timestamp = time();
        $period = 30;
        
        // Check current time and surrounding windows
        for ($i = -$window; $i <= $window; $i++) {
            $testTime = $timestamp + ($i * $period);
            $validCode = self::generateCode($secret, $testTime, $period);
            if (hash_equals($validCode, $code)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Generate backup codes for account recovery
     * @param int $count Number of codes to generate (default: 8)
     * @return array Array of backup codes
     */
    public static function generateBackupCodes(int $count = 8): array {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            // Generate 8-character alphanumeric codes
            $bytes = random_bytes(6);
            $code = strtoupper(substr(bin2hex($bytes), 0, 8));
            // Format as XXXX-XXXX for readability
            $codes[] = substr($code, 0, 4) . '-' . substr($code, 4, 4);
        }
        return $codes;
    }
    
    /**
     * Hash a backup code for storage
     * @param string $code Plain backup code
     * @return string Hashed code
     */
    public static function hashBackupCode(string $code): string {
        return hash('sha256', strtoupper(str_replace('-', '', $code)));
    }
    
    /**
     * Verify a backup code against stored hashes
     * @param string $code User-provided backup code
     * @param array $hashedCodes Array of hashed backup codes
     * @return bool True if code matches one of the hashes
     */
    public static function verifyBackupCode(string $code, array $hashedCodes): bool {
        $hash = self::hashBackupCode($code);
        return in_array($hash, $hashedCodes, true);
    }
    
    /**
     * Generate a QR code data URI for authenticator apps
     * @param string $secret Base32 encoded secret
     * @param string $email User's email/identifier
     * @param string $issuer Application name (default: "Project Alpha")
     * @return string otpauth:// URI
     */
    public static function getOtpAuthUri(string $secret, string $email, string $issuer = 'Project Alpha'): string {
        $label = rawurlencode($issuer . ':' . $email);
        $params = http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => 6,
            'period' => 30
        ]);
        return "otpauth://totp/{$label}?{$params}";
    }
    
    /**
     * Base32 encode (RFC 4648)
     * @param string $data Binary data
     * @return string Base32 encoded string
     */
    private static function base32Encode(string $data): string {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $output = '';
        $v = 0;
        $vBits = 0;
        
        for ($i = 0, $len = strlen($data); $i < $len; $i++) {
            $v = ($v << 8) | ord($data[$i]);
            $vBits += 8;
            while ($vBits >= 5) {
                $vBits -= 5;
                $output .= $alphabet[($v >> $vBits) & 31];
            }
        }
        
        if ($vBits > 0) {
            $output .= $alphabet[($v << (5 - $vBits)) & 31];
        }
        
        return $output;
    }
    
    /**
     * Base32 decode (RFC 4648)
     * @param string $data Base32 encoded string
     * @return string Binary data
     */
    private static function base32Decode(string $data): string {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $data = strtoupper($data);
        $output = '';
        $v = 0;
        $vBits = 0;
        
        for ($i = 0, $len = strlen($data); $i < $len; $i++) {
            $char = $data[$i];
            if ($char === '=') {
                break;
            }
            $v = ($v << 5) | strpos($alphabet, $char);
            $vBits += 5;
            if ($vBits >= 8) {
                $vBits -= 8;
                $output .= chr(($v >> $vBits) & 255);
            }
        }
        
        return $output;
    }
}
