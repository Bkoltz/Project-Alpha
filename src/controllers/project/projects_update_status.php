<?php
// src/controllers/project/projects_update_status.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/csrf.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../services/ScheduleService.php';
require_once __DIR__ . '/../../services/PortalProjectionMutationService.php';
require_once __DIR__ . '/../../utils/audit.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

if (!csrf_validate()) {
    http_response_code(403);
    exit('CSRF token invalid');
}

$id = (int)($_POST['project_id'] ?? 0);
$status = $_POST['status'] ?? '';

if (!$id) {
    http_response_code(400);
    exit('Project ID is required');
}

$validStatuses = ['not_started', 'active', 'overdue', 'completed', 'cancelled'];
if (!in_array($status, $validStatuses)) {
    http_response_code(400);
    exit('Invalid status');
}

try {
    $pdo->beginTransaction();
    $st = $pdo->prepare("UPDATE projects SET completed_at=CASE WHEN ?='completed' AND status<>'completed' THEN UTC_TIMESTAMP(6) WHEN ?<>'completed' THEN NULL ELSE completed_at END,status=?,source_version=?,updated_at=NOW() WHERE id=?");
    $st->execute([$status,$status,$status,'v-'.bin2hex(random_bytes(16)),$id]);
    if($st->rowCount()!==1)throw new RuntimeException('Project not found.');
    ScheduleService::syncProject($pdo, $id, (string)($appConfig['timezone'] ?? 'UTC'), (int)($_SESSION['user']['id']??0));
    (new App\Services\PortalProjectionMutationService())->queueProject($pdo,$id);
    audit_log($pdo,'project.status.changed','project',$id,['status'=>$status,'completed_at_authoritative'=>$status==='completed']);
    $pdo->commit();
} catch (Throwable $error) {
    if($pdo->inTransaction())$pdo->rollBack();
    http_response_code(500);exit('Project status could not be updated.');
}

$redirect = $_POST['redirect'] ?? '/?page=project/projects-details&id=' . $id;
header('Location: ' . $redirect);
exit;
?>
