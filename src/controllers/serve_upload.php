<?php
// src/controllers/serve_upload.php
// Securely serve files stored in src/uploads (fallback storage)

$base = __DIR__ . '/../uploads';
$fname = isset($_GET['file']) ? basename($_GET['file']) : '';
if ($fname === '') { http_response_code(404); exit; }
$path = realpath($base . '/' . $fname);
if ($path === false || strpos($path, realpath($base)) !== 0 || !is_file($path)) {
  http_response_code(404);
  exit;
}

$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$mime = 'application/octet-stream';
if ($ext === 'pdf') $mime = 'application/pdf';
elseif (in_array($ext, ['png','jpg','jpeg','webp','gif','svg'])) {
  $map = ['png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','webp'=>'image/webp','gif'=>'image/gif','svg'=>'image/svg+xml'];
  $mime = $map[$ext] ?? $mime;
}

$disposition = (isset($_GET['download']) && $_GET['download']=='1') ? 'attachment' : 'inline';
header('Content-Type: ' . $mime);
header('Content-Disposition: ' . $disposition . '; filename="' . rawurldecode($fname) . '"');
header('Content-Length: ' . filesize($path));
@readfile($path);
exit;
