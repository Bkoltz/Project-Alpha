<?php

declare(strict_types=1);

namespace App\Services;

use DomainException;
use PDO;

/** Applies an approved pay delta without rewriting an issued or settled statement. */
final class WorkerStatementCorrectionService
{
    public function __construct(private readonly PDO $pdo) {}

    /** @return array{action:string,adjustment_id:?int,original_statement_id:?int,replacement_statement_id:?int,carryforward_adjustment_id?:?int} */
    public function applyDelta(string $earningId, string $signedDelta, string $reason, int $actorId, ?int $nextOpenPeriodId = null): array
    {
        if (!is_numeric($signedDelta) || trim($reason) === '' || $actorId <= 0) {
            throw new DomainException('A pay correction requires a numeric delta, reason, and actor.');
        }
        $delta = round((float)$signedDelta, 2);
        if (abs($delta) < 0.005) {
            return ['action' => 'none', 'adjustment_id' => null, 'original_statement_id' => null, 'replacement_statement_id' => null];
        }
        $earningStatement = $this->pdo->prepare(
            'SELECT e.*,l.worker_statement_id,s.status statement_status,s.pay_period_id statement_pay_period_id,s.statement_version,
                    s.statement_type,s.currency,s.gross_amount,s.adjustment_amount,s.total_amount,s.worker_profile_id,
                    s.created_by statement_created_by
             FROM worker_earnings e
             LEFT JOIN worker_statement_lines l ON l.id=e.statement_line_id
             LEFT JOIN worker_statements s ON s.id=l.worker_statement_id
             WHERE e.id=? FOR UPDATE'
        );
        $earningStatement->execute([$earningId]);
        $earning = $earningStatement->fetch(PDO::FETCH_ASSOC);
        if (!$earning) {
            throw new DomainException('Original worker earning not found.');
        }
        $statementId = $earning['worker_statement_id'] === null ? null : (int)$earning['worker_statement_id'];
        $locked = false;
        if ($statementId !== null) {
            $lock = $this->pdo->prepare(
                "SELECT EXISTS(SELECT 1 FROM worker_payment_allocations a JOIN worker_payment_records p
                    ON p.id=a.worker_payment_record_id AND p.status='confirmed' WHERE a.worker_statement_id=?)
                       OR EXISTS(SELECT 1 FROM payroll_export_rows r JOIN payroll_exports x ON x.id=r.payroll_export_id
                    JOIN worker_statement_lines l2 ON l2.worker_earning_id=r.worker_earning_id
                    WHERE l2.worker_statement_id=? AND x.status='generated')"
            );
            $lock->execute([$statementId, $statementId]);
            $locked = (bool)$lock->fetchColumn() || in_array((string)$earning['statement_status'], ['settled'], true);
        }

        if ($statementId !== null && (string)$earning['statement_status'] === 'issued' && !$locked) {
            return $this->voidAndReissue($earning, $delta, $reason, $actorId, $nextOpenPeriodId);
        }

        $periodId = $nextOpenPeriodId ?: $this->nextOpenPeriodId();
        $adjustmentId = $this->recordReviewedAdjustment(
            (int)$earning['worker_profile_id'],
            $periodId,
            $delta,
            $reason,
            $actorId,
            ['original_earning_id' => $earningId, 'original_statement_id' => $statementId],
            false,
            $delta < 0
        );
        if (in_array((string)$earning['status'], ['included','settled'], true)) {
            (new WorkerEarningService($this->pdo))->transition($earningId, 'adjusted', $actorId, $reason);
        }
        return [
            'action' => $locked ? 'next_period_adjustment' : 'draft_rebuild',
            'adjustment_id' => $adjustmentId,
            'original_statement_id' => $statementId,
            'replacement_statement_id' => null,
        ];
    }

    /** @param array<string,mixed> $earning */
    private function voidAndReissue(
        array $earning,
        float $delta,
        string $reason,
        int $actorId,
        ?int $nextOpenPeriodId
    ): array
    {
        $statementId = (int)$earning['worker_statement_id'];
        $version = (int)$earning['statement_version'] + 1;
        $originalTotal = max(0.0, (float)$earning['total_amount']);
        $statementDelta = max($delta, -$originalTotal);
        $carryforwardDelta = round($delta - $statementDelta, 2);
        $this->pdo->prepare(
            "UPDATE worker_statements SET status='voided',voided_by=?,voided_at=UTC_TIMESTAMP(6),void_reason=?
             WHERE id=? AND status='issued'"
        )->execute([$actorId, $reason, $statementId]);
        $this->pdo->prepare(
            "INSERT INTO worker_statements
             (pay_period_id,worker_profile_id,statement_version,replaces_statement_id,statement_type,status,currency,
              gross_amount,adjustment_amount,total_amount,issued_at,created_by)
             VALUES (?,?,?,?,?,'issued',?,?,?,?,UTC_TIMESTAMP(6),?)"
        )->execute([
            $earning['statement_pay_period_id'], $earning['worker_profile_id'], $version, $statementId,
            $earning['statement_type'], $earning['currency'], $earning['gross_amount'],
            number_format((float)$earning['adjustment_amount'] + $statementDelta, 2, '.', ''),
            number_format(max(0.0, (float)$earning['total_amount'] + $statementDelta), 2, '.', ''), $actorId,
        ]);
        $replacementId = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare(
            'INSERT INTO worker_statement_lines
             (worker_statement_id,worker_earning_id,work_assignment_id,work_time_entry_id,description,quantity,rate,amount,calculation_snapshot)
             SELECT ?,NULL,work_assignment_id,work_time_entry_id,description,quantity,rate,amount,
                    JSON_SET(calculation_snapshot,\'$.replacement_source\',JSON_OBJECT(
                        \'statement_id\',worker_statement_id,\'statement_line_id\',id,\'worker_earning_id\',worker_earning_id))
             FROM worker_statement_lines WHERE worker_statement_id=? ORDER BY id'
        )->execute([$replacementId, $statementId]);

        $adjustmentId = null;
        if (abs($statementDelta) >= 0.005) {
            $adjustmentId = $this->recordReviewedAdjustment(
                (int)$earning['worker_profile_id'],
                (int)$earning['statement_pay_period_id'],
                $statementDelta,
                $reason,
                $actorId,
                ['original_earning_id' => $earning['id'], 'original_statement_id' => $statementId],
                true
            );
            $snapshot = [
                'adjustment_id' => $adjustmentId,
                'direction' => $statementDelta < 0 ? 'debit' : 'credit',
                'reason' => $reason,
                'replaces_statement_id' => $statementId,
            ];
            $correctionEarning = (new WorkerEarningService($this->pdo))->record(
                'adjustment', (string)$adjustmentId, 1, (int)$earning['worker_profile_id'], 'adjustment', '1', null,
                number_format(abs($statementDelta), 2, '.', ''), (string)$earning['currency'], $snapshot, $actorId, 'approved',
                null, null, (int)$earning['statement_pay_period_id']
            );
            $this->pdo->prepare(
                'INSERT INTO worker_statement_lines
                 (worker_statement_id,description,quantity,amount,calculation_snapshot) VALUES (?,?,1,?,?)'
            )->execute([$replacementId, $reason, number_format($statementDelta, 2, '.', ''), json_encode($snapshot, JSON_THROW_ON_ERROR)]);
            $lineId = (int)$this->pdo->lastInsertId();
            (new WorkerEarningService($this->pdo))->includeOnStatement((string)$correctionEarning['id'], $lineId, $actorId);
            $this->pdo->prepare("UPDATE compensation_adjustments SET status='applied',statement_line_id=? WHERE id=?")
                ->execute([$lineId, $adjustmentId]);
        }
        $carryforwardAdjustmentId = null;
        if ($carryforwardDelta < -0.005) {
            $carryforwardAdjustmentId = $this->recordReviewedAdjustment(
                (int)$earning['worker_profile_id'],
                $nextOpenPeriodId ?: $this->nextOpenPeriodId(),
                $carryforwardDelta,
                $reason,
                $actorId,
                [
                    'original_earning_id' => $earning['id'],
                    'original_statement_id' => $statementId,
                    'replacement_statement_id' => $replacementId,
                    'carryforward' => true,
                ],
                false,
                true
            );
        }
        if (in_array((string)$earning['status'], ['included','settled'], true)) {
            (new WorkerEarningService($this->pdo))->transition((string)$earning['id'], 'adjusted', $actorId, $reason);
        }
        return [
            'action' => 'void_reissue',
            'adjustment_id' => $adjustmentId,
            'original_statement_id' => $statementId,
            'replacement_statement_id' => $replacementId,
            'carryforward_adjustment_id' => $carryforwardAdjustmentId,
        ];
    }

    /** @param array<string,mixed> $source */
    private function recordReviewedAdjustment(
        int $workerId,
        int $periodId,
        float $delta,
        string $reason,
        int $actorId,
        array $source,
        bool $allowClosed = false,
        bool $deferDebit = false
    ): int
    {
        $period = $this->pdo->prepare('SELECT status FROM pay_periods WHERE id=? FOR UPDATE');
        $period->execute([$periodId]);
        $status = $period->fetchColumn();
        if ($status === false) {
            throw new DomainException('Correction pay period not found.');
        }
        if (!$allowClosed && $status !== 'open') {
            throw new DomainException('Settled-pay corrections must be placed in an open pay period.');
        }
        $status = $deferDebit && $delta < 0 ? 'pending' : 'reviewed';
        if ($status === 'pending') {
            $source['carryforward'] = true;
            $source['review_required_before_statement'] = true;
        }
        $this->pdo->prepare(
            "INSERT INTO compensation_adjustments
             (worker_profile_id,pay_period_id,adjustment_type,amount,reason,source_snapshot,status,reviewed_by,reviewed_at,created_by)
             VALUES (?,?,?,?,?,?,?,CASE WHEN ?='reviewed' THEN ? ELSE NULL END,
                     CASE WHEN ?='reviewed' THEN UTC_TIMESTAMP(6) ELSE NULL END,?)"
        )->execute([
            $workerId, $periodId, $delta < 0 ? 'debit' : 'credit', number_format(abs($delta), 2, '.', ''),
            $reason, json_encode($source, JSON_THROW_ON_ERROR), $status, $status, $actorId, $status, $actorId,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    private function nextOpenPeriodId(): int
    {
        $id = $this->pdo->query("SELECT id FROM pay_periods WHERE status='open' ORDER BY period_start LIMIT 1 FOR UPDATE")->fetchColumn();
        if (!$id) {
            throw new DomainException('Create an open pay period before approving a settled-pay correction.');
        }
        return (int)$id;
    }
}
