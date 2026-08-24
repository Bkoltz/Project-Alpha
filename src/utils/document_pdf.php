<?php

/**
 * Render a client document from the same shared view used by the UI and public
 * route. Failures are deliberately raised so callers can abort or retry rather
 * than sending an email that promises a missing attachment.
 *
 * @return array{filename:string,content:string,mime:string}
 */
function document_pdf_attachment(
    PDO $pdo,
    array $appConfig,
    string $type,
    int $id,
    string $documentNumber,
    int $maxBytes = 10485760
): array {
    $views = [
        'quote' => __DIR__ . '/../views/pages/quote/quote-details.php',
        'contract' => __DIR__ . '/../views/pages/contract/contract-details.php',
        'invoice' => __DIR__ . '/../views/pages/invoice/invoice-details.php',
        'project_invoice' => __DIR__ . '/../views/pages/project/project-invoice-details.php',
    ];
    if (!isset($views[$type]) || $id <= 0) {
        throw new InvalidArgumentException('Unsupported document PDF request.');
    }
    $view = $views[$type];
    if ($type === 'quote' || $type === 'contract') {
        $table = $type === 'quote' ? 'quotes' : 'contracts';
        $typeColumn = $type === 'quote' ? 'quote_type' : 'contract_type';
        $statement = $pdo->prepare("SELECT {$typeColumn} FROM {$table} WHERE id=? LIMIT 1");
        $statement->execute([$id]);
        if ((string)$statement->fetchColumn() === 'long_term') {
            $view = $type === 'quote'
                ? __DIR__ . '/../views/pages/quote/long-term-quote-details.php'
                : __DIR__ . '/../views/pages/contract/long-term-contract-details.php';
        }
    }

    $autoload = __DIR__ . '/../../vendor/autoload.php';
    if (!is_file($autoload)) {
        throw new RuntimeException('PDF generation dependency is unavailable.');
    }
    require_once $autoload;
    if (!class_exists('Dompdf\\Dompdf')) {
        throw new RuntimeException('PDF renderer is unavailable.');
    }
    if (!class_exists('Dompdf\\Cpdf')) {
        $cpdf = __DIR__ . '/../../vendor/dompdf/dompdf/lib/Cpdf.php';
        if (is_file($cpdf)) {
            require_once $cpdf;
        }
    }

    $previousId = $_GET['id'] ?? null;
    $_GET['id'] = (string)$id;
    ob_start();
    try {
        if (!defined('PDF_MODE')) {
            define('PDF_MODE', true);
        }
        require $view;
        $content = (string)ob_get_clean();
    } catch (Throwable $error) {
        ob_end_clean();
        throw $error;
    } finally {
        if ($previousId === null) {
            unset($_GET['id']);
        } else {
            $_GET['id'] = $previousId;
        }
    }

    $brand = htmlspecialchars((string)($appConfig['brand_name'] ?? 'Project Alpha'));
    $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Document - '
        . $brand . '</title><style>@page{margin:72px 54px}body{font-family:DejaVu Sans,Helvetica,Arial,sans-serif;font-size:12px;color:#111}</style>'
        . '</head><body>' . $content . '</body></html>';
    $options = new Dompdf\Options();
    $options->set('isRemoteEnabled', false);
    $options->set('isHtml5ParserEnabled', true);
    $projectRoot = realpath(__DIR__ . '/../..');
    if ($projectRoot) {
        $options->set('chroot', $projectRoot);
    }
    $dompdf = new Dompdf\Dompdf($options);
    if ($projectRoot) {
        $publicDir = realpath($projectRoot . DIRECTORY_SEPARATOR . 'public');
        $dompdf->setBasePath($publicDir ?: $projectRoot);
    }
    $dompdf->setProtocol('file://');
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('letter', 'portrait');
    $dompdf->render();
    $pdf = $dompdf->output();
    if ($pdf === '' || strlen($pdf) > $maxBytes) {
        throw new RuntimeException('Generated PDF is empty or exceeds the attachment size limit.');
    }

    $prefix = ['quote' => 'quote_Q-', 'contract' => 'contract_C-', 'invoice' => 'invoice_I-', 'project_invoice' => 'project_invoice_PI-'][$type];
    return [
        'filename' => $prefix . ($documentNumber !== '' ? $documentNumber : (string)$id) . '.pdf',
        'content' => $pdf,
        'mime' => 'application/pdf',
    ];
}
