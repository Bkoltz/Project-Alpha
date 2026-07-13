<?php

declare(strict_types=1);

use App\Modules\Timekeeping\ApprovalService;
use App\Modules\Timekeeping\AuditRecorder;
use App\Modules\Timekeeping\BillingTimeConsumer;
use App\Modules\Timekeeping\TimekeepingService;

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../utils/audit.php';
require_once __DIR__ . '/../../utils/password_policy.php';

$userId = (int) ($_SESSION['user']['id'] ?? 0);
if ($userId <= 0 || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code($userId <= 0 ? 401 : 405);
    exit;
}

$action = trim((string) ($_POST['action'] ?? ''));
$audit = new AuditRecorder($pdo);
$time = new TimekeepingService($pdo, $audit);
$approval = new ApprovalService($pdo, $audit, new BillingTimeConsumer($pdo));

function workforce_redirect(string $path, string $key, string $message): never
{
    $separator = str_contains($path, '?') ? '&' : '?';
    header('Location: ' . $path . $separator . $key . '=' . rawurlencode($message));
    exit;
}

function workforce_require(PDO $pdo, int $userId, string $permission): void
{
    if (($_SESSION['user']['role'] ?? '') === 'admin' || user_can($pdo, $userId, $permission, 0)) {
        return;
    }
    http_response_code(403);
    exit('Permission denied.');
}

function workforce_require_any(PDO $pdo, int $userId, array $permissions): void
{
    if (($_SESSION['user']['role'] ?? '') === 'admin') {
        return;
    }
    foreach ($permissions as $permission) {
        if (user_can($pdo, $userId, $permission, 0)) {
            return;
        }
    }
    http_response_code(403);
    exit('Permission denied.');
}

try {
    if (in_array($action, ['clock-in','clock-out','break-start','break-end','manual-create','resubmit','cancel'], true)) {
        workforce_require_any($pdo, $userId, ['timekeeping.self', 'timekeeping.manage']);
        $manageAll = user_can($pdo, $userId, 'timekeeping.manage', 0);
        if ($action === 'clock-in') {
            $time->clockIn($userId, (int) ($_POST['project_id'] ?? 0) ?: null, (string) ($_POST['description'] ?? ''), [], $manageAll);
        } elseif ($action === 'clock-out') {
            $time->clockOut($userId, (string) ($_POST['entry_id'] ?? ''));
        } elseif ($action === 'break-start') {
            $time->startBreak($userId, (string) ($_POST['entry_id'] ?? ''));
        } elseif ($action === 'break-end') {
            $time->endBreak($userId, (string) ($_POST['break_id'] ?? ''));
        } elseif ($action === 'manual-create') {
            $time->saveManual($userId, $_POST, $manageAll);
        } elseif ($action === 'resubmit') {
            $time->reviseRejected($userId, (string) ($_POST['entry_id'] ?? ''), $_POST, $manageAll);
        } else {
            $time->cancel($userId, (string) ($_POST['entry_id'] ?? ''));
        }
        workforce_redirect('/time', 'success', 'Time entry updated.');
    }

    if (in_array($action, ['approve','reject','correct','void'], true)) {
        workforce_require($pdo, $userId, 'approvals.review');
        $entryId = (string) ($_POST['entry_id'] ?? '');
        if ($action === 'approve') {
            $approval->approve($userId, $entryId);
        } elseif ($action === 'reject') {
            $approval->reject($userId, $entryId, (string) ($_POST['reason'] ?? ''));
        } elseif ($action === 'correct') {
            $approval->correct($userId, $entryId, $_POST);
        } else {
            $approval->void($userId, $entryId, (string) ($_POST['reason'] ?? ''));
        }
        workforce_redirect('/approvals', 'success', 'Approval workflow updated.');
    }

    if ($action === 'business-settings') {
        workforce_require($pdo, $userId, 'workforce.manage');
        $timezone = trim((string) ($_POST['timezone'] ?? 'UTC'));
        try { new DateTimeZone($timezone); } catch (Throwable) { throw new DomainException('Choose a valid IANA timezone.'); }
        $currency = strtoupper(trim((string) ($_POST['currency'] ?? 'USD')));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) { throw new DomainException('Currency must be a three-letter ISO code.'); }
        $pay = trim((string) ($_POST['default_hourly_rate'] ?? ''));
        $billing = trim((string) ($_POST['default_billing_rate'] ?? ''));
        foreach ([$pay,$billing] as $rate) { if ($rate !== '' && !preg_match('/^\d+(?:\.\d{1,4})?$/', $rate)) throw new DomainException('Rates must be non-negative decimals with at most four places.'); }
        $pdo->prepare('UPDATE business_settings SET business_name=?,timezone=?,currency=?,default_hourly_rate=?,default_billing_rate=?,require_project=?,require_description=? WHERE singleton=1')
            ->execute([trim((string)($_POST['business_name']??'')) ?: 'My Business',$timezone,$currency,$pay!==''?$pay:null,$billing!==''?$billing:null,!empty($_POST['require_project'])?1:0,!empty($_POST['require_description'])?1:0]);
        audit_log($pdo,'workforce.business_settings_updated','business_settings',1,['timezone'=>$timezone,'currency'=>$currency]);
        workforce_redirect('/workforce','success','Business time settings updated.');
    }

    if ($action === 'employee-create') {
        workforce_require($pdo, $userId, 'workforce.manage');
        $email = trim((string) ($_POST['email'] ?? ''));
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $firstName = trim((string) ($_POST['first_name'] ?? ''));
        $lastName = trim((string) ($_POST['last_name'] ?? ''));
        $hourlyRate = trim((string) ($_POST['hourly_rate'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new DomainException('Enter a valid employee email address.');
        }
        if ($firstName === '') {
            throw new DomainException('First name is required.');
        }
        if ($username !== '' && !preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username)) {
            throw new DomainException('Username must use 3-50 letters, numbers, dots, dashes, or underscores.');
        }
        if ($error = password_policy_error($password)) {
            throw new DomainException($error);
        }
        if ($hourlyRate !== '' && !preg_match('/^\d+(?:\.\d{1,4})?$/', $hourlyRate)) {
            throw new DomainException('Hourly rate must be a non-negative decimal with at most four places.');
        }
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("INSERT INTO users (email,username,password_hash,role,force_password_reset) VALUES (?,?,?,'employee',1)");
            $stmt->execute([$email, $username !== '' ? $username : null, password_hash($password, PASSWORD_DEFAULT)]);
            $employeeId = (int) $pdo->lastInsertId();
            $currency = (string) $pdo->query('SELECT currency FROM business_settings WHERE singleton=1')->fetchColumn();
            $pdo->prepare(
                'INSERT INTO employee_profiles (user_id,first_name,last_name,hourly_rate,currency) VALUES (?,?,?,?,?)'
            )->execute([
                $employeeId, $firstName, $lastName, $hourlyRate !== '' ? $hourlyRate : null, $currency,
            ]);
            $displayName = trim($firstName . ' ' . $lastName);
            $pdo->prepare("INSERT INTO team_members (user_id,display_name,email,is_active,profile_source) VALUES (?,?,?,1,'pa')")
                ->execute([$employeeId, $displayName, $email]);
            $pdo->commit();
            audit_log($pdo, 'workforce.employee_created', 'user', $employeeId, ['email' => $email, 'role' => 'employee']);
        } catch (Throwable $error) {
            $pdo->rollBack();
            throw $error;
        }
        workforce_redirect('/workforce', 'success', 'Employee account created.');
    }

    if ($action === 'employee-update') {
        workforce_require($pdo, $userId, 'workforce.manage');
        $employeeId = (int) ($_POST['user_id'] ?? 0);
        $status = in_array($_POST['employment_status'] ?? '', ['active','inactive','terminated'], true)
            ? (string) $_POST['employment_status'] : 'active';
        $rate = trim((string) ($_POST['hourly_rate'] ?? ''));
        if ($rate !== '' && !preg_match('/^\d+(?:\.\d{1,4})?$/', $rate)) {
            throw new DomainException('Hourly rate must be a non-negative decimal with at most four places.');
        }
        $firstName = trim((string) ($_POST['first_name'] ?? ''));
        $lastName = trim((string) ($_POST['last_name'] ?? ''));
        if ($firstName === '') {
            throw new DomainException('First name is required.');
        }
        $projectIds = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['project_ids'] ?? [])))));
        $projectRates = (array) ($_POST['project_rates'] ?? []);
        $pdo->beginTransaction();
        try {
            $employee = $pdo->prepare("SELECT email FROM users WHERE id=? AND role='employee' FOR UPDATE");
            $employee->execute([$employeeId]);
            $employeeEmail = $employee->fetchColumn();
            if ($employeeEmail === false) {
                throw new DomainException('Employee account not found.');
            }
            if ($status !== 'active') {
                $activeTimer = $pdo->prepare('SELECT time_entry_id FROM work_timer_locks WHERE user_id=? FOR UPDATE');
                $activeTimer->execute([$employeeId]);
                if ($activeTimer->fetchColumn() !== false) {
                    throw new DomainException('Clock out the employee before making the account inactive or terminated.');
                }
            }
            $pdo->prepare(
                "UPDATE employee_profiles SET first_name=?,last_name=?,employment_status=?,hourly_rate=?,employee_can_view_pay=?,
                 terminated_at=CASE WHEN ?='terminated' THEN COALESCE(terminated_at,CURRENT_DATE) ELSE NULL END WHERE user_id=?"
            )->execute([
                $firstName, $lastName, $status, $rate !== '' ? $rate : null,
                !empty($_POST['employee_can_view_pay']) ? 1 : 0, $status, $employeeId,
            ]);
            $disabled = $status === 'active' ? 0 : 1;
            $pdo->prepare('UPDATE users SET is_disabled=?,auth_version=auth_version+1 WHERE id=? AND role=\'employee\'')->execute([$disabled, $employeeId]);
            $pdo->prepare('UPDATE team_members SET display_name=?,email=?,is_active=? WHERE user_id=?')
                ->execute([trim($firstName . ' ' . $lastName), (string) $employeeEmail, $disabled ? 0 : 1, $employeeId]);
            $pdo->prepare('DELETE FROM project_assignments WHERE user_id=?')->execute([$employeeId]);
            $insert = $pdo->prepare('INSERT INTO project_assignments (project_id,user_id,pay_rate_override,created_by) VALUES (?,?,?,?)');
            foreach ($projectIds as $projectId) {
                $override = trim((string)($projectRates[$projectId] ?? ''));
                if ($override !== '' && !preg_match('/^\d+(?:\.\d{1,4})?$/', $override)) {
                    throw new DomainException('Assignment pay overrides must be non-negative decimals with at most four places.');
                }
                $insert->execute([$projectId, $employeeId, $override !== '' ? $override : null, $userId]);
            }
            $pdo->commit();
            audit_log($pdo, 'workforce.employee_updated', 'user', $employeeId, ['employment_status' => $status, 'project_ids' => $projectIds]);
        } catch (Throwable $error) {
            $pdo->rollBack();
            throw $error;
        }
        workforce_redirect('/workforce?employee=' . $employeeId, 'success', 'Employee and assignments updated.');
    }

    if ($action === 'pay-status') {
        workforce_require($pdo, $userId, 'employee_pay.manage');
        $status = in_array($_POST['status'] ?? '', ['pending','paid'], true) ? (string) $_POST['status'] : 'pending';
        $stmt = $pdo->prepare("UPDATE work_pay_accruals SET status=?,paid_at=CASE WHEN ?='paid' THEN UTC_TIMESTAMP(6) ELSE NULL END WHERE id=? AND status<>'voided'");
        $stmt->execute([$status, $status, (string) ($_POST['accrual_id'] ?? '')]);
        audit_log($pdo, 'employee_pay.status_updated', 'work_pay_accrual', null, ['id' => (string) ($_POST['accrual_id'] ?? ''), 'status' => $status]);
        workforce_redirect('/pay', 'success', 'Pay status updated.');
    }

    throw new DomainException('Unsupported workforce action.');
} catch (Throwable $error) {
    $target = match (true) {
        in_array($action, ['approve','reject','correct','void'], true) => '/approvals',
        str_starts_with($action, 'employee-') => '/workforce',
        $action === 'pay-status' => '/pay',
        default => '/time',
    };
    workforce_redirect($target, 'error', $error instanceof DomainException ? $error->getMessage() : 'The operation could not be completed.');
}
