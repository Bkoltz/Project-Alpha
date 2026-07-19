<?php

declare(strict_types=1);

namespace App\Services;

use App\Modules\Timekeeping\Uuid;
use DomainException;
use PDO;
use Throwable;

/** Produces an immutable, provider-neutral CSV of approved gross earnings. */
final class PayrollExportService
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * @param list<string> $earningIds
     * @return array{id:string,file_name:string,csv:string,sha256:string,row_count:int,gross_total:string}
     */
    public function generate(string $exportKey, array $earningIds, int $actorId, ?int $payPeriodId = null): array
    {
        $exportKey = trim($exportKey);
        $earningIds = array_values(array_unique(array_filter(array_map('trim', $earningIds))));
        if ($exportKey === '' || mb_strlen($exportKey) > 190 || $earningIds === [] || !$this->canManage($actorId)) {
            throw new DomainException('Payroll export key, approved earnings, and actor are required.');
        }
        if ($payPeriodId !== null && $payPeriodId <= 0) {
            throw new DomainException('Payroll pay period must be a positive identifier.');
        }

        return $this->transaction(function () use ($exportKey, $earningIds, $actorId, $payPeriodId): array {
            if ($payPeriodId !== null) {
                $period = $this->pdo->prepare('SELECT id FROM pay_periods WHERE id=? FOR UPDATE');
                $period->execute([$payPeriodId]);
                if (!$period->fetchColumn()) {
                    throw new DomainException('Payroll pay period not found.');
                }
            }
            $existing = $this->pdo->prepare('SELECT * FROM payroll_exports WHERE export_key=?');
            $existing->execute([$exportKey]);
            $export = $existing->fetch(PDO::FETCH_ASSOC);
            if ($export) {
                $existingPeriod = $export['pay_period_id'] === null ? null : (int)$export['pay_period_id'];
                if ($existingPeriod !== $payPeriodId) {
                    throw new DomainException('This payroll export key already belongs to a different pay period.');
                }
                if ($payPeriodId !== null) {
                    $mismatch = $this->pdo->prepare(
                        'SELECT COUNT(*) FROM payroll_export_rows r JOIN worker_earnings e ON e.id=r.worker_earning_id
                         WHERE r.payroll_export_id=? AND (e.pay_period_id IS NULL OR e.pay_period_id<>?)'
                    );
                    $mismatch->execute([$export['id'], $payPeriodId]);
                    if ((int)$mismatch->fetchColumn() > 0) {
                        throw new DomainException('Stored payroll export rows do not match the labeled pay period.');
                    }
                }
                return self::result($export);
            }

            $placeholders = implode(',', array_fill(0, count($earningIds), '?'));
            $statement = $this->pdo->prepare(
                "SELECT e.*,wp.display_name,u.email,
                        COALESCE(DATE(wt.start_time),DATE(wa.completed_at),DATE(e.eligible_at),DATE(e.approved_at),DATE(e.created_at)) work_date,
                        JSON_UNQUOTE(JSON_EXTRACT(e.calculation_snapshot,'$.direction')) direction,
                        JSON_UNQUOTE(JSON_EXTRACT(e.calculation_snapshot,'$.reason')) correction_reason
                 FROM worker_earnings e
                 JOIN worker_profiles wp ON wp.id=e.worker_profile_id
                 LEFT JOIN users u ON u.id=wp.user_id
                 LEFT JOIN work_time_entries wt ON wt.id=e.work_time_entry_id
                 LEFT JOIN work_assignments wa ON wa.id=e.work_assignment_id
                 WHERE e.id IN ({$placeholders}) AND e.status IN ('approved','included','settled')
                 ORDER BY wp.display_name,work_date,e.created_at,e.id FOR UPDATE"
            );
            $statement->execute($earningIds);
            $earnings = $statement->fetchAll(PDO::FETCH_ASSOC);
            if (count($earnings) !== count($earningIds)) {
                throw new DomainException('Every payroll export row must be an approved, included, or settled earning.');
            }
            if ($payPeriodId !== null) {
                foreach ($earnings as $earning) {
                    if ($earning['pay_period_id'] === null || (int)$earning['pay_period_id'] !== $payPeriodId) {
                        throw new DomainException('Every selected earning must belong to the labeled payroll pay period.');
                    }
                }
            }
            $alreadyExported = $this->pdo->prepare(
                "SELECT r.worker_earning_id FROM payroll_export_rows r JOIN payroll_exports x ON x.id=r.payroll_export_id
                 WHERE r.worker_earning_id IN ({$placeholders}) AND x.status='generated' LIMIT 1"
            );
            $alreadyExported->execute($earningIds);
            if ($alreadyExported->fetchColumn()) {
                throw new DomainException('One or more earnings are already present in an active payroll export.');
            }
            $currencies = array_values(array_unique(array_column($earnings, 'currency')));
            if (count($currencies) !== 1) {
                throw new DomainException('Create separate payroll exports for each currency.');
            }

            $stream = fopen('php://temp', 'w+');
            if ($stream === false) {
                throw new DomainException('Unable to create payroll CSV.');
            }
            fputcsv($stream, [
                'export_id','statement_id','earning_id','worker_id','worker_name','worker_email','period_id',
                'work_date','method','quantity','rate','gross_delta','currency','correction_reason',
            ]);
            $id = Uuid::v4();
            $rows = [];
            $total = 0.0;
            foreach ($earnings as $index => $earning) {
                $direction = strtolower((string)($earning['direction'] ?? ''));
                $signed = (float)$earning['amount'] * ($direction === 'debit' ? -1 : 1);
                $total += $signed;
                $row = [
                    $id,
                    $earning['statement_line_id'] ? $this->statementIdForLine((int)$earning['statement_line_id']) : '',
                    $earning['id'],
                    $earning['worker_profile_id'],
                    $earning['display_name'],
                    $earning['email'],
                    $earning['pay_period_id'],
                    $earning['work_date'],
                    $earning['method'],
                    $earning['quantity'],
                    $earning['rate'],
                    number_format($signed, 2, '.', ''),
                    $earning['currency'],
                    $earning['correction_reason'],
                ];
                fputcsv($stream, $row);
                $rows[] = [
                    'earning_id' => (string)$earning['id'],
                    'export_row_number' => $index + 1,
                    'signed_amount' => number_format($signed, 2, '.', ''),
                    'snapshot' => $row,
                ];
            }
            rewind($stream);
            $csv = stream_get_contents($stream);
            fclose($stream);
            if (!is_string($csv)) {
                throw new DomainException('Unable to read payroll CSV.');
            }
            $fileName = 'payroll-' . ($payPeriodId ?: gmdate('Ymd-His')) . '-' . substr($id, 0, 8) . '.csv';
            $hash = hash('sha256', $csv);
            $this->pdo->prepare(
                'INSERT INTO payroll_exports
                 (id,export_key,pay_period_id,file_name,content_sha256,csv_content,row_count,gross_total,currency,created_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?)'
            )->execute([
                $id, $exportKey, $payPeriodId, $fileName, $hash, $csv, count($rows),
                number_format($total, 2, '.', ''), $currencies[0], $actorId,
            ]);
            $insertRow = $this->pdo->prepare(
                'INSERT INTO payroll_export_rows (payroll_export_id,worker_earning_id,export_row_number,signed_amount,row_snapshot) VALUES (?,?,?,?,?)'
            );
            foreach ($rows as $row) {
                $insertRow->execute([
                    $id, $row['earning_id'], $row['export_row_number'], $row['signed_amount'],
                    json_encode($row['snapshot'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                ]);
            }
            return [
                'id' => $id,
                'file_name' => $fileName,
                'csv' => $csv,
                'sha256' => $hash,
                'row_count' => count($rows),
                'gross_total' => number_format($total, 2, '.', ''),
            ];
        });
    }

    public function void(string $exportId, string $reason, int $actorId): void
    {
        if (trim($reason) === '' || !$this->canManage($actorId)) {
            throw new DomainException('Voiding a payroll export requires a reason.');
        }
        $statement = $this->pdo->prepare(
            "UPDATE payroll_exports SET status='voided',voided_by=?,voided_at=UTC_TIMESTAMP(6),void_reason=?
             WHERE id=? AND status='generated'"
        );
        $statement->execute([$actorId, trim($reason), $exportId]);
        if ($statement->rowCount() !== 1) {
            throw new DomainException('Active payroll export not found.');
        }
    }

    private function statementIdForLine(int $lineId): string
    {
        $statement = $this->pdo->prepare('SELECT worker_statement_id FROM worker_statement_lines WHERE id=?');
        $statement->execute([$lineId]);
        return (string)($statement->fetchColumn() ?: '');
    }

    /** @param array<string,mixed> $row */
    private static function result(array $row): array
    {
        return [
            'id' => (string)$row['id'],
            'file_name' => (string)$row['file_name'],
            'csv' => (string)$row['csv_content'],
            'sha256' => (string)$row['content_sha256'],
            'row_count' => (int)$row['row_count'],
            'gross_total' => number_format((float)$row['gross_total'], 2, '.', ''),
        ];
    }

    private function canManage(int $userId): bool
    {
        if ($userId <= 0) return false;
        if (function_exists('user_can') && \user_can($this->pdo, $userId, 'workforce.payroll_exports.manage')) return true;
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
