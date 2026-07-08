<?php

declare(strict_types=1);

const PA_PUBLIC_LINK_TERMINAL_STATUS_DAYS = 7;

function pa_public_link_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    try {
        $pdo->exec('ALTER TABLE public_links MODIFY COLUMN expires_at DATETIME NULL');
    } catch (Throwable $e) {
    }
    try {
        $pdo->exec('ALTER TABLE public_links ADD COLUMN expire_when_paid TINYINT(1) NOT NULL DEFAULT 0');
    } catch (Throwable $e) {
    }
    try {
        $pdo->exec('ALTER TABLE public_links ADD COLUMN redirect VARCHAR(500) NULL');
    } catch (Throwable $e) {
    }

    $done = true;
}

function pa_public_link_redirect_path(string $type, string $reason): string
{
    return '/?page=public-redirect&type=' . rawurlencode($type) . '&reason=' . rawurlencode($reason);
}

function pa_public_link_terminal_reason(PDO $pdo, string $type, int $id): ?string
{
    if ($id <= 0) {
        return null;
    }

    try {
        if ($type === 'quote') {
            $stmt = $pdo->prepare('SELECT status FROM quotes WHERE id=? LIMIT 1');
            $stmt->execute([$id]);
            $status = strtolower((string)($stmt->fetchColumn() ?: ''));
            return match ($status) {
                'approved' => 'approved',
                'rejected' => 'denied',
                default => null,
            };
        }

        if ($type === 'contract') {
            $stmt = $pdo->prepare('SELECT status, signed_pdf_path FROM contracts WHERE id=? LIMIT 1');
            $stmt->execute([$id]);
            $contract = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$contract) {
                return null;
            }
            $status = strtolower((string)($contract['status'] ?? ''));
            if (!empty($contract['signed_pdf_path'])) {
                return 'signed';
            }
            return match ($status) {
                'active' => 'signed',
                'completed' => 'completed',
                'denied' => 'denied',
                'cancelled' => 'cancelled',
                'void' => 'void',
                default => null,
            };
        }

        if ($type === 'invoice' || $type === 'project_invoice') {
            $table = $type === 'project_invoice' ? 'project_invoices' : 'invoices';
            $stmt = $pdo->prepare("SELECT status FROM {$table} WHERE id=? LIMIT 1");
            $stmt->execute([$id]);
            $status = strtolower((string)($stmt->fetchColumn() ?: ''));
            return match ($status) {
                'paid' => 'paid',
                'void' => 'void',
                'cancelled' => 'cancelled',
                'denied' => 'denied',
                default => null,
            };
        }
    } catch (Throwable $e) {
        @error_log('[public_links] terminal reason failed: ' . $e->getMessage());
    }

    return null;
}

function pa_public_link_terminalize(PDO $pdo, string $type, int $id, ?string $reason = null): ?string
{
    if (!in_array($type, ['quote', 'contract', 'invoice', 'project_invoice'], true) || $id <= 0) {
        return null;
    }

    $reason = $reason ?: pa_public_link_terminal_reason($pdo, $type, $id);
    if ($reason === null) {
        return null;
    }

    if (!$pdo->inTransaction()) {
        pa_public_link_ensure_schema($pdo);
    }
    $redirect = pa_public_link_redirect_path($type, $reason);
    try {
        $stmt = $pdo->prepare(
            'UPDATE public_links
             SET revoked = 1,
                 redirect = ?,
                 expires_at = DATE_ADD(NOW(), INTERVAL ' . PA_PUBLIC_LINK_TERMINAL_STATUS_DAYS . ' DAY),
                 expire_when_paid = 0
             WHERE document_type = ?
               AND document_id = ?
               AND revoked = 0'
        );
        $stmt->execute([$redirect, $type, $id]);
    } catch (Throwable $e) {
        @error_log('[public_links] terminalize failed: ' . $e->getMessage());
    }

    return $reason;
}

/**
 * @return array{state:string,label:string,detail:string,color:string,background:string,border:string}
 */
function pa_public_link_status(PDO $pdo, string $type, int $id): array
{
    pa_public_link_terminalize($pdo, $type, $id);

    $fallback = [
        'state' => 'not_accessible',
        'label' => 'Not accessible',
        'detail' => 'No active public link is available.',
        'color' => '#374151',
        'background' => '#f3f4f6',
        'border' => '#d1d5db',
    ];

    try {
        pa_public_link_ensure_schema($pdo);
        $stmt = $pdo->prepare(
            'SELECT token, expires_at, expire_when_paid, revoked, redirect, created_at
             FROM public_links
             WHERE document_type = ? AND document_id = ?
             ORDER BY
               CASE
                 WHEN revoked = 0 AND (expires_at IS NULL OR expires_at > NOW()) THEN 0
                 WHEN revoked = 1 AND redirect IS NOT NULL AND redirect <> "" AND (expires_at IS NULL OR expires_at > NOW()) THEN 1
                 ELSE 2
               END,
               created_at DESC,
               id DESC
             LIMIT 1'
        );
        $stmt->execute([$type, $id]);
        $link = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$link) {
            return $fallback;
        }

        $expiresAt = trim((string)($link['expires_at'] ?? ''));
        $expiresTs = $expiresAt !== '' ? strtotime($expiresAt) : false;
        $isExpired = $expiresTs !== false && $expiresTs <= time();
        $isRedirected = (int)($link['revoked'] ?? 0) === 1 && trim((string)($link['redirect'] ?? '')) !== '';

        if ((int)($link['revoked'] ?? 0) === 0 && !$isExpired) {
            return [
                'state' => 'accessible',
                'label' => 'Accessible',
                'detail' => !empty($link['expire_when_paid'])
                    ? 'Client can open this link until the invoice is paid in full.'
                    : ($expiresAt !== '' ? 'Client can open this link until ' . date('M j, Y g:i A', strtotime($expiresAt)) . '.' : 'Client can open this link.'),
                'color' => '#065f46',
                'background' => '#ecfdf5',
                'border' => '#a7f3d0',
            ];
        }

        if ($isRedirected && !$isExpired) {
            return [
                'state' => 'redirected',
                'label' => 'Redirected',
                'detail' => $expiresAt !== ''
                    ? 'Client sees a status message until ' . date('M j, Y g:i A', strtotime($expiresAt)) . '.'
                    : 'Client sees a status message.',
                'color' => '#1e40af',
                'background' => '#eff6ff',
                'border' => '#bfdbfe',
            ];
        }
    } catch (Throwable $e) {
        @error_log('[public_links] status failed: ' . $e->getMessage());
    }

    return $fallback;
}

function pa_public_link_status_badge_html(PDO $pdo, string $type, int $id): string
{
    $status = pa_public_link_status($pdo, $type, $id);
    return '<span style="display:inline-flex;align-items:center;gap:6px;margin-left:8px;padding:3px 8px;border:1px solid '
        . htmlspecialchars($status['border'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        . ';border-radius:999px;background:'
        . htmlspecialchars($status['background'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        . ';color:'
        . htmlspecialchars($status['color'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        . ';font-weight:600" title="'
        . htmlspecialchars($status['detail'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        . '">Public Link: '
        . htmlspecialchars($status['label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        . '</span>';
}
