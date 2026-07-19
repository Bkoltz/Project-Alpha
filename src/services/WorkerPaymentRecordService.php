<?php

declare(strict_types=1);

namespace App\Services;

use App\Modules\Timekeeping\Uuid;
use DomainException;
use PDO;
use Throwable;

/** Records the admin-confirmed fact of payment independently from calculated statements. */
final class WorkerPaymentRecordService
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * @param array<int,array{statement_id:int,amount:string|int|float}> $allocations
     */
    public function record(
        int $workerProfileId,
        string $paymentDate,
        string $amount,
        string $currency,
        string $method,
        ?string $reference,
        ?string $notes,
        array $allocations,
        int $actorId
    ): string {
        $amount = self::money($amount);
        $currency = self::currency($currency);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $paymentDate) || trim($method) === '' || !$this->canManage($actorId)) {
            throw new DomainException('Worker payment date, method, and authenticated actor are required.');
        }
        if ($allocations === []) {
            throw new DomainException('Allocate a worker payment to at least one statement.');
        }

        return $this->transaction(function () use ($workerProfileId, $paymentDate, $amount, $currency, $method, $reference, $notes, $allocations, $actorId): string {
            $worker = $this->pdo->prepare('SELECT id,currency FROM worker_profiles WHERE id=? FOR UPDATE');
            $worker->execute([$workerProfileId]);
            $profile = $worker->fetch(PDO::FETCH_ASSOC);
            if (!$profile || strtoupper((string)$profile['currency']) !== $currency) {
                throw new DomainException('Worker payment currency must match the worker profile.');
            }

            $normalized = [];
            $allocated = 0.0;
            foreach ($allocations as $allocation) {
                $statementId = (int)($allocation['statement_id'] ?? 0);
                $allocationAmount = self::money((string)($allocation['amount'] ?? '0'));
                if ($statementId <= 0 || isset($normalized[$statementId])) {
                    throw new DomainException('Each worker statement may be allocated only once per payment.');
                }
                $statement = $this->pdo->prepare(
                    "SELECT s.*,
                       COALESCE((SELECT SUM(a.amount) FROM worker_payment_allocations a
                         JOIN worker_payment_records p ON p.id=a.worker_payment_record_id AND p.status='confirmed'
                         WHERE a.worker_statement_id=s.id),0) paid_amount
                     FROM worker_statements s WHERE s.id=? FOR UPDATE"
                );
                $statement->execute([$statementId]);
                $row = $statement->fetch(PDO::FETCH_ASSOC);
                if (!$row || (int)$row['worker_profile_id'] !== $workerProfileId
                    || (string)$row['currency'] !== $currency
                    || !in_array((string)$row['status'], ['issued','settled'], true)) {
                    throw new DomainException('Worker payments can be allocated only to this worker’s issued statements.');
                }
                if ((float)$row['total_amount'] <= 0) {
                    throw new DomainException('A zero or negative worker statement is not payable. Carry debit corrections forward to positive earnings.');
                }
                $outstanding = max(0.0, (float)$row['total_amount'] - (float)$row['paid_amount']);
                if ((float)$allocationAmount > $outstanding + 0.00001) {
                    throw new DomainException('Worker payment allocation exceeds the statement balance.');
                }
                $normalized[$statementId] = ['amount' => $allocationAmount, 'outstanding' => $outstanding];
                $allocated += (float)$allocationAmount;
            }
            if (abs($allocated - (float)$amount) > 0.005) {
                throw new DomainException('Worker payment allocations must equal the payment amount.');
            }

            $id = Uuid::v4();
            $this->pdo->prepare(
                'INSERT INTO worker_payment_records
                 (id,worker_profile_id,payment_date,amount,currency,payment_method,reference_number,notes,created_by)
                 VALUES (?,?,?,?,?,?,?,?,?)'
            )->execute([
                $id, $workerProfileId, $paymentDate, $amount, $currency, mb_substr(trim($method), 0, 50),
                $reference === null ? null : mb_substr(trim($reference), 0, 255),
                $notes === null ? null : mb_substr(trim($notes), 0, 1000), $actorId,
            ]);
            foreach ($normalized as $statementId => $allocation) {
                $this->pdo->prepare(
                    'INSERT INTO worker_payment_allocations (worker_payment_record_id,worker_statement_id,amount) VALUES (?,?,?)'
                )->execute([$id, $statementId, $allocation['amount']]);
                $newPaid = $allocation['outstanding'] - (float)$allocation['amount'];
                if ($newPaid <= 0.005) {
                    $this->pdo->prepare(
                        "UPDATE worker_statements SET status='settled',settled_at=COALESCE(settled_at,UTC_TIMESTAMP(6))
                         WHERE id=? AND status='issued'"
                    )->execute([$statementId]);
                    $this->pdo->prepare(
                        "UPDATE worker_earnings e JOIN worker_statement_lines l
                           ON e.id=COALESCE(l.worker_earning_id,
                             JSON_UNQUOTE(JSON_EXTRACT(l.calculation_snapshot,'$.replacement_source.worker_earning_id')))
                         SET e.status='settled',e.settled_at=COALESCE(e.settled_at,UTC_TIMESTAMP(6))
                         WHERE l.worker_statement_id=? AND e.status='included'"
                    )->execute([$statementId]);
                }
            }
            return $id;
        });
    }

    public function void(string $paymentRecordId, string $reason, int $actorId): void
    {
        if (trim($reason) === '' || !$this->canManage($actorId)) {
            throw new DomainException('Voiding a worker payment record requires a reason.');
        }
        $this->transaction(function () use ($paymentRecordId, $reason, $actorId): void {
            $statement = $this->pdo->prepare('SELECT * FROM worker_payment_records WHERE id=? FOR UPDATE');
            $statement->execute([$paymentRecordId]);
            $payment = $statement->fetch(PDO::FETCH_ASSOC);
            if (!$payment || (string)$payment['status'] !== 'confirmed') {
                throw new DomainException('Confirmed worker payment record not found.');
            }
            $this->pdo->prepare(
                "UPDATE worker_payment_records SET status='voided',voided_by=?,voided_at=UTC_TIMESTAMP(6),void_reason=? WHERE id=?"
            )->execute([$actorId, trim($reason), $paymentRecordId]);
            $statements = $this->pdo->prepare('SELECT worker_statement_id FROM worker_payment_allocations WHERE worker_payment_record_id=?');
            $statements->execute([$paymentRecordId]);
            foreach ($statements->fetchAll(PDO::FETCH_COLUMN) as $statementId) {
                $balance = $this->pdo->prepare(
                    "SELECT s.total_amount,COALESCE(SUM(CASE WHEN p.status='confirmed' THEN a.amount ELSE 0 END),0) paid
                     FROM worker_statements s LEFT JOIN worker_payment_allocations a ON a.worker_statement_id=s.id
                     LEFT JOIN worker_payment_records p ON p.id=a.worker_payment_record_id WHERE s.id=? GROUP BY s.id"
                );
                $balance->execute([$statementId]);
                $row = $balance->fetch(PDO::FETCH_ASSOC);
                if ($row && (float)$row['paid'] + 0.005 < (float)$row['total_amount']) {
                    $this->pdo->prepare("UPDATE worker_statements SET status='issued',settled_at=NULL WHERE id=? AND status='settled'")
                        ->execute([$statementId]);
                    $this->pdo->prepare(
                        "UPDATE worker_earnings e JOIN worker_statement_lines l
                           ON e.id=COALESCE(l.worker_earning_id,
                             JSON_UNQUOTE(JSON_EXTRACT(l.calculation_snapshot,'$.replacement_source.worker_earning_id')))
                         SET e.status='included',e.settled_at=NULL WHERE l.worker_statement_id=? AND e.status='settled'"
                    )->execute([$statementId]);
                }
            }
        });
    }

    private static function money(string $amount): string
    {
        if (!is_numeric($amount) || (float)$amount <= 0) {
            throw new DomainException('Payment amount must be greater than zero.');
        }
        return number_format(round((float)$amount, 2), 2, '.', '');
    }

    private static function currency(string $currency): string
    {
        $currency = strtoupper(trim($currency));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new DomainException('Payment currency must use a three-letter code.');
        }
        return $currency;
    }

    private function canManage(int $userId): bool
    {
        if ($userId <= 0) return false;
        if (function_exists('user_can') && \user_can($this->pdo, $userId, 'workforce.payments.manage')) return true;
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
