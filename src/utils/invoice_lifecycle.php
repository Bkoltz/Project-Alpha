<?php

require_once __DIR__ . '/../services/EmailService.php';
require_once __DIR__ . '/../services/StripeService.php';
require_once __DIR__ . '/public_links.php';
require_once __DIR__ . '/invoice_content_links.php';

function invoice_is_collectible_status(string $status): bool
{
    return in_array(strtolower($status), ['sent', 'unpaid', 'partial', 'overdue'], true);
}

function invoice_is_draft(array $invoice): bool
{
    return strtolower((string)($invoice['status'] ?? '')) === 'draft';
}

function invoice_public_base_url(array $appConfig): string
{
    $configured = trim((string)($appConfig['app_host'] ?? ''));
    if ($configured !== '') {
        return rtrim(preg_match('#^https?://#i', $configured) ? $configured : 'https://' . $configured, '/');
    }

    $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '') {
        return 'http://localhost';
    }
    $scheme = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
    return $scheme . '://' . $host;
}

function invoice_table_has_column(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . ':' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) {
        $cache[$key] = false;
        return false;
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?
             LIMIT 1'
        );
        $stmt->execute([$table, $column]);
        $cache[$key] = $stmt->fetchColumn() !== false;
        return $cache[$key];
    } catch (Throwable $e) {
        $cache[$key] = false;
        return false;
    }
}

function invoice_ensure_payments_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $columns = [
        'project_invoice_payment_id' => 'BIGINT NULL AFTER invoice_id',
        'contract_id' => 'INT NULL AFTER project_invoice_payment_id',
        'organization_id' => 'INT NULL AFTER contract_id',
        'refunded_amount' => 'DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER amount',
        'disputed_amount' => 'DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER refunded_amount',
        'reference_number' => 'VARCHAR(255) NULL AFTER payment_date',
        'notes' => 'TEXT NULL AFTER reference_number',
        'status' => "VARCHAR(32) NOT NULL DEFAULT 'succeeded'",
        'processor_provider' => 'VARCHAR(50) NULL AFTER payment_method',
        'processor_payment_id' => 'VARCHAR(255) NULL AFTER processor_provider',
        'processor_transaction_id' => 'BIGINT NULL AFTER processor_payment_id',
        'processor_gross_amount' => 'DECIMAL(12,2) NULL AFTER processor_transaction_id',
        'processor_fee_amount' => 'DECIMAL(12,2) NULL AFTER processor_gross_amount',
        'processor_net_amount' => 'DECIMAL(12,2) NULL AFTER processor_fee_amount',
        'processor_fee_policy' => "VARCHAR(32) NOT NULL DEFAULT 'unknown' AFTER processor_net_amount",
        'processor_fee_source' => "VARCHAR(32) NOT NULL DEFAULT 'unknown' AFTER processor_fee_policy",
    ];

    try {
        $pdo->exec('ALTER TABLE payments MODIFY COLUMN client_id INT NULL');
    } catch (Throwable $e) {
        @error_log('[invoice_lifecycle] Failed to relax payments.client_id: ' . $e->getMessage());
    }
    try {
        $pdo->exec("ALTER TABLE payments MODIFY COLUMN payment_method VARCHAR(50) NOT NULL DEFAULT 'cash'");
    } catch (Throwable $e) {
        @error_log('[invoice_lifecycle] Failed to widen payments.payment_method: ' . $e->getMessage());
    }

    foreach ($columns as $column => $definition) {
        if (!invoice_table_has_column($pdo, 'payments', $column)) {
            try {
                $pdo->exec("ALTER TABLE payments ADD COLUMN {$column} {$definition}");
            } catch (Throwable $e) {
                @error_log('[invoice_lifecycle] Failed to repair payments.' . $column . ': ' . $e->getMessage());
            }
        }
    }

    try {
        $pdo->exec('CREATE UNIQUE INDEX uq_payments_processor_payment ON payments (processor_provider, processor_payment_id)');
    } catch (Throwable $e) {
    }
    try {
        $pdo->exec('CREATE INDEX idx_payments_processor_transaction ON payments (processor_transaction_id)');
    } catch (Throwable $e) {
    }

    $done = true;
}

function invoice_expire_active_checkout(PDO $pdo, string $table, int $id, array $appConfig): void
{
    if (!in_array($table, ['invoices', 'project_invoices'], true)) {
        throw new InvalidArgumentException('Unsupported checkout record type.');
    }
    $stmt = $pdo->prepare("SELECT stripe_session_id,stripe_checkout_expires_at FROM {$table} WHERE id=?");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $sessionId = trim((string)($row['stripe_session_id'] ?? ''));
    $expiresAt = !empty($row['stripe_checkout_expires_at']) ? strtotime((string)$row['stripe_checkout_expires_at']) : 0;
    if ($sessionId !== '' && $expiresAt > time()) {
        $stripe = StripeService::fromAppConfig($appConfig);
        if (!$stripe) {
            throw new RuntimeException('An active Stripe Checkout session must expire before recording another payment.');
        }
        try {
            $stripe->expireCheckoutSession($sessionId);
        } catch (Throwable $e) {
            if (!str_contains(strtolower($e->getMessage()), 'expired')) {
                throw new RuntimeException('Could not close the active Stripe Checkout session. Try again shortly.', 0, $e);
            }
        }
    }
    $pdo->prepare("UPDATE {$table} SET stripe_session_id=NULL,stripe_checkout_expires_at=NULL WHERE id=?")
        ->execute([$id]);
}

function invoice_effective_paid_total(PDO $pdo, int $invoiceId): float
{
    invoice_ensure_payments_schema($pdo);
    $stmt = $pdo->prepare('
        SELECT COALESCE(SUM(GREATEST(amount - refunded_amount - disputed_amount, 0)), 0)
        FROM payments
        WHERE invoice_id = ? AND status = "succeeded"
    ');
    $stmt->execute([$invoiceId]);
    return (float)$stmt->fetchColumn();
}

function invoice_status_for_balance(float $total, float $paid): array
{
    $paid = max(0.0, $paid);
    $balance = max(0.0, $total - $paid);
    if ($total > 0 && $balance <= 0.005) {
        return ['paid', min($paid, $total), 0.0];
    }
    if ($paid > 0.005) {
        return ['partial', $paid, $balance];
    }
    return ['unpaid', 0.0, max(0.0, $total)];
}

function invoice_refresh_payment_totals(PDO $pdo, int $invoiceId, bool $revokePaidPublicLinks = true): array
{
    $totalStmt = $pdo->prepare('SELECT total FROM invoices WHERE id = ?');
    $totalStmt->execute([$invoiceId]);
    $total = (float)$totalStmt->fetchColumn();
    $paid = invoice_effective_paid_total($pdo, $invoiceId);
    [$status, $storedPaid, $balanceDue] = invoice_status_for_balance($total, $paid);

    $paidAtSql = $status === 'paid'
        ? ', paid_at = COALESCE(paid_at, NOW())'
        : ', paid_at = NULL';
    $pdo->prepare("UPDATE invoices SET status = ?, amount_paid = ?, balance_due = ?{$paidAtSql} WHERE id = ?")
        ->execute([$status, $storedPaid, $balanceDue, $invoiceId]);

    if ($revokePaidPublicLinks && !in_array($status, ['unpaid', 'partial'], true)) {
        pa_public_link_terminalize($pdo, 'invoice', $invoiceId, $status);
    }

    return [
        'status' => $status,
        'amount_paid' => $storedPaid,
        'balance_due' => $balanceDue,
        'total' => $total,
    ];
}

function invoice_record_locked_payment(
    PDO $pdo,
    int $invoiceId,
    float $amount,
    string $method,
    ?string $reference,
    ?string $notes,
    array $options = []
): array {
    if ($amount <= 0) {
        throw new RuntimeException('Payment amount must be greater than zero.');
    }

    invoice_ensure_payments_schema($pdo);
    $activeOrgId = $options['organization_id'] ?? null;
    $completeContractWhenPaid = (bool)($options['complete_contract_when_paid'] ?? false);
    $allowUnfinalized = (bool)($options['allow_unfinalized'] ?? false);
    $source = substr((string)($options['source'] ?? 'manual'), 0, 50);
    $paymentDate = trim((string)($options['payment_date'] ?? date('Y-m-d')));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $paymentDate)) {
        $paymentDate = date('Y-m-d');
    }

    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $where = 'id = ?';
        $params = [$invoiceId];
        if ($activeOrgId !== null) {
            $where .= ' AND organization_id = ?';
            $params[] = (int)$activeOrgId;
        }

        $lock = $pdo->prepare("
            SELECT id, client_id, contract_id, organization_id, status, finalized_at, total, collection_mode
            FROM invoices
            WHERE {$where}
            FOR UPDATE
        ");
        $lock->execute($params);
        $invoice = $lock->fetch(PDO::FETCH_ASSOC);
        if (!$invoice) {
            throw new RuntimeException('Invoice not found.');
        }

        if (!$allowUnfinalized && (empty($invoice['finalized_at']) || strtolower((string)$invoice['status']) === 'draft')) {
            throw new RuntimeException('Finalize the invoice before recording payment.');
        }
        if (($invoice['collection_mode'] ?? 'direct') !== 'direct') {
            throw new RuntimeException('Record payment from the project invoice so it is allocated correctly.');
        }

        $paid = invoice_effective_paid_total($pdo, $invoiceId);
        $outstanding = max(0.0, (float)$invoice['total'] - $paid);
        if ($amount > $outstanding + 0.005) {
            throw new RuntimeException('Payment cannot exceed the outstanding balance.');
        }

        $insert = $pdo->prepare('
            INSERT INTO payments
                (client_id, invoice_id, contract_id, organization_id, amount, payment_method, reference_number, notes, status, payment_date)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, "succeeded", ?)
        ');
        $insert->execute([
            (int)$invoice['client_id'],
            $invoiceId,
            !empty($invoice['contract_id']) ? (int)$invoice['contract_id'] : null,
            !empty($invoice['organization_id']) ? (int)$invoice['organization_id'] : null,
            $amount,
            $method !== '' ? $method : 'cash',
            $reference !== '' ? $reference : null,
            $notes !== '' ? $notes : null,
            $paymentDate,
        ]);
        $paymentId = (int)$pdo->lastInsertId();

        $totals = invoice_refresh_payment_totals($pdo, $invoiceId);
        if ($completeContractWhenPaid && $totals['status'] === 'paid' && !empty($invoice['contract_id'])) {
            $pdo->prepare('UPDATE contracts SET status = ? WHERE id = ?')
                ->execute(['completed', (int)$invoice['contract_id']]);
        }

        if ($ownsTransaction) {
            $pdo->commit();
        }

        $totals['payment_id'] = $paymentId;
        $totals['source'] = $source;
        return $totals;
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Finalize an invoice exactly once. Finalization is the boundary that permits
 * client delivery, public links, reminders, and one-time Checkout payments.
 */
function invoice_finalize(PDO $pdo, int $invoiceId, array $appConfig, string $source, ?int $userId = null): array
{
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $stmt = $pdo->prepare('SELECT * FROM invoices WHERE id=? FOR UPDATE');
        $stmt->execute([$invoiceId]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$invoice) {
            throw new RuntimeException('Invoice not found.');
        }

        $status = strtolower((string)$invoice['status']);
        if (in_array($status, ['paid', 'void', 'cancelled'], true)) {
            throw new RuntimeException('Invoice status does not allow finalization.');
        }

        if ($status === 'draft') {
            $netDays = max(0, (int)($appConfig['net_terms_days'] ?? 30));
            $dueDate = !empty($invoice['due_date'])
                ? $invoice['due_date']
                : date('Y-m-d', strtotime('+' . $netDays . ' days'));

            $update = $pdo->prepare(
                'UPDATE invoices
                 SET status="unpaid", due_date=?, finalized_at=COALESCE(finalized_at,NOW()),
                     finalized_by=COALESCE(finalized_by,?), finalization_source=COALESCE(finalization_source,?)
                 WHERE id=? AND status="draft"'
            );
            $update->execute([$dueDate, $userId, substr($source, 0, 50), $invoiceId]);
        }

        $stmt->execute([$invoiceId]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($ownsTransaction) {
            $pdo->commit();
        }
        return $invoice;
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Deliver a finalized invoice once. This intentionally creates only a normal
 * one-time payment link; it never saves or reuses a payment method.
 */
function invoice_send_finalized(PDO $pdo, int $invoiceId, array $appConfig, string $notificationType = 'on_finalize'): bool
{
    $stmt = $pdo->prepare(
        'SELECT i.id,i.doc_number,i.total,i.due_date,i.status,i.finalized_at,i.collection_mode,c.email,c.name
         FROM invoices i JOIN clients c ON c.id=i.client_id WHERE i.id=?'
    );
    $stmt->execute([$invoiceId]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$invoice || !invoice_is_collectible_status((string)$invoice['status'])
        || empty($invoice['finalized_at'])
        || ($invoice['collection_mode'] ?? 'direct') !== 'direct') {
        return false;
    }

    $to = trim((string)($invoice['email'] ?? ''));
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    if (invoice_missing_content_links_behavior($appConfig) === 'block'
        && invoice_should_prompt_for_missing_content_links($pdo, 'invoice', $invoiceId, $appConfig)) {
        return false;
    }

    $claim = $pdo->prepare(
        'INSERT IGNORE INTO invoice_notifications
         (invoice_id,notification_type,sent_at,email_to,email_subject,email_body)
         VALUES (?,?,NULL,?,NULL,NULL)'
    );
    $claim->execute([$invoiceId, $notificationType, $to]);
    if ($claim->rowCount() === 0) {
        $existingClaim = $pdo->prepare('SELECT sent_at,created_at FROM invoice_notifications WHERE invoice_id=? AND notification_type=? LIMIT 1');
        $existingClaim->execute([$invoiceId, $notificationType]);
        $claimRow = $existingClaim->fetch(PDO::FETCH_ASSOC) ?: [];
        if (!empty($claimRow['sent_at'])) {
            return true;
        }
        if (empty($claimRow['created_at']) || strtotime((string)$claimRow['created_at']) > time() - 300) {
            return true;
        }
        $pdo->prepare('DELETE FROM invoice_notifications WHERE invoice_id=? AND notification_type=? AND sent_at IS NULL')
            ->execute([$invoiceId, $notificationType]);
        $claim->execute([$invoiceId, $notificationType, $to]);
        if ($claim->rowCount() === 0) {
            return true;
        }
    }

    $token = bin2hex(random_bytes(32));
    try {
        $pdo->exec('ALTER TABLE public_links MODIFY COLUMN expires_at DATETIME NULL');
    } catch (Throwable $e) {
    }
    try {
        $pdo->exec('ALTER TABLE public_links ADD COLUMN expire_when_paid TINYINT(1) NOT NULL DEFAULT 0');
    } catch (Throwable $e) {
    }
    $pdo->prepare(
        'INSERT INTO public_links (document_type,document_id,token,expires_at,expire_when_paid,revoked,created_at)
         VALUES ("invoice",?,?,NULL,1,0,NOW())'
    )->execute([$invoiceId, $token]);

    $base = invoice_public_base_url($appConfig);
    $url = $base . '/?page=public-doc&token=' . rawurlencode($token);
    $docNumber = $invoice['doc_number'] ?: $invoiceId;
    $name = trim((string)($invoice['name'] ?? ''));
    $firstName = $name !== '' ? preg_split('/\s+/', $name)[0] : 'there';
    $subject = 'Invoice I-' . $docNumber . ' is ready';
    $body = '<p>Hello ' . htmlspecialchars($firstName) . ',</p>'
        . '<p>Invoice <strong>I-' . htmlspecialchars((string)$docNumber) . '</strong> is ready for <strong>$'
        . number_format((float)$invoice['total'], 2) . '</strong>.</p>'
        . (!empty($invoice['due_date']) ? '<p>Due date: ' . htmlspecialchars((string)$invoice['due_date']) . '</p>' : '')
        . '<p><a href="' . htmlspecialchars($url) . '">View and pay this invoice</a></p>';
    $contentLinksHtml = invoice_content_links_html(invoice_content_links_for_invoice($pdo, $invoiceId, $appConfig));
    if ($contentLinksHtml !== '') {
        $body .= $contentLinksHtml;
    }

    [$ok, $error] = EmailService::sendEmail($to, $subject, $body);
    if (!$ok) {
        $pdo->prepare('DELETE FROM invoice_notifications WHERE invoice_id=? AND notification_type=? AND sent_at IS NULL')
            ->execute([$invoiceId, $notificationType]);
        $pdo->prepare('UPDATE public_links SET revoked=1 WHERE document_type="invoice" AND document_id=? AND token=?')
            ->execute([$invoiceId, $token]);
        @error_log('[invoice_lifecycle] Invoice delivery failed for ' . $invoiceId . ': ' . $error);
        return false;
    }

    $pdo->prepare(
        'UPDATE invoice_notifications SET sent_at=NOW(),email_to=?,email_subject=?,email_body=?
         WHERE invoice_id=? AND notification_type=?'
    )->execute([$to, $subject, $body, $invoiceId, $notificationType]);
    $pdo->prepare('UPDATE invoices SET sent_at=COALESCE(sent_at,NOW()) WHERE id=?')->execute([$invoiceId]);
    return true;
}

function invoice_reopen_draft(PDO $pdo, int $invoiceId): void
{
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT status,stripe_session_id,stripe_checkout_expires_at FROM invoices WHERE id=? FOR UPDATE');
        $stmt->execute([$invoiceId]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $status = strtolower((string)($invoice['status'] ?? ''));
        if (!in_array($status, ['sent', 'unpaid', 'overdue'], true)) {
            throw new RuntimeException('Only an unpaid finalized invoice can be reopened.');
        }

        $payment = $pdo->prepare('SELECT COUNT(*) FROM payments WHERE invoice_id=? AND status IN ("succeeded","pending")');
        $payment->execute([$invoiceId]);
        if ((int)$payment->fetchColumn() > 0) {
            throw new RuntimeException('This invoice has payment activity and cannot be reopened.');
        }

        $intent = $pdo->prepare('SELECT COUNT(*) FROM payment_intents WHERE invoice_id=? AND status IN ("pending","processing","requires_action")');
        $intent->execute([$invoiceId]);
        if ((int)$intent->fetchColumn() > 0) {
            throw new RuntimeException('This invoice has an active payment attempt and cannot be reopened.');
        }

        if (!empty($invoice['stripe_session_id'])
            && !empty($invoice['stripe_checkout_expires_at'])
            && strtotime((string)$invoice['stripe_checkout_expires_at']) > time()) {
            throw new RuntimeException('This invoice has an active Stripe Checkout session and cannot be reopened yet.');
        }

        $pdo->prepare('UPDATE public_links SET revoked=1 WHERE document_type="invoice" AND document_id=? AND revoked=0')
            ->execute([$invoiceId]);
        $pdo->prepare('UPDATE invoices SET status="draft", finalized_at=NULL, finalized_by=NULL, finalization_source=NULL, sent_at=NULL, stripe_session_id=NULL, stripe_checkout_expires_at=NULL WHERE id=?')
            ->execute([$invoiceId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
