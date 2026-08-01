<?php
// src/controllers/public_view/public_doc_pdf.php
// Token-gated PDF view for public document links.

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/public_links.php';
require_once __DIR__ . '/../../utils/rate_limiter.php';
if (!rate_limit_check($pdo, 'public_doc_pdf', 30, 60)) {
    http_response_code(429);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Rate limited';
    exit;
}

$token = isset($_GET['token']) ? (string)$_GET['token'] : '';
if ($token === '') {
    http_response_code(400);
    echo 'Invalid link';
    exit;
}

try {
    try {
        $st = $pdo->prepare('SELECT document_type, document_id, expires_at, revoked, redirect, expire_when_paid FROM public_links WHERE token=? LIMIT 1');
        $st->execute([$token]);
    } catch (Throwable $e) {
        $st = $pdo->prepare('SELECT document_type, document_id, expires_at, revoked, redirect FROM public_links WHERE token=? LIMIT 1');
        $st->execute([$token]);
    }
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new Exception('Link not found');
    }
    if (in_array((string)$row['document_type'], ['quote', 'contract', 'invoice', 'project_invoice'], true)) {
        $terminalReason = pa_public_link_terminalize($pdo, (string)$row['document_type'], (int)$row['document_id']);
        if ($terminalReason !== null) {
            $st = $pdo->prepare('SELECT document_type, document_id, expires_at, revoked, redirect, expire_when_paid FROM public_links WHERE token=? LIMIT 1');
            $st->execute([$token]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                throw new Exception('Link not found');
            }
        }
    }

    // Do not convert the intentional dated paid-receipt window back into an
    // expire-on-payment link. Only legacy links with neither setting migrate.
    if (in_array((string)$row['document_type'], ['invoice', 'project_invoice'], true)
        && empty($row['expire_when_paid']) && empty($row['expires_at'])) {
        try {
            $pdo->exec("ALTER TABLE public_links ADD COLUMN expire_when_paid TINYINT(1) NOT NULL DEFAULT 0");
        } catch (Throwable $e) {
        }
        try {
            $pdo->exec("ALTER TABLE public_links MODIFY COLUMN expires_at DATETIME NULL");
            $up = $pdo->prepare('UPDATE public_links SET expire_when_paid=1, expires_at=NULL WHERE token=? AND revoked=0 AND document_type IN ("invoice","project_invoice")');
            $up->execute([$token]);
            $row['expire_when_paid'] = 1;
            $row['expires_at'] = null;
        } catch (Throwable $e) {
            // Keep serving the link with its existing expiration if the schema cannot be adjusted here.
        }
    }

    if ((int)($row['revoked'] ?? 0) === 1) {
        $redirect = trim((string)($row['redirect'] ?? ''));
        $redirectExpiresAt = !empty($row['expires_at']) ? strtotime((string)$row['expires_at']) : null;
        if ($redirect !== '' && ($redirectExpiresAt === null || $redirectExpiresAt > time())) {
            header('Location: ' . $redirect);
            exit;
        }
        throw new Exception('Link expired');
    }

    $type = (string)$row['document_type'];
    $id = (int)$row['document_id'];
    if (!in_array($type, ['quote', 'contract', 'invoice', 'project_invoice'], true) || $id <= 0) {
        throw new Exception('Invalid document');
    }

    if (!empty($row['expire_when_paid']) && in_array($type, ['invoice', 'project_invoice'], true)) {
        $table = $type === 'project_invoice' ? 'project_invoices' : 'invoices';
        $inv = $pdo->prepare("SELECT status FROM {$table} WHERE id=? LIMIT 1");
        $inv->execute([$id]);
        $status = strtolower((string)($inv->fetchColumn() ?: ''));
        if (in_array($status, ['paid', 'void'], true)) {
            throw new Exception('Link expired');
        }
    } elseif (!empty($row['expires_at']) && strtotime((string)$row['expires_at']) < time()) {
        throw new Exception('Link expired');
    }

    if ($type === 'invoice') {
        $eligibility = $pdo->prepare('SELECT status, finalized_at, collection_mode, recipient_presentation_mode, paid_at FROM invoices WHERE id=?');
        $eligibility->execute([$id]);
        $invoice = $eligibility->fetch(PDO::FETCH_ASSOC);
        if (!$invoice) {
            throw new Exception('Invoice is not public');
        }
        $collectionMode = trim((string)($invoice['collection_mode'] ?? ''));
        if ($collectionMode === '') {
            $collectionMode = 'direct';
        }
        $isPaidGeneralReceipt = pa_general_recipient_public_receipt_window_open($invoice);
        if (!in_array((string)$invoice['status'], ['sent','unpaid','partial','overdue'], true)
            && !$isPaidGeneralReceipt
            || empty($invoice['finalized_at']) || $collectionMode !== 'direct') {
            throw new Exception('Invoice is not public');
        }
    }
    if ($type === 'project_invoice') {
        $eligibility = $pdo->prepare('SELECT status, finalized_at FROM project_invoices WHERE id=?');
        $eligibility->execute([$id]);
        $projectInvoice = $eligibility->fetch(PDO::FETCH_ASSOC);
        if (!$projectInvoice || ($projectInvoice['status'] ?? '') === 'draft' || empty($projectInvoice['finalized_at'])) {
            throw new Exception('Project invoice is not public');
        }
    }

    if (!defined('PUBLIC_VIEW')) {
        define('PUBLIC_VIEW', true);
    }
    $_GET['id'] = (string)$id;

    if ($type === 'quote') {
        require __DIR__ . '/../quote/quote_pdf.php';
    } elseif ($type === 'contract') {
        require __DIR__ . '/../contract/contract_pdf.php';
    } else {
        if ($type === 'project_invoice') {
            require __DIR__ . '/../project/project_invoice_pdf.php';
            exit;
        }
        require __DIR__ . '/../invoice/invoice_pdf.php';
    }
} catch (Throwable $e) {
    http_response_code(404);
    @error_log('[public_doc_pdf] Error: ' . $e->getMessage());
    echo 'This link has expired or is no longer valid.';
    exit;
}
