<?php
// src/controllers/public_view/public_doc_pdf.php
// Token-gated PDF view for public document links.

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

require_once __DIR__ . '/../../config/db.php';
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
    if ((int)($row['revoked'] ?? 0) === 1) {
        $redirect = trim((string)($row['redirect'] ?? ''));
        if ($redirect !== '') {
            header('Location: ' . $redirect);
            exit;
        }
        throw new Exception('Link expired');
    }

    $type = (string)$row['document_type'];
    $id = (int)$row['document_id'];
    if (!in_array($type, ['quote', 'contract', 'invoice'], true) || $id <= 0) {
        throw new Exception('Invalid document');
    }

    if (!empty($row['expire_when_paid']) && $type === 'invoice') {
        $inv = $pdo->prepare('SELECT status FROM invoices WHERE id=? LIMIT 1');
        $inv->execute([$id]);
        $status = strtolower((string)($inv->fetchColumn() ?: ''));
        if (in_array($status, ['paid', 'void'], true)) {
            throw new Exception('Link expired');
        }
    } elseif (!empty($row['expires_at']) && strtotime((string)$row['expires_at']) < time()) {
        throw new Exception('Link expired');
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
        require __DIR__ . '/../invoice/invoice_pdf.php';
    }
} catch (Throwable $e) {
    http_response_code(404);
    @error_log('[public_doc_pdf] Error: ' . $e->getMessage());
    echo 'This link has expired or is no longer valid.';
    exit;
}
