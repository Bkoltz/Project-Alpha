<?php
// src/utils/project_invoice_billing.php
require_once __DIR__ . '/../services/EmailService.php';
require_once __DIR__ . '/public_links.php';
require_once __DIR__ . '/invoice_content_links.php';

function project_invoice_table_has_column(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . ':' . $column;
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) {
        return $cache[$key] = false;
    }
    try {
        $stmt = $pdo->prepare('
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?
            LIMIT 1
        ');
        $stmt->execute([$table, $column]);
        return $cache[$key] = $stmt->fetchColumn() !== false;
    } catch (Throwable $e) {
        return $cache[$key] = false;
    }
}

function project_invoice_period_for_date(?string $date = null, bool $previousMonth = false): array
{
    $ts = strtotime($date ?: date('Y-m-d'));
    if ($ts === false) {
        $ts = time();
    }
    if ($previousMonth) {
        $ts = strtotime(date('Y-m-01', $ts) . ' -1 month');
    }
    return [
        date('Y-m-01', $ts),
        date('Y-m-t', $ts),
    ];
}

function project_invoice_due_date_for_period(array $project, array $appConfig, string $periodEnd): string
{
    $netTerms = (int)($appConfig['net_terms_days'] ?? 30);
    if (($project['invoice_net_terms_days'] ?? '') !== '') {
        $netTerms = max(0, (int)$project['invoice_net_terms_days']);
    }
    return date('Y-m-d', strtotime($periodEnd . ' +' . max(0, $netTerms) . ' days'));
}

function project_invoice_sync_clients(PDO $pdo, int $projectId, ?int $primaryClientId, array $clientIds, ?array $invoiceRecipientClientIds = null, ?array $invoiceLinkClientIds = null): void
{
    $clean = [];
    if ($primaryClientId) {
        $clean[] = $primaryClientId;
    }
    foreach ($clientIds as $cid) {
        $cid = (int)$cid;
        if ($cid > 0) {
            $clean[] = $cid;
        }
    }
    $clean = array_values(array_unique($clean));
    $recipientIds = $invoiceRecipientClientIds === null
        ? $clean
        : array_values(array_unique(array_filter(array_map('intval', $invoiceRecipientClientIds), static fn($id) => $id > 0)));
    $linkViewerIds = $invoiceLinkClientIds === null
        ? $clean
        : array_values(array_unique(array_filter(array_map('intval', $invoiceLinkClientIds), static fn($id) => $id > 0)));
    if ($invoiceRecipientClientIds !== null && $primaryClientId && in_array($primaryClientId, $recipientIds, true) && !in_array($primaryClientId, $clean, true)) {
        $clean[] = $primaryClientId;
    }
    if ($invoiceLinkClientIds !== null && $primaryClientId && in_array($primaryClientId, $linkViewerIds, true) && !in_array($primaryClientId, $clean, true)) {
        $clean[] = $primaryClientId;
    }

    $pdo->prepare('DELETE FROM project_clients WHERE project_id = ?')->execute([$projectId]);
    $hasSendColumn = project_invoice_table_has_column($pdo, 'project_clients', 'send_project_invoices');
    $hasLinkColumn = project_invoice_table_has_column($pdo, 'project_clients', 'can_view_invoice_links');
    if ($hasSendColumn && $hasLinkColumn) {
        $ins = $pdo->prepare('INSERT IGNORE INTO project_clients (project_id, client_id, is_primary_billing, send_project_invoices, can_view_invoice_links, sort_order) VALUES (?,?,?,?,?,?)');
    } elseif ($hasSendColumn) {
        $ins = $pdo->prepare('INSERT IGNORE INTO project_clients (project_id, client_id, is_primary_billing, send_project_invoices, sort_order) VALUES (?,?,?,?,?)');
    } else {
        $ins = $pdo->prepare('INSERT IGNORE INTO project_clients (project_id, client_id, is_primary_billing, sort_order) VALUES (?,?,?,?)');
    }
    foreach ($clean as $idx => $cid) {
        $isPrimary = ($primaryClientId && $cid === $primaryClientId) ? 1 : 0;
        $sendProjectInvoices = in_array($cid, $recipientIds, true) ? 1 : 0;
        $canViewInvoiceLinks = in_array($cid, $linkViewerIds, true) ? 1 : 0;
        if ($hasSendColumn && $hasLinkColumn) {
            $ins->execute([$projectId, $cid, $isPrimary, $sendProjectInvoices, $canViewInvoiceLinks, $idx]);
        } elseif ($hasSendColumn) {
            $ins->execute([$projectId, $cid, $isPrimary, $sendProjectInvoices, $idx]);
        } else {
            $ins->execute([$projectId, $cid, $isPrimary, $idx]);
        }
    }
}

function project_invoice_client_recipients(PDO $pdo, int $projectInvoiceId, ?array $clientIds = null): array
{
    $selectedClientIds = $clientIds === null ? null : array_values(array_unique(array_filter(array_map('intval', $clientIds), static fn($id) => $id > 0)));
    $params = [$projectInvoiceId];
    $sendFilter = project_invoice_table_has_column($pdo, 'project_clients', 'send_project_invoices')
        ? 'AND pc.send_project_invoices = 1'
        : '';
    $selectedFilter = '';
    if ($selectedClientIds !== null) {
        if (!$selectedClientIds) {
            return [];
        }
        $selectedFilter = 'AND c.id IN (' . implode(',', array_fill(0, count($selectedClientIds), '?')) . ')';
        $params = array_merge($params, $selectedClientIds);
        $sendFilter = '';
    }

    $stmt = $pdo->prepare("
        SELECT c.id, c.name, c.email
        FROM project_invoices pi
        JOIN project_clients pc ON pc.project_id = pi.project_id
        JOIN clients c ON c.id = pc.client_id
        WHERE pi.id = ? AND c.email IS NOT NULL AND c.email <> \"\"
          {$sendFilter}
          {$selectedFilter}
        ORDER BY pc.is_primary_billing DESC, pc.sort_order ASC, c.name ASC
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($rows) {
        return $rows;
    }
    if ($selectedClientIds !== null) {
        return [];
    }

    $fallback = $pdo->prepare('
        SELECT c.id, c.name, c.email
        FROM project_invoices pi
        JOIN projects p ON p.id = pi.project_id
        JOIN clients c ON c.id = COALESCE(pi.primary_client_id, p.client_id)
        WHERE pi.id = ? AND c.email IS NOT NULL AND c.email <> ""
    ');
    $fallback->execute([$projectInvoiceId]);
    return $fallback->fetchAll(PDO::FETCH_ASSOC);
}

function project_invoice_create_public_link(PDO $pdo, int $projectInvoiceId, array $appConfig): string
{
    $token = bin2hex(random_bytes(16));
    try {
        $pdo->exec('ALTER TABLE public_links MODIFY COLUMN expires_at DATETIME NULL');
    } catch (Throwable $e) {
    }
    try {
        $pdo->exec('ALTER TABLE public_links ADD COLUMN expire_when_paid TINYINT(1) NOT NULL DEFAULT 0');
    } catch (Throwable $e) {
    }
    $stmt = $pdo->prepare('INSERT INTO public_links (document_type, document_id, token, expires_at, expire_when_paid, revoked) VALUES ("project_invoice", ?, ?, NULL, 1, 0)');
    $stmt->execute([$projectInvoiceId, $token]);
    return $token;
}

function project_invoice_base_url(array $appConfig): string
{
    $host = trim((string)($appConfig['app_host'] ?? ''));
    if ($host !== '' && preg_match('#^https?://#i', $host)) {
        return rtrim($host, '/');
    }
    if ($host === '') {
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    }
    $scheme = 'http';
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
        $scheme = 'https';
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strpos((string)$_SERVER['HTTP_X_FORWARDED_PROTO'], 'https') === 0) {
        $scheme = 'https';
    }
    return $scheme . '://' . rtrim($host, '/');
}

function project_invoice_refresh_status(PDO $pdo, int $projectInvoiceId): void
{
    $stmt = $pdo->prepare('
        SELECT pi.total,
               COALESCE(SUM(
                   GREATEST(
                       0,
                       pii.amount_due_at_generation - LEAST(
                           pii.amount_due_at_generation,
                           GREATEST(0, i.total - COALESCE(p.paid, 0))
                       )
                   )
               ), 0) AS paid
        FROM project_invoices pi
        LEFT JOIN project_invoice_items pii ON pii.project_invoice_id = pi.id
        LEFT JOIN invoices i ON i.id = pii.invoice_id
        LEFT JOIN (
            SELECT invoice_id, SUM(GREATEST(amount-refunded_amount,0)) AS paid
            FROM payments
            WHERE status = "succeeded"
            GROUP BY invoice_id
        ) p ON p.invoice_id = pii.invoice_id
        WHERE pi.id = ?
        GROUP BY pi.id, pi.total
    ');
    $stmt->execute([$projectInvoiceId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return;
    }

    $total = (float)$row['total'];
    $paid = min($total, (float)$row['paid']);
    $balance = max(0.0, $total - $paid);
    $status = 'unpaid';
    $paidAtSql = 'paid_at = NULL';
    if ($paid > 0 && $balance > 0) {
        $status = 'partial';
    } elseif ($total > 0 && $balance <= 0.005) {
        $status = 'paid';
        $paidAtSql = 'paid_at = COALESCE(paid_at, NOW())';
    }

    $pdo->prepare("UPDATE project_invoices SET status=?, amount_paid=?, balance_due=?, {$paidAtSql} WHERE id=? AND status <> 'void'")
        ->execute([$status, $paid, $balance, $projectInvoiceId]);
}

function project_invoice_create_for_period(PDO $pdo, int $projectId, string $periodStart, string $periodEnd, array $appConfig, bool $sendEmail = false, bool $finalize = false): ?int
{
    $createdBy = (int)($_SESSION['user']['id'] ?? 0) ?: null;
    $shouldFinalize = $sendEmail || $finalize;

    try {
        $pdo->beginTransaction();

        $projectStmt = $pdo->prepare('SELECT * FROM projects WHERE id = ? FOR UPDATE');
        $projectStmt->execute([$projectId]);
        $project = $projectStmt->fetch(PDO::FETCH_ASSOC);
        if (!$project || ($project['invoice_billing_period'] ?? 'per_invoice') !== 'monthly') {
            $pdo->rollBack();
            return null;
        }

        $existing = $pdo->prepare('SELECT id FROM project_invoices WHERE project_id=? AND billing_period_start=? AND billing_period_end=? AND status <> "void" LIMIT 1');
        $existing->execute([$projectId, $periodStart, $periodEnd]);
        $existingId = (int)($existing->fetchColumn() ?: 0);
        if ($existingId > 0) {
            if ($shouldFinalize) {
                $pdo->prepare('UPDATE project_invoices SET status=IF(status="draft","unpaid",status), finalized_at=COALESCE(finalized_at,NOW()), finalization_source=COALESCE(finalization_source,"project_billing") WHERE id=? AND status<>"void"')
                    ->execute([$existingId]);
            }
            $pdo->commit();
            if ($sendEmail) {
                project_invoice_send_email($pdo, $existingId, $appConfig);
            }
            return $existingId;
        }

        $invoiceStmt = $pdo->prepare('
            SELECT i.id, i.doc_number, i.status, i.total, i.due_date,
                   DATE(COALESCE(i.fulfillment_date, i.document_date, i.created_at)) AS invoice_date,
                   COALESCE(p.paid, 0) AS paid
            FROM invoices i
            LEFT JOIN (
                SELECT invoice_id, SUM(GREATEST(amount-refunded_amount,0)) AS paid
                FROM payments
                WHERE status = "succeeded"
                GROUP BY invoice_id
            ) p ON p.invoice_id = i.id
            LEFT JOIN project_invoice_items pii ON pii.invoice_id = i.id
            WHERE i.project_id = ?
              AND i.status IN ("unpaid", "partial")
              AND DATE(COALESCE(i.fulfillment_date, i.document_date, i.created_at)) BETWEEN ? AND ?
              AND pii.id IS NULL
            ORDER BY invoice_date ASC, i.doc_number ASC, i.id ASC
        ');
        $invoiceStmt->execute([$projectId, $periodStart, $periodEnd]);
        $children = $invoiceStmt->fetchAll(PDO::FETCH_ASSOC);

        $total = 0.0;
        foreach ($children as $child) {
            $due = max(0.0, (float)$child['total'] - (float)$child['paid']);
            if ($due > 0) {
                $total += $due;
            }
        }
        if ($total <= 0 || empty($children)) {
            $pdo->rollBack();
            return null;
        }

        $primaryClientId = null;
        $primaryStmt = $pdo->prepare('SELECT client_id FROM project_clients WHERE project_id=? ORDER BY is_primary_billing DESC, sort_order ASC, id ASC LIMIT 1');
        $primaryStmt->execute([$projectId]);
        $primaryClientId = (int)($primaryStmt->fetchColumn() ?: ($project['client_id'] ?? 0)) ?: null;

        $docNumber = (int)$pdo->query('SELECT COALESCE(MAX(doc_number),0) + 1 FROM project_invoices')->fetchColumn();
        $dueDate = project_invoice_due_date_for_period($project, $appConfig, $periodEnd);
        $insert = $pdo->prepare('
            INSERT INTO project_invoices
                (project_id, organization_id, primary_client_id, doc_number, status, billing_period_start, billing_period_end, due_date, subtotal, total, amount_paid, balance_due, created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
        ');
        $insert->execute([
            $projectId,
            !empty($project['organization_id']) ? (int)$project['organization_id'] : null,
            $primaryClientId,
            $docNumber,
            $shouldFinalize ? 'unpaid' : 'draft',
            $periodStart,
            $periodEnd,
            $dueDate,
            $total,
            $total,
            0,
            $total,
            $createdBy,
        ]);
        $projectInvoiceId = (int)$pdo->lastInsertId();
        if ($shouldFinalize) {
            $pdo->prepare('UPDATE project_invoices SET finalized_at=NOW(), finalization_source="project_billing" WHERE id=?')
                ->execute([$projectInvoiceId]);
        }

        $item = $pdo->prepare('
            INSERT INTO project_invoice_items
                (project_invoice_id, invoice_id, invoice_doc_number, invoice_date, invoice_due_date, invoice_status, invoice_total, amount_paid_at_generation, amount_due_at_generation)
            VALUES (?,?,?,?,?,?,?,?,?)
        ');
        foreach ($children as $child) {
            $due = max(0.0, (float)$child['total'] - (float)$child['paid']);
            if ($due <= 0) {
                continue;
            }
            $item->execute([
                $projectInvoiceId,
                (int)$child['id'],
                $child['doc_number'] !== null ? (int)$child['doc_number'] : null,
                $child['invoice_date'],
                $child['due_date'] ?: null,
                (string)$child['status'],
                (float)$child['total'],
                (float)$child['paid'],
                $due,
            ]);
            $pdo->prepare('UPDATE invoices SET collection_mode="project_aggregate" WHERE id=?')
                ->execute([(int)$child['id']]);
            $pdo->prepare('UPDATE public_links SET revoked=1 WHERE document_type="invoice" AND document_id=? AND revoked=0')
                ->execute([(int)$child['id']]);
        }

        $pdo->commit();

        if ($sendEmail) {
            project_invoice_send_email($pdo, $projectInvoiceId, $appConfig);
        }

        return $projectInvoiceId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        @error_log('[project_invoice_billing] create failed: ' . $e->getMessage());
        return null;
    }
}

function project_invoice_send_email(PDO $pdo, int $projectInvoiceId, array $appConfig, ?array $clientIds = null, bool $allowResend = false): int
{
    $stmt = $pdo->prepare('
        SELECT pi.*, p.name AS project_name
        FROM project_invoices pi
        JOIN projects p ON p.id = pi.project_id
        WHERE pi.id = ?
    ');
    $stmt->execute([$projectInvoiceId]);
    $projectInvoice = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$projectInvoice || ($projectInvoice['status'] ?? '') === 'void') {
        return 0;
    }
    if (($projectInvoice['status'] ?? '') === 'draft' || empty($projectInvoice['finalized_at'])) {
        $pdo->prepare('UPDATE project_invoices SET status="unpaid", finalized_at=COALESCE(finalized_at,NOW()), finalization_source=COALESCE(finalization_source,"manual_email") WHERE id=? AND status="draft"')
            ->execute([$projectInvoiceId]);
        $projectInvoice['status'] = 'unpaid';
        $projectInvoice['finalized_at'] = date('Y-m-d H:i:s');
    }

    $recipients = project_invoice_client_recipients($pdo, $projectInvoiceId, $clientIds);
    if (!$recipients) {
        return 0;
    }

    $pendingRecipients = [];
    $exists = $pdo->prepare('SELECT id FROM project_invoice_notifications WHERE project_invoice_id=? AND notification_type="on_generate" AND email_to=? LIMIT 1');
    foreach ($recipients as $recipient) {
        $to = trim((string)($recipient['email'] ?? ''));
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            continue;
        }
        if (!$allowResend) {
            $exists->execute([$projectInvoiceId, $to]);
            if ($exists->fetchColumn()) {
                continue;
            }
        }
        $pendingRecipients[] = $recipient;
    }
    if (!$pendingRecipients) {
        return 0;
    }

    $token = project_invoice_create_public_link($pdo, $projectInvoiceId, $appConfig);
    $url = project_invoice_base_url($appConfig) . '/?page=public-doc&token=' . rawurlencode($token);
    $docNumber = $projectInvoice['doc_number'] ?: $projectInvoiceId;
    $subject = 'Project Invoice PI-' . $docNumber . ' for ' . ($projectInvoice['project_name'] ?? 'Project');
    $body = '<p>Hello,</p>'
        . '<p>Your project invoice for <strong>' . htmlspecialchars((string)$projectInvoice['project_name']) . '</strong> is ready.</p>'
        . '<p><strong>Total due:</strong> $' . number_format((float)$projectInvoice['balance_due'], 2) . '</p>'
        . '<p><strong>Billing period:</strong> ' . htmlspecialchars(date('M j, Y', strtotime($projectInvoice['billing_period_start']))) . ' - ' . htmlspecialchars(date('M j, Y', strtotime($projectInvoice['billing_period_end']))) . '</p>'
        . '<p><a href="' . htmlspecialchars($url) . '">View project invoice</a></p>';
    $invoiceLinks = invoice_content_links_for_project_invoice(
        $pdo,
        $projectInvoiceId,
        $appConfig,
        array_map(static fn($row) => (int)$row['id'], $pendingRecipients)
    );
    $body .= invoice_content_links_html($invoiceLinks);

    $sent = 0;
    foreach ($pendingRecipients as $recipient) {
        $to = trim((string)($recipient['email'] ?? ''));
        [$ok, $err] = EmailService::sendEmail($to, $subject, $body);
        if ($ok) {
            $notificationType = $allowResend ? 'manual' : 'on_generate';
            $pdo->prepare('INSERT IGNORE INTO project_invoice_notifications (project_invoice_id, notification_type, email_to, sent_at) VALUES (?, ?, ?, NOW())')
                ->execute([$projectInvoiceId, $notificationType, $to]);
            $sent++;
        } else {
            @error_log('[project_invoice_billing] email failed for project_invoice_id=' . $projectInvoiceId . ': ' . $err);
        }
    }

    if ($sent > 0) {
        $pdo->prepare('UPDATE project_invoices SET status = IF(status="draft","sent",status), sent_at = COALESCE(sent_at, NOW()) WHERE id=?')
            ->execute([$projectInvoiceId]);
    }

    return $sent;
}

function project_invoice_allocate_payment(PDO $pdo, int $projectInvoiceId, float $amount, string $method, string $reference = '', string $notes = '', ?int $projectPaymentId = null): bool
{
    if ($amount <= 0) {
        return false;
    }

    try {
        $pdo->beginTransaction();

        $parent = $pdo->prepare('SELECT * FROM project_invoices WHERE id=? AND status IN ("unpaid","partial","sent") FOR UPDATE');
        $parent->execute([$projectInvoiceId]);
        $pi = $parent->fetch(PDO::FETCH_ASSOC);
        if (!$pi) {
            $pdo->rollBack();
            return false;
        }

        if ($projectPaymentId) {
            $allocated = $pdo->prepare('SELECT COUNT(*) FROM payments WHERE project_invoice_payment_id=?');
            $allocated->execute([$projectPaymentId]);
            if ((int)$allocated->fetchColumn() > 0) {
                $pdo->rollBack();
                return true;
            }
        }

        $children = $pdo->prepare('
            SELECT i.id, i.client_id, i.contract_id, i.organization_id, i.total,
                   COALESCE(p.paid, 0) AS paid
            FROM project_invoice_items pii
            JOIN invoices i ON i.id = pii.invoice_id
            LEFT JOIN (
                SELECT invoice_id, SUM(GREATEST(amount-refunded_amount,0)) AS paid
                FROM payments
                WHERE status = "succeeded"
                GROUP BY invoice_id
            ) p ON p.invoice_id = i.id
            WHERE pii.project_invoice_id = ?
            ORDER BY COALESCE(i.due_date, i.created_at) ASC, i.doc_number ASC, i.id ASC
        ');
        $children->execute([$projectInvoiceId]);

        $childRows = $children->fetchAll(PDO::FETCH_ASSOC);
        $available = 0.0;
        foreach ($childRows as $child) {
            $available += max(0.0, (float)$child['total'] - (float)$child['paid']);
        }
        if ($amount > $available + 0.005) {
            $pdo->rollBack();
            return false;
        }

        $remaining = $amount;
        $pay = $pdo->prepare('
            INSERT INTO payments (client_id, invoice_id, project_invoice_payment_id, contract_id, organization_id, amount, payment_method, reference_number, notes, status, payment_date)
            VALUES (?,?,?,?,?,?,?,?,?, "succeeded", CURDATE())
        ');
        $updateInvoice = $pdo->prepare('UPDATE invoices SET status=?, amount_paid=?, balance_due=?, paid_at = IF(? = "paid", COALESCE(paid_at, NOW()), paid_at) WHERE id=?');

        foreach ($childRows as $child) {
            if ($remaining <= 0.005) {
                break;
            }
            $childTotal = (float)$child['total'];
            $childPaid = (float)$child['paid'];
            $childDue = max(0.0, $childTotal - $childPaid);
            if ($childDue <= 0) {
                continue;
            }
            $apply = min($remaining, $childDue);
            $paymentNotes = trim($notes . "\nProject invoice PI-" . ($pi['doc_number'] ?: $projectInvoiceId));
            $pay->execute([
                (int)$child['client_id'],
                (int)$child['id'],
                $projectPaymentId,
                !empty($child['contract_id']) ? (int)$child['contract_id'] : null,
                !empty($child['organization_id']) ? (int)$child['organization_id'] : null,
                $apply,
                $method ?: 'cash',
                $reference ?: null,
                $paymentNotes ?: null,
            ]);

            $newPaid = $childPaid + $apply;
            $balance = max(0.0, $childTotal - $newPaid);
            $status = $balance <= 0.005 ? 'paid' : 'partial';
            $updateInvoice->execute([$status, $newPaid, $balance, $status, (int)$child['id']]);
            $pdo->prepare('UPDATE project_invoice_items SET amount_applied = amount_applied + ? WHERE project_invoice_id=? AND invoice_id=?')
                ->execute([$apply, $projectInvoiceId, (int)$child['id']]);
            $remaining -= $apply;
        }

        $pdo->commit();
        project_invoice_refresh_status($pdo, $projectInvoiceId);
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        @error_log('[project_invoice_billing] payment allocation failed: ' . $e->getMessage());
        return false;
    }
}

function project_invoice_record_stripe_payment(PDO $pdo, array $stripeObject): bool
{
    require_once __DIR__ . '/../services/StripeService.php';
    require_once __DIR__ . '/stripe_payment_accounting.php';

    $metadata = $stripeObject['metadata'] ?? [];
    $projectInvoiceId = (int)($metadata['pa_project_invoice_id'] ?? $metadata['project_invoice_id'] ?? 0);
    if ($projectInvoiceId <= 0) {
        return false;
    }
    $sessionId = str_starts_with((string)($stripeObject['id'] ?? ''), 'cs_') ? (string)$stripeObject['id'] : null;
    $paymentIntentId = $sessionId
        ? (is_string($stripeObject['payment_intent'] ?? null) ? $stripeObject['payment_intent'] : null)
        : (string)($stripeObject['id'] ?? '');
    $amountCents = $sessionId ? ($stripeObject['amount_total'] ?? 0) : ($stripeObject['amount_received'] ?? $stripeObject['amount'] ?? 0);
    $grossAmount = ((float)$amountCents) / 100;
    $amount = isset($metadata['original_amount']) ? (float)$metadata['original_amount'] : $grossAmount;
    if ($amount <= 0 || (!$sessionId && $paymentIntentId === '')) {
        return false;
    }

    $processorTx = [
        'provider' => 'stripe',
        'provider_payment_id' => $paymentIntentId ?: '',
        'status' => 'succeeded',
        'gross_amount' => $grossAmount,
        'fee_amount' => null,
        'net_amount' => null,
        'metadata' => $metadata,
    ];
    if ($paymentIntentId) {
        $stripe = StripeService::fromAppConfig($GLOBALS['appConfig'] ?? []);
        if ($stripe) {
            try {
                $processorTx = $stripe->normalizePaymentIntentForImport(
                    str_starts_with((string)($stripeObject['id'] ?? ''), 'pi_')
                        ? $stripeObject
                        : $stripe->getPaymentIntentWithBalanceTransaction($paymentIntentId)
                );
            } catch (Throwable $e) {
                @error_log('[project_invoice_billing] Stripe fee/net lookup failed for ' . $paymentIntentId . ': ' . $e->getMessage());
            }
        }
    }
    $processorFields = stripe_processor_fields_from_normalized($processorTx, $GLOBALS['appConfig'] ?? [], $amount, 0.0);

    $pdo->prepare(
        'INSERT IGNORE INTO project_invoice_payments
         (project_invoice_id,amount,payment_method,processor_provider,processor_payment_id,processor_gross_amount,
          processor_fee_amount,processor_net_amount,processor_fee_policy,processor_fee_source,
          stripe_session_id,stripe_payment_intent_id,status,payment_date)
         VALUES (?,? ,"stripe",?,?,?,?,?,?,?,?,? ,"processing",CURDATE())'
    )->execute([
        $projectInvoiceId,
        $amount,
        $processorFields['processor_provider'],
        $processorFields['processor_payment_id'] ?: null,
        $processorFields['processor_gross_amount'],
        $processorFields['processor_fee_amount'],
        $processorFields['processor_net_amount'],
        $processorFields['processor_fee_policy'],
        $processorFields['processor_fee_source'],
        $sessionId,
        $paymentIntentId ?: null,
    ]);
    $lookup = $pdo->prepare(
        'SELECT id,status FROM project_invoice_payments
         WHERE (stripe_payment_intent_id IS NOT NULL AND stripe_payment_intent_id=?)
            OR (stripe_session_id IS NOT NULL AND stripe_session_id=?) LIMIT 1'
    );
    $lookup->execute([$paymentIntentId ?: '', $sessionId ?: '']);
    $projectPayment = $lookup->fetch(PDO::FETCH_ASSOC);
    if (!$projectPayment) {
        return false;
    }
    $projectPaymentId = (int)$projectPayment['id'];
    if (($projectPayment['status'] ?? '') === 'succeeded') {
        stripe_update_project_payment_processor_fields($pdo, $projectPaymentId, $processorTx, $GLOBALS['appConfig'] ?? []);
        return true;
    }
    $pdo->prepare('UPDATE project_invoice_payments SET stripe_session_id=COALESCE(stripe_session_id,?),stripe_payment_intent_id=COALESCE(stripe_payment_intent_id,?),amount=? WHERE id=?')
        ->execute([$sessionId, $paymentIntentId ?: null, $amount, $projectPaymentId]);
    stripe_update_project_payment_processor_fields($pdo, $projectPaymentId, $processorTx, $GLOBALS['appConfig'] ?? []);

    $ok = project_invoice_allocate_payment(
        $pdo,
        $projectInvoiceId,
        $amount,
        'stripe',
        $paymentIntentId ?: ($sessionId ?: ''),
        'Client-approved one-time Stripe payment',
        $projectPaymentId
    );
    $pdo->prepare('UPDATE project_invoice_payments SET status=? WHERE id=?')
        ->execute([$ok ? 'succeeded' : 'failed', $projectPaymentId]);
    if ($ok) {
        require_once __DIR__ . '/stripe_financial_events.php';
        require_once __DIR__ . '/notifications.php';
        stripe_link_pending_project_financial_events($pdo, $projectPaymentId, $paymentIntentId ?: null);
        stripe_allocate_project_processor_fields($pdo, $projectPaymentId, $processorFields);
        $pdo->prepare('UPDATE project_invoices SET stripe_session_id=NULL,stripe_checkout_expires_at=NULL WHERE id=?')
            ->execute([$projectInvoiceId]);
        $status = $pdo->prepare('SELECT status FROM project_invoices WHERE id=?');
        $status->execute([$projectInvoiceId]);
        $statusValue = (string)$status->fetchColumn();
        if ($statusValue === 'paid') {
            pa_public_link_terminalize($pdo, 'project_invoice', $projectInvoiceId, 'paid');
        }
        notify_admin_project_invoice_paid($pdo, $GLOBALS['appConfig'] ?? [], $projectInvoiceId, $amount, $statusValue === 'paid' ? 'paid' : 'partial');
    }
    return $ok;
}

function project_invoice_generate_due_monthly(PDO $pdo, array $appConfig): int
{
    [$start, $end] = project_invoice_period_for_date(date('Y-m-d'), true);
    $hasAutoEmail = project_invoice_table_has_column($pdo, 'projects', 'project_invoice_auto_email');
    $selectAuto = $hasAutoEmail ? ', project_invoice_auto_email' : '';
    $stmt = $pdo->query('SELECT id' . $selectAuto . ' FROM projects WHERE status IN ("active","not_started") AND invoice_billing_period = "monthly"');
    $count = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $project) {
        $sendEmail = $hasAutoEmail ? !empty($project['project_invoice_auto_email']) : true;
        $id = project_invoice_create_for_period($pdo, (int)$project['id'], $start, $end, $appConfig, $sendEmail, true);
        if ($id) {
            $count++;
        }
    }
    return $count;
}
