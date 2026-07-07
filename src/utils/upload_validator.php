<?php
/**
 * Validate an uploaded file and return a safe filename + extension on success.
 *
 * This is the preferred central upload entry point. It performs:
 *   - upload error / source checks
 *   - size validation
 *   - real MIME detection via finfo
 *   - extension allow-list matching against the detected MIME
 *   - optional ClamAV malware scan
 *   - generation of a random, collision-resistant target filename
 *
 * @param array      $file         The $_FILES entry for the upload.
 * @param array      $allowedMap   Map of allowed MIME type => extension(s). Extension may be
 *                                 a string or an array of accepted extensions for that MIME.
 * @param int        $maxBytes     Maximum allowed file size in bytes (default 8 MB).
 * @param string     $targetDir    Directory where the file will be stored (must exist/be writable).
 * @param string|null $error       Populated with an error message on failure.
 * @param array      $options      Optional hardening flags for higher-risk surfaces.
 * @return string|null The generated safe filename on success, or null on failure.
 */
function validate_and_store_upload(
    array $file,
    array $allowedMap,
    int $maxBytes,
    string $targetDir,
    ?string &$error = null,
    array $options = []
): ?string {
    if (!isset($file['error'])) {
        $error = 'No upload data provided';
        return null;
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $error = 'Upload failed with error code ' . $file['error'];
        return null;
    }

    if ((int)$file['size'] <= 0) {
        $error = 'File is empty';
        return null;
    }

    if ($file['size'] > $maxBytes) {
        $error = 'File too large (max ' . round($maxBytes / 1024 / 1024, 1) . 'MB)';
        return null;
    }

    if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        $error = 'Invalid upload source';
        return null;
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    $originalExt = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));

    if (!empty($options['reject_archives']) && upload_looks_like_archive($mime, $originalExt)) {
        $error = 'Archive files are not accepted';
        return null;
    }

    if (!array_key_exists($mime, $allowedMap)) {
        $error = 'Invalid file type';
        return null;
    }

    $allowedExts = $allowedMap[$mime];
    $exts = is_array($allowedExts) ? $allowedExts : [$allowedExts];

    if (!in_array($originalExt, $exts, true)) {
        $error = 'Invalid file extension';
        return null;
    }

    if (str_starts_with((string)$mime, 'image/')) {
        $imageError = validate_image_upload_shape((string)$file['tmp_name'], (int)($options['max_image_pixels'] ?? 0));
        if ($imageError !== null) {
            $error = $imageError;
            return null;
        }
    }

    if ($mime === 'application/pdf') {
        $pdfError = validate_pdf_upload_content(
            (string)$file['tmp_name'],
            !empty($options['require_pdf_header']),
            !empty($options['reject_pdf_active_content'])
        );
        if ($pdfError !== null) {
            $error = $pdfError;
            return null;
        }
    }

    $clamavError = scan_clamav($file['tmp_name'], !empty($options['clamav_required']));
    if ($clamavError !== null) {
        $error = $clamavError;
        return null;
    }

    if (!is_dir($targetDir)) {
        @mkdir($targetDir, 0755, true);
    }
    if (!is_dir($targetDir) || !is_writable($targetDir)) {
        $error = 'Upload storage directory is not writable';
        return null;
    }

    $safeExt = $exts[0];
    $filename = time() . '_' . bin2hex(random_bytes(8)) . '.' . $safeExt;
    $targetPath = rtrim($targetDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;

    if (!@move_uploaded_file($file['tmp_name'], $targetPath)) {
        $error = 'Failed to save uploaded file';
        return null;
    }

    return $filename;
}

function upload_looks_like_archive(string|false $mime, string $extension): bool
{
    $archiveExts = ['zip', 'rar', '7z', 'gz', 'gzip', 'bz2', 'xz', 'tar', 'tgz', 'tbz', 'jar', 'war', 'ear'];
    if (in_array($extension, $archiveExts, true)) {
        return true;
    }

    $archiveMimes = [
        'application/zip',
        'application/x-zip-compressed',
        'application/x-rar',
        'application/vnd.rar',
        'application/x-7z-compressed',
        'application/gzip',
        'application/x-gzip',
        'application/x-bzip2',
        'application/x-xz',
        'application/x-tar',
        'application/java-archive',
    ];

    return in_array((string)$mime, $archiveMimes, true);
}

function validate_image_upload_shape(string $path, int $maxPixels): ?string
{
    $info = @getimagesize($path);
    if ($info === false || empty($info[0]) || empty($info[1])) {
        return 'Invalid image file';
    }

    if ($maxPixels > 0 && ((int)$info[0] * (int)$info[1]) > $maxPixels) {
        return 'Image dimensions are too large';
    }

    return null;
}

function validate_pdf_upload_content(string $path, bool $requireHeader, bool $rejectActiveContent): ?string
{
    if ($requireHeader) {
        $head = @file_get_contents($path, false, null, 0, 1024);
        if (!is_string($head) || strpos($head, '%PDF-') === false) {
            return 'Invalid PDF file';
        }
    }

    if ($rejectActiveContent) {
        $contents = @file_get_contents($path);
        if (!is_string($contents)) {
            return 'Failed to inspect PDF file';
        }
        if (preg_match('/\/\s*(JavaScript|JS|Launch|EmbeddedFile|RichMedia|OpenAction)\b/i', $contents) === 1) {
            return 'PDF contains active or embedded content that is not accepted';
        }
    }

    return null;
}

/**
 * Validates an uploaded file for upload errors, size limits, and MIME type.
 *
 * @deprecated Use validate_and_store_upload() for new code. This function is
 *             kept only for backward compatibility with legacy call sites.
 *
 * @param array  $file        The $_FILES entry for the upload.
 * @param array  $allowedMimes Allowed MIME types.
 * @param int    $maxBytes     Maximum allowed file size in bytes (default 5 MB).
 * @return string|null Error message or null if valid.
 */
function validate_upload(array $file, array $allowedMimes, int $maxBytes = 5 * 1024 * 1024): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return 'Upload failed with error code ' . (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    }
    if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return 'Invalid upload';
    }
    if ((int)($file['size'] ?? 0) <= 0 || (int)$file['size'] > $maxBytes) {
        return 'File is empty or exceeds the size limit';
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file($file['tmp_name']);
    if (!in_array($mime, $allowedMimes, true)) {
        return 'File type is not allowed';
    }

    $mimeExtensions = [
        'application/pdf' => ['pdf'],
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/gif' => ['gif'],
        'text/csv' => ['csv'],
        'text/plain' => ['txt', 'csv'],
    ];
    $extension = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
    if (isset($mimeExtensions[$mime]) && !in_array($extension, $mimeExtensions[$mime], true)) {
        return 'File extension does not match its detected type';
    }

    $scanError = scan_clamav($file['tmp_name']);
    if ($scanError !== null) {
        return $scanError;
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
function scan_clamav(string $filepath, bool $required = false): ?string
{
    $host = getenv('CLAMAV_HOST');
    if (!$host) {
        if ($required) {
            return 'Virus scanning is required but not configured';
        }
        // ClamAV not configured — fail open
        return null;
    }

    $port = (int)(getenv('CLAMAV_PORT') ?: 3310);
    $timeout = (int)(getenv('CLAMAV_TIMEOUT') ?: 10);

    $socket = @fsockopen($host, $port, $errno, $errstr, $timeout);
    if (!$socket) {
        if ($required) {
            return 'Virus scanner is unavailable';
        }
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
        if ($required) {
            return 'Virus scanner did not respond';
        }
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
    if ($required) {
        return 'Virus scanner returned an unexpected response';
    }

    @error_log('[clamav] Unexpected response: ' . $response);
    return null;
}
