<?php
// src/controllers/contract_pdf.php
// Generate a server-side PDF with accurate page numbers using Dompdf

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';

// Locate Composer autoload by walking up to the project root
$autoload = '';
$dir = __DIR__;
for ($i = 0; $i < 8; $i++) {
    $candidate = $dir . '/vendor/autoload.php';
    if (@is_file($candidate)) {
        $autoload = $candidate;
        break;
    }
    $parent = dirname($dir);
    if ($parent === $dir) { // reached filesystem root
        break;
    }
    $dir = $parent;
}
if ($autoload === '') {
    http_response_code(500);
    echo 'Composer autoload not found. Please run "composer install" at the project root.';
    exit;
}
require_once $autoload;
// Some Composer builds may not autoload Dompdf's legacy Cpdf class. Fallback-load it if needed.
if (!class_exists('Dompdf\\Cpdf')) {
    $vendorDir = dirname($autoload);
    $cpdf = $vendorDir . '/dompdf/dompdf/lib/Cpdf.php';
    if (is_file($cpdf)) { require_once $cpdf; }
}

use Dompdf\Dompdf;
use Dompdf\Options;

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); echo 'Invalid id'; exit; }

// Build HTML by invoking the existing view in PDF mode (hides toolbar)
ob_start();
define('PDF_MODE', true);
$_GET['id'] = (string)$id;
require __DIR__ . '/../views/pages/contract-print.php';
$content = ob_get_clean();

$brand = htmlspecialchars($appConfig['brand_name'] ?? 'Project Alpha');
$html = "<!DOCTYPE html>\n<html><head><meta charset=\"utf-8\"><title>Contract - {$brand}</title>
<style>
  @page { margin: 72px 54px 72px 54px; }
  body { font-family: DejaVu Sans, Helvetica, Arial, sans-serif; font-size: 12px; color: #111; }
</style>
</head><body>" . $content . "</body></html>";

$options = new Options();
$options->set('isRemoteEnabled', true);
$options->set('isHtml5ParserEnabled', true);
// Allow Dompdf to access local files under the project directory (for logos, etc.)
$projectRoot = realpath(__DIR__ . '/..' . '/..');
if ($projectRoot) {
    $options->set('chroot', $projectRoot);
}
$dompdf = new Dompdf($options);
// Set base path and protocol so relative/local file URLs resolve
if ($projectRoot) {
    $publicDir = realpath($projectRoot . DIRECTORY_SEPARATOR . 'public');
    if ($publicDir) {
        $dompdf->setBasePath($publicDir);
    } else {
        $dompdf->setBasePath($projectRoot);
    }
}
$dompdf->setProtocol('file://');

$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('letter', 'portrait');
$dompdf->render();

// Add page header text: Page X of Y at top-right
$canvas = $dompdf->getCanvas();
$font = $dompdf->getFontMetrics()->getFont('Helvetica', 'normal');
$w = $canvas->get_width();
// Header: date at top-left, page X of Y at top-right
$dateStr = date('m/d/Y');
$canvas->page_text(54, 22, $dateStr, $font, 10, [0,0,0]);
$pageText = 'Page {PAGE_NUM} of {PAGE_COUNT}';
$canvas->page_text($w - 140, 22, $pageText, $font, 10, [0,0,0]);
// Footer: powered-by text at bottom-left on every page
$h = $canvas->get_height();
$canvas->page_text(54, $h - 30, 'Powered by Project Alpha', $font, 10, [0,0,0]);

$filename = 'contract_C-' . ($id) . '.pdf';
$dompdf->stream($filename, ['Attachment' => false]);
exit;
