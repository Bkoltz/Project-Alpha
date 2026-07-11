<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/csrf.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../utils/alphaledger_integration.php';
require_once __DIR__ . '/../../utils/audit.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit;
}
csrf_verify_post_or_redirect('financial/employee-pay-status');
$currentUserId = (int) ($_SESSION['user']['id'] ?? 0);
if ($currentUserId < 1 || !user_can($pdo, $currentUserId, 'financial.manage')) {
    http_response_code(403);
    exit;
}

$id = (int) ($_POST['id'] ?? 0);
$status = (string) ($_POST['status'] ?? '');
if ($id < 1 || !in_array($status, ['pending', 'paid', 'voided'], true)) {
    header('Location: /?page=financial/expenses-list&tab=employee-pay&error=' . urlencode('Invalid employee pay status.'));
    exit;
}

$orgId = request_client_org_id();
$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare('SELECT epr.*,ai.installation_id AS external_installation_id FROM employee_pay_records epr JOIN alphaledger_installations ai ON ai.id=epr.installation_id WHERE epr.id=? AND epr.deleted_at IS NULL AND (?=0 OR epr.organization_id=?) FOR UPDATE');
    $stmt->execute([$id, $orgId, $orgId]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$record) {
        throw new DomainException('Employee pay record not found.');
    }
    if ($record['status'] !== $status) {
        $revision = (int) $record['status_revision'] + 1;
        $paidAt = $status === 'paid' ? gmdate('Y-m-d H:i:s') : null;
        $pdo->prepare('UPDATE employee_pay_records SET status=?,status_revision=?,paid_at=? WHERE id=?')->execute([$status, $revision, $paidAt, $id]);
        $installationStmt = $pdo->prepare('SELECT * FROM alphaledger_installations WHERE id=?');
        $installationStmt->execute([(int) $record['installation_id']]);
        $installation = $installationStmt->fetch(PDO::FETCH_ASSOC);
        pa_al_emit_event($pdo, $installation, 'pay_accrual.status_changed', (string) $id, $revision, [
            'record_id' => (string) $id,
            'status' => $status,
            'paid_at' => $paidAt ? gmdate('Y-m-d\TH:i:s\Z', strtotime($paidAt . ' UTC')) : null,
        ], (string) $record['currency']);
        audit_log($pdo, 'employee_pay.status_changed', 'employee_pay_record', $id, ['from' => $record['status'], 'to' => $status, 'revision' => $revision]);
    }
    $pdo->commit();
    header('Location: /?page=financial/expenses-list&tab=employee-pay&updated=1');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    @error_log('[EmployeePayStatus] ' . $e->getMessage());
    header('Location: /?page=financial/expenses-list&tab=employee-pay&error=' . urlencode('Could not update employee pay status.'));
}
exit;
