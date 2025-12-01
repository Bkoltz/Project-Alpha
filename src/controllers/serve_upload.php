<?php
// src/controllers/serve_upload.php
// Securely serve files stored in config/uploads (preferred) or src/uploads (fallback)

$fnameRaw = isset($_GET['file']) ? (string)$_GET['file'] : '';
// sanitize and allow a single subdirectory (e.g. "signed_contracts/filename.pdf" or "organizations/filename.pdf")
$fnameRaw = str_replace(chr(0), '', $fnameRaw);
$fname = ltrim($fnameRaw, '/\\');
if ($fname === '' || strpos($fname, '..') !== false) { http_response_code(404); exit; }

$bases = [
  __DIR__ . '/../uploads', // src/uploads for contract files
  '/var/www/config/uploads', // fallback for Docker environments
];
$path = false;
foreach ($bases as $b) {
  $candidate = realpath($b . '/' . $fname);
  if ($candidate !== false && is_file($candidate)) { $path = $candidate; $base = realpath($b); break; }
}
if ($path === false) { http_response_code(404); exit; }
if (strpos($path, $base) !== 0) { http_response_code(404); exit; }

$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$mime = 'application/octet-stream';
if ($ext === 'pdf') $mime = 'application/pdf';
elseif (in_array($ext, ['png','jpg','jpeg','webp','gif','svg'])) {
  $map = ['png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','webp'=>'image/webp','gif'=>'image/gif','svg'=>'image/svg+xml'];
  $mime = $map[$ext] ?? $mime;
}

$disposition = (isset($_GET['download']) && $_GET['download']=='1') ? 'attachment' : 'inline';
header('Content-Type: ' . $mime);
header('Content-Disposition: ' . $disposition . '; filename="' . basename(rawurldecode($fname)) . '"');
header('Content-Length: ' . filesize($path));
@readfile($path);
exit;
