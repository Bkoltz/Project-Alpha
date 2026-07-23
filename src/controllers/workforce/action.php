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
use App\Services\PayrollExportService;
use App\Services\TimeApprovalPolicy;
use App\Services\TimeCorrectionBillingResolutionService;
use App\Services\TimeCorrectionService;
use App\Services\TimeSubmissionService;
use App\Services\WorkTimeInvoiceLinkService;
use App\Services\WorkerEarningService;
use App\Services\WorkerPaymentRecordService;
use App\Services\WorkforceCommandRegistry;

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../utils/audit.php';
require_once __DIR__ . '/../../utils/invoice_lifecycle.php';
require_once __DIR__ . '/../../services/DocumentPolicy.php';
require_once __DIR__ . '/../../services/DocumentRevisionService.php';
require_once __DIR__ . '/../../services/WorkTimeInvoiceLinkService.php';

$userId = (int) ($_SESSION['user']['id'] ?? 0);
if ($userId <= 0 || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code($userId <= 0 ? 401 : 405);
    exit;
}

$action = trim((string) ($_POST['action'] ?? ''));
$audit = new AuditRecorder($pdo);
$time = new TimekeepingService($pdo, $audit);
$approvalPolicy = new TimeApprovalPolicy($pdo);
$approval = new ApprovalService($pdo, $audit, new BillingTimeConsumer($pdo), $approvalPolicy);

function workforce_redirect(string $path, string $key, string $message): never
{
    $separator = str_contains($path, '?') ? '&' : '?';
    header('Location: ' . $path . $separator . $key . '=' . rawurlencode($message));
    exit;
}

function workforce_require(PDO $pdo, int $userId, string $permission): void
{
    if (in_array((string)($_SESSION['user']['role'] ?? ''), ['admin','owner'], true)
        || user_can($pdo, $userId, $permission, 0)) {
        return;
    }
    http_response_code(403);
    exit('Permission denied.');
}

function workforce_require_any(PDO $pdo, int $userId, array $permissions): void
{
    if (in_array((string)($_SESSION['user']['role'] ?? ''), ['admin','owner'], true)) {
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

function workforce_self_confirm_owner(ApprovalService $approval, int $ownerId, string $entryId): void
{
    try {
        $approval->selfConfirmOwner($ownerId, $entryId);
    } catch (Throwable $error) {
        try {
            $approval->returnOwnerForRepair($ownerId, $entryId);
        } catch (Throwable $recoveryError) {
            throw new DomainException(
                'Time was saved, but owner confirmation and automatic recovery both failed. Contact an administrator before editing it.',
                0,
                $recoveryError
            );
        }
        throw new DomainException(
            'Time was saved but could not be confirmed. It remains editable so you can review and resubmit it.',
            0,
            $error
        );
    }
}

function workforce_link_preselected_invoice(
    PDO $pdo,
    int $actorId,
    string $entryId,
    bool $manageAll
): ?string {
    $selected = $pdo->prepare('SELECT invoice_id,job_id,client_id,user_id,billable FROM work_time_entries WHERE id=?');
    $selected->execute([$entryId]);
    $entry = $selected->fetch(PDO::FETCH_ASSOC);
    if (!$entry || (int)($entry['billable'] ?? 0) !== 1) return null;
    $invoiceId = (int)($entry['invoice_id'] ?? 0);
    if ($invoiceId <= 0 && !empty($entry['job_id'])) {
        $drafts = $pdo->prepare(
            "SELECT id FROM invoices
             WHERE job_id=? AND client_id=? AND status='draft' AND finalized_at IS NULL
             ORDER BY id"
        );
        $drafts->execute([(int)$entry['job_id'],(int)$entry['client_id']]);
        $candidateIds = array_map('intval', $drafts->fetchAll(PDO::FETCH_COLUMN));
        if (count($candidateIds) !== 1) return null;
        $invoiceId = $candidateIds[0];
    }
    if ($invoiceId <= 0) {
        return null;
    }
    if (!user_can($pdo, $actorId, 'invoices.edit', 0)
        || !can_access_record($pdo, 'invoices', $invoiceId, $actorId)) {
        return 'Time was confirmed, but it was not added to the invoice because you do not have billing access.';
    }
    try {
        (new WorkTimeInvoiceLinkService($pdo))->link($actorId, $entryId, $invoiceId, null, $manageAll);
        return null;
    } catch (DomainException $error) {
        return 'Time was confirmed, but it was not added to the selected invoice: ' . $error->getMessage();
    }
}

try {
    WorkforceCommandRegistry::require($action, (string)($_SERVER['REQUEST_METHOD'] ?? ''));
    if ($action === 'submit-period') {
        $manageAll = WorkforceSettings::canManageAllTime($pdo, $userId);
        if (!$manageAll && !user_can($pdo, $userId, 'timekeeping.self', 0)) {
            http_response_code(403);
            exit('Permission denied.');
        }
        $workerProfileId = (int)($_POST['worker_profile_id'] ?? 0);
        $periodId = (int)($_POST['pay_period_id'] ?? 0);
        $workerStmt = $pdo->prepare('SELECT user_id FROM worker_profiles WHERE id=? AND status="active"');
        $workerStmt->execute([$workerProfileId]);
        $workerUserId = (int)($workerStmt->fetchColumn() ?: 0);
        if ($workerUserId <= 0 || (!$manageAll && $workerUserId !== $userId)) {
            throw new DomainException('You cannot submit time for that worker.');
        }
        $entryIds = is_array($_POST['entry_ids'] ?? null) ? $_POST['entry_ids'] : [];
        $result = (new TimeSubmissionService($pdo))->submit(
            $periodId,
            $workerProfileId,
            $userId,
            $entryIds,
            (string)($_POST['notes'] ?? ''),
            $manageAll
        );
        $returnPath = '/?page=workforce/time' . ($manageAll ? '&user=' . $workerUserId : '');
        workforce_redirect($returnPath, 'success', $result['entry_count'] . ' time entr' . ($result['entry_count'] === 1 ? 'y was' : 'ies were') . ' submitted for review.');
    }
    if ($action === 'link-invoice') {
        $manageAll = WorkforceSettings::canManageAllTime($pdo, $userId);
        if (!$manageAll || !user_can($pdo, $userId, 'invoices.edit', 0)) {
            http_response_code(403);
            exit('Permission denied.');
        }
        $entryId = trim((string)($_POST['entry_id'] ?? ''));
        $entryUserId = (int)($_POST['entry_user_id'] ?? $userId);
        $invoiceId = (int)($_POST['invoice_id'] ?? 0);
        if ($invoiceId <= 0) {
            throw new DomainException('Choose an invoice.');
        }
        require_record_ownership($pdo, 'invoices', $invoiceId);
        if ($entryUserId === $userId && $approval->canSelfConfirmOwner($userId)) {
            $approval->ensureOwnerProjection($userId, $entryId);
        }
        (new WorkTimeInvoiceLinkService($pdo))->link(
            $userId,
            $entryId,
            $invoiceId,
            isset($_POST['billing_rate']) ? (string)$_POST['billing_rate'] : null,
            $manageAll
        );
        $returnPath = (string)($_POST['return_to'] ?? '') === 'invoice-edit'
            ? '/?page=invoice/invoices-edit&id=' . $invoiceId
            : '/?page=workforce/time' . ($manageAll ? '&user=' . $entryUserId : '');
        workforce_redirect($returnPath, 'success', 'Time was added to the invoice.');
    }
    if(in_array($action,['assignment-accept','assignment-decline','assignment-start','assignment-complete'],true)){
        $worker=$pdo->prepare("SELECT id FROM worker_profiles WHERE user_id=? AND status='active'");$worker->execute([$userId]);$workerId=(int)$worker->fetchColumn();if($workerId<=0)throw new DomainException('This account is not linked to an active worker profile.');
        $planning=new JobWorkPlanningService($pdo,new CompensationRuleService($pdo));$assignmentId=(int)($_POST['assignment_id']??0);
        if($action==='assignment-accept')$planning->accept($assignmentId,$workerId);
        elseif($action==='assignment-decline')$planning->decline($assignmentId,$workerId,(string)($_POST['reason']??''));
        elseif($action==='assignment-start')$planning->start($assignmentId,$workerId);
        else $planning->complete($assignmentId,$workerId);
        workforce_redirect('/?page=workforce/time','success','Assignment updated.');
    }
    if (in_array($action, ['clock-in','clock-out','break-start','break-end','manual-create','quick-duration','resubmit','cancel'], true) || $action === 'edit') {
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
        $entryToSelfConfirm = null;
        $_POST['entered_by_user_id'] = $userId;
        if ($action === 'clock-in') {
            $time->clockIn($entryUserId, $_POST, $manageAll);
        } elseif ($action === 'clock-out') {
            $entryToSelfConfirm = (string)($_POST['entry_id'] ?? '');
            $time->clockOut($entryUserId, $entryToSelfConfirm);
        } elseif ($action === 'break-start') {
            $time->startBreak($entryUserId, (string) ($_POST['entry_id'] ?? ''));
        } elseif ($action === 'break-end') {
            $time->endBreak($entryUserId, (string) ($_POST['break_id'] ?? ''));
        } elseif ($action === 'manual-create') {
            $entryToSelfConfirm = $time->saveManual($entryUserId, $_POST, $manageAll);
        } elseif ($action === 'quick-duration') {
            if (!$manageAll) {
                throw new DomainException('Quick duration entry is limited to owners and timekeeping managers.');
            }
            $entryToSelfConfirm = $time->saveDuration($entryUserId, $_POST);
        } elseif (in_array($action, ['resubmit', 'edit'], true)) {
            $entryToSelfConfirm = (string)($_POST['entry_id'] ?? '');
            $time->reviseEntry($userId, $entryUserId, $entryToSelfConfirm, $_POST, $manageAll);
        } else {
            $time->cancel($entryUserId, (string) ($_POST['entry_id'] ?? ''));
        }
        $invoiceLinkWarning = null;
        if ($entryToSelfConfirm !== ''
            && $entryUserId === $userId
            && $approval->canSelfConfirmOwner($userId)) {
            workforce_self_confirm_owner($approval, $userId, $entryToSelfConfirm);
            $invoiceLinkWarning = workforce_link_preselected_invoice($pdo, $userId, $entryToSelfConfirm, $manageAll);
        }
        $returnPath = '/?page=workforce/time' . ($manageAll ? '&user=' . $entryUserId : '');
        workforce_redirect(
            $returnPath,
            $invoiceLinkWarning === null ? 'success' : 'error',
            $invoiceLinkWarning ?? 'Time entry updated.'
        );
    }

    if (in_array($action, ['approve','reject','void'], true)) {
        $entryId = (string) ($_POST['entry_id'] ?? '');
        $approvalPolicy->assertCanReviewEntry($userId, $entryId, $action);
        $invoiceLinkWarning = null;
        if ($action === 'approve') {
            $approval->approve($userId, $entryId);
            $invoiceLinkWarning = workforce_link_preselected_invoice(
                $pdo,
                $userId,
                $entryId,
                WorkforceSettings::canManageAllTime($pdo, $userId)
            );
        } elseif ($action === 'reject') {
            $approval->reject($userId, $entryId, (string) ($_POST['reason'] ?? ''));
        } else {
            $approval->void($userId, $entryId, (string) ($_POST['reason'] ?? ''));
        }
        workforce_redirect(
            '/?page=workforce/approvals',
            $invoiceLinkWarning === null ? 'success' : 'error',
            $invoiceLinkWarning ?? 'Approval workflow updated.'
        );
    }

    if ($action === 'pay-status') {
        workforce_require($pdo, $userId, 'employee_pay.manage');
        $status = in_array($_POST['status'] ?? '', ['pending','paid'], true) ? (string) $_POST['status'] : 'pending';
        $stmt = $pdo->prepare("UPDATE work_pay_accruals SET status=?,paid_at=CASE WHEN ?='paid' THEN UTC_TIMESTAMP(6) ELSE NULL END WHERE id=? AND status<>'voided'");
        $stmt->execute([$status, $status, (string) ($_POST['accrual_id'] ?? '')]);
        audit_log($pdo, 'employee_pay.status_updated', 'work_pay_accrual', null, ['id' => (string) ($_POST['accrual_id'] ?? ''), 'status' => $status]);
        workforce_redirect('/?page=workforce/pay', 'success', 'Pay status updated.');
    }
    if ($action === 'earning-approve') {
        workforce_require_any($pdo, $userId, ['workforce.statements.manage','employee_pay.manage']);
        (new WorkerEarningService($pdo))->transition(
            trim((string)($_POST['earning_id'] ?? '')),
            'approved',
            $userId,
            'Approved for the next open worker statement'
        );
        workforce_redirect('/?page=workforce/pay', 'success', 'Worker earning approved for statement inclusion.');
    }
    if ($action === 'correction-request') {
        $entryId = trim((string)($_POST['entry_id'] ?? ''));
        $changes = [];
        $fieldAliases = [
            'client_id' => ['client_id'],
            'project_id' => ['project_id'],
            'invoice_id' => ['invoice_id'],
            'job_id' => ['job_id'],
            'work_type_id' => ['work_type_id'],
            'work_assignment_id' => ['work_assignment_id'],
            'start_time' => ['start_time', 'corrected_start_time'],
            'end_time' => ['end_time', 'corrected_end_time'],
            'description' => ['description', 'corrected_description'],
        ];
        foreach ($fieldAliases as $field => $aliases) {
            foreach ($aliases as $alias) {
                if (array_key_exists($alias, $_POST)) {
                    $changes[$field] = $_POST[$alias];
                    break;
                }
            }
        }
        (new TimeCorrectionService($pdo))->request(
            $entryId,
            $changes,
            (string)($_POST['reason'] ?? ''),
            $userId
        );
        workforce_redirect('/?page=workforce/approvals&tab=corrections', 'success', 'Time correction submitted for review.');
    }
    if ($action === 'admin-correction-apply') {
        workforce_require($pdo, $userId, 'workforce.corrections.manage');
        $entryId = trim((string)($_POST['entry_id'] ?? ''));
        $changes = [];
        foreach (['client_id','project_id','invoice_id','job_id','work_type_id','work_assignment_id','start_time','end_time','description'] as $field) {
            if (array_key_exists($field, $_POST)) {
                $changes[$field] = $_POST[$field];
            }
        }
        $reason = trim((string)($_POST['reason'] ?? ''));
        if ($reason === '') {
            throw new DomainException('A correction reason is required.');
        }
        $service = new TimeCorrectionService($pdo);
        $ownsTransaction = !$pdo->inTransaction();
        try {
            if ($ownsTransaction) {
                $pdo->beginTransaction();
            }
            $requestId = $service->request($entryId, $changes, $reason, $userId);
            $service->approve($requestId, $userId, null, 'Applied directly by an authorized administrator.');
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (Throwable $error) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
        $correctedUserId = (int)($_POST['entry_user_id'] ?? 0);
        workforce_redirect('/?page=workforce/time' . ($correctedUserId > 0 ? '&user=' . $correctedUserId : ''), 'success', 'The approved time was corrected and all resulting billing and pay effects were recorded.');
    }
    if ($action === 'correction-approve') {
        workforce_require($pdo, $userId, 'workforce.corrections.manage');
        $periodId = (int)($_POST['next_open_pay_period_id'] ?? 0);
        $manualDelta = trim((string)($_POST['manual_worker_delta'] ?? ''));
        (new TimeCorrectionService($pdo))->approve(
            trim((string)($_POST['request_id'] ?? '')),
            $userId,
            $periodId > 0 ? $periodId : null,
            (string)($_POST['notes'] ?? ''),
            $manualDelta === '' ? null : $manualDelta
        );
        workforce_redirect('/?page=workforce/approvals&tab=corrections', 'success', 'Time correction approved and its effects were recorded.');
    }
    if ($action === 'correction-reject') {
        workforce_require($pdo, $userId, 'workforce.corrections.manage');
        (new TimeCorrectionService($pdo))->reject(
            trim((string)($_POST['request_id'] ?? '')),
            $userId,
            (string)($_POST['notes'] ?? '')
        );
        workforce_redirect('/?page=workforce/approvals&tab=corrections', 'success', 'Time correction rejected.');
    }
    if ($action === 'correction-billing-resolve') {
        workforce_require($pdo, $userId, 'billing.client_credits.manage');
        $targetInvoiceId = (int)($_POST['target_draft_invoice_id'] ?? 0);
        (new TimeCorrectionBillingResolutionService($pdo))->resolve(
            trim((string)($_POST['request_id'] ?? '')),
            trim((string)($_POST['decision'] ?? '')),
            (string)($_POST['reason'] ?? ''),
            $userId,
            $targetInvoiceId > 0 ? $targetInvoiceId : null
        );
        workforce_redirect('/?page=workforce/approvals&tab=billing', 'success', 'The finalized-invoice correction was resolved.');
    }
    if ($action === 'worker-payment-record') {
        workforce_require($pdo, $userId, 'workforce.payments.manage');
        $statementIds = is_array($_POST['statement_ids'] ?? null) ? $_POST['statement_ids'] : [];
        $amounts = is_array($_POST['allocation_amounts'] ?? null) ? $_POST['allocation_amounts'] : [];
        $allocations = [];
        foreach ($statementIds as $index => $statementId) {
            if ((int)$statementId > 0 && isset($amounts[$index]) && trim((string)$amounts[$index]) !== '') {
                $allocations[] = ['statement_id' => (int)$statementId, 'amount' => (string)$amounts[$index]];
            }
        }
        (new WorkerPaymentRecordService($pdo))->record(
            (int)($_POST['worker_profile_id'] ?? 0),
            (string)($_POST['payment_date'] ?? ''),
            (string)($_POST['amount'] ?? ''),
            (string)($_POST['currency'] ?? 'USD'),
            (string)($_POST['method'] ?? ''),
            isset($_POST['reference']) ? (string)$_POST['reference'] : null,
            isset($_POST['notes']) ? (string)$_POST['notes'] : null,
            $allocations,
            $userId
        );
        workforce_redirect('/?page=workforce/pay&tab=payments', 'success', 'Worker payment recorded.');
    }
    if ($action === 'worker-payment-void') {
        workforce_require($pdo, $userId, 'workforce.payments.manage');
        (new WorkerPaymentRecordService($pdo))->void(
            trim((string)($_POST['payment_record_id'] ?? '')),
            (string)($_POST['reason'] ?? ''),
            $userId
        );
        workforce_redirect('/?page=workforce/pay&tab=payments', 'success', 'Worker payment record voided.');
    }
    if ($action === 'payroll-export-generate') {
        workforce_require($pdo, $userId, 'workforce.payroll_exports.manage');
        $earningIds = is_array($_POST['earning_ids'] ?? null) ? array_map('strval', $_POST['earning_ids']) : [];
        $periodId = (int)($_POST['pay_period_id'] ?? 0);
        $result = (new PayrollExportService($pdo))->generate(
            trim((string)($_POST['export_key'] ?? '')),
            $earningIds,
            $userId,
            $periodId > 0 ? $periodId : null
        );
        $fileName = preg_replace('/[^A-Za-z0-9._-]/', '-', (string)$result['file_name']) ?: 'payroll-export.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $fileName . '"');
        header('X-Content-Type-Options: nosniff');
        header('Content-Length: ' . strlen((string)$result['csv']));
        echo $result['csv'];
        exit;
    }
    if ($action === 'payroll-export-void') {
        workforce_require($pdo, $userId, 'workforce.payroll_exports.manage');
        (new PayrollExportService($pdo))->void(
            trim((string)($_POST['export_id'] ?? '')),
            (string)($_POST['reason'] ?? ''),
            $userId
        );
        workforce_redirect('/?page=workforce/pay&tab=exports', 'success', 'Payroll export voided.');
    }
    if($action==='statement-settle'){
        workforce_require_any($pdo,$userId,['workforce.statements.manage','employee_pay.manage']);
        (new PayPeriodService($pdo))->settleStatement((int)($_POST['statement_id']??0),$userId);
        workforce_redirect('/?page=workforce/pay','success','Statement settled.');
    }

    throw new DomainException('Unsupported workforce action.');
} catch (Throwable $error) {
    $target = match (true) {
        $action === 'link-invoice'
            && (string)($_POST['return_to'] ?? '') === 'invoice-edit'
            && (int)($_POST['invoice_id'] ?? 0) > 0
                => '/?page=invoice/invoices-edit&id=' . (int)$_POST['invoice_id'],
        in_array($action, ['approve','reject','void'], true) => '/?page=workforce/approvals',
        $action === 'admin-correction-apply' => '/?page=workforce/time',
        str_starts_with($action, 'correction-') => '/?page=workforce/approvals&tab=corrections',
        in_array($action,['pay-status','earning-approve','statement-settle','worker-payment-record','worker-payment-void','payroll-export-generate','payroll-export-void'],true) => '/?page=workforce/pay',
        default => '/?page=workforce/time',
    };
    workforce_redirect($target, 'error', $error instanceof DomainException ? $error->getMessage() : 'The operation could not be completed.');
}
