<?php

declare(strict_types=1);

use App\Modules\Timekeeping\ApprovalService;
use App\Modules\Timekeeping\AuditRecorder;
use App\Modules\Timekeeping\BillingTimeConsumer;
use App\Modules\Timekeeping\TimekeepingService;
use App\Modules\Timekeeping\WorkforceSettings;
use App\Services\CompensationRuleService;
use App\Services\JobWorkPlanningService;
use App\Services\PayPeriodService;

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../utils/audit.php';

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
    if(in_array($action,['assignment-accept','assignment-decline','assignment-start','assignment-complete'],true)){
        $worker=$pdo->prepare("SELECT id FROM worker_profiles WHERE user_id=? AND status='active'");$worker->execute([$userId]);$workerId=(int)$worker->fetchColumn();if($workerId<=0)throw new DomainException('This account is not linked to an active worker profile.');
        $planning=new JobWorkPlanningService($pdo,new CompensationRuleService($pdo));$assignmentId=(int)($_POST['assignment_id']??0);
        if($action==='assignment-accept')$planning->accept($assignmentId,$workerId);
        elseif($action==='assignment-decline')$planning->decline($assignmentId,$workerId,(string)($_POST['reason']??''));
        elseif($action==='assignment-start')$planning->start($assignmentId,$workerId);
        else $planning->complete($assignmentId,$workerId);
        workforce_redirect('/?page=workforce/time','success','Assignment updated.');
    }
    if (in_array($action, ['clock-in','clock-out','break-start','break-end','manual-create','quick-duration','resubmit','cancel'], true)) {
        $manageAll = WorkforceSettings::canManageAllTime($pdo, $userId);
        if (!$manageAll && !user_can($pdo, $userId, 'timekeeping.self', 0)) {
            http_response_code(403);
            exit('Permission denied.');
        }
        $entryUserId = $userId;
        if ($manageAll && (int)($_POST['entry_user_id'] ?? 0) > 0) {
            $entryUserId = (int)$_POST['entry_user_id'];
            $target = $pdo->prepare('SELECT 1 FROM users WHERE id=? AND deleted_at IS NULL AND is_disabled=0');
            $target->execute([$entryUserId]);
            if (!$target->fetchColumn()) {
                throw new DomainException('Choose an active PA account for this time entry.');
            }
        }
        if ($action === 'clock-in') {
            $time->clockIn($entryUserId, $_POST, $manageAll);
        } elseif ($action === 'clock-out') {
            $time->clockOut($entryUserId, (string) ($_POST['entry_id'] ?? ''));
        } elseif ($action === 'break-start') {
            $time->startBreak($entryUserId, (string) ($_POST['entry_id'] ?? ''));
        } elseif ($action === 'break-end') {
            $time->endBreak($entryUserId, (string) ($_POST['break_id'] ?? ''));
        } elseif ($action === 'manual-create') {
            $time->saveManual($entryUserId, $_POST, $manageAll);
        } elseif ($action === 'quick-duration') {
            if (!$manageAll) {
                throw new DomainException('Quick duration entry is limited to owners and timekeeping managers.');
            }
            $time->saveDuration($entryUserId, $_POST);
        } elseif ($action === 'resubmit') {
            $time->reviseRejected($entryUserId, (string) ($_POST['entry_id'] ?? ''), $_POST, $manageAll);
        } else {
            $time->cancel($entryUserId, (string) ($_POST['entry_id'] ?? ''));
        }
        $returnPath = '/?page=workforce/time' . ($manageAll ? '&user=' . $entryUserId : '');
        workforce_redirect($returnPath, 'success', 'Time entry updated.');
    }

    if (in_array($action, ['approve','reject','correct','void'], true)) {
        if (!WorkforceSettings::canReviewTime($pdo, $userId)) {
            http_response_code(403);
            exit('Time approval is limited to administrators unless enabled in Workflow settings.');
        }
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
        workforce_redirect('/?page=workforce/approvals', 'success', 'Approval workflow updated.');
    }

    if ($action === 'pay-status') {
        workforce_require($pdo, $userId, 'employee_pay.manage');
        $status = in_array($_POST['status'] ?? '', ['pending','paid'], true) ? (string) $_POST['status'] : 'pending';
        $stmt = $pdo->prepare("UPDATE work_pay_accruals SET status=?,paid_at=CASE WHEN ?='paid' THEN UTC_TIMESTAMP(6) ELSE NULL END WHERE id=? AND status<>'voided'");
        $stmt->execute([$status, $status, (string) ($_POST['accrual_id'] ?? '')]);
        audit_log($pdo, 'employee_pay.status_updated', 'work_pay_accrual', null, ['id' => (string) ($_POST['accrual_id'] ?? ''), 'status' => $status]);
        workforce_redirect('/?page=workforce/pay', 'success', 'Pay status updated.');
    }
    if($action==='statement-settle'){
        workforce_require($pdo,$userId,'employee_pay.manage');(new PayPeriodService($pdo))->settleStatement((int)($_POST['statement_id']??0));
        workforce_redirect('/?page=workforce/pay','success','Statement settled.');
    }

    throw new DomainException('Unsupported workforce action.');
} catch (Throwable $error) {
    $target = match (true) {
        in_array($action, ['approve','reject','correct','void'], true) => '/?page=workforce/approvals',
        in_array($action,['pay-status','statement-settle'],true) => '/?page=workforce/pay',
        default => '/?page=workforce/time',
    };
    workforce_redirect($target, 'error', $error instanceof DomainException ? $error->getMessage() : 'The operation could not be completed.');
}
