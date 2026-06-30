<?php
// src/controllers/project/project_invoice_pdf.php

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/acl.php';

$autoload = __DIR__ . '/../../../vendor/autoload.php';
if (!is_file($autoload)) {
    http_response_code(500);
    echo 'Composer autoload not found.';
    exit;
}
require_once $autoload;
if (!class_exists('Dompdf\\Cpdf')) {
    $vendorDir = dirname($autoload);
    $cpdf = $vendorDir . '/dompdf/dompdf/lib/Cpdf.php';
    if (is_file($cpdf)) { require_once $cpdf; }
}

use Dompdf\Dompdf;
use Dompdf\Options;

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); echo 'Invalid id'; exit; }

if (!defined('PUBLIC_VIEW')) {
    $stmt = $pdo->prepare('SELECT project_id FROM project_invoices WHERE id=?');
    $stmt->execute([$id]);
    require_record_ownership($pdo, 'projects', (int)($stmt->fetchColumn() ?: 0));
}

ob_start();
if (!defined('PDF_MODE')) { define('PDF_MODE', true); }
$_GET['id'] = (string)$id;
require __DIR__ . '/../../views/pages/project/project-invoice-details.php';
$content = ob_get_clean();

$brand = htmlspecialchars($appConfig['brand_name'] ?? 'Project Alpha');
$html = "<!DOCTYPE html><html><head><meta charset=\"utf-8\"><title>Project Invoice - {$brand}</title><style>@page{margin:72px 54px 72px 54px}body{font-family:DejaVu Sans,Helvetica,Arial,sans-serif;font-size:12px;color:#111}.no-print{display:none!important}</style></head><body>{$content}</body></html>";

$options = new Options();
$options->set('isRemoteEnabled', true);
$options->set('isHtml5ParserEnabled', true);
$projectRoot = realpath(__DIR__ . '/../../..');
if ($projectRoot) { $options->set('chroot', $projectRoot); }
$dompdf = new Dompdf($options);
if ($projectRoot) {
    $publicDir = realpath($projectRoot . DIRECTORY_SEPARATOR . 'public');
    $dompdf->setBasePath($publicDir ?: $projectRoot);
}
$dompdf->setProtocol('file://');
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('letter', 'portrait');
$dompdf->render();

try {
    $canvas = $dompdf->getCanvas();
    $font = $dompdf->getFontMetrics()->getFont('Helvetica', 'normal');
    $canvas->page_text(54, 22, date('m/d/Y'), $font, 10, [0,0,0]);
    $canvas->page_text($canvas->get_width() - 140, 22, 'Page {PAGE_NUM} of {PAGE_COUNT}', $font, 10, [0,0,0]);
    $canvas->page_text(54, $canvas->get_height() - 30, 'Powered by Project Alpha', $font, 10, [0,0,0]);
} catch (Throwable $e) {}

$filename = 'project-invoice_PI-' . $id . '.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $filename . '"');
$dompdf->stream($filename, ['Attachment' => false]);
exit;
