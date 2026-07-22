<?php

declare(strict_types=1);

use App\Services\ExternalOpsIntegrationService;
use App\Services\OperationsPlanningService;
use App\Services\ProjectWorkPlanningService;

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../utils/csrf.php';
require_once __DIR__ . '/../../utils/audit.php';
require_once __DIR__ . '/../../utils/external_ops.php';

$actorUserId = (int)($_SESSION['user']['id'] ?? 0);
$projectId = (int)($_POST['project_id'] ?? 0);
$redirect = '/?page=project/projects-details&id=' . $projectId;
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !csrf_validate()) {
    http_response_code(403);
    exit('Invalid request');
}
if ($actorUserId < 1
    || !user_can($pdo, $actorUserId, 'projects.edit', 0)
    || !user_can($pdo, $actorUserId, 'workforce.assignments.manage', 0)) {
    http_response_code(403);
    exit('Permission denied');
}
require_record_ownership($pdo, 'projects', $projectId);

try {
    $action = (string)($_POST['action'] ?? '');
    $planning = new ProjectWorkPlanningService();
    $operations = new OperationsPlanningService();
    $integration = new ExternalOpsIntegrationService();
    $affectedUsers = [];
    $events = [];

    if ($action === 'add-team-member') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $entityId = $planning->addTeamMember($pdo, $projectId, $userId, $actorUserId);
        $affectedUsers[] = $userId;
        $rowStmt=$pdo->prepare('SELECT *,1 active FROM project_assignments WHERE id=?');$rowStmt->execute([$entityId]);$events[]=['project_assignment',(string)$entityId,'upsert',$rowStmt->fetch(PDO::FETCH_ASSOC)?:[]];
        audit_log($pdo, 'project.team_member.added', 'project', $projectId, ['user_id' => $userId]);
    } elseif ($action === 'end-team-member') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $planning->endTeamMember($pdo, $projectId, $userId);
        $affectedUsers[] = $userId;
        $rowStmt=$pdo->prepare('SELECT *,0 active FROM project_assignments WHERE project_id=? AND user_id=?');$rowStmt->execute([$projectId,$userId]);$row=$rowStmt->fetch(PDO::FETCH_ASSOC)?:['project_id'=>$projectId,'user_id'=>$userId];$events[]=['project_assignment',(string)($row['id']??($projectId.':'.$userId)),'revoke',$row];
        audit_log($pdo, 'project.team_member.ended', 'project', $projectId, ['user_id' => $userId]);
    } elseif ($action === 'save-operation') {
        $editingId=(int)($_POST['id']??0);$oldUsers=[];if($editingId>0){$oldStmt=$pdo->prepare('SELECT user_id FROM operation_assignments WHERE operation_id=?');$oldStmt->execute([$editingId]);$oldUsers=array_map('intval',$oldStmt->fetchAll(PDO::FETCH_COLUMN));}
        $entityId = $operations->saveOperation($pdo, $_POST, (array)($_POST['assigned_user_ids'] ?? []), $actorUserId);
        $rowStmt=$pdo->prepare('SELECT * FROM operations WHERE id=?');$rowStmt->execute([$entityId]);$events[]=['operation',(string)$entityId,'upsert',$rowStmt->fetch(PDO::FETCH_ASSOC)?:[]];
        $assignmentStmt=$pdo->prepare('SELECT * FROM operation_assignments WHERE operation_id=?');$assignmentStmt->execute([$entityId]);$current=[];foreach($assignmentStmt->fetchAll(PDO::FETCH_ASSOC) as $row){$current[]=(int)$row['user_id'];$events[]=['operation_assignment',$entityId.':'.$row['user_id'],'upsert',$row];}foreach(array_diff($oldUsers,$current) as $removedUserId)$events[]=['operation_assignment',$entityId.':'.$removedUserId,'revoke',['operation_id'=>$entityId,'user_id'=>$removedUserId]];
        foreach($current as $assignedUserId)audit_log($pdo,'project.operation_assignment.saved','operation',$entityId,['project_id'=>$projectId,'user_id'=>$assignedUserId]);foreach(array_diff($oldUsers,$current) as $removedUserId)audit_log($pdo,'project.operation_assignment.removed','operation',$entityId,['project_id'=>$projectId,'user_id'=>$removedUserId]);
        audit_log($pdo, 'project.operation.saved', 'operation', $entityId, ['project_id' => $projectId, 'status' => (string)($_POST['status'] ?? '')]);
    } elseif ($action === 'save-task') {
        $editingId=(int)($_POST['id']??0);$oldUsers=[];if($editingId>0){$oldStmt=$pdo->prepare('SELECT user_id FROM task_assignments WHERE task_id=?');$oldStmt->execute([$editingId]);$oldUsers=array_map('intval',$oldStmt->fetchAll(PDO::FETCH_COLUMN));}
        $entityId = $operations->saveTask($pdo, $_POST, $actorUserId, (array)($_POST['assigned_user_ids'] ?? []));
        $rowStmt=$pdo->prepare('SELECT * FROM tasks WHERE id=?');$rowStmt->execute([$entityId]);$events[]=['task',(string)$entityId,'upsert',$rowStmt->fetch(PDO::FETCH_ASSOC)?:[]];
        $assignmentStmt=$pdo->prepare('SELECT * FROM task_assignments WHERE task_id=?');$assignmentStmt->execute([$entityId]);$current=[];foreach($assignmentStmt->fetchAll(PDO::FETCH_ASSOC) as $row){$current[]=(int)$row['user_id'];$events[]=['task_assignment',$entityId.':'.$row['user_id'],'upsert',$row];}foreach(array_diff($oldUsers,$current) as $removedUserId)$events[]=['task_assignment',$entityId.':'.$removedUserId,'revoke',['task_id'=>$entityId,'user_id'=>$removedUserId]];
        foreach($current as $assignedUserId)audit_log($pdo,'project.task_assignment.saved','task',$entityId,['project_id'=>$projectId,'user_id'=>$assignedUserId]);foreach(array_diff($oldUsers,$current) as $removedUserId)audit_log($pdo,'project.task_assignment.removed','task',$entityId,['project_id'=>$projectId,'user_id'=>$removedUserId]);
        audit_log($pdo, 'project.task.saved', 'task', $entityId, ['project_id' => $projectId, 'status' => (string)($_POST['status'] ?? '')]);
    } else {
        throw new DomainException('Unknown project work action.');
    }

    $config = pa_external_ops_delivery_config($pdo);
    if (!empty($config['enabled'])) {
        foreach (array_unique($affectedUsers) as $userId) {
            $integration->resyncAccountAccess($pdo, (int)$userId, (string)$config['application_key'], $actorUserId);
        }
        foreach($events as [$entityType,$eventEntityId,$eventAction,$eventData]){$eventData['updated_at']=gmdate('Y-m-d\TH:i:s.u\Z');$integration->enqueueProjectionChange($pdo,(string)$config['application_key'],$entityType,$eventEntityId,$eventAction,$eventData);}
    }
    header('Location: ' . $redirect . '&saved=1#team-work');
} catch (Throwable $error) {
    error_log('[project_work] ' . $error->getMessage());
    header('Location: ' . $redirect . '&error=' . rawurlencode($error->getMessage()) . '#team-work');
}
exit;
