<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Workforce\TimeEntryWorkflow;
use DomainException;
use PDO;
use Throwable;

/** Keeps client billing decisions independent from time approval and worker pay. */
final class TimeBillingAllocationService
{
    private const TREATMENTS = ['undecided', 'internal', 'fixed_price_included', 'hourly'];

    public function __construct(private readonly PDO $pdo) {}

    /**
     * @param array<string,mixed> $context
     * @return array{id:int,status:string,amount:?string,allocation_key:string}
     */
    public function allocate(
        string $timeEntryId,
        int $entryRevision,
        string $treatment,
        int $durationSeconds,
        ?string $rate,
        string $currency,
        int $actorId,
        array $context = [],
        ?string $idempotencyKey = null
    ): array {
        if (!in_array($treatment, self::TREATMENTS, true)) {
            throw new DomainException('Choose a valid client-billing treatment.');
        }
        if ($timeEntryId === '' || $entryRevision <= 0 || $durationSeconds <= 0 || $actorId <= 0) {
            throw new DomainException('Billing allocation data is incomplete.');
        }
        $currency = strtoupper(trim($currency));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new DomainException('Billing currency must be a three-letter code.');
        }
        $normalizedRate = self::normalizeRate($rate);
        $status = self::initialStatus($treatment, $normalizedRate);
        $quantity = number_format($durationSeconds / 3600, 4, '.', '');
        $amount = $treatment === 'hourly' && $normalizedRate !== null
            ? number_format(round(($durationSeconds / 3600) * (float)$normalizedRate + 1e-9, 2), 2, '.', '')
            : null;
        $contextIds = [
            'client_id' => self::nullablePositiveInt($context['client_id'] ?? null),
            'project_id' => self::nullablePositiveInt($context['project_id'] ?? null),
            'job_id' => self::nullablePositiveInt($context['job_id'] ?? null),
            'invoice_id' => self::nullablePositiveInt($context['invoice_id'] ?? null),
            'invoice_item_id' => self::nullablePositiveInt($context['invoice_item_id'] ?? null),
        ];
        $allocationKey = hash('sha256', trim((string)$idempotencyKey) !== ''
            ? trim((string)$idempotencyKey)
            : json_encode([
                $timeEntryId,
                $entryRevision,
                $treatment,
                $durationSeconds,
                $normalizedRate,
                $currency,
                $contextIds,
            ], JSON_THROW_ON_ERROR));

        return $this->transaction(function () use (
            $timeEntryId,
            $entryRevision,
            $treatment,
            $durationSeconds,
            $normalizedRate,
            $currency,
            $actorId,
            $context,
            $contextIds,
            $allocationKey,
            $status,
            $quantity,
            $amount
        ): array {
            $entryStatement = $this->pdo->prepare('SELECT * FROM work_time_entries WHERE id=? FOR UPDATE');
            $entryStatement->execute([$timeEntryId]);
            $entry = $entryStatement->fetch(PDO::FETCH_ASSOC);
            if (!$entry) {
                throw new DomainException('Time entry not found.');
            }
            if ((int)$entry['revision'] !== $entryRevision) {
                throw new DomainException('The time entry changed before billing was allocated.');
            }
            if (in_array((string)$entry['workflow_status'], [TimeEntryWorkflow::RUNNING, TimeEntryWorkflow::VOIDED], true)) {
                throw new DomainException('Running or voided time cannot be allocated for billing.');
            }
            if ($durationSeconds > (int)$entry['duration_seconds']) {
                throw new DomainException('Billing allocation cannot exceed the time-entry duration.');
            }

            $existingStatement = $this->pdo->prepare(
                'SELECT id,status,amount,allocation_key FROM work_time_billing_allocations WHERE allocation_key=?'
            );
            $existingStatement->execute([$allocationKey]);
            $existing = $existingStatement->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                return [
                    'id' => (int)$existing['id'],
                    'status' => (string)$existing['status'],
                    'amount' => $existing['amount'] === null ? null : number_format((float)$existing['amount'], 2, '.', ''),
                    'allocation_key' => (string)$existing['allocation_key'],
                ];
            }

            $allocatedStatement = $this->pdo->prepare(
                "SELECT COALESCE(SUM(duration_seconds),0) FROM work_time_billing_allocations
                 WHERE time_entry_id=? AND entry_revision=? AND status<>'reversed' FOR UPDATE"
            );
            $allocatedStatement->execute([$timeEntryId, $entryRevision]);
            if ((int)$allocatedStatement->fetchColumn() + $durationSeconds > (int)$entry['duration_seconds']) {
                throw new DomainException('Existing billing allocations already cover this duration.');
            }

            $snapshot = json_encode([
                'time_entry' => [
                    'id' => $entry['id'],
                    'revision' => (int)$entry['revision'],
                    'duration_seconds' => (int)$entry['duration_seconds'],
                    'description' => (string)$entry['description'],
                    'client_id' => self::nullablePositiveInt($entry['client_id'] ?? null),
                    'project_id' => self::nullablePositiveInt($entry['project_id'] ?? null),
                    'job_id' => self::nullablePositiveInt($entry['job_id'] ?? null),
                    'work_type_id' => self::nullablePositiveInt($entry['work_type_id'] ?? null),
                ],
                'decision' => [
                    'treatment' => $treatment,
                    'duration_seconds' => $durationSeconds,
                    'quantity' => $quantity,
                    'rate' => $normalizedRate,
                    'amount' => $amount,
                    'currency' => $currency,
                    'context' => $context,
                ],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            $insert = $this->pdo->prepare(
                'INSERT INTO work_time_billing_allocations
                 (allocation_key,time_entry_id,entry_revision,treatment,status,duration_seconds,quantity,rate,amount,currency,
                  client_id,project_id,job_id,invoice_id,invoice_item_id,allocation_snapshot,created_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            $insert->execute([
                $allocationKey,
                $timeEntryId,
                $entryRevision,
                $treatment,
                $status,
                $durationSeconds,
                $quantity,
                $normalizedRate,
                $amount,
                $currency,
                $contextIds['client_id'] ?? self::nullablePositiveInt($entry['client_id'] ?? null),
                $contextIds['project_id'] ?? self::nullablePositiveInt($entry['project_id'] ?? null),
                $contextIds['job_id'] ?? self::nullablePositiveInt($entry['job_id'] ?? null),
                $contextIds['invoice_id'],
                $contextIds['invoice_item_id'],
                $snapshot,
                $actorId,
            ]);
            $id = (int)$this->pdo->lastInsertId();
            $this->refreshEntryBillingState($timeEntryId, (int)$entry['duration_seconds']);
            return ['id' => $id, 'status' => $status, 'amount' => $amount, 'allocation_key' => $allocationKey];
        });
    }

    public function markInvoiced(int $allocationId, int $invoiceId, int $invoiceItemId): void
    {
        if ($allocationId <= 0 || $invoiceId <= 0 || $invoiceItemId <= 0) {
            throw new DomainException('Choose a billing allocation and invoice line.');
        }
        $this->transaction(function () use ($allocationId, $invoiceId, $invoiceItemId): void {
            $statement = $this->pdo->prepare(
                'SELECT a.*,t.workflow_status,t.duration_seconds AS entry_duration
                 FROM work_time_billing_allocations a
                 JOIN work_time_entries t ON t.id=a.time_entry_id
                 WHERE a.id=? FOR UPDATE'
            );
            $statement->execute([$allocationId]);
            $allocation = $statement->fetch(PDO::FETCH_ASSOC);
            if (!$allocation) {
                throw new DomainException('Billing allocation not found.');
            }
            if ((string)$allocation['workflow_status'] !== TimeEntryWorkflow::CONFIRMED) {
                throw new DomainException('Only confirmed time can be linked to an invoice.');
            }
            if ((string)$allocation['treatment'] !== 'hourly' || (string)$allocation['status'] !== 'ready') {
                throw new DomainException('Only ready hourly time can be linked to an invoice.');
            }
            $update = $this->pdo->prepare(
                "UPDATE work_time_billing_allocations SET status='invoiced',invoice_id=?,invoice_item_id=?
                 WHERE id=? AND status='ready'"
            );
            $update->execute([$invoiceId, $invoiceItemId, $allocationId]);
            if ($update->rowCount() !== 1) {
                throw new DomainException('The billing allocation changed before invoice linking completed.');
            }
            $this->refreshEntryBillingState((string)$allocation['time_entry_id'], (int)$allocation['entry_duration']);
        });
    }

    public function reverse(int $allocationId, int $actorId, string $reason): void
    {
        $reason = trim($reason);
        if ($allocationId <= 0 || $actorId <= 0 || $reason === '') {
            throw new DomainException('A reversal reason is required.');
        }
        $this->transaction(function () use ($allocationId, $actorId, $reason): void {
            $statement = $this->pdo->prepare(
                'SELECT a.*,t.duration_seconds AS entry_duration FROM work_time_billing_allocations a
                 JOIN work_time_entries t ON t.id=a.time_entry_id WHERE a.id=? FOR UPDATE'
            );
            $statement->execute([$allocationId]);
            $allocation = $statement->fetch(PDO::FETCH_ASSOC);
            if (!$allocation || (string)$allocation['status'] === 'reversed') {
                throw new DomainException('Active billing allocation not found.');
            }
            $this->pdo->prepare(
                "UPDATE work_time_billing_allocations
                 SET status='reversed',reversed_by=?,reversed_at=UTC_TIMESTAMP(6),reversal_reason=? WHERE id=?"
            )->execute([$actorId, $reason, $allocationId]);
            $this->refreshEntryBillingState((string)$allocation['time_entry_id'], (int)$allocation['entry_duration']);
        });
    }

    public static function initialStatus(string $treatment, ?string $rate): string
    {
        if (!in_array($treatment, self::TREATMENTS, true)) {
            throw new DomainException('Choose a valid client-billing treatment.');
        }
        return match ($treatment) {
            'undecided' => 'pending',
            'hourly' => $rate === null ? 'rate_needed' : 'ready',
            'internal', 'fixed_price_included' => 'ready',
        };
    }

    private function refreshEntryBillingState(string $timeEntryId, int $entryDuration): void
    {
        $statement = $this->pdo->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN status<>'reversed' THEN duration_seconds ELSE 0 END),0) allocated_seconds,
                COALESCE(SUM(CASE WHEN status='invoiced' THEN duration_seconds ELSE 0 END),0) invoiced_seconds,
                SUM(status='rate_needed') rate_needed_count,
                SUM(treatment='undecided' AND status<>'reversed') undecided_count,
                SUM(treatment='internal' AND status<>'reversed') internal_count,
                SUM(treatment='fixed_price_included' AND status<>'reversed') included_count,
                SUM(status='ready' AND treatment='hourly') ready_count,
                SUM(status<>'reversed') active_count,
                SUM(status='reversed') reversed_count
             FROM work_time_billing_allocations WHERE time_entry_id=?"
        );
        $statement->execute([$timeEntryId]);
        $totals = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
        $active = (int)($totals['active_count'] ?? 0);
        $invoiced = (int)($totals['invoiced_seconds'] ?? 0);
        $state = match (true) {
            $active === 0 && (int)($totals['reversed_count'] ?? 0) > 0 => 'reversed',
            $active === 0 => 'decide_later',
            $invoiced >= $entryDuration => 'invoiced',
            $invoiced > 0 => 'partially_invoiced',
            (int)($totals['rate_needed_count'] ?? 0) > 0 => 'rate_needed',
            (int)($totals['undecided_count'] ?? 0) > 0 || (int)($totals['allocated_seconds'] ?? 0) < $entryDuration => 'decide_later',
            (int)($totals['ready_count'] ?? 0) > 0 => 'ready',
            (int)($totals['included_count'] ?? 0) > 0 => 'fixed_price_included',
            default => 'internal',
        };
        $this->pdo->prepare('UPDATE work_time_entries SET billing_state=? WHERE id=?')
            ->execute([$state, $timeEntryId]);
    }

    private static function normalizeRate(?string $rate): ?string
    {
        $rate = trim((string)$rate);
        if ($rate === '') {
            return null;
        }
        if (!is_numeric($rate) || (float)$rate <= 0) {
            throw new DomainException('Billing rate must be greater than zero.');
        }
        return number_format((float)$rate, 4, '.', '');
    }

    private static function nullablePositiveInt(mixed $value): ?int
    {
        $value = (int)($value ?? 0);
        return $value > 0 ? $value : null;
    }

    private function transaction(callable $callback): mixed
    {
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $result = $callback();
            if ($ownsTransaction) {
                $this->pdo->commit();
            }
            return $result;
        } catch (Throwable $error) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }
}
