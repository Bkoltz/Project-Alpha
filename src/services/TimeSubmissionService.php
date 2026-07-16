<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Workforce\TimeEntryWorkflow;
use App\Modules\Timekeeping\Uuid;
use DomainException;
use PDO;
use Throwable;

/**
 * Creates immutable, revision-level time submissions.
 *
 * Approval remains a separate command: recordDecision() only snapshots a
 * decision after the time entry has been moved by the approval workflow. This
 * keeps submission bookkeeping from bypassing approval snapshots, earnings,
 * billing consumers, or audit behavior.
 */
final class TimeSubmissionService
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * @param array<int,string> $timeEntryIds
     * @return array{id:string,sequence:int,entry_count:int}
     */
    public function submit(
        int $payPeriodId,
        int $workerProfileId,
        int $actorId,
        array $timeEntryIds,
        ?string $notes = null,
        bool $canManageWorker = false
    ): array {
        $timeEntryIds = array_values(array_unique(array_filter(
            array_map(static fn(mixed $id): string => trim((string)$id), $timeEntryIds),
            static fn(string $id): bool => $id !== ''
        )));
        if ($payPeriodId <= 0 || $workerProfileId <= 0 || $actorId <= 0 || $timeEntryIds === []) {
            throw new DomainException('Choose a worker, review period, and at least one time entry.');
        }

        return $this->transaction(function () use (
            $payPeriodId,
            $workerProfileId,
            $actorId,
            $timeEntryIds,
            $notes,
            $canManageWorker
        ): array {
            $period = $this->row(
                'SELECT * FROM pay_periods WHERE id=? FOR UPDATE',
                [$payPeriodId],
                'Review period not found.'
            );
            if ((string)$period['status'] !== 'open') {
                throw new DomainException('Only an open review period can be submitted.');
            }

            $worker = $this->row(
                'SELECT * FROM worker_profiles WHERE id=? FOR UPDATE',
                [$workerProfileId],
                'Worker profile not found.'
            );
            if (!$canManageWorker && (int)($worker['user_id'] ?? 0) !== $actorId) {
                throw new DomainException('You cannot submit time for this worker.');
            }

            $placeholders = implode(',', array_fill(0, count($timeEntryIds), '?'));
            $entryStatement = $this->pdo->prepare(
                "SELECT * FROM work_time_entries WHERE id IN ({$placeholders}) FOR UPDATE"
            );
            $entryStatement->execute($timeEntryIds);
            $entries = $entryStatement->fetchAll(PDO::FETCH_ASSOC);
            if (count($entries) !== count($timeEntryIds)) {
                throw new DomainException('One or more selected time entries are unavailable.');
            }

            foreach ($entries as $entry) {
                if ((int)($entry['worker_profile_id'] ?? 0) !== $workerProfileId) {
                    throw new DomainException('Every submitted entry must belong to the selected worker.');
                }
                $workDate = substr((string)$entry['start_time'], 0, 10);
                if ($workDate < (string)$period['period_start'] || $workDate > (string)$period['period_end']) {
                    throw new DomainException('Every submitted entry must be inside the selected review period.');
                }
                $workflowStatus = (string)$entry['workflow_status'];
                $isUnattachedLegacySubmission = $workflowStatus === TimeEntryWorkflow::SUBMITTED
                    && empty($entry['current_submission_id']);
                if (!in_array($workflowStatus, [TimeEntryWorkflow::DRAFT, TimeEntryWorkflow::RETURNED], true)
                    && !$isUnattachedLegacySubmission) {
                    throw new DomainException('Only draft or returned time can be submitted.');
                }
                if (empty($entry['end_time']) || (int)$entry['duration_seconds'] <= 0) {
                    throw new DomainException('Stop running time and enter a positive duration before submission.');
                }
            }

            // The worker row lock serializes sequence allocation for this worker.
            $sequenceStatement = $this->pdo->prepare(
                'SELECT COALESCE(MAX(submission_sequence),0)+1 FROM time_submissions
                 WHERE pay_period_id=? AND worker_profile_id=?'
            );
            $sequenceStatement->execute([$payPeriodId, $workerProfileId]);
            $sequence = max(1, (int)$sequenceStatement->fetchColumn());
            $submissionId = Uuid::v4();
            $cleanNotes = trim((string)$notes);

            $this->pdo->prepare(
                "INSERT INTO time_submissions
                 (id,pay_period_id,worker_profile_id,submission_sequence,status,source,notes,submitted_by,submitted_at)
                 VALUES (?,?,?,?,'submitted','workflow',?,?,UTC_TIMESTAMP(6))"
            )->execute([
                $submissionId,
                $payPeriodId,
                $workerProfileId,
                $sequence,
                $cleanNotes !== '' ? $cleanNotes : null,
                $actorId,
            ]);

            $insertEntry = $this->pdo->prepare(
                "INSERT INTO time_submission_entries
                 (submission_id,time_entry_id,entry_revision,entry_snapshot,decision)
                 VALUES (?,?,?,?,'pending')"
            );
            $updateEntry = $this->pdo->prepare(
                "UPDATE work_time_entries
                 SET workflow_status='submitted',status='review',submitted_at=UTC_TIMESTAMP(6),
                     current_submission_id=?
                 WHERE id=? AND revision=?"
            );
            foreach ($entries as $entry) {
                $snapshot = json_encode(
                    $entry,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                );
                $insertEntry->execute([
                    $submissionId,
                    $entry['id'],
                    (int)$entry['revision'],
                    $snapshot,
                ]);
                $updateEntry->execute([$submissionId, $entry['id'], (int)$entry['revision']]);
                if ($updateEntry->rowCount() !== 1) {
                    throw new DomainException('A time entry changed while the submission was being created.');
                }
            }

            // Compatibility marker for current pay-period readers.
            $this->pdo->prepare(
                "INSERT INTO worker_period_submissions
                 (pay_period_id,worker_profile_id,status,submitted_at,notes)
                 VALUES (?,?,'submitted',UTC_TIMESTAMP(6),?)
                 ON DUPLICATE KEY UPDATE status='submitted',submitted_at=VALUES(submitted_at),notes=VALUES(notes)"
            )->execute([$payPeriodId, $workerProfileId, $cleanNotes !== '' ? $cleanNotes : null]);

            return ['id' => $submissionId, 'sequence' => $sequence, 'entry_count' => count($entries)];
        });
    }

    /**
     * Records a decision after the authoritative approval command has moved
     * the entry to the matching canonical state.
     */
    public function recordDecision(
        string $submissionId,
        string $timeEntryId,
        int $entryRevision,
        string $decision,
        int $reviewerId,
        ?string $reason = null
    ): void {
        if (!in_array($decision, ['confirmed', 'returned', 'voided'], true)) {
            throw new DomainException('Choose a valid time-review decision.');
        }
        if ($submissionId === '' || $timeEntryId === '' || $entryRevision <= 0 || $reviewerId <= 0) {
            throw new DomainException('Submission decision data is incomplete.');
        }
        $cleanReason = trim((string)$reason);
        if ($decision === 'returned' && $cleanReason === '') {
            throw new DomainException('Returned time requires a reason.');
        }

        $this->transaction(function () use (
            $submissionId,
            $timeEntryId,
            $entryRevision,
            $decision,
            $reviewerId,
            $cleanReason
        ): void {
            $submission = $this->row(
                'SELECT * FROM time_submissions WHERE id=? FOR UPDATE',
                [$submissionId],
                'Time submission not found.'
            );
            if (!in_array((string)$submission['status'], ['submitted', 'partially_reviewed'], true)) {
                throw new DomainException('This time submission is no longer reviewable.');
            }

            $entry = $this->row(
                'SELECT workflow_status,revision,current_submission_id FROM work_time_entries WHERE id=? FOR UPDATE',
                [$timeEntryId],
                'Time entry not found.'
            );
            $revisionMatchesDecision = $decision === 'voided'
                ? (int)$entry['revision'] >= $entryRevision
                : (int)$entry['revision'] === $entryRevision;
            if ((string)$entry['current_submission_id'] !== $submissionId
                || !$revisionMatchesDecision) {
                throw new DomainException('The submitted time entry changed before the decision was recorded.');
            }
            $expectedStatus = match ($decision) {
                'confirmed' => TimeEntryWorkflow::CONFIRMED,
                'returned' => TimeEntryWorkflow::RETURNED,
                'voided' => TimeEntryWorkflow::VOIDED,
            };
            if ((string)$entry['workflow_status'] !== $expectedStatus) {
                throw new DomainException('Run the authoritative approval command before recording its submission decision.');
            }

            $update = $this->pdo->prepare(
                'UPDATE time_submission_entries
                 SET decision=?,decision_reason=?,reviewed_by=?,reviewed_at=UTC_TIMESTAMP(6)
                 WHERE submission_id=? AND time_entry_id=? AND entry_revision=? AND decision=\'pending\''
            );
            $update->execute([
                $decision,
                $cleanReason !== '' ? $cleanReason : null,
                $reviewerId,
                $submissionId,
                $timeEntryId,
                $entryRevision,
            ]);
            if ($update->rowCount() !== 1) {
                throw new DomainException('This submitted entry already has a review decision.');
            }
            $this->refreshSubmissionStatus($submissionId, $reviewerId);
        });
    }

    /** @return array<int,array<string,mixed>> */
    public function entriesForReview(string $submissionId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT se.*,t.workflow_status,t.billing_state,t.compensation_state
             FROM time_submission_entries se
             JOIN work_time_entries t ON t.id=se.time_entry_id
             WHERE se.submission_id=? ORDER BY t.start_time,t.id'
        );
        $statement->execute([$submissionId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private function refreshSubmissionStatus(string $submissionId, int $reviewerId): void
    {
        $statement = $this->pdo->prepare(
            "SELECT COUNT(*) total,
                    SUM(decision='pending') pending_count,
                    SUM(decision='confirmed') confirmed_count,
                    SUM(decision='returned') returned_count
             FROM time_submission_entries WHERE submission_id=?"
        );
        $statement->execute([$submissionId]);
        $counts = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
        $pending = (int)($counts['pending_count'] ?? 0);
        $confirmed = (int)($counts['confirmed_count'] ?? 0);
        $returned = (int)($counts['returned_count'] ?? 0);
        $status = $pending > 0
            ? 'partially_reviewed'
            : ($returned > 0 ? ($confirmed > 0 ? 'partially_reviewed' : 'returned') : 'confirmed');
        $this->pdo->prepare(
            'UPDATE time_submissions SET status=?,reviewed_by=?,reviewed_at=UTC_TIMESTAMP(6) WHERE id=?'
        )->execute([$status, $reviewerId, $submissionId]);
    }

    /** @return array<string,mixed> */
    private function row(string $sql, array $parameters, string $notFoundMessage): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new DomainException($notFoundMessage);
        }
        return $row;
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
