<?php

declare(strict_types=1);

namespace App\Services;

use DomainException;
use PDO;
use Throwable;

/** Resolves the client-side delta when corrected time was already on a finalized invoice. */
final class TimeCorrectionBillingResolutionService
{
    private const DECISIONS = ['invoice_adjustment','move_to_draft','absorb'];

    public function __construct(private readonly PDO $pdo) {}

    /** @return array{id:int,decision:string,invoice_adjustment_id:?int,client_credit_id:?string} */
    public function resolve(
        string $correctionRequestId,
        string $decision,
        string $reason,
        int $actorId,
        ?int $targetDraftInvoiceId = null
    ): array {
        if (!in_array($decision, self::DECISIONS, true) || trim($reason) === '' || !$this->canManage($actorId)) {
            throw new DomainException('Choose a billing resolution, enter a reason, and use an authorized account.');
        }
        return $this->transaction(function () use ($correctionRequestId, $decision, $reason, $actorId, $targetDraftInvoiceId): array {
            $effectStatement = $this->pdo->prepare(
                "SELECT e.*,r.time_entry_id,r.original_revision,wt.client_id,t.organization_id
                 FROM time_correction_effects e
                 JOIN time_correction_requests r ON r.id=e.correction_request_id
                 JOIN work_time_entries wt ON wt.id=r.time_entry_id
                 LEFT JOIN clients t ON t.id=wt.client_id
                 WHERE r.id=? AND r.status='approved' AND e.billing_action='admin_review' FOR UPDATE"
            );
            $effectStatement->execute([$correctionRequestId]);
            $effect = $effectStatement->fetch(PDO::FETCH_ASSOC);
            if (!$effect || $effect['billing_amount_delta'] === null) {
                throw new DomainException('This correction has no unresolved finalized-invoice billing delta.');
            }
            $existing = $this->pdo->prepare('SELECT 1 FROM time_correction_billing_resolutions WHERE correction_effect_id=?');
            $existing->execute([$effect['id']]);
            if ($existing->fetchColumn()) {
                throw new DomainException('This correction billing delta has already been resolved.');
            }
            $sourceStatement = $this->pdo->prepare(
                "SELECT i.*,a.invoice_item_id FROM work_time_billing_allocations a
                 JOIN invoices i ON i.id=a.invoice_id
                 WHERE a.time_entry_id=? AND a.entry_revision=? AND a.status IN ('invoiced','ready')
                 ORDER BY a.id DESC LIMIT 1 FOR UPDATE"
            );
            $sourceStatement->execute([$effect['time_entry_id'], $effect['original_revision']]);
            $source = $sourceStatement->fetch(PDO::FETCH_ASSOC);
            if (!$source || (string)$source['status'] === 'draft' || $source['finalized_at'] === null) {
                throw new DomainException('The original finalized invoice is unavailable.');
            }
            $delta = round((float)$effect['billing_amount_delta'], 2);
            $adjustmentId = null;
            $creditId = null;
            $targetInvoiceId = null;

            if ($decision === 'move_to_draft') {
                $target = $this->draftInvoice($targetDraftInvoiceId, (int)$source['client_id'], $source['organization_id']);
                $targetInvoiceId = (int)$target['id'];
                $adjustmentId = $this->recordInvoiceAdjustment($target, $delta, $reason, $actorId);
                $this->applyInvoiceDelta($target, $delta);
            } elseif ($decision === 'invoice_adjustment') {
                $adjustmentId = $this->recordInvoiceAdjustment($source, $delta, $reason, $actorId);
                $newTotal = max(0.0, (float)$source['total'] + $delta);
                $excessPaid = $delta < 0 ? max(0.0, (float)$source['amount_paid'] - $newTotal) : 0.0;
                $this->applyInvoiceDelta($source, $delta);
                if ($excessPaid >= 0.005) {
                    $creditId = (new ClientCreditLedgerService($this->pdo))->issueFromInvoice(
                        (int)$source['id'], number_format($excessPaid, 2, '.', ''), (string)$effect['currency'], $reason, $actorId
                    );
                }
            }

            $this->pdo->prepare(
                'INSERT INTO time_correction_billing_resolutions
                 (correction_effect_id,decision,source_invoice_id,target_invoice_id,invoice_adjustment_id,client_credit_id,signed_amount,reason,actor_id)
                 VALUES (?,?,?,?,?,?,?,?,?)'
            )->execute([
                $effect['id'], $decision, $source['id'], $targetInvoiceId, $adjustmentId, $creditId,
                number_format($delta, 2, '.', ''), trim($reason), $actorId,
            ]);
            return [
                'id' => (int)$this->pdo->lastInsertId(),
                'decision' => $decision,
                'invoice_adjustment_id' => $adjustmentId,
                'client_credit_id' => $creditId,
            ];
        });
    }

    /** @return array<string,mixed> */
    private function draftInvoice(?int $invoiceId, int $clientId, mixed $organizationId): array
    {
        if (($invoiceId ?? 0) <= 0) {
            throw new DomainException('Choose a draft invoice for this correction delta.');
        }
        $statement = $this->pdo->prepare("SELECT * FROM invoices WHERE id=? AND status='draft' AND finalized_at IS NULL FOR UPDATE");
        $statement->execute([$invoiceId]);
        $invoice = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$invoice || (int)$invoice['client_id'] !== $clientId
            || (string)($invoice['organization_id'] ?? '') !== (string)($organizationId ?? '')) {
            throw new DomainException('The target draft must belong to the same client and organization.');
        }
        return $invoice;
    }

    /** @param array<string,mixed> $invoice */
    private function recordInvoiceAdjustment(array $invoice, float $signedDelta, string $reason, int $actorId): int
    {
        $revision = max(1, (int)($invoice['revision_number'] ?? 1)) + 1;
        $this->pdo->prepare(
            'INSERT INTO invoice_adjustments
             (invoice_id,adjustment_type,label,description,quantity,unit_price,amount,revision_number,created_by)
             VALUES (?,?,?,?,1,?,?,?,?)'
        )->execute([
            $invoice['id'], $signedDelta < 0 ? 'credit' : 'charge', 'Time correction', trim($reason),
            number_format(abs($signedDelta), 2, '.', ''), number_format(abs($signedDelta), 2, '.', ''), $revision, $actorId,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    /** @param array<string,mixed> $invoice */
    private function applyInvoiceDelta(array $invoice, float $delta): void
    {
        $total = max(0.0, (float)$invoice['total'] + $delta);
        $balance = max(0.0, $total - (float)$invoice['amount_paid']);
        $status = $balance <= 0.005 ? 'paid' : ((float)$invoice['amount_paid'] > 0 ? 'partial' : ((string)$invoice['status'] === 'draft' ? 'draft' : 'unpaid'));
        $this->pdo->prepare(
            'UPDATE invoices SET total=?,balance_due=?,status=?,revision_number=revision_number+1,revision_updated_at=UTC_TIMESTAMP(6) WHERE id=?'
        )->execute([number_format($total, 2, '.', ''), number_format($balance, 2, '.', ''), $status, $invoice['id']]);
    }

    private function canManage(int $userId): bool
    {
        if ($userId <= 0) return false;
        if (function_exists('user_can') && \user_can($this->pdo, $userId, 'billing.client_credits.manage')) return true;
        $statement = $this->pdo->prepare('SELECT role FROM users WHERE id=? AND is_disabled=0 AND deleted_at IS NULL');
        $statement->execute([$userId]);
        return in_array((string)$statement->fetchColumn(), ['admin','owner'], true);
    }

    private function transaction(callable $callback): mixed
    {
        $owns = !$this->pdo->inTransaction();
        if ($owns) $this->pdo->beginTransaction();
        try {
            $result = $callback();
            if ($owns) $this->pdo->commit();
            return $result;
        } catch (Throwable $error) {
            if ($owns && $this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }
}
