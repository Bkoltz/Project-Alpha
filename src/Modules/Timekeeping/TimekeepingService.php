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
            "SELECT i.id,i.client_id,i.project_id,i.doc_number,i.invoice_type,i.status,c.name client_name,p.name project_name
             FROM invoices i JOIN clients c ON c.id=i.client_id LEFT JOIN projects p ON p.id=i.project_id
             WHERE i.billing_mode='hourly' AND i.status NOT IN ('paid','cancelled','void')
             ORDER BY c.name,i.created_at DESC"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function running(int $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT t.*,p.name project_name,c.name client_name,i.doc_number invoice_number,i.invoice_type,
             (SELECT id FROM work_time_breaks b WHERE b.time_entry_id=t.id AND b.end_time IS NULL LIMIT 1) open_break_id
             FROM work_time_entries t LEFT JOIN projects p ON p.id=t.project_id
             LEFT JOIN clients c ON c.id=t.client_id LEFT JOIN invoices i ON i.id=t.invoice_id
             WHERE t.user_id=? AND t.end_time IS NULL AND t.status=\'running\' LIMIT 1'
        );
        $stmt->execute([$userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function entries(int $userId, bool $manageAll = false, int $limit = 250): array
    {
        $sql = 'SELECT t.*,p.name project_name,c.name client_name,i.doc_number invoice_number,i.invoice_type,
                COALESCE(NULLIF(TRIM(CONCAT(ep.first_name,\' \',ep.last_name)),\'\'),NULLIF(u.username,\'\'),u.email) employee_name
                FROM work_time_entries t JOIN users u ON u.id=t.user_id
                LEFT JOIN employee_profiles ep ON ep.user_id=t.user_id LEFT JOIN projects p ON p.id=t.project_id
                LEFT JOIN clients c ON c.id=t.client_id LEFT JOIN invoices i ON i.id=t.invoice_id';
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
        if (!$manageAll && (int) $settings['require_project'] === 1 && !$projectId) {
            throw new DomainException('A project is required.');
        }
        if (!$manageAll && (int) $settings['require_description'] === 1 && $description === '') {
            throw new DomainException('A description is required.');
        }
        $billable = $manageAll ? (!empty($input['billable']) ? 1 : 0) : ($projectId ? 1 : 0);
        $isPayable = $manageAll ? (!empty($input['is_payable']) ? 1 : 0) : 1;
        $id = Uuid::v4();
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO work_time_entries
                 (id,user_id,client_id,project_id,invoice_id,start_time,description,tags,billable,is_payable,status)
                 VALUES (?,?,?,?,?,UTC_TIMESTAMP(6),?,?,?,?,'running')"
            );
            $stmt->execute([
                $id, $userId, $context['client_id'], $projectId, $context['invoice_id'], $description,
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
        $description = trim((string) ($input['description'] ?? ''));
        if (!$manageAll && (int) $settings['require_project'] === 1 && !$projectId) {
            throw new DomainException('A project is required.');
        }
        if (!$manageAll && (int) $settings['require_description'] === 1 && $description === '') {
            throw new DomainException('A description is required.');
        }
        $billable = $manageAll ? (!empty($input['billable']) ? 1 : 0) : ($projectId ? 1 : 0);
        $isPayable = $manageAll ? (!empty($input['is_payable']) ? 1 : 0) : 1;
        $id = Uuid::v4();
        $startUtc = $start->setTimezone(new DateTimeZone('UTC'));
        $endUtc = $end->setTimezone(new DateTimeZone('UTC'));
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO work_time_entries
                 (id,user_id,client_id,project_id,invoice_id,start_time,end_time,duration_seconds,description,tags,billable,is_payable,status)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,'review')"
            );
            $stmt->execute([
                $id, $userId, $context['client_id'], $projectId, $context['invoice_id'],
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

    public function reviseRejected(int $userId, string $entryId, array $input, bool $manageAll = false): void
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
        $description = trim((string) ($input['description'] ?? ''));
        if (!$manageAll && (int) $settings['require_project'] === 1 && !$projectId) {
            throw new DomainException('A project is required.');
        }
        if (!$manageAll && (int) $settings['require_description'] === 1 && $description === '') {
            throw new DomainException('A description is required.');
        }
        $billable = $manageAll ? (!empty($input['billable']) ? 1 : 0) : ($projectId ? 1 : 0);
        $isPayable = $manageAll ? (!empty($input['is_payable']) ? 1 : 0) : 1;
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM work_time_entries WHERE id=? AND user_id=? AND status='rejected' FOR UPDATE");
            $stmt->execute([$entryId, $userId]);
            $entry = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$entry) {
                throw new DomainException('Rejected entry not found.');
            }
            $this->pdo->prepare(
                'INSERT INTO work_time_revisions (id,time_entry_id,revision,snapshot,reason,created_by) VALUES (?,?,?,?,?,?)'
            )->execute([Uuid::v4(), $entryId, $entry['revision'], json_encode($entry, JSON_THROW_ON_ERROR), 'Employee resubmission', $userId]);
            $startUtc = $start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
            $endUtc = $end->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
            $this->pdo->prepare(
                "UPDATE work_time_entries SET client_id=?,project_id=?,invoice_id=?,start_time=?,end_time=?,duration_seconds=?,description=?,billable=?,is_payable=?,
                 revision=revision+1,status='review',rejection_reason='',reviewed_by=NULL,reviewed_at=NULL WHERE id=?"
            )->execute([
                $context['client_id'], $projectId, $context['invoice_id'], $startUtc, $endUtc,
                $end->getTimestamp() - $start->getTimestamp(), $description,
                $billable, $isPayable, $entryId,
            ]);
            $this->audit->record('time_entry.resubmitted', 'work_time_entry', $entryId, $userId, $entry, ['revision' => (int) $entry['revision'] + 1]);
            $this->pdo->commit();
        } catch (Throwable $error) {
            $this->pdo->rollBack();
            throw $error;
        }
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

        if (!$manageAll && ($clientId || $invoiceId)) {
            throw new DomainException('Client and invoice details are available only to timekeeping managers.');
        }

        if ($invoiceId) {
            $stmt = $this->pdo->prepare(
                "SELECT client_id,project_id FROM invoices
                 WHERE id=? AND billing_mode='hourly' AND status NOT IN ('paid','cancelled','void')"
            );
            $stmt->execute([$invoiceId]);
            $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$invoice) {
                throw new DomainException('Choose an open hourly invoice.');
            }
            $invoiceClientId = (int)$invoice['client_id'];
            $invoiceProjectId = !empty($invoice['project_id']) ? (int)$invoice['project_id'] : null;
            if ($clientId && $clientId !== $invoiceClientId) {
                throw new DomainException('The selected invoice does not belong to that client.');
            }
            if ($projectId && $invoiceProjectId && $projectId !== $invoiceProjectId) {
                throw new DomainException('The selected invoice does not belong to that project.');
            }
            $clientId = $invoiceClientId;
            $projectId ??= $invoiceProjectId;
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
            $this->assertProjectAssigned($userId, $projectId, $manageAll);
        }

        if ($clientId) {
            $stmt = $this->pdo->prepare('SELECT 1 FROM clients WHERE id=? AND archived=0 AND deleted_at IS NULL');
            $stmt->execute([$clientId]);
            if (!$stmt->fetchColumn()) {
                throw new DomainException('Choose an active client.');
            }
        }

        return ['client_id' => $clientId, 'project_id' => $projectId, 'invoice_id' => $invoiceId];
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
