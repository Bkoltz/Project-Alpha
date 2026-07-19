<?php

declare(strict_types=1);

namespace App\Services;

use App\Modules\Timekeeping\Uuid;
use DomainException;
use PDO;
use Throwable;

/** Append-only client credit ledger. A credit is never represented as a negative payment. */
final class ClientCreditLedgerService
{
    public function __construct(private readonly PDO $pdo) {}

    public function issueFromInvoice(int $invoiceId, string $amount, string $currency, string $reason, int $actorId): string
    {
        $amount = self::money($amount);
        $currency = self::currency($currency);
        if (trim($reason) === '' || !$this->canManage($actorId)) {
            throw new DomainException('A client credit requires a reason and authenticated actor.');
        }

        return $this->transaction(function () use ($invoiceId, $amount, $currency, $reason, $actorId): string {
            $statement = $this->pdo->prepare('SELECT id,client_id,organization_id,status,total,amount_paid,credit_due FROM invoices WHERE id=? FOR UPDATE');
            $statement->execute([$invoiceId]);
            $invoice = $statement->fetch(PDO::FETCH_ASSOC);
            if (!$invoice || !in_array((string)$invoice['status'], ['sent','unpaid','partial','paid','overdue'], true)) {
                throw new DomainException('Credits can be issued only from a finalized client invoice.');
            }
            $id = Uuid::v4();
            $this->pdo->prepare(
                'INSERT INTO client_credits
                 (id,client_id,organization_id,source_invoice_id,currency,original_amount,remaining_amount,reason,created_by)
                 VALUES (?,?,?,?,?,?,?,?,?)'
            )->execute([
                $id, $invoice['client_id'], $invoice['organization_id'], $invoiceId, $currency,
                $amount, $amount, trim($reason), $actorId,
            ]);
            $this->event($id, 'issued', null, $amount, null, null, trim($reason), $actorId, null, [
                'source_invoice_id' => $invoiceId,
                'invoice_status' => $invoice['status'],
                'invoice_total' => $invoice['total'],
                'invoice_amount_paid' => $invoice['amount_paid'],
            ]);
            $this->pdo->prepare('UPDATE invoices SET credit_due=credit_due+? WHERE id=?')->execute([$amount, $invoiceId]);
            return $id;
        });
    }

    public function allocate(string $creditId, int $invoiceId, string $amount, int $actorId, ?string $reason = null): int
    {
        $amount = self::money($amount);
        if (!$this->canManage($actorId)) {
            throw new DomainException('Client-credit management permission is required.');
        }
        return $this->transaction(function () use ($creditId, $invoiceId, $amount, $actorId, $reason): int {
            $credit = $this->creditForUpdate($creditId);
            if (!in_array((string)$credit['status'], ['available','partially_applied'], true)
                || (float)$credit['remaining_amount'] < (float)$amount) {
                throw new DomainException('The client credit does not have enough available balance.');
            }
            $invoiceStatement = $this->pdo->prepare('SELECT id,client_id,organization_id,status,total,amount_paid,credit_applied,balance_due FROM invoices WHERE id=? FOR UPDATE');
            $invoiceStatement->execute([$invoiceId]);
            $invoice = $invoiceStatement->fetch(PDO::FETCH_ASSOC);
            if (!$invoice || (int)$invoice['client_id'] !== (int)$credit['client_id']
                || (string)($invoice['organization_id'] ?? '') !== (string)($credit['organization_id'] ?? '')) {
                throw new DomainException('Client credits may be applied only within the same client and organization.');
            }
            if (in_array((string)$invoice['status'], ['draft','cancelled','void','paid'], true)) {
                throw new DomainException('Choose an open finalized invoice for this credit.');
            }
            if ((float)$invoice['balance_due'] + 0.00001 < (float)$amount) {
                throw new DomainException('A client credit allocation cannot exceed the invoice balance.');
            }
            $remaining = number_format((float)$credit['remaining_amount'] - (float)$amount, 2, '.', '');
            $status = (float)$remaining === 0.0 ? 'applied' : 'partially_applied';
            $this->pdo->prepare('UPDATE client_credits SET remaining_amount=?,status=? WHERE id=?')
                ->execute([$remaining, $status, $creditId]);
            $this->adjustSourceCreditDue($credit, -(float)$amount);
            $invoiceAfter = $this->applyInvoiceCreditDelta($invoiceId, (float)$amount);
            return $this->event($creditId, 'allocated', $invoiceId, $amount, null, null, $reason, $actorId, null, [
                'remaining_after' => $remaining,
                'currency' => $credit['currency'],
                'cash_amount_paid_unchanged' => number_format((float)$invoice['amount_paid'], 2, '.', ''),
                'credit_applied_after' => $invoiceAfter['credit_applied'],
                'balance_due_after' => $invoiceAfter['balance_due'],
            ]);
        });
    }

    public function reverseAllocation(string $creditId, int $allocationEventId, string $reason, int $actorId): int
    {
        if (trim($reason) === '' || !$this->canManage($actorId)) {
            throw new DomainException('Reversing a client credit allocation requires a reason.');
        }
        return $this->transaction(function () use ($creditId, $allocationEventId, $reason, $actorId): int {
            $credit = $this->creditForUpdate($creditId);
            $statement = $this->pdo->prepare(
                "SELECT e.* FROM client_credit_events e WHERE e.id=? AND e.client_credit_id=? AND e.event_type='allocated' FOR UPDATE"
            );
            $statement->execute([$allocationEventId, $creditId]);
            $allocation = $statement->fetch(PDO::FETCH_ASSOC);
            if (!$allocation) {
                throw new DomainException('Client credit allocation not found.');
            }
            $duplicate = $this->pdo->prepare("SELECT 1 FROM client_credit_events WHERE reverses_event_id=? AND event_type='allocation_reversed'");
            $duplicate->execute([$allocationEventId]);
            if ($duplicate->fetchColumn()) {
                throw new DomainException('This client credit allocation has already been reversed.');
            }
            $amount = self::money((string)$allocation['amount']);
            $remaining = number_format((float)$credit['remaining_amount'] + (float)$amount, 2, '.', '');
            $this->pdo->prepare("UPDATE client_credits SET remaining_amount=?,status=CASE WHEN ?=original_amount THEN 'available' ELSE 'partially_applied' END WHERE id=?")
                ->execute([$remaining, $remaining, $creditId]);
            $this->adjustSourceCreditDue($credit, (float)$amount);
            if ($allocation['invoice_id'] !== null) {
                $this->applyInvoiceCreditDelta((int)$allocation['invoice_id'], -(float)$amount);
            }
            return $this->event($creditId, 'allocation_reversed', $allocation['invoice_id'] === null ? null : (int)$allocation['invoice_id'], $amount, null, null, trim($reason), $actorId, $allocationEventId, [
                'remaining_after' => $remaining,
            ]);
        });
    }

    public function recordRefund(string $creditId, string $amount, ?int $paymentId, ?string $reference, string $reason, int $actorId): int
    {
        $amount = self::money($amount);
        if (trim($reason) === '' || !$this->canManage($actorId)) {
            throw new DomainException('Recording a client refund requires a reason.');
        }
        return $this->transaction(function () use ($creditId, $amount, $paymentId, $reference, $reason, $actorId): int {
            $credit = $this->creditForUpdate($creditId);
            if ((float)$credit['remaining_amount'] < (float)$amount) {
                throw new DomainException('The refund cannot exceed the available client credit.');
            }
            $remaining = number_format((float)$credit['remaining_amount'] - (float)$amount, 2, '.', '');
            $status = (float)$remaining === 0.0 ? 'refunded' : 'partially_applied';
            $this->pdo->prepare('UPDATE client_credits SET remaining_amount=?,status=? WHERE id=?')
                ->execute([$remaining, $status, $creditId]);
            $this->adjustSourceCreditDue($credit, -(float)$amount);
            return $this->event($creditId, 'refund_recorded', null, $amount, $paymentId, $reference, trim($reason), $actorId, null, [
                'remaining_after' => $remaining,
                'external_refund' => true,
            ]);
        });
    }

    /** @return array<string,mixed> */
    private function creditForUpdate(string $id): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM client_credits WHERE id=? FOR UPDATE');
        $statement->execute([$id]);
        $credit = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$credit || in_array((string)$credit['status'], ['voided','refunded'], true)) {
            throw new DomainException('Available client credit not found.');
        }
        return $credit;
    }

    /** @param array<string,mixed> $snapshot */
    private function event(string $creditId, string $type, ?int $invoiceId, string $amount, ?int $paymentId, ?string $reference, ?string $reason, int $actorId, ?int $reverses, array $snapshot): int
    {
        $this->pdo->prepare(
            'INSERT INTO client_credit_events
             (client_credit_id,event_type,invoice_id,amount,payment_id,reference_number,reason,event_snapshot,actor_id,reverses_event_id)
             VALUES (?,?,?,?,?,?,?,?,?,?)'
        )->execute([$creditId, $type, $invoiceId, $amount, $paymentId, $reference, $reason, json_encode($snapshot, JSON_THROW_ON_ERROR), $actorId, $reverses]);
        return (int)$this->pdo->lastInsertId();
    }

    /** @param array<string,mixed> $credit */
    private function adjustSourceCreditDue(array $credit, float $delta): void
    {
        if (empty($credit['source_invoice_id'])) {
            return;
        }
        if ($delta < 0) {
            $this->pdo->prepare('UPDATE invoices SET credit_due=GREATEST(0,credit_due-?) WHERE id=?')
                ->execute([number_format(abs($delta), 2, '.', ''), $credit['source_invoice_id']]);
        } else {
            $this->pdo->prepare('UPDATE invoices SET credit_due=credit_due+? WHERE id=?')
                ->execute([number_format($delta, 2, '.', ''), $credit['source_invoice_id']]);
        }
    }

    /** @return array{credit_applied:string,balance_due:string,status:string} */
    private function applyInvoiceCreditDelta(int $invoiceId, float $delta): array
    {
        $statement = $this->pdo->prepare('SELECT id,status,total,amount_paid,credit_applied FROM invoices WHERE id=? FOR UPDATE');
        $statement->execute([$invoiceId]);
        $invoice = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$invoice) {
            throw new DomainException('Credit target invoice not found.');
        }
        $cashPaid = max(0.0, (float)$invoice['amount_paid']);
        $creditApplied = max(0.0, (float)$invoice['credit_applied'] + $delta);
        $total = max(0.0, (float)$invoice['total']);
        if ($creditApplied > max(0.0, $total - $cashPaid) + 0.005) {
            throw new DomainException('Applied client credit cannot exceed the non-cash invoice balance.');
        }
        $balance = max(0.0, $total - $cashPaid - $creditApplied);
        $status = match (true) {
            $cashPaid + 0.005 >= $total => 'paid',
            $balance <= 0.005 => 'credited',
            $cashPaid > 0.005 || $creditApplied > 0.005 => 'partial',
            default => 'unpaid',
        };
        $this->pdo->prepare(
            "UPDATE invoices SET credit_applied=?,balance_due=?,status=?,paid_at=CASE WHEN ?='paid' THEN paid_at ELSE NULL END WHERE id=?"
        )->execute([
            number_format($creditApplied, 2, '.', ''), number_format($balance, 2, '.', ''), $status, $status, $invoiceId,
        ]);
        return [
            'credit_applied' => number_format($creditApplied, 2, '.', ''),
            'balance_due' => number_format($balance, 2, '.', ''),
            'status' => $status,
        ];
    }

    private static function money(string $amount): string
    {
        if (!is_numeric($amount) || (float)$amount <= 0) {
            throw new DomainException('Amount must be greater than zero.');
        }
        return number_format(round((float)$amount, 2), 2, '.', '');
    }

    private static function currency(string $currency): string
    {
        $currency = strtoupper(trim($currency));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new DomainException('Currency must use a three-letter code.');
        }
        return $currency;
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
