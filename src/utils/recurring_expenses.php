<?php

declare(strict_types=1);

/**
 * Calculate the next occurrence without month-end drift. A Jan 31 monthly
 * schedule therefore produces Feb 28/29 and then Mar 31, not Mar 28.
 */
function recurring_expense_next_date(
    string $currentDate,
    int $intervalCount,
    string $intervalUnit,
    string $anchorDate
): string {
    $intervalCount = max(1, $intervalCount);
    $current = new DateTimeImmutable($currentDate);
    $anchor = new DateTimeImmutable($anchorDate);

    if ($intervalUnit === 'week') {
        return $current->modify('+' . (7 * $intervalCount) . ' days')->format('Y-m-d');
    }

    if ($intervalUnit === 'year') {
        $targetYear = (int)$current->format('Y') + $intervalCount;
        $month = (int)$anchor->format('n');
        $daysInMonth = (int)(new DateTimeImmutable(sprintf('%04d-%02d-01', $targetYear, $month)))->format('t');
        $day = min((int)$anchor->format('j'), $daysInMonth);
        return sprintf('%04d-%02d-%02d', $targetYear, $month, $day);
    }

    if ($intervalUnit !== 'month') {
        throw new InvalidArgumentException('Recurring expense interval unit is invalid.');
    }

    $monthStart = $current->modify('first day of this month')->modify('+' . $intervalCount . ' months');
    $year = (int)$monthStart->format('Y');
    $month = (int)$monthStart->format('n');
    $daysInMonth = (int)$monthStart->format('t');
    $day = min((int)$anchor->format('j'), $daysInMonth);
    return sprintf('%04d-%02d-%02d', $year, $month, $day);
}

function recurring_expense_schedule_label(array $schedule): string
{
    $count = max(1, (int)($schedule['interval_count'] ?? 1));
    $unit = strtolower((string)($schedule['interval_unit'] ?? 'month'));
    if ($count === 1) {
        return match ($unit) {
            'week' => 'Weekly',
            'year' => 'Yearly',
            default => 'Monthly',
        };
    }
    if ($unit === 'month' && $count === 3) {
        return 'Quarterly';
    }
    return 'Every ' . $count . ' ' . ucfirst($unit) . ($count === 1 ? '' : 's');
}

function recurring_expense_annualized_amount(array $schedule): float
{
    $amount = max(0.0, (float)($schedule['amount'] ?? 0));
    $count = max(1, (int)($schedule['interval_count'] ?? 1));
    return round(match ((string)($schedule['interval_unit'] ?? 'month')) {
        'week' => $amount * 52 / $count,
        'year' => $amount / $count,
        default => $amount * 12 / $count,
    }, 2);
}

/**
 * Generate exactly one due occurrence. The expense occurrence unique key and
 * row lock make retries idempotent.
 *
 * @return array{expense_id:int,recurring_expense_id:int,scheduled_date:string,next_expense_date:?string,status:string}|null
 */
function recurring_expense_generate_one(
    PDO $pdo,
    int $recurringExpenseId,
    string $throughDate
): ?array {
    if ($recurringExpenseId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $throughDate)) {
        throw new InvalidArgumentException('A recurring expense and valid processing date are required.');
    }

    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $stmt = $pdo->prepare('
            SELECT * FROM recurring_expenses
            WHERE id=? AND status="active" AND next_expense_date IS NOT NULL AND next_expense_date<=?
            FOR UPDATE
        ');
        $stmt->execute([$recurringExpenseId, $throughDate]);
        $schedule = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        if (!$schedule) {
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return null;
        }

        $scheduledDate = (string)$schedule['next_expense_date'];
        if (!empty($schedule['end_date']) && $scheduledDate > (string)$schedule['end_date']) {
            $pdo->prepare('UPDATE recurring_expenses SET status="ended",next_expense_date=NULL WHERE id=?')
                ->execute([$recurringExpenseId]);
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return null;
        }

        $insert = $pdo->prepare('
            INSERT INTO expenses
                (organization_id,vendor_id,category_id,client_id,project_id,receipt_id,
                 recurring_expense_id,recurring_occurrence_date,amount,tax_amount,total_amount,
                 expense_date,description,payment_method,reference_number,is_billable,
                 is_tax_deductible,notes,created_by,status)
            VALUES (?,?,?,?,?,NULL,?,?,?,NULL,?,?,?,NULL,NULL,?,?,?, ?,"confirmed")
            ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)
        ');
        $insert->execute([
            $schedule['organization_id'] !== null ? (int)$schedule['organization_id'] : null,
            $schedule['vendor_id'] !== null ? (int)$schedule['vendor_id'] : null,
            $schedule['category_id'] !== null ? (int)$schedule['category_id'] : null,
            $schedule['client_id'] !== null ? (int)$schedule['client_id'] : null,
            $schedule['project_id'] !== null ? (int)$schedule['project_id'] : null,
            $recurringExpenseId,
            $scheduledDate,
            (float)$schedule['amount'],
            (float)$schedule['amount'],
            $scheduledDate,
            (string)$schedule['description'],
            !empty($schedule['is_billable']) ? 1 : 0,
            !empty($schedule['is_tax_deductible']) ? 1 : 0,
            $schedule['notes'] !== null ? (string)$schedule['notes'] : null,
            $schedule['created_by'] !== null ? (int)$schedule['created_by'] : null,
        ]);
        $expenseId = (int)$pdo->lastInsertId();

        $nextDate = recurring_expense_next_date(
            $scheduledDate,
            (int)$schedule['interval_count'],
            (string)$schedule['interval_unit'],
            (string)$schedule['start_date']
        );
        $status = 'active';
        if (!empty($schedule['end_date']) && $nextDate > (string)$schedule['end_date']) {
            $nextDate = null;
            $status = 'ended';
        }

        $pdo->prepare('
            UPDATE recurring_expenses
            SET next_expense_date=?,last_generated_date=?,generated_count=generated_count+1,status=?
            WHERE id=?
        ')->execute([$nextDate, $scheduledDate, $status, $recurringExpenseId]);

        if ($ownsTransaction) {
            $pdo->commit();
        }

        return [
            'expense_id' => $expenseId,
            'recurring_expense_id' => $recurringExpenseId,
            'scheduled_date' => $scheduledDate,
            'next_expense_date' => $nextDate,
            'status' => $status,
        ];
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** @return array{generated:int,errors:int,attempts:int} */
function recurring_expense_process_due(PDO $pdo, string $throughDate, int $limit = 500): array
{
    $generated = 0;
    $errors = 0;
    $attempts = 0;
    $limit = max(1, min(5000, $limit));

    while ($attempts < $limit) {
        $due = $pdo->prepare('
            SELECT id FROM recurring_expenses
            WHERE status="active" AND next_expense_date IS NOT NULL AND next_expense_date<=?
            ORDER BY next_expense_date,id
            LIMIT 1
        ');
        $due->execute([$throughDate]);
        $id = (int)($due->fetchColumn() ?: 0);
        if ($id <= 0) {
            break;
        }
        $attempts++;
        try {
            $result = recurring_expense_generate_one($pdo, $id, $throughDate);
            if ($result !== null) {
                $generated++;
            }
        } catch (Throwable $e) {
            $errors++;
            @error_log('[recurring_expenses] Schedule ' . $id . ' failed: ' . $e->getMessage());
            // Pause a broken schedule so one row cannot starve all other due work.
            $pdo->prepare('UPDATE recurring_expenses SET status="paused" WHERE id=?')->execute([$id]);
        }
    }

    return ['generated' => $generated, 'errors' => $errors, 'attempts' => $attempts];
}
