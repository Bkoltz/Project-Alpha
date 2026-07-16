<?php
// src/utils/two_factor_auth.php
// Two-Factor Authentication utility using TOTP (Time-based One-Time Password)

namespace App\Utils;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

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
     * Render an authenticator QR code locally. The TOTP secret is never sent to
     * a third-party QR endpoint or written to disk.
     */
    public static function getQrCodeSvg(string $otpAuthUri, int $size = 256): string {
        if (!str_starts_with($otpAuthUri, 'otpauth://totp/')) {
            throw new \InvalidArgumentException('Only TOTP otpauth URIs may be rendered.');
        }

        $renderer = new ImageRenderer(
            new RendererStyle(max(160, min(512, $size)), 4),
            new SvgImageBackEnd()
        );
        return (new Writer($renderer))->writeString($otpAuthUri);
    }

    public static function getQrCodeDataUri(string $otpAuthUri, int $size = 256): string {
        return 'data:image/svg+xml;base64,' . base64_encode(self::getQrCodeSvg($otpAuthUri, $size));
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
