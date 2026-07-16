<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping;

use App\Services\CompensationRuleService;
use App\Services\JobWorkPlanningService;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PDO;
use Throwable;

final class ApprovalService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly AuditRecorder $audit,
        private readonly ApprovedTimeConsumer $billing
    ) {}

    public function approve(int $approverId, string $entryId): string
    {
        return $this->finalize($approverId, $entryId, false);
    }

    public function selfConfirmOwner(int $ownerId, string $entryId): string
    {
        return $this->finalize($ownerId, $entryId, true, false);
    }

    /** Materialize snapshots for owner entries created by the pre-0045 direct-approved path. */
    public function ensureOwnerProjection(int $ownerId, string $entryId): string
    {
        $existing = $this->pdo->prepare(
            'SELECT id FROM work_approval_snapshots WHERE time_entry_id=? ORDER BY entry_revision DESC LIMIT 1'
        );
        $existing->execute([$entryId]);
        $snapshotId = (string)($existing->fetchColumn() ?: '');
        return $snapshotId !== '' ? $snapshotId : $this->finalize($ownerId, $entryId, true, true);
    }

    public function canSelfConfirmOwner(int $userId): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT u.role,wp.relationship_type
             FROM users u
             LEFT JOIN worker_profiles wp ON wp.user_id=u.id AND wp.status='active'
             WHERE u.id=? AND u.deleted_at IS NULL AND u.is_disabled=0
             LIMIT 1"
        );
        $stmt->execute([$userId]);
        $identity = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($identity)
            && (($identity['relationship_type'] ?? '') === 'owner'
                || in_array((string)($identity['role'] ?? ''), ['admin', 'owner'], true));
    }

    private function finalize(
        int $approverId,
        string $entryId,
        bool $ownerSelfConfirmation,
        bool $allowLegacyApproved = false
    ): string
    {
        $settings = WorkforceSettings::load($this->pdo);
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                "SELECT t.*,u.email,u.username,u.role user_role,ep.first_name,ep.last_name,ep.hourly_rate employee_rate,
                        wp.id worker_profile_id,wp.relationship_type,
                        jwc.catalog_work_component_id,
                        pa.pay_rate_override,p.name project_name,c.name client_name,
                        i.doc_number invoice_number,i.invoice_type
                 FROM work_time_entries t JOIN users u ON u.id=t.user_id
                 LEFT JOIN employee_profiles ep ON ep.user_id=t.user_id
                 LEFT JOIN worker_profiles wp ON wp.user_id=t.user_id AND wp.status='active'
                 LEFT JOIN work_assignments wa ON wa.id=t.work_assignment_id
                 LEFT JOIN job_work_components jwc ON jwc.id=wa.job_work_component_id
                 LEFT JOIN projects p ON p.id=t.project_id
                 LEFT JOIN clients c ON c.id=t.client_id
                 LEFT JOIN invoices i ON i.id=t.invoice_id
                 LEFT JOIN project_assignments pa ON pa.project_id=t.project_id AND pa.user_id=t.user_id
                 WHERE t.id=? FOR UPDATE"
            );
            $stmt->execute([$entryId]);
            $entry = $stmt->fetch(PDO::FETCH_ASSOC);
            $readyStatus = $entry && (
                $entry['status'] === 'review'
                || ($ownerSelfConfirmation && $allowLegacyApproved
                    && $entry['status'] === 'approved' && !empty($entry['owner_self_confirmed']))
            );
            if (!$readyStatus || empty($entry['end_time'])) {
                throw new DomainException('Entry is not ready for approval.');
            }
            if ((int)$entry['user_id'] === $approverId && !$ownerSelfConfirmation) {
                throw new DomainException('You cannot approve your own time entry.');
            }
            if ($ownerSelfConfirmation) {
                $isOwner = ($entry['relationship_type'] ?? '') === 'owner'
                    || in_array((string)($entry['user_role'] ?? ''), ['admin', 'owner'], true);
                if ((int)$entry['user_id'] !== $approverId || !$isOwner) {
                    throw new DomainException('Only an owner can self-confirm their own time entry.');
                }
            }
            $entry['default_hourly_rate'] = $settings['default_hourly_rate'];
            $entry['default_billing_rate'] = $settings['default_billing_rate'];
            $entry['currency'] = $settings['currency'];
            if ((int) $entry['revision'] > 1 && $this->hasPaidAccrual($entryId)) {
                throw new DomainException('Paid time cannot be replaced by a correction. Return the pay accrual to pending first.');
            }
            $effectivePayable=!$ownerSelfConfirmation&&(int)$entry['is_payable']===1&&($entry['relationship_type']??'')!=='owner'&&empty($entry['work_assignment_id']);
            $payRate = $entry['pay_rate_override'] ?? $entry['employee_rate'] ?? $entry['default_hourly_rate'];
            $catalogPay=null;
            if($effectivePayable&&!empty($entry['worker_profile_id'])&&!empty($entry['work_type_id'])){
                $rules=new CompensationRuleService($this->pdo);
                $rule=$rules->resolve((int)$entry['worker_profile_id'],(int)$entry['work_type_id'],!empty($entry['catalog_work_component_id'])?(int)$entry['catalog_work_component_id']:null);
                if($rule['method']==='nonpayable'||$rule['eligibility_trigger']!=='completed_approved')$effectivePayable=false;
                elseif($rule['method']==='percentage')throw new DomainException('Percentage compensation must be linked to a catalog assignment with an eligible client-price basis.');
                else{$catalogPay=$rules->calculate($rule,['duration_seconds'=>(int)$entry['duration_seconds'],'quantity'=>1]);$payRate=$rule['amount'];}
            }
            if ($effectivePayable && $payRate === null) {
                throw new DomainException('A project, employee, or business pay rate is required.');
            }
            $billingRate = $this->billingRate($entry);
            $snapshotId = Uuid::v4();
            $employeeName = trim((string) $entry['first_name'] . ' ' . (string) $entry['last_name']);
            if ($employeeName === '') {
                $employeeName = (string) ($entry['username'] ?: $entry['email']);
            }
            $payAmount = $effectivePayable
                ? ($catalogPay['amount']??DecimalMoney::payAmount((int) $entry['duration_seconds'], (string) $payRate))
                : null;
            $snapshot = [
                'id' => $snapshotId,
                'time_entry_id' => $entryId,
                'entry_revision' => (int) $entry['revision'],
                'employee_user_id' => (int) $entry['user_id'],
                'employee_name' => $employeeName,
                'client_id' => $entry['client_id'] !== null ? (int)$entry['client_id'] : null,
                'client_name' => (string)($entry['client_name'] ?? ''),
                'project_id' => $entry['project_id'] !== null ? (int) $entry['project_id'] : null,
                'project_name' => (string) ($entry['project_name'] ?? ''),
                'invoice_id' => $entry['invoice_id'] !== null ? (int)$entry['invoice_id'] : null,
                'invoice_number' => $this->invoiceLabel($entry),
                'start_time' => (string) $entry['start_time'],
                'end_time' => (string) $entry['end_time'],
                'duration_seconds' => (int) $entry['duration_seconds'],
                'description' => (string) $entry['description'],
                'billable' => (int) $entry['billable'],
                'is_payable' => $effectivePayable ? 1 : 0,
                'pay_rate' => $payRate,
                'billing_rate' => $billingRate,
                'pay_amount' => $payAmount,
                'currency' => (string) $entry['currency'],
                'approved_by' => $approverId,
            ];
            $insert = $this->pdo->prepare(
                'INSERT INTO work_approval_snapshots
                 (id,time_entry_id,entry_revision,employee_user_id,employee_name,client_id,client_name,project_id,project_name,invoice_id,invoice_number,
                  start_time,end_time,duration_seconds,description,billable,is_payable,pay_rate,billing_rate,pay_amount,currency,approved_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            $insert->execute(array_values($snapshot));
            $this->pdo->prepare(
                "UPDATE work_time_entries
                 SET status='approved',owner_self_confirmed=?,rejection_reason='',reviewed_by=?,reviewed_at=UTC_TIMESTAMP(6)
                 WHERE id=?"
            )->execute([$ownerSelfConfirmation ? 1 : (int)$entry['owner_self_confirmed'], $approverId, $entryId]);
            if ((int) $entry['revision'] > 1) {
                $this->pdo->prepare(
                    "UPDATE work_pay_accruals a
                     JOIN work_approval_snapshots s ON s.id=a.approval_snapshot_id
                     SET a.status='voided'
                     WHERE s.time_entry_id=? AND s.entry_revision<? AND a.status='pending'"
                )->execute([$entryId, $entry['revision']]);
            }
            if ($effectivePayable) {
                $hours = number_format(((int) $entry['duration_seconds']) / 3600, 4, '.', '');
                $this->pdo->prepare(
                    "INSERT INTO work_pay_accruals
                     (id,approval_snapshot_id,employee_user_id,employee_name,hours,rate,amount,currency,status)
                     VALUES (?,?,?,?,?,?,?,?,'pending')"
                )->execute([Uuid::v4(), $snapshotId, $entry['user_id'], $employeeName, $hours, $payRate, $payAmount, $entry['currency']]);
            }
            $this->billing->consume($snapshot);
            if(!$ownerSelfConfirmation&&!empty($entry['work_assignment_id'])){
                $pending=$this->pdo->prepare("SELECT COUNT(*) FROM work_time_entries WHERE work_assignment_id=? AND id<>? AND status NOT IN ('approved','cancelled','voided')");$pending->execute([$entry['work_assignment_id'],$entryId]);
                if((int)$pending->fetchColumn()===0){$assignment=$this->pdo->prepare("SELECT status,JSON_UNQUOTE(JSON_EXTRACT(compensation_snapshot,'$.eligibility_trigger')) trigger_name FROM work_assignments WHERE id=?");$assignment->execute([$entry['work_assignment_id']]);$assignment=$assignment->fetch(PDO::FETCH_ASSOC);if($assignment&&$assignment['status']==='completed'&&$assignment['trigger_name']==='completed_approved')(new JobWorkPlanningService($this->pdo,new CompensationRuleService($this->pdo)))->markEligible((int)$entry['work_assignment_id'],['trigger_event'=>'completed_approved'],$approverId);}
            }
            $this->audit->record(
                $ownerSelfConfirmation ? 'time_entry.owner_self_confirmed' : 'time_entry.approved',
                'work_time_entry',
                $entryId,
                $approverId,
                $entry,
                $snapshot,
                $snapshotId
            );
            $this->pdo->commit();
            return $snapshotId;
        } catch (Throwable $error) {
            $this->pdo->rollBack();
            throw $error;
        }
    }

    public function reject(int $approverId, string $entryId, string $reason): void
    {
        if (trim($reason) === '') {
            throw new DomainException('A rejection reason is required.');
        }
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                "UPDATE work_time_entries SET status='rejected',rejection_reason=?,reviewed_by=?,reviewed_at=UTC_TIMESTAMP(6)
                 WHERE id=? AND status='review'"
            );
            $stmt->execute([trim($reason), $approverId, $entryId]);
            if ($stmt->rowCount() !== 1) {
                throw new DomainException('Entry is not awaiting review.');
            }
            $this->audit->record('time_entry.rejected', 'work_time_entry', $entryId, $approverId, [], ['reason' => trim($reason)]);
            $this->pdo->commit();
        } catch (Throwable $error) {
            $this->pdo->rollBack();
            throw $error;
        }
    }

    public function correct(int $approverId, string $entryId, array $input): void
    {
        $reason = trim((string) ($input['reason'] ?? ''));
        if ($reason === '') {
            throw new DomainException('A correction reason is required.');
        }
        $timezone = (string)WorkforceSettings::load($this->pdo)['timezone'];
        $tz = new DateTimeZone($timezone ?: 'UTC');
        $start = $this->parseLocalDateTime((string) ($input['start_time'] ?? ''), $tz);
        $end = $this->parseLocalDateTime((string) ($input['end_time'] ?? ''), $tz);
        if ($end <= $start) {
            throw new DomainException('End time must follow start time.');
        }
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM work_time_entries WHERE id=? AND status='approved' FOR UPDATE");
            $stmt->execute([$entryId]);
            $entry = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$entry) {
                throw new DomainException('Only an approved entry can be corrected.');
            }
            if ($this->hasPaidAccrual($entryId)) {
                throw new DomainException('Paid time cannot be corrected. Return the pay accrual to pending first.');
            }
            if ($this->billingProjectionIsInvoiced($entryId, (int) $entry['revision'])) {
                throw new DomainException('Invoiced time cannot be corrected. Reverse or adjust the invoice first.');
            }
            $projectId = !empty($input['project_id']) ? (int) $input['project_id'] : null;
            if ($projectId) {
                $assigned = $this->pdo->prepare(
                    'SELECT 1 FROM project_assignments WHERE project_id=? AND user_id=? AND (ends_at IS NULL OR ends_at>UTC_TIMESTAMP(6))'
                );
                $assigned->execute([$projectId, $entry['user_id']]);
                if (!$assigned->fetchColumn()) {
                    throw new DomainException('The corrected project is not assigned to this employee.');
                }
            }
            $revisionId = Uuid::v4();
            $this->pdo->prepare(
                'INSERT INTO work_time_revisions (id,time_entry_id,revision,snapshot,reason,created_by) VALUES (?,?,?,?,?,?)'
            )->execute([$revisionId, $entryId, $entry['revision'], json_encode($entry, JSON_THROW_ON_ERROR), $reason, $approverId]);
            $breaks = $this->pdo->prepare('SELECT COALESCE(SUM(duration_seconds),0) FROM work_time_breaks WHERE time_entry_id=?');
            $breaks->execute([$entryId]);
            $duration = max(0, $end->getTimestamp() - $start->getTimestamp() - (int) $breaks->fetchColumn());
            $startUtc = $start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
            $endUtc = $end->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
            $this->pdo->prepare(
                "UPDATE work_time_entries SET project_id=?,start_time=?,end_time=?,duration_seconds=?,description=?,billable=?,is_payable=?,
                 revision=revision+1,status='review',reviewed_by=NULL,reviewed_at=NULL,rejection_reason='' WHERE id=?"
            )->execute([
                $projectId, $startUtc, $endUtc, $duration, trim((string) ($input['description'] ?? $entry['description'])),
                !empty($input['billable']) ? 1 : 0, !empty($input['is_payable']) ? 1 : 0, $entryId,
            ]);
            $this->audit->record('time_entry.correction_requested', 'work_time_entry', $entryId, $approverId, $entry, ['reason' => $reason, 'revision' => (int) $entry['revision'] + 1], $revisionId);
            $this->pdo->commit();
        } catch (Throwable $error) {
            $this->pdo->rollBack();
            throw $error;
        }
    }

    public function void(int $approverId, string $entryId, string $reason): void
    {
        if (trim($reason) === '') {
            throw new DomainException('A void reason is required.');
        }
        $this->pdo->beginTransaction();
        try {
            $entryStmt = $this->pdo->prepare("SELECT * FROM work_time_entries WHERE id=? AND status='approved' FOR UPDATE");
            $entryStmt->execute([$entryId]);
            $entry = $entryStmt->fetch(PDO::FETCH_ASSOC);
            if (!$entry) {
                throw new DomainException('Only an approved entry can be voided.');
            }
            $snapshotStmt = $this->pdo->prepare(
                'SELECT * FROM work_approval_snapshots
                 WHERE time_entry_id=? AND entry_revision<=? AND voided_at IS NULL
                 ORDER BY entry_revision DESC LIMIT 1 FOR UPDATE'
            );
            $snapshotStmt->execute([$entryId, $entry['revision']]);
            $snapshot = $snapshotStmt->fetch(PDO::FETCH_ASSOC);
            if (!$snapshot || $snapshot['voided_at'] !== null) {
                throw new DomainException('The approval snapshot is unavailable or already voided.');
            }
            if ($this->billingProjectionIsInvoiced($entryId, (int) $entry['revision'])) {
                throw new DomainException('Invoiced time cannot be voided. Reverse or adjust the invoice first.');
            }
            $payStatus = $this->pdo->prepare('SELECT status FROM work_pay_accruals WHERE approval_snapshot_id=? FOR UPDATE');
            $payStatus->execute([$snapshot['id']]);
            if ($payStatus->fetchColumn() === 'paid') {
                throw new DomainException('Paid time cannot be voided. Return the pay accrual to pending first.');
            }
            $revisionId = Uuid::v4();
            $this->pdo->prepare(
                'INSERT INTO work_time_revisions (id,time_entry_id,revision,snapshot,reason,created_by) VALUES (?,?,?,?,?,?)'
            )->execute([$revisionId, $entryId, $entry['revision'], json_encode($entry, JSON_THROW_ON_ERROR), 'Void: ' . trim($reason), $approverId]);
            $this->pdo->prepare("UPDATE work_time_entries SET status='voided',revision=revision+1 WHERE id=?")->execute([$entryId]);
            $this->pdo->prepare(
                'UPDATE work_approval_snapshots SET voided_at=UTC_TIMESTAMP(6),voided_by=?,void_reason=? WHERE id=?'
            )->execute([$approverId, trim($reason), $snapshot['id']]);
            $this->pdo->prepare("UPDATE work_pay_accruals SET status='voided' WHERE approval_snapshot_id=? AND status='pending'")->execute([$snapshot['id']]);
            $this->billing->void($snapshot);
            $this->audit->record('time_entry.voided', 'work_time_entry', $entryId, $approverId, $entry, ['reason' => trim($reason)], $revisionId);
            $this->pdo->commit();
        } catch (Throwable $error) {
            $this->pdo->rollBack();
            throw $error;
        }
    }

    private function billingRate(array $entry): ?string
    {
        if ((int) $entry['billable'] !== 1) {
            return null;
        }
        if (!empty($entry['project_id'])) {
            $stmt = $this->pdo->prepare(
                "SELECT amount FROM billing_rate_rules WHERE scope_type='project' AND project_id=?
                 AND effective_from<=DATE(?) AND (effective_until IS NULL OR effective_until>=DATE(?))
                 ORDER BY effective_from DESC,id DESC LIMIT 1"
            );
            $stmt->execute([$entry['project_id'], $entry['start_time'], $entry['start_time']]);
            if (($rate = $stmt->fetchColumn()) !== false) {
                return (string)$rate;
            }
        }
        if (!empty($entry['client_id'])) {
            $stmt = $this->pdo->prepare(
                "SELECT amount FROM billing_rate_rules WHERE scope_type='client' AND client_id=?
                 AND effective_from<=DATE(?) AND (effective_until IS NULL OR effective_until>=DATE(?))
                 ORDER BY effective_from DESC,id DESC LIMIT 1"
            );
            $stmt->execute([$entry['client_id'], $entry['start_time'], $entry['start_time']]);
            if (($rate = $stmt->fetchColumn()) !== false) {
                return (string)$rate;
            }
        }
        return $entry['default_billing_rate'] !== null ? (string)$entry['default_billing_rate'] : null;
    }

    private function hasPaidAccrual(string $entryId): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT a.status FROM work_pay_accruals a
             JOIN work_approval_snapshots s ON s.id=a.approval_snapshot_id
             WHERE s.time_entry_id=? FOR UPDATE"
        );
        $stmt->execute([$entryId]);
        return in_array('paid', $stmt->fetchAll(PDO::FETCH_COLUMN), true);
    }

    private function billingProjectionIsInvoiced(string $entryId, int $revision): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM work_approval_snapshots s
             JOIN work_billing_consumptions c ON c.approval_snapshot_id=s.id
                AND c.consumption_type IN ('approved','correction')
             JOIN time_entries t ON t.id=c.billing_time_entry_id
             WHERE s.time_entry_id=? AND s.entry_revision<=?
               AND (t.billed=1 OR t.invoice_item_id IS NOT NULL)
             ORDER BY s.entry_revision DESC LIMIT 1 FOR UPDATE"
        );
        $stmt->execute([$entryId, $revision]);
        return (bool) $stmt->fetchColumn();
    }

    private function invoiceLabel(array $entry): string
    {
        if (empty($entry['invoice_number']) && empty($entry['invoice_id'])) {
            return '';
        }
        $prefix = match ((string)($entry['invoice_type'] ?? 'regular')) {
            'long_term' => 'LTI-',
            'on_demand' => 'ODI-',
            default => 'I-',
        };
        return $prefix . (string)($entry['invoice_number'] ?: $entry['invoice_id']);
    }

    private function parseLocalDateTime(string $value, DateTimeZone $timezone): DateTimeImmutable
    {
        $value = trim($value);
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i', $value, $timezone);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$parsed
            || $parsed->format('Y-m-d\\TH:i') !== $value
            || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new DomainException('Enter valid local start and end times.');
        }
        return $parsed;
    }

}
