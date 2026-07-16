<?php

declare(strict_types=1);

use App\Services\CompensationRuleService;
use App\Services\JobWorkPlanningService;
use App\Services\PayPeriodService;
use App\Services\WorkforceAccessService;

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../utils/csrf.php';
require_once __DIR__ . '/../../utils/api_response.php';
require_once __DIR__ . '/../../utils/project_id.php';

$userId = (int)($_SESSION['user']['id'] ?? 0);
if ($userId <= 0) api_json_failure(401, 'authentication_required', 'Authentication is required.');

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$input = $_POST;
if (str_contains(strtolower((string)($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json')) {
    $decoded = json_decode((string)file_get_contents('php://input'), true);
    if (is_array($decoded)) $input = $decoded;
}
if ($method !== 'GET') {
    $headerToken = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $sessionToken = (string)($_SESSION['csrf'] ?? '');
    $csrfOk = csrf_validate() || ($headerToken !== '' && $sessionToken !== '' && hash_equals($sessionToken, $headerToken));
    if (!$csrfOk) api_json_failure(403, 'csrf_failed', 'The request could not be verified.');
}

$resource = trim((string)($_GET['resource'] ?? $input['resource'] ?? 'actor'));
$action = trim((string)($_GET['action'] ?? $input['action'] ?? 'list'));
$access = new WorkforceAccessService($pdo);
$planning = new JobWorkPlanningService($pdo, new CompensationRuleService($pdo));
$periods = new PayPeriodService($pdo);
$actor = $access->actor($userId);

$canManage = static function (string $permission) use ($pdo, $userId, $actor): bool {
    return in_array((string)($actor['role'] ?? ''), ['admin', 'owner'], true) || user_can($pdo, $userId, $permission, 0);
};

try {
    if ($method === 'GET' && $resource === 'actor') {
        api_json_success(['data' => $actor]);
    }
    if ($method === 'GET' && $resource === 'clients') {
        api_json_success(['data' => $access->clientDirectory($userId, (string)($_GET['q'] ?? ''), (int)($_GET['limit'] ?? 25))]);
    }
    if ($method === 'GET' && $resource === 'jobs') {
        $stmt=$pdo->query("SELECT j.id,j.job_code,j.client_id,j.project_id,j.status,j.created_by,c.name client_name FROM jobs j JOIN clients c ON c.id=j.client_id WHERE j.archived=0 ORDER BY j.created_at DESC,j.id DESC LIMIT 250");
        $rows=array_values(array_filter($stmt->fetchAll(PDO::FETCH_ASSOC),static function(array $job)use($access,$userId,$canManage):bool{return $canManage('jobs.view')||$access->can($userId,'jobs.view','job',(int)$job['id']);}));
        api_json_success(['data'=>$rows]);
    }
    if ($method === 'GET' && $resource === 'assignments') {
        $workerId = (int)($actor['worker_profile_id'] ?? 0);
        $manage = $canManage('workforce.assignments.manage');
        $sql = 'SELECT wa.id,wa.status,wa.estimated_pay,wa.currency,wa.offered_at,wa.decline_reason,
                       jwc.name,jwc.description,jwc.expected_duration_minutes,jwc.planned_quantity,j.id job_id,j.job_code
                FROM work_assignments wa JOIN job_work_components jwc ON jwc.id=wa.job_work_component_id
                JOIN jobs j ON j.id=jwc.job_id';
        $params = [];
        if (!$manage) {
            $sql .= ' WHERE wa.worker_profile_id=?';
            $params[] = $workerId;
        }
        $sql .= ' ORDER BY COALESCE(wa.offered_at,wa.created_at) DESC,wa.id DESC LIMIT 250';
        $stmt = $pdo->prepare($sql); $stmt->execute($params);
        api_json_success(['data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }
    if ($method === 'GET' && $resource === 'pay_periods') {
        $period = $periods->periodFor(new DateTimeImmutable((string)($_GET['date'] ?? 'now')));
        api_json_success(['data' => $period]);
    }
    if ($method === 'GET' && $resource === 'statements') {
        $workerId = (int)($actor['worker_profile_id'] ?? 0);
        $manage = $canManage('workforce.statements.manage');
        $stmt = $pdo->prepare(
            'SELECT ws.*,pp.period_start,pp.period_end,wp.display_name FROM worker_statements ws
             JOIN pay_periods pp ON pp.id=ws.pay_period_id JOIN worker_profiles wp ON wp.id=ws.worker_profile_id'
            . ($manage ? '' : ' WHERE ws.worker_profile_id=?') . ' ORDER BY pp.period_end DESC,ws.id DESC'
        );
        $stmt->execute($manage ? [] : [$workerId]);
        api_json_success(['data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($method !== 'POST') api_json_failure(405, 'method_not_allowed', 'Use POST for this operation.');

    if ($resource === 'assignment') {
        $assignmentId = (int)($input['assignment_id'] ?? 0);
        $workerId = (int)($actor['worker_profile_id'] ?? 0);
        if ($assignmentId <= 0) throw new DomainException('Choose an assignment.');
        if (in_array($action, ['accept','decline','start','complete'], true)) {
            if ($workerId <= 0) api_json_failure(403, 'worker_profile_required', 'This account is not linked to a worker profile.');
            if ($action === 'accept') $planning->accept($assignmentId, $workerId);
            if ($action === 'decline') $planning->decline($assignmentId, $workerId, (string)($input['reason'] ?? ''));
            if ($action === 'start') $planning->start($assignmentId, $workerId);
            if ($action === 'complete') $planning->complete($assignmentId, $workerId);
            api_json_success(['message' => 'Assignment updated.']);
        }
        if (!$canManage('workforce.assignments.manage')) api_json_failure(403, 'permission_denied', 'Assignment management permission is required.');
        if ($action === 'offer') {
            $preview = $planning->offer($assignmentId, (int)($input['worker_profile_id'] ?? 0), $userId, is_array($input['compensation_override'] ?? null) ? $input['compensation_override'] : null);
            api_json_success(['data' => $preview]);
        }
        if ($action === 'eligible') {
            $preview = $planning->markEligible($assignmentId, is_array($input['context'] ?? null) ? $input['context'] : [], $userId);
            api_json_success(['data' => $preview]);
        }
        if ($action === 'approve') { $planning->approvePayable($assignmentId, $userId); api_json_success(['message' => 'Compensation approved.']); }
        if ($action === 'settle') { $planning->settle($assignmentId); api_json_success(['message' => 'Assignment settled.']); }
    }

    if ($resource === 'job' && $action === 'create') {
        if (!$canManage('jobs.edit') && !$canManage('jobs.create')) api_json_failure(403,'permission_denied','Job creation permission is required.');
        $clientId=(int)($input['client_id']??0);$projectId=(int)($input['project_id']??0)?:null;
        $client=$pdo->prepare('SELECT id,organization_id FROM clients WHERE id=? AND archived=0 AND deleted_at IS NULL');$client->execute([$clientId]);$client=$client->fetch(PDO::FETCH_ASSOC);
        if(!$client)throw new DomainException('Choose an active client.');
        if($projectId){$project=$pdo->prepare('SELECT 1 FROM projects WHERE id=? AND client_id=?');$project->execute([$projectId,$clientId]);if(!$project->fetchColumn())throw new DomainException('The Project does not belong to that client.');}
        $code=project_next_code($pdo,$clientId);
        $pdo->prepare('INSERT INTO jobs (client_id,organization_id,project_id,job_code,status,notes,created_by) VALUES (?,?,?,?,"not_started",?,?)')->execute([$clientId,$client['organization_id']?:null,$projectId,$code,trim((string)($input['notes']??''))?:null,$userId]);
        api_json_success(['data'=>['id'=>(int)$pdo->lastInsertId(),'job_code'=>$code]],201);
    }

    if ($resource === 'pay_period') {
        $periodId = (int)($input['pay_period_id'] ?? 0);
        if ($action === 'submit') {
            $workerId = (int)($actor['worker_profile_id'] ?? 0);
            if ($workerId <= 0) api_json_failure(403, 'worker_profile_required', 'This account is not linked to a worker profile.');
            $periods->submit($periodId, $workerId, (string)($input['notes'] ?? ''));
            api_json_success(['message' => 'Period submitted.']);
        }
        if (!$canManage('workforce.pay_periods.manage')) api_json_failure(403, 'permission_denied', 'Pay-period management permission is required.');
        if ($action === 'close') {
            $result = $periods->close($periodId, $userId, !empty($input['force']));
            api_json_success(['data' => $result], $result['closed'] ? 200 : 409);
        }
        if ($action === 'settle_statement') {
            $periods->settleStatement((int)($input['statement_id'] ?? 0));
            api_json_success(['message' => 'Statement settled.']);
        }
    }

    if($resource==='adjustment'){
        if(!$canManage('workforce.statements.manage'))api_json_failure(403,'permission_denied','Statement management permission is required.');
        if($action==='create'){$id=$periods->recordAdjustment((int)($input['worker_profile_id']??0),(int)($input['pay_period_id']??0),(string)($input['adjustment_type']??''),(float)($input['amount']??0),(string)($input['reason']??''),!empty($input['source_assignment_id'])?(int)$input['source_assignment_id']:null,$userId);api_json_success(['data'=>['id'=>$id]],201);}
        if($action==='review'){$periods->reviewAdjustment((int)($input['adjustment_id']??0),$userId);api_json_success(['message'=>'Adjustment reviewed.']);}
    }

    api_json_failure(404, 'operation_not_found', 'The workforce operation was not found.');
} catch (DomainException $error) {
    api_json_failure(422, 'invalid_workforce_operation', $error->getMessage());
} catch (PDOException $error) {
    error_log('[WorkforceV1][' . api_request_id() . '] ' . $error->getMessage());
    api_json_failure(503, 'schema_out_of_date', 'The workforce foundation is unavailable until the latest migration is applied.');
} catch (Throwable $error) {
    error_log('[WorkforceV1][' . api_request_id() . '] ' . $error->getMessage());
    api_json_failure(500, 'internal_error', 'The workforce request could not be completed.');
}
