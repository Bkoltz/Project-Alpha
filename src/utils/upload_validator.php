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

    return null;
}
