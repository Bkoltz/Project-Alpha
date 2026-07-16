<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PDO;
use Throwable;

final class TimekeepingService
{
    public function __construct(private readonly PDO $pdo, private readonly AuditRecorder $audit) {}

    public function projectsFor(int $userId, bool $manageAll = false): array
    {
        if ($manageAll) {
            return $this->pdo->query(
                "SELECT p.id,p.name,p.client_id,c.name client_name
                 FROM projects p LEFT JOIN clients c ON c.id=p.client_id
                 WHERE p.status NOT IN ('completed','cancelled') ORDER BY c.name,p.name"
            )->fetchAll(PDO::FETCH_ASSOC);
        }
        $stmt = $this->pdo->prepare(
            "SELECT p.id,p.name FROM projects p
             JOIN project_assignments a ON a.project_id=p.id AND a.user_id=? AND (a.ends_at IS NULL OR a.ends_at>UTC_TIMESTAMP(6))
             WHERE p.status NOT IN ('completed','cancelled') ORDER BY p.name"
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function jobsFor(int $userId, bool $manageAll = false): array
    {
        if ($manageAll) {
            return $this->pdo->query(
                "SELECT j.id,j.job_code,j.client_id,j.project_id,c.name client_name
                 FROM jobs j JOIN clients c ON c.id=j.client_id
                 WHERE j.archived=0 AND j.status NOT IN ('completed','cancelled')
                 ORDER BY j.created_at DESC,j.id DESC"
            )->fetchAll(PDO::FETCH_ASSOC);
        }
        $stmt = $this->pdo->prepare(
            "SELECT DISTINCT j.id,j.job_code,j.client_id,j.project_id,c.name client_name
             FROM jobs j JOIN clients c ON c.id=j.client_id
             LEFT JOIN job_work_components jwc ON jwc.job_id=j.id
             LEFT JOIN work_assignments wa ON wa.job_work_component_id=jwc.id
             LEFT JOIN worker_profiles wp ON wp.id=wa.worker_profile_id
             WHERE j.archived=0 AND j.status NOT IN ('completed','cancelled')
               AND (j.created_by=? OR (wp.user_id=? AND wa.status NOT IN ('declined','cancelled')))
             ORDER BY j.created_at DESC,j.id DESC"
        );
        $stmt->execute([$userId,$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function workTypes(): array
    {
        return $this->pdo->query('SELECT id,name FROM work_types WHERE is_active=1 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
    }

    public function assignmentsFor(int $userId): array
    {
        $stmt=$this->pdo->prepare("SELECT wa.id,wa.status,j.id job_id,j.job_code,jwc.work_type_id,jwc.name,wt.name work_type_name FROM work_assignments wa JOIN worker_profiles wp ON wp.id=wa.worker_profile_id JOIN job_work_components jwc ON jwc.id=wa.job_work_component_id JOIN jobs j ON j.id=jwc.job_id JOIN work_types wt ON wt.id=jwc.work_type_id WHERE wp.user_id=? AND wa.status IN ('accepted','in_progress') ORDER BY j.created_at DESC,wa.id DESC");
        $stmt->execute([$userId]);return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function offeredAssignmentsFor(int $userId): array
    {
        $stmt=$this->pdo->prepare("SELECT wa.id,wa.status,wa.estimated_pay,wa.currency,j.id job_id,j.job_code,jwc.name,jwc.expected_duration_minutes,wt.name work_type_name FROM work_assignments wa JOIN worker_profiles wp ON wp.id=wa.worker_profile_id JOIN job_work_components jwc ON jwc.id=wa.job_work_component_id JOIN jobs j ON j.id=jwc.job_id JOIN work_types wt ON wt.id=jwc.work_type_id WHERE wp.user_id=? AND wa.status='offered' ORDER BY wa.offered_at DESC,wa.id DESC");$stmt->execute([$userId]);return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function usersForManager(): array
    {
        return $this->pdo->query(
            "SELECT u.id,u.email,u.username,u.role,
                    COALESCE(NULLIF(TRIM(CONCAT(ep.first_name,' ',ep.last_name)),''),NULLIF(u.username,''),u.email) display_name
             FROM users u LEFT JOIN employee_profiles ep ON ep.user_id=u.id
             WHERE u.deleted_at IS NULL AND u.is_disabled=0
             ORDER BY CASE WHEN u.role='employee' THEN 0 ELSE 1 END,display_name"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function clientsForManager(): array
    {
        return $this->pdo->query(
            'SELECT id,name FROM clients WHERE archived=0 AND deleted_at IS NULL ORDER BY name'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function invoicesForManager(): array
    {
        return $this->pdo->query(
            "SELECT i.id,i.client_id,i.project_id,i.job_id,i.doc_number,i.invoice_type,i.status,c.name client_name,p.name project_name
             FROM invoices i JOIN clients c ON c.id=i.client_id LEFT JOIN projects p ON p.id=i.project_id
             WHERE i.billing_mode='hourly' AND i.status NOT IN ('paid','cancelled','void')
             ORDER BY c.name,i.created_at DESC"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function running(int $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT t.*,p.name project_name,c.name client_name,i.doc_number invoice_number,i.invoice_type,j.job_code,wt.name work_type_name,
             (SELECT id FROM work_time_breaks b WHERE b.time_entry_id=t.id AND b.end_time IS NULL LIMIT 1) open_break_id
             FROM work_time_entries t LEFT JOIN projects p ON p.id=t.project_id
             LEFT JOIN clients c ON c.id=t.client_id LEFT JOIN invoices i ON i.id=t.invoice_id LEFT JOIN jobs j ON j.id=t.job_id LEFT JOIN work_types wt ON wt.id=t.work_type_id
             WHERE t.user_id=? AND t.end_time IS NULL AND t.status=\'running\' LIMIT 1'
        );
        $stmt->execute([$userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function entries(int $userId, bool $manageAll = false, int $limit = 250): array
    {
        $sql = 'SELECT t.*,p.name project_name,c.name client_name,i.doc_number invoice_number,i.invoice_type,j.job_code,wt.name work_type_name,
                bt.id billing_time_entry_id,COALESCE(bt.billed,0) billing_projection_billed,bt.invoice_item_id billing_invoice_item_id,bt.rate billing_rate,
                COALESCE(NULLIF(TRIM(CONCAT(ep.first_name,\' \',ep.last_name)),\'\'),NULLIF(u.username,\'\'),u.email) employee_name
                FROM work_time_entries t JOIN users u ON u.id=t.user_id
                LEFT JOIN employee_profiles ep ON ep.user_id=t.user_id LEFT JOIN projects p ON p.id=t.project_id
                LEFT JOIN clients c ON c.id=t.client_id LEFT JOIN invoices i ON i.id=t.invoice_id LEFT JOIN jobs j ON j.id=t.job_id LEFT JOIN work_types wt ON wt.id=t.work_type_id
                LEFT JOIN work_approval_snapshots aps ON aps.id=(SELECT aps2.id FROM work_approval_snapshots aps2 WHERE aps2.time_entry_id=t.id AND aps2.entry_revision<=t.revision AND aps2.voided_at IS NULL ORDER BY aps2.entry_revision DESC LIMIT 1)
                LEFT JOIN work_billing_consumptions wbc ON wbc.approval_snapshot_id=aps.id AND wbc.consumption_type IN (\'approved\',\'correction\')
                LEFT JOIN time_entries bt ON bt.id=wbc.billing_time_entry_id';
        $params = [];
        if (!$manageAll) {
            $sql .= ' WHERE t.user_id=?';
            $params[] = $userId;
        }
        $sql .= ' ORDER BY t.start_time DESC LIMIT ' . max(1, min(1000, $limit));
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function clockIn(int $userId, array $input, bool $manageAll = false): string
    {
        $settings = $this->settings();
        $description = trim((string)($input['description'] ?? ''));
        $context = $this->resolveBillingContext($userId, $input, $manageAll);
        $projectId = $context['project_id'];
        [$workTypeId,$assignmentId]=$this->resolveWorkSelection($userId,$input,$context['job_id']);
        if (!$manageAll && (int) $settings['require_project'] === 1 && !$projectId && !$context['job_id']) {
            throw new DomainException('A Job or Project is required.');
        }
        if (!$manageAll && (int) $settings['require_description'] === 1 && $description === '') {
            throw new DomainException('A description is required.');
        }
        $billable = $manageAll ? (!empty($input['billable']) ? 1 : 0) : (($context['job_id'] || $projectId) ? 1 : 0);
        $isPayable = $manageAll ? (!empty($input['is_payable']) ? 1 : 0) : 1;
        $id = Uuid::v4();
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO work_time_entries
                 (id,user_id,client_id,project_id,invoice_id,job_id,work_type_id,work_assignment_id,entry_mode,start_time,description,tags,billable,is_payable,status)
                 VALUES (?,?,?,?,?,?,?,?, 'timer',UTC_TIMESTAMP(6),?,?,?,?,'running')"
            );
            $stmt->execute([
                $id, $userId, $context['client_id'], $projectId, $context['invoice_id'],$context['job_id'],$workTypeId,$assignmentId,$description,
                json_encode([], JSON_THROW_ON_ERROR), $billable, $isPayable,
            ]);
            $this->pdo->prepare('INSERT INTO work_timer_locks (user_id,time_entry_id) VALUES (?,?)')->execute([$userId, $id]);
            $this->audit->record('timer.started', 'work_time_entry', $id, $userId, [], ['project_id' => $projectId]);
            $this->pdo->commit();
            return $id;
        } catch (Throwable $error) {
            $this->pdo->rollBack();
            if ($error instanceof \PDOException && $error->getCode() === '23000') {
                throw new DomainException('A timer is already running.');
            }
            throw $error;
        }
    }

    public function clockOut(int $userId, string $entryId): void
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM work_time_entries WHERE id=? AND user_id=? AND status='running' FOR UPDATE");
            $stmt->execute([$entryId, $userId]);
            $entry = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$entry) {
                throw new DomainException('Running entry not found.');
            }
            $this->pdo->prepare(
                'UPDATE work_time_breaks SET end_time=UTC_TIMESTAMP(6),duration_seconds=GREATEST(0,TIMESTAMPDIFF(SECOND,start_time,UTC_TIMESTAMP(6)))
                 WHERE time_entry_id=? AND end_time IS NULL'
            )->execute([$entryId]);
            $this->pdo->prepare('DELETE FROM work_break_locks WHERE time_entry_id=?')->execute([$entryId]);
            $breaks = $this->pdo->prepare('SELECT COALESCE(SUM(duration_seconds),0) FROM work_time_breaks WHERE time_entry_id=?');
            $breaks->execute([$entryId]);
            $duration = $this->pdo->prepare('SELECT GREATEST(0,TIMESTAMPDIFF(SECOND,start_time,UTC_TIMESTAMP(6))-?) FROM work_time_entries WHERE id=?');
            $duration->execute([(int) $breaks->fetchColumn(), $entryId]);
            $seconds = (int) $duration->fetchColumn();
            $this->pdo->prepare("UPDATE work_time_entries SET end_time=UTC_TIMESTAMP(6),duration_seconds=?,status='review' WHERE id=?")->execute([$seconds, $entryId]);
            $this->pdo->prepare('DELETE FROM work_timer_locks WHERE user_id=?')->execute([$userId]);
            $this->audit->record('timer.stopped', 'work_time_entry', $entryId, $userId, $entry, ['duration_seconds' => $seconds]);
            $this->pdo->commit();
        } catch (Throwable $error) {
            $this->pdo->rollBack();
            throw $error;
        }
    }

    public function startBreak(int $userId, string $entryId): string
    {
        $id = Uuid::v4();
        $this->pdo->beginTransaction();
        try {
            // Serialize against clockOut() so a break cannot be inserted after
            // the timer has already transitioned to review.
            $stmt = $this->pdo->prepare(
                "SELECT id FROM work_time_entries WHERE id=? AND user_id=? AND status='running' FOR UPDATE"
            );
            $stmt->execute([$entryId, $userId]);
            if (!$stmt->fetchColumn()) {
                throw new DomainException('Running entry not found.');
            }
            $this->pdo->prepare('INSERT INTO work_time_breaks (id,time_entry_id,start_time) VALUES (?,?,UTC_TIMESTAMP(6))')->execute([$id, $entryId]);
            $this->pdo->prepare('INSERT INTO work_break_locks (time_entry_id,break_id) VALUES (?,?)')->execute([$entryId, $id]);
            $this->audit->record('break.started', 'work_time_break', $id, $userId, [], ['time_entry_id' => $entryId]);
            $this->pdo->commit();
            return $id;
        } catch (Throwable $error) {
            $this->pdo->rollBack();
            if ($error instanceof \PDOException && $error->getCode() === '23000') {
                throw new DomainException('A break is already running.', 0, $error);
            }
            throw $error;
        }
    }

    public function endBreak(int $userId, string $breakId): void
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'UPDATE work_time_breaks b JOIN work_time_entries t ON t.id=b.time_entry_id
                 SET b.end_time=UTC_TIMESTAMP(6),b.duration_seconds=GREATEST(0,TIMESTAMPDIFF(SECOND,b.start_time,UTC_TIMESTAMP(6)))
                 WHERE b.id=? AND t.user_id=? AND b.end_time IS NULL'
            );
            $stmt->execute([$breakId, $userId]);
            if ($stmt->rowCount() !== 1) {
                throw new DomainException('Open break not found.');
            }
            $this->pdo->prepare('DELETE FROM work_break_locks WHERE break_id=?')->execute([$breakId]);
            $this->audit->record('break.ended', 'work_time_break', $breakId, $userId);
            $this->pdo->commit();
        } catch (Throwable $error) {
            $this->pdo->rollBack();
            throw $error;
        }
    }

    public function saveManual(int $userId, array $input, bool $manageAll = false): string
    {
        $settings = $this->settings();
        $timezone = new DateTimeZone((string) $settings['timezone']);
        $start = $this->parseLocalDateTime((string) ($input['start_time'] ?? ''), $timezone);
        $end = $this->parseLocalDateTime((string) ($input['end_time'] ?? ''), $timezone);
        if ($end <= $start) {
            throw new DomainException('End time must follow start time.');
        }
        $context = $this->resolveBillingContext($userId, $input, $manageAll);
        $projectId = $context['project_id'];
        [$workTypeId,$assignmentId]=$this->resolveWorkSelection($userId,$input,$context['job_id']);
        $description = trim((string) ($input['description'] ?? ''));
        if (!$manageAll && (int) $settings['require_project'] === 1 && !$projectId && !$context['job_id']) {
            throw new DomainException('A Job or Project is required.');
        }
        if (!$manageAll && (int) $settings['require_description'] === 1 && $description === '') {
            throw new DomainException('A description is required.');
        }
        $billable = $manageAll ? (!empty($input['billable']) ? 1 : 0) : (($context['job_id'] || $projectId) ? 1 : 0);
        $isPayable = $manageAll ? (!empty($input['is_payable']) ? 1 : 0) : 1;
        $id = Uuid::v4();
        $startUtc = $start->setTimezone(new DateTimeZone('UTC'));
        $endUtc = $end->setTimezone(new DateTimeZone('UTC'));
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO work_time_entries
                 (id,user_id,client_id,project_id,invoice_id,job_id,work_type_id,work_assignment_id,entry_mode,start_time,end_time,duration_seconds,description,tags,billable,is_payable,status)
                 VALUES (?,?,?,?,?,?,?,?, 'exact',?,?,?,?,?,?,?,'review')"
            );
            $stmt->execute([
                $id, $userId, $context['client_id'], $projectId, $context['invoice_id'],$context['job_id'],$workTypeId,$assignmentId,
                $startUtc->format('Y-m-d H:i:s.u'), $endUtc->format('Y-m-d H:i:s.u'),
                $end->getTimestamp() - $start->getTimestamp(), $description,
                json_encode([], JSON_THROW_ON_ERROR), $billable, $isPayable,
            ]);
            $this->audit->record('time_entry.created', 'work_time_entry', $id, $userId, [], ['project_id' => $projectId]);
            $this->pdo->commit();
            return $id;
        } catch (Throwable $error) {
            $this->pdo->rollBack();
            throw $error;
        }
    }

    /**
     * Owner/admin quick entry: duration is canonical; exact start time is optional.
     * The former direct-approval INSERT (`0,1,?,'approved'`) is intentionally
     * replaced by ApprovalService::selfConfirmOwner so snapshots stay complete.
     */
    public function saveDuration(int $userId, array $input): string
    {
        $settings = $this->settings();
        $timezone = new DateTimeZone((string)$settings['timezone']);
        $minutes = (int)($input['duration_minutes'] ?? 0);
        if ($minutes <= 0 || $minutes > 1440) {
            throw new DomainException('Duration must be between 1 minute and 24 hours.');
        }
        $workDate = trim((string)($input['work_date'] ?? ''));
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $workDate, $timezone);
        if (!$date || $date->format('Y-m-d') !== $workDate) {
            throw new DomainException('Choose a valid work date.');
        }
        $optionalStart = trim((string)($input['start_time'] ?? ''));
        $start = $optionalStart !== '' ? $this->parseLocalDateTime($optionalStart, $timezone) : $date;
        if ($start->format('Y-m-d') !== $workDate) {
            throw new DomainException('The optional start time must be on the selected work date.');
        }
        $profile = $this->pdo->prepare(
            'SELECT wp.id,wp.relationship_type,wp.owner_internal_cost_rate,u.role user_role
             FROM users u LEFT JOIN worker_profiles wp ON wp.user_id=u.id AND wp.status="active"
             WHERE u.id=? AND u.deleted_at IS NULL AND u.is_disabled=0'
        );
        $profile->execute([$userId]);
        $profile = $profile->fetch(PDO::FETCH_ASSOC);
        $isOwner = $profile && (($profile['relationship_type'] ?? '') === 'owner'
            || in_array((string)($profile['user_role'] ?? ''), ['admin', 'owner'], true));
        if (!$isOwner) {
            throw new DomainException('Quick duration entry is available to owners.');
        }
        $context = $this->resolveBillingContext($userId, $input, true);
        $workTypeId = !empty($input['work_type_id']) ? (int)$input['work_type_id'] : null;
        if ($workTypeId) {
            $type = $this->pdo->prepare('SELECT 1 FROM work_types WHERE id=? AND is_active=1');
            $type->execute([$workTypeId]);
            if (!$type->fetchColumn()) throw new DomainException('Choose an active Work Type.');
        }
        $assignmentId = !empty($input['work_assignment_id']) ? (int)$input['work_assignment_id'] : null;
        if ($assignmentId) {
            if (empty($profile['id'])) {
                throw new DomainException('This owner needs an active worker profile before selecting an assignment.');
            }
            $assigned = $this->pdo->prepare('SELECT 1 FROM work_assignments wa JOIN job_work_components jwc ON jwc.id=wa.job_work_component_id WHERE wa.id=? AND wa.worker_profile_id=? AND (? IS NULL OR jwc.job_id=?)');
            $assigned->execute([$assignmentId,$profile['id'],$context['job_id'],$context['job_id']]);
            if (!$assigned->fetchColumn()) throw new DomainException('The assignment does not match this owner and Job.');
        }
        $startUtc = $start->setTimezone(new DateTimeZone('UTC'));
        $endUtc = $start->modify('+' . $minutes . ' minutes')->setTimezone(new DateTimeZone('UTC'));
        $id = Uuid::v4();
        $this->pdo->prepare(
            "INSERT INTO work_time_entries
             (id,user_id,client_id,project_id,invoice_id,job_id,work_type_id,work_assignment_id,entry_mode,start_time,end_time,duration_seconds,description,tags,billable,is_payable,owner_self_confirmed,internal_cost_rate,status,reviewed_by,reviewed_at)
             VALUES (?,?,?,?,?,?,?,?, 'duration',?,?,?,?,? ,?,0,0,?,'review',NULL,NULL)"
        )->execute([
            $id,$userId,$context['client_id'],$context['project_id'],$context['invoice_id'],$context['job_id'],$workTypeId,$assignmentId,
            $startUtc->format('Y-m-d H:i:s.u'),$endUtc->format('Y-m-d H:i:s.u'),$minutes*60,
            trim((string)($input['description'] ?? '')),json_encode([],JSON_THROW_ON_ERROR),!empty($input['billable'])?1:0,
            $profile['owner_internal_cost_rate'] ?? null,
        ]);
        $this->audit->record('time_entry.created', 'work_time_entry', $id, $userId, [], ['job_id'=>$context['job_id'],'duration_minutes'=>$minutes,'entry_mode'=>'duration']);
        return $id;
    }

    public function reviseEntry(
        int $actorId,
        int $entryUserId,
        string $entryId,
        array $input,
        bool $manageAll = false
    ): void
    {
        if (!$manageAll && $actorId !== $entryUserId) {
            throw new DomainException('You can edit only your own time entries.');
        }
        $settings = $this->settings();
        $timezone = new DateTimeZone((string) $settings['timezone']);
        $start = $this->parseLocalDateTime((string) ($input['start_time'] ?? ''), $timezone);
        $end = $this->parseLocalDateTime((string) ($input['end_time'] ?? ''), $timezone);
        if ($end <= $start) {
            throw new DomainException('End time must follow start time.');
        }
        $context = $this->resolveBillingContext($entryUserId, $input, $manageAll);
        $projectId = $context['project_id'];
        [$workTypeId,$assignmentId] = $this->resolveWorkSelection($entryUserId, $input, $context['job_id']);
        $description = trim((string) ($input['description'] ?? ''));
        if (!$manageAll && (int) $settings['require_project'] === 1 && !$projectId && !$context['job_id']) {
            throw new DomainException('A Job or Project is required.');
        }
        if (!$manageAll && (int) $settings['require_description'] === 1 && $description === '') {
            throw new DomainException('A description is required.');
        }
        $billable = $manageAll ? (!empty($input['billable']) ? 1 : 0) : (($context['job_id'] || $projectId) ? 1 : 0);
        $isPayable = $manageAll ? (!empty($input['is_payable']) ? 1 : 0) : 1;
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                "SELECT * FROM work_time_entries
                 WHERE id=? AND user_id=?
                   AND (status IN ('review','rejected') OR (status='approved' AND owner_self_confirmed=1))
                 FOR UPDATE"
            );
            $stmt->execute([$entryId, $entryUserId]);
            $entry = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$entry) {
                throw new DomainException('Only your review, rejected, or owner-confirmed time can be edited.');
            }
            $billing = $this->pdo->prepare(
                "SELECT te.id,te.billed,te.invoice_item_id
                 FROM work_approval_snapshots s
                 JOIN work_billing_consumptions c ON c.approval_snapshot_id=s.id
                    AND c.consumption_type IN ('approved','correction')
                 JOIN time_entries te ON te.id=c.billing_time_entry_id
                 WHERE s.time_entry_id=? AND (te.billed=1 OR te.invoice_item_id IS NOT NULL)
                 FOR UPDATE"
            );
            $billing->execute([$entryId]);
            if ($billing->fetch(PDO::FETCH_ASSOC)) {
                throw new DomainException('Billed or invoiced time cannot be edited. Adjust or unlink the invoice first.');
            }
            $revisionReason = $entry['status'] === 'approved'
                ? 'Owner self-confirmed revision'
                : ($entry['status'] === 'rejected' ? 'Worker resubmission' : 'Worker time edit');
            $this->pdo->prepare(
                'INSERT INTO work_time_revisions (id,time_entry_id,revision,snapshot,reason,created_by) VALUES (?,?,?,?,?,?)'
            )->execute([Uuid::v4(), $entryId, $entry['revision'], json_encode($entry, JSON_THROW_ON_ERROR), $revisionReason, $actorId]);
            $startUtc = $start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
            $endUtc = $end->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
            $this->pdo->prepare(
                "UPDATE work_time_entries SET client_id=?,project_id=?,invoice_id=?,job_id=?,work_type_id=?,work_assignment_id=?,start_time=?,end_time=?,duration_seconds=?,description=?,billable=?,is_payable=?,
                 owner_self_confirmed=0,revision=revision+1,status='review',rejection_reason='',reviewed_by=NULL,reviewed_at=NULL WHERE id=?"
            )->execute([
                $context['client_id'], $projectId, $context['invoice_id'], $context['job_id'], $workTypeId, $assignmentId, $startUtc, $endUtc,
                $end->getTimestamp() - $start->getTimestamp(), $description,
                $billable, $isPayable, $entryId,
            ]);
            $this->audit->record('time_entry.revised', 'work_time_entry', $entryId, $actorId, $entry, [
                'revision' => (int)$entry['revision'] + 1,
                'previous_status' => (string)$entry['status'],
            ]);
            $this->pdo->commit();
        } catch (Throwable $error) {
            $this->pdo->rollBack();
            throw $error;
        }
    }

    /** Backward-compatible alias for existing callers and integrations. */
    public function reviseRejected(int $userId, string $entryId, array $input, bool $manageAll = false): void
    {
        $this->reviseEntry($userId, $userId, $entryId, $input, $manageAll);
    }

    public function cancel(int $userId, string $entryId): void
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM work_time_entries WHERE id=? AND user_id=? AND status IN ('review','rejected') FOR UPDATE");
            $stmt->execute([$entryId, $userId]);
            $entry = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$entry) {
                throw new DomainException('Only review or rejected entries can be cancelled.');
            }
            $this->pdo->prepare(
                'INSERT INTO work_time_revisions (id,time_entry_id,revision,snapshot,reason,created_by) VALUES (?,?,?,?,?,?)'
            )->execute([Uuid::v4(), $entryId, $entry['revision'], json_encode($entry, JSON_THROW_ON_ERROR), 'Employee cancellation', $userId]);
            $this->pdo->prepare("UPDATE work_time_entries SET status='cancelled',revision=revision+1 WHERE id=?")->execute([$entryId]);
            $this->audit->record('time_entry.cancelled', 'work_time_entry', $entryId, $userId, $entry, []);
            $this->pdo->commit();
        } catch (Throwable $error) {
            $this->pdo->rollBack();
            throw $error;
        }
    }

    private function assertProjectAssigned(int $userId, ?int $projectId, bool $manageAll = false): void
    {
        if (!$projectId || $manageAll) {
            return;
        }
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM project_assignments WHERE project_id=? AND user_id=? AND (ends_at IS NULL OR ends_at>UTC_TIMESTAMP(6))'
        );
        $stmt->execute([$projectId, $userId]);
        if (!$stmt->fetchColumn()) {
            throw new DomainException('The selected project is not assigned to this employee.');
        }
    }

    private function settings(): array
    {
        return WorkforceSettings::load($this->pdo);
    }

    private function resolveBillingContext(int $userId, array $input, bool $manageAll): array
    {
        $clientId = !empty($input['client_id']) ? (int)$input['client_id'] : null;
        $projectId = !empty($input['project_id']) ? (int)$input['project_id'] : null;
        $invoiceId = !empty($input['invoice_id']) ? (int)$input['invoice_id'] : null;
        $jobId = !empty($input['job_id']) ? (int)$input['job_id'] : null;

        if (!$manageAll && ($clientId || $invoiceId)) {
            throw new DomainException('Client and invoice details are available only to timekeeping managers.');
        }

        if ($jobId) {
            $stmt = $this->pdo->prepare("SELECT client_id,project_id FROM jobs WHERE id=? AND archived=0 AND status NOT IN ('completed','cancelled')");
            $stmt->execute([$jobId]);
            $job = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$job) throw new DomainException('Choose an active Job.');
            $jobClientId = (int)$job['client_id'];
            $jobProjectId = !empty($job['project_id']) ? (int)$job['project_id'] : null;
            if ($clientId && $clientId !== $jobClientId) throw new DomainException('The selected Job does not belong to that client.');
            if ($projectId && $jobProjectId && $projectId !== $jobProjectId) throw new DomainException('The selected Job does not belong to that Project.');
            $clientId = $jobClientId;
            $projectId ??= $jobProjectId;
            if(!$manageAll){$allowed=$this->pdo->prepare("SELECT 1 FROM jobs j LEFT JOIN job_work_components jwc ON jwc.job_id=j.id LEFT JOIN work_assignments wa ON wa.job_work_component_id=jwc.id LEFT JOIN worker_profiles wp ON wp.id=wa.worker_profile_id WHERE j.id=? AND (j.created_by=? OR (wp.user_id=? AND wa.status NOT IN ('declined','cancelled'))) LIMIT 1");$allowed->execute([$jobId,$userId,$userId]);if(!$allowed->fetchColumn())throw new DomainException('The selected Job is not assigned to this worker.');}
        }

        if ($invoiceId) {
            $stmt = $this->pdo->prepare(
                "SELECT client_id,project_id,job_id FROM invoices
                 WHERE id=? AND billing_mode='hourly' AND status NOT IN ('paid','cancelled','void')"
            );
            $stmt->execute([$invoiceId]);
            $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$invoice) {
                throw new DomainException('Choose an open hourly invoice.');
            }
            $invoiceClientId = (int)$invoice['client_id'];
            $invoiceProjectId = !empty($invoice['project_id']) ? (int)$invoice['project_id'] : null;
            $invoiceJobId = !empty($invoice['job_id']) ? (int)$invoice['job_id'] : null;
            if ($clientId && $clientId !== $invoiceClientId) {
                throw new DomainException('The selected invoice does not belong to that client.');
            }
            if ($projectId && $invoiceProjectId && $projectId !== $invoiceProjectId) {
                throw new DomainException('The selected invoice does not belong to that project.');
            }
            if ($jobId && $invoiceJobId && $jobId !== $invoiceJobId) {
                throw new DomainException('The selected invoice does not belong to that Job.');
            }
            $clientId = $invoiceClientId;
            $projectId ??= $invoiceProjectId;
            $jobId ??= $invoiceJobId;
        }

        if ($projectId) {
            $stmt = $this->pdo->prepare(
                "SELECT client_id FROM projects WHERE id=? AND status NOT IN ('completed','cancelled')"
            );
            $stmt->execute([$projectId]);
            $projectClient = $stmt->fetchColumn();
            if ($projectClient === false) {
                throw new DomainException('Choose an active project.');
            }
            $projectClientId = (int)$projectClient ?: null;
            if ($clientId && $projectClientId && $clientId !== $projectClientId) {
                throw new DomainException('The selected project does not belong to that client.');
            }
            $clientId ??= $projectClientId;
            if (!$jobId) {
                $this->assertProjectAssigned($userId, $projectId, $manageAll);
            }
        }

        if ($clientId) {
            $stmt = $this->pdo->prepare('SELECT 1 FROM clients WHERE id=? AND archived=0 AND deleted_at IS NULL');
            $stmt->execute([$clientId]);
            if (!$stmt->fetchColumn()) {
                throw new DomainException('Choose an active client.');
            }
        }

        return ['client_id' => $clientId, 'project_id' => $projectId, 'invoice_id' => $invoiceId, 'job_id' => $jobId];
    }

    private function resolveWorkSelection(int $userId,array $input,?int $jobId): array
    {
        $workTypeId=!empty($input['work_type_id'])?(int)$input['work_type_id']:null;$assignmentId=!empty($input['work_assignment_id'])?(int)$input['work_assignment_id']:null;
        if($assignmentId){$stmt=$this->pdo->prepare("SELECT jwc.job_id,jwc.work_type_id FROM work_assignments wa JOIN worker_profiles wp ON wp.id=wa.worker_profile_id JOIN job_work_components jwc ON jwc.id=wa.job_work_component_id WHERE wa.id=? AND wp.user_id=? AND wa.status IN ('accepted','in_progress','completed')");$stmt->execute([$assignmentId,$userId]);$assignment=$stmt->fetch(PDO::FETCH_ASSOC);if(!$assignment)throw new DomainException('Choose an accepted assignment.');if(!$jobId)throw new DomainException('Select the assignment Job before saving time.');if((int)$assignment['job_id']!==$jobId)throw new DomainException('The assignment does not belong to that Job.');$workTypeId=(int)$assignment['work_type_id'];}
        if($workTypeId){$stmt=$this->pdo->prepare('SELECT 1 FROM work_types WHERE id=? AND is_active=1');$stmt->execute([$workTypeId]);if(!$stmt->fetchColumn())throw new DomainException('Choose an active Work Type.');}
        return [$workTypeId,$assignmentId];
    }

    private function parseLocalDateTime(string $value, DateTimeZone $timezone): DateTimeImmutable
    {
        $value = trim($value);
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i', $value, $timezone);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$parsed
            || $parsed->format('Y-m-d\\TH:i') !== $value
            || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new DomainException('Enter a valid local date and time.');
        }
        return $parsed;
    }
}
