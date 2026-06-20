<?php
/**
 * Upload validation utilities for Project Alpha.
 *
 * Validates an uploaded file for upload errors, size limits, and MIME type.
 *
 * @param array  $file        The $_FILES entry for the upload.
 * @param array  $allowedMimes Allowed MIME types.
 * @param int    $maxBytes     Maximum allowed file size in bytes (default 5 MB).
 * @return string|null Error message or null if valid.
 */
function validate_upload(array $file, array $allowedMimes, int $maxBytes = 5 * 1024 * 1024): ?string
{
    if (!isset($file['error'])) {
        return 'No upload data provided';
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        return 'Upload failed with error code ' . $file['error'];
    }

    if ($file['size'] > $maxBytes) {
        return 'File too large (max ' . round($maxBytes / 1024 / 1024, 1) . 'MB)';
    }

    if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return 'Invalid upload source';
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);

    if (!in_array($mime, $allowedMimes, true)) {
        return 'Invalid file type. Allowed: ' . implode(', ', $allowedMimes);
    }

    // Optional malware scan via ClamAV daemon (fails open if unavailable)
    $clamavError = scan_clamav($file['tmp_name']);
    if ($clamavError !== null) {
        return $clamavError;
    }

    return null;
}

/**
 * Scan a file with ClamAV daemon via INSTREAM protocol.
 *
 * If CLAMAV_HOST env-var is not set or the daemon is unreachable, the scan
 * is skipped (fail-open) so uploads are not blocked by missing infrastructure.
 * Set CLAMAV_HOST=clamav (or the daemon's hostname/IP) to enable scanning.
 *
 * @param string $filepath Path to the file to scan
 * @return string|null Error message if malware detected or scan failed, null if clean/skipped
 */
function scan_clamav(string $filepath): ?string
{
    $host = getenv('CLAMAV_HOST');
    if (!$host) {
        // ClamAV not configured — fail open
        return null;
    }

    $port = (int)(getenv('CLAMAV_PORT') ?: 3310);
    $timeout = (int)(getenv('CLAMAV_TIMEOUT') ?: 10);

    $socket = @fsockopen($host, $port, $errno, $errstr, $timeout);
    if (!$socket) {
        // Daemon unreachable — fail open (log for admin awareness)
        @error_log('[clamav] Cannot connect to daemon at ' . $host . ':' . $port . ' — skipping scan');
        return null;
    }

    stream_set_timeout($socket, $timeout);

    // INSTREAM protocol: send chunks with length prefix, terminate with 0-length chunk
    fputs($socket, "zINSTREAM\0");
    $fh = @fopen($filepath, 'rb');
    if (!$fh) {
        fclose($socket);
        return 'Failed to read uploaded file for scanning';
    }
    while (!feof($fh)) {
        $chunk = fread($fh, 8192);
        if ($chunk === false || $chunk === '') {
            break;
        }
        fputs($socket, pack('N', strlen($chunk)) . $chunk);
    }
    fclose($fh);
    fputs($socket, pack('N', 0)); // zero-length chunk = end of stream

    $response = fgets($socket, 4096);
    fclose($socket);

    if ($response === false) {
        @error_log('[clamav] No response from daemon — skipping scan');
        return null;
    }

    $response = trim($response);
    // Response format: "stream: OK" or "stream: Trojan.EICAR.Test FOUND"
    if (strpos($response, 'OK') !== false) {
        return null; // File is clean
    }
    if (preg_match('/:\s*(.+)\s+FOUND$/', $response, $m)) {
        @error_log('[clamav] Malware detected: ' . $m[1]);
        return 'File rejected: malware detected (' . $m[1] . ')';
    }

    // Unknown response — fail open
    @error_log('[clamav] Unexpected response: ' . $response);
    return null;
}
