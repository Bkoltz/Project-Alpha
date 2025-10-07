<?php
// src/controllers/contract_pdf.php
// Generate a server-side PDF with accurate page numbers using Dompdf

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';

// Locate Composer autoload
$autoloadCandidates = [
    __DIR__ . '/../../vendor/autoload.php',
    dirname(__DIR__, 2) . '/vendor/autoload.php',
    dirname(__DIR__, 3) . '/vendor/autoload.php',
];
$autoload = '';
foreach ($autoloadCandidates as $cand) {
    if ($cand && @is_file($cand)) { $autoload = $cand; break; }
}
if ($autoload === '') {
    http_response_code(500);
    echo 'Composer autoload not found. Please run "composer install" at the project root.';
    exit;
}
require_once $autoload;

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
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('letter', 'portrait');
$dompdf->render();

// Add page header text: Page X of Y at top-right
$canvas = $dompdf->getCanvas();
$font = $dompdf->getFontMetrics()->getFont('Helvetica', 'normal');
$w = $canvas->get_width();
$pageText = 'Page {PAGE_NUM} of {PAGE_COUNT}';
$canvas->page_text($w - 140, 22, $pageText, $font, 10, [0,0,0]);

$filename = 'contract_C-' . ($id) . '.pdf';
$dompdf->stream($filename, ['Attachment' => false]);
exit;
