<?php

require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/alphaledger_time_bridge.php';

const PA_AL_SCHEMA_VERSION = '1.0';

function pa_al_uuid(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
}

function pa_al_json_response(array $body, int $status = 200): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    http_response_code($status);
    echo json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    exit;
}

function pa_al_request_json(): array
{
    $raw = (string) file_get_contents('php://input');
    if ($raw === '') {
        return [];
    }
    try {
        $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        pa_al_json_response(['error' => 'invalid_json'], 400);
    }
    if (!is_array($decoded)) {
        pa_al_json_response(['error' => 'json_object_required'], 400);
    }
    return $decoded;
}

function pa_al_business_id(PDO $pdo): string
{
    $id = $pdo->query('SELECT business_id FROM pa_integration_identity WHERE singleton=1')->fetchColumn();
    if (!is_string($id) || $id === '') {
        throw new RuntimeException('Project Alpha integration identity is not initialized. Run migrations.');
    }
    return $id;
}

function pa_al_policy(PDO $pdo): array
{
    $row = $pdo->query('SELECT * FROM alphaledger_policy WHERE singleton=1')->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException('AlphaLedger integration policy is not initialized. Run migrations.');
    }
    return $row;
}

function pa_al_policy_enabled(PDO $pdo): bool
{
    try {
        return !empty(pa_al_policy($pdo)['enabled']);
    } catch (Throwable $e) {
        return false;
    }
}

function pa_al_block_local_time_mutation_when_enabled(PDO $pdo): void
{
    if (pa_al_policy_enabled($pdo)) {
        header('Location: /?page=time-tracking&error=' . rawurlencode('AlphaLedger owns time entries while synchronization is enabled. Make the change in AlphaLedger.'));
        exit;
    }
}

function pa_al_validate_callback_url(string $url): string
{
    $url = trim($url);
    $parts = filter_var($url, FILTER_VALIDATE_URL) ? parse_url($url) : false;
    if (!$parts || empty($parts['scheme']) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
        throw new DomainException('callback_url must be an absolute URL without credentials or a fragment.');
    }
    $scheme = strtolower((string) $parts['scheme']);
    $allowHttp = filter_var(getenv('ALPHALEDGER_ALLOW_HTTP_CALLBACKS') ?: 'false', FILTER_VALIDATE_BOOLEAN);
    if ($scheme !== 'https' && !($allowHttp && $scheme === 'http')) {
        throw new DomainException('callback_url must use HTTPS.');
    }
    if (!str_ends_with(rtrim((string) ($parts['path'] ?? ''), '/'), '/api/v1/integrations/pa/events')) {
        throw new DomainException('callback_url must end with /api/v1/integrations/pa/events.');
    }
    $allowed = array_values(array_filter(array_map('strtolower', array_map('trim', explode(',', (string) (getenv('ALPHALEDGER_CALLBACK_HOSTS') ?: ''))))));
    if ($allowed && !in_array(strtolower((string) $parts['host']), $allowed, true)) {
        throw new DomainException('callback_url host is not in ALPHALEDGER_CALLBACK_HOSTS.');
    }
    return $url;
}

function pa_al_webhook_signature(string $timestamp, string $rawBody, string $secret): string
{
    return hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
}

function pa_al_installation_for_api_key(PDO $pdo, int $apiKeyId): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM alphaledger_installations WHERE api_key_id=? AND status<>'disabled' ORDER BY id DESC LIMIT 1");
    $stmt->execute([$apiKeyId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function pa_al_assert_envelope(array $event, array $allowedTypes, string $installationId): void
{
    foreach (['schema_version', 'event_id', 'event_type', 'occurred_at', 'installation_id', 'aggregate_id', 'revision', 'data'] as $field) {
        if (!array_key_exists($field, $event)) {
            throw new DomainException('Missing event field: ' . $field);
        }
    }
    if ($event['schema_version'] !== PA_AL_SCHEMA_VERSION || $event['installation_id'] !== $installationId) {
        throw new DomainException('Unsupported schema version or installation.');
    }
    if (!in_array($event['event_type'], $allowedTypes, true)) {
        throw new DomainException('Unsupported event type.');
    }
    if (!is_string($event['event_id']) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $event['event_id'])) {
        throw new DomainException('event_id must be a UUID.');
    }
    if ((int) $event['revision'] < 1 || !is_array($event['data'])) {
        throw new DomainException('Invalid revision or data.');
    }
    if (strtotime((string) $event['occurred_at']) === false) {
        throw new DomainException('occurred_at must be an ISO-8601 timestamp.');
    }
    if (isset($event['currency']) && $event['currency'] !== null && !preg_match('/^[A-Z]{3}$/', (string) $event['currency'])) {
        throw new DomainException('currency must be an uppercase ISO 4217 code.');
    }
}

function pa_al_emit_event(PDO $pdo, array $installation, string $eventType, string $aggregateId, int $revision, array $data, ?string $currency = null): array
{
    $eventId = pa_al_uuid();
    $envelope = [
        'schema_version' => PA_AL_SCHEMA_VERSION,
        'event_id' => $eventId,
        'event_type' => $eventType,
        'occurred_at' => gmdate('Y-m-d\TH:i:s\Z'),
        'installation_id' => (string) $installation['installation_id'],
        'aggregate_id' => $aggregateId,
        'revision' => $revision,
        'currency' => $currency,
        'data' => $data,
    ];
    $stmt = $pdo->prepare('INSERT INTO alphaledger_events (installation_id,event_id,event_type,aggregate_id,revision,envelope,next_attempt_at,occurred_at) VALUES (?,?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())');
    $stmt->execute([(int) $installation['id'], $eventId, $eventType, $aggregateId, $revision, json_encode($envelope, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)]);
    return $envelope;
}

function pa_al_sync_object(PDO $pdo, array $installation, string $type, string $id, bool $present, string $upsertEvent, string $removeEvent, array $data, ?string $currency = null): void
{
    $canonical = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    $hash = hash('sha256', ($present ? '1:' : '0:') . $canonical);
    $stmt = $pdo->prepare('SELECT id,revision,payload_hash,is_present FROM alphaledger_object_state WHERE installation_id=? AND object_type=? AND object_id=? FOR UPDATE');
    $stmt->execute([(int) $installation['id'], $type, $id]);
    $state = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($state && hash_equals((string) $state['payload_hash'], $hash) && (bool) $state['is_present'] === $present) {
        return;
    }
    $revision = $state ? ((int) $state['revision'] + 1) : 1;
    if ($state) {
        $pdo->prepare('UPDATE alphaledger_object_state SET revision=?,payload_hash=?,is_present=? WHERE id=?')->execute([$revision, $hash, $present ? 1 : 0, (int) $state['id']]);
    } else {
        $pdo->prepare('INSERT INTO alphaledger_object_state (installation_id,object_type,object_id,revision,payload_hash,is_present) VALUES (?,?,?,?,?,?)')->execute([(int) $installation['id'], $type, $id, $revision, $hash, $present ? 1 : 0]);
    }
    $data['version'] = $revision;
    pa_al_emit_event($pdo, $installation, $present ? $upsertEvent : $removeEvent, $id, $revision, $data, $currency);
}

function pa_al_capture_owned_state(PDO $pdo, array $installation): void
{
    $policy = pa_al_policy($pdo);
    $keyStmt = $pdo->prepare('SELECT scopes,allowed_ips,revoked_at FROM api_keys WHERE id=? LIMIT 1');
    $keyStmt->execute([(int)($installation['api_key_id'] ?? 0)]);
    $integrationKey = $keyStmt->fetch(PDO::FETCH_ASSOC);
    if (empty($policy['enabled'])
        || (int)($policy['approved_api_key_id'] ?? 0) !== (int)($installation['api_key_id'] ?? 0)
        || empty($policy['approved_callback_hash'])
        || !hash_equals((string)$policy['approved_callback_hash'], (string)($installation['callback_hash'] ?? ''))
        || !$integrationKey
        || $integrationKey['revoked_at'] !== null
        || trim((string)$integrationKey['scopes']) !== 'alphaledger.sync'
        || (trim((string)($integrationKey['allowed_ips'] ?? '')) === '' && empty($policy['allow_unrestricted_key']))) {
        return;
    }
    $lockName = 'pa_al_capture_' . (int) $installation['id'];
    $lockStmt = $pdo->prepare('SELECT GET_LOCK(?,5)');
    $lockStmt->execute([$lockName]);
    if ((int) $lockStmt->fetchColumn() !== 1) {
        return;
    }
    $pdo->beginTransaction();
    try {
        $seenPeople = [];
        $users = $pdo->query("SELECT id,email,display_name AS name,is_active FROM team_members")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($users as $user) {
            $id = (string) $user['id'];
            $present = !empty($user['is_active']);
            $data = ['person_id' => $id, 'team_member_id'=>$id, 'name' => (string) $user['name'], 'email' => (string)($user['email']??'')];
            pa_al_sync_object($pdo, $installation, 'person', $id, $present, 'person.upserted', 'person.deactivated', $data);
            $seenPeople[$id] = true;
        }

        $currency = 'USD';
        $seenProjects = [];
        $projects = $pdo->query("SELECT p.id,p.name,p.description,p.status,p.client_id,c.name AS client_name FROM projects p LEFT JOIN clients c ON c.id=p.client_id")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($projects as $project) {
            $id = (string) $project['id'];
            $present = !in_array((string) $project['status'], ['completed', 'cancelled'], true);
            $data = [
                'project_id' => $id,
                'client_id' => $project['client_id'] !== null ? (string) $project['client_id'] : null,
                'name' => (string) $project['name'],
                'description' => (string) ($project['description'] ?? ''),
                'client_name' => (string) ($project['client_name'] ?? ''),
                'billable_default' => true,
                'billing_rate' => null,
                'currency' => $currency,
            ];
            pa_al_sync_object($pdo, $installation, 'project', $id, $present, 'project.upserted', 'project.archived', $data, $currency);
            $seenProjects[$id] = true;
        }

        $seenAssignments = [];
        $assignments = $pdo->query("SELECT a.project_id,tm.id user_id FROM alphaledger_project_assignments a JOIN projects p ON p.id=a.project_id JOIN users u ON u.id=a.user_id JOIN team_members tm ON tm.user_id=u.id WHERE p.status NOT IN ('completed','cancelled') AND u.is_disabled=0 AND u.deleted_at IS NULL AND tm.is_active=1")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($assignments as $assignment) {
            $id = (string) $assignment['project_id'] . ':' . (string) $assignment['user_id'];
            $data = ['project_id' => (string) $assignment['project_id'], 'person_id' => (string) $assignment['user_id']];
            pa_al_sync_object($pdo, $installation, 'assignment', $id, true, 'assignment.upserted', 'assignment.revoked', $data);
            $seenAssignments[$id] = true;
        }

        $stateStmt = $pdo->prepare("SELECT object_type,object_id FROM alphaledger_object_state WHERE installation_id=? AND is_present=1 AND object_type IN ('person','project','assignment')");
        $stateStmt->execute([(int) $installation['id']]);
        foreach ($stateStmt->fetchAll(PDO::FETCH_ASSOC) as $state) {
            $type = (string) $state['object_type'];
            $id = (string) $state['object_id'];
            $seen = $type === 'person' ? $seenPeople : ($type === 'project' ? $seenProjects : $seenAssignments);
            if (isset($seen[$id])) {
                continue;
            }
            if ($type === 'assignment') {
                [$projectId, $personId] = array_pad(explode(':', $id, 2), 2, '');
                pa_al_sync_object($pdo, $installation, $type, $id, false, 'assignment.upserted', 'assignment.revoked', ['project_id' => $projectId, 'person_id' => $personId]);
            } elseif ($type === 'person') {
                pa_al_sync_object($pdo, $installation, $type, $id, false, 'person.upserted', 'person.deactivated', ['person_id' => $id, 'name' => '', 'email' => '']);
            } elseif ($type === 'project') {
                pa_al_sync_object($pdo, $installation, $type, $id, false, 'project.upserted', 'project.archived', ['project_id' => $id, 'client_id' => null, 'name' => 'Archived PA project', 'description' => '', 'client_name' => '', 'billable_default' => true, 'billing_rate' => null, 'currency' => $currency], $currency);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $release->execute([$lockName]);
        throw $e;
    }
    $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
    $release->execute([$lockName]);
}

function pa_al_record_conflict(PDO $pdo, array $installation, string $type, string $id, string $localRevision, string $remoteRevision, string $reason, array $details): void
{
    $stmt = $pdo->prepare("INSERT INTO alphaledger_sync_conflicts (installation_id,object_type,object_id,local_revision,remote_revision,reason,details,status) VALUES (?,?,?,?,?,?,?,'open')");
    $stmt->execute([(int) $installation['id'], $type, $id, $localRevision, $remoteRevision, $reason, json_encode($details, JSON_THROW_ON_ERROR)]);
}

function pa_al_refresh_assignments(PDO $pdo, array $installation): void
{
    if (!pa_al_policy_enabled($pdo)) {
        return;
    }
    $recent = $pdo->prepare("SELECT 1 FROM alphaledger_events WHERE installation_id=? AND event_type='assignment.upserted' AND created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 5 MINUTE) LIMIT 1");
    $recent->execute([(int) $installation['id']]);
    if ($recent->fetchColumn()) {
        return;
    }
    $stmt = $pdo->prepare("SELECT object_id,revision FROM alphaledger_object_state WHERE installation_id=? AND object_type='assignment' AND is_present=1 ORDER BY object_id");
    $stmt->execute([(int) $installation['id']]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $state) {
        [$projectId, $personId] = array_pad(explode(':', (string) $state['object_id'], 2), 2, '');
        pa_al_emit_event($pdo, $installation, 'assignment.upserted', (string) $state['object_id'], (int) $state['revision'], ['project_id' => $projectId, 'person_id' => $personId]);
    }
}

function pa_al_ingest_time_event(PDO $pdo, array $installation, array $event): array
{
    pa_al_assert_envelope($event, ['time_entry.approved', 'time_entry.corrected', 'time_entry.voided'], (string) $installation['installation_id']);
    $data = $event['data'];
    $externalId = (string) ($data['id'] ?? $event['aggregate_id']);
    $revision = (int) $event['revision'];
    if ($externalId === '' || $externalId !== (string) $event['aggregate_id'] || (isset($data['revision']) && (int) $data['revision'] !== $revision)) {
        throw new DomainException('Time entry aggregate identity or revision does not match its envelope.');
    }
    $alBusinessId=(string)($installation['al_business_id']??'');
    $alEmployeeId=(string)($data['employee_id']??'');
    $alProjectId=(string)($data['project_id']??'');
    if($alBusinessId===''||$alEmployeeId==='') throw new DomainException('AlphaLedger business and employee identities are required.');
    $member=pa_al_time_resolve_employee($pdo,$installation,$alEmployeeId);
    if(!$member){
        pa_al_time_record_exception($pdo,$installation,'unmapped_employee','time_entry',$externalId,'Time entry references an unmapped AlphaLedger employee.',['al_employee_id'=>$alEmployeeId,'event'=>$event]);
        return ['event_id'=>$event['event_id'],'status'=>'accepted','exception'=>'unmapped_employee'];
    }
    $project=null;
    if($alProjectId!==''){
        $project=pa_al_time_resolve_project($pdo,$installation,$alProjectId);
        if(!$project){
            pa_al_time_record_exception($pdo,$installation,'unmapped_project','time_entry',$externalId,'Time entry references an unmapped AlphaLedger project.',['al_project_id'=>$alProjectId,'event'=>$event]);
            return ['event_id'=>$event['event_id'],'status'=>'accepted','exception'=>'unmapped_project'];
        }
    }
    $existingStmt = $pdo->prepare("SELECT * FROM time_entries WHERE source_system='alphaledger' AND al_business_id=? AND source_entry_id=? FOR UPDATE");
    $existingStmt->execute([$alBusinessId,$externalId]);
    $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);
    if ($existing && (int) $existing['external_revision'] >= $revision) {
        return ['event_id' => $event['event_id'], 'status' => 'duplicate', 'remote_id' => (string) $existing['id']];
    }
    $duration = max(0, (int) ($data['duration_seconds'] ?? 0));
    if (!isset($data['duration_seconds']) || !is_numeric($data['duration_seconds']) || (int) $data['duration_seconds'] < 0) {
        throw new DomainException('duration_seconds must be a non-negative integer.');
    }
    if (!empty($data['start_time']) && strtotime((string) $data['start_time']) === false) {
        throw new DomainException('start_time must be an ISO-8601 timestamp.');
    }
    if (!empty($data['end_time']) && strtotime((string) $data['end_time']) === false) {
        throw new DomainException('end_time must be an ISO-8601 timestamp.');
    }
    $hours = number_format($duration / 3600, 2, '.', '');
    $status = str_ends_with((string) $event['event_type'], '.voided') ? 'voided' : 'approved';
    if ($existing && !empty($existing['billed'])) {
        pa_al_record_conflict($pdo, $installation, 'time_entry', $externalId, (string) $existing['external_revision'], (string) $revision, 'AL changed a time entry already attached to an invoice.', $event);
        $pdo->prepare('UPDATE time_entries SET external_revision=?,external_status=?,source_updated_at=?,updated_at=NOW() WHERE id=?')->execute([$revision,$status,gmdate('Y-m-d H:i:s',strtotime((string)$event['occurred_at'])),(int)$existing['id']]);
        return ['event_id' => $event['event_id'], 'status' => 'accepted', 'remote_id' => (string) $existing['id'], 'conflict' => true];
    }
    $started = !empty($data['start_time']) ? gmdate('Y-m-d H:i:s', strtotime((string) $data['start_time'])) : null;
    $ended = !empty($data['end_time']) ? gmdate('Y-m-d H:i:s', strtotime((string) $data['end_time'])) : null;
    $billable = $status !== 'voided' && !empty($data['billable']);
    $alCost=(isset($data['cost_rate_snapshot'])&&is_numeric($data['cost_rate_snapshot']))?(float)$data['cost_rate_snapshot']:((isset($data['pay_rate_snapshot'])&&is_numeric($data['pay_rate_snapshot']))?(float)$data['pay_rate_snapshot']:null);
    $workDate=substr((string)($started?:gmdate('Y-m-d')),0,10);
    $rates=pa_al_time_resolve_rates($pdo,(int)$member['id'],$project,$workDate,$alCost,!empty($data['service_item_id'])?(int)$data['service_item_id']:null);
    if($billable&&$rates['billing_rate']===null){
        pa_al_time_record_exception($pdo,$installation,'missing_rate','time_entry',$externalId,'Approved billable time has no effective PA billing rate.',['team_member_id'=>(int)$member['id'],'project_id'=>$project['id']??null,'work_date'=>$workDate]);
    }
    $userId=!empty($member['user_id'])?(int)$member['user_id']:null;
    $sourceUpdated=gmdate('Y-m-d H:i:s',strtotime((string)$event['occurred_at']));
    $params = [
        (int) ($project['organization_id'] ?? $member['organization_id'] ?? $installation['organization_id'] ?? 0) ?: null,
        $userId,(int)$member['id'],$project['client_id']??null,$project['id']??null,
        (string) ($data['description'] ?? ''),
        $started,$ended,$hours,$billable?1:0,$status==='voided'?1:0,
        (string)($rates['billing_rate']??'0'),$rates['cost_rate'],$rates['billing_rate'],(string)$rates['currency'],(string)$rates['source'],
        $revision,$status,$alBusinessId,$externalId,$sourceUpdated,
    ];
    if ($existing) {
        $pdo->prepare('UPDATE time_entries SET organization_id=?,user_id=?,team_member_id=?,client_id=?,project_id=?,description=?,started_at=?,ended_at=?,hours=?,billable=?,billed=?,rate=?,cost_rate_snapshot=?,billing_rate_snapshot=?,currency=?,rate_snapshot_source=?,external_revision=?,external_status=?,al_business_id=?,source_entry_id=?,source_updated_at=?,imported_at=COALESCE(imported_at,UTC_TIMESTAMP()),updated_at=NOW() WHERE id=?')->execute(array_merge($params,[(int)$existing['id']]));
        $remoteId = (string) $existing['id'];
    } else {
        $pdo->prepare("INSERT INTO time_entries (source_system,external_id,organization_id,user_id,team_member_id,client_id,project_id,description,started_at,ended_at,hours,billable,billed,rate,cost_rate_snapshot,billing_rate_snapshot,currency,rate_snapshot_source,external_revision,external_status,al_business_id,source_entry_id,source_updated_at,imported_at) VALUES ('alphaledger',?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())")->execute(array_merge([$externalId],$params));
        $remoteId = (string) $pdo->lastInsertId();
    }
    return ['event_id'=>$event['event_id'],'status'=>'accepted','remote_id'=>$remoteId,'billing_ready'=>$billable&&$rates['billing_rate']!==null];
}

function pa_al_ingest_pay_event(PDO $pdo, array $installation, array $event): array
{
    pa_al_assert_envelope($event, ['pay_accrual.approved', 'pay_accrual.corrected', 'pay_accrual.voided'], (string) $installation['installation_id']);
    $data = $event['data'];
    $externalId = (string) ($data['id'] ?? $event['aggregate_id']);
    $revision = (int) $event['revision'];
    if ($externalId === '' || $externalId !== (string) $event['aggregate_id'] || (isset($data['entry_revision']) && (int) $data['entry_revision'] !== $revision)) {
        throw new DomainException('Pay accrual aggregate identity or revision does not match its envelope.');
    }
    foreach (['hours', 'rate', 'amount'] as $moneyField) {
        if (!isset($data[$moneyField]) || !is_numeric($data[$moneyField]) || (float) $data[$moneyField] < 0) {
            throw new DomainException($moneyField . ' must be non-negative.');
        }
    }
    $currency = (string) ($data['currency'] ?? $event['currency'] ?? '');
    if (!preg_match('/^[A-Z]{3}$/', $currency)) {
        throw new DomainException('currency must be an uppercase ISO 4217 code.');
    }
    $alEmployeeId=(string)($data['employee_id']??'');
    $member=pa_al_time_resolve_employee($pdo,$installation,$alEmployeeId);
    if(!$member){
        pa_al_time_record_exception($pdo,$installation,'unmapped_employee','pay_accrual',$externalId,'Pay accrual references an unmapped AlphaLedger employee.',['al_employee_id'=>$alEmployeeId,'event'=>$event]);
        return ['event_id'=>$event['event_id'],'status'=>'accepted','exception'=>'unmapped_employee'];
    }
    $personId=!empty($member['user_id'])?(int)$member['user_id']:null;
    $stmt = $pdo->prepare('SELECT * FROM employee_pay_records WHERE installation_id=? AND external_id=? FOR UPDATE');
    $stmt->execute([(int) $installation['id'], $externalId]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($existing && (int) $existing['external_revision'] >= $revision) {
        return ['event_id' => $event['event_id'], 'status' => 'duplicate', 'remote_id' => (string) $existing['id']];
    }
    $newStatus = str_ends_with((string) $event['event_type'], '.voided') ? 'voided' : 'pending';
    if ($existing && $existing['status'] === 'paid') {
        pa_al_record_conflict($pdo, $installation, 'pay_accrual', $externalId, (string) $existing['external_revision'], (string) $revision, 'AL changed an employee pay record already marked paid in PA.', $event);
        $pdo->prepare('UPDATE employee_pay_records SET external_revision=? WHERE id=?')->execute([$revision, (int) $existing['id']]);
        return ['event_id' => $event['event_id'], 'status' => 'accepted', 'remote_id' => (string) $existing['id'], 'conflict' => true];
    }
    $values = [
        (int) ($installation['organization_id'] ?? 0) ?: null,
        (string) ($data['entry_id'] ?? ''),
        $revision,
        $personId,
        (int)$member['id'],
        (string) ($data['hours'] ?? '0'),
        (string) ($data['rate'] ?? '0'),
        (string) ($data['amount'] ?? '0'),
        $currency,
        $newStatus,
    ];
    if ($existing) {
        $pdo->prepare('UPDATE employee_pay_records SET organization_id=?,external_time_entry_id=?,external_revision=?,user_id=?,team_member_id=?,hours=?,rate=?,amount=?,currency=?,status=?,updated_at=NOW() WHERE id=?')->execute(array_merge($values, [(int) $existing['id']]));
        $remoteId = (string) $existing['id'];
    } else {
        $pdo->prepare('INSERT INTO employee_pay_records (organization_id,installation_id,external_id,external_time_entry_id,external_revision,user_id,team_member_id,hours,rate,amount,currency,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')->execute(array_merge([array_shift($values), (int) $installation['id'], $externalId], $values));
        $remoteId = (string) $pdo->lastInsertId();
    }
    return ['event_id' => $event['event_id'], 'status' => 'accepted', 'remote_id' => $remoteId];
}

function pa_al_deliver_pending(PDO $pdo, int $limit = 50): array
{
    if (!pa_al_policy_enabled($pdo)) {
        return ['delivered' => 0, 'failed' => 0];
    }
    $stmt = $pdo->prepare("SELECT e.*,i.callback_url,i.callback_hash,i.webhook_secret_enc FROM alphaledger_events e JOIN alphaledger_installations i ON i.id=e.installation_id JOIN alphaledger_policy p ON p.singleton=1 AND p.enabled=1 AND p.approved_api_key_id=i.api_key_id AND p.approved_callback_hash=i.callback_hash JOIN api_keys k ON k.id=i.api_key_id AND k.revoked_at IS NULL AND k.scopes='alphaledger.sync' AND (COALESCE(k.allowed_ips,'')<>'' OR p.allow_unrestricted_key=1) WHERE e.delivery_state IN ('pending','attention') AND e.next_attempt_at<=UTC_TIMESTAMP() AND i.status IN ('active','degraded') ORDER BY e.sequence_id LIMIT ?");
    $stmt->bindValue(1, max(1, min(200, $limit)), PDO::PARAM_INT);
    $stmt->execute();
    $sent = 0;
    $failed = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $error = '';
        try {
            pa_al_validate_callback_url((string)$row['callback_url']);
            $secret = crypto_decrypt((string) $row['webhook_secret_enc']);
        } catch (Throwable $e) {
            $secret = null;
            $error = $e->getMessage();
        }
        if (!$secret) {
            $error = $error !== '' ? $error : 'Webhook secret cannot be decrypted.';
            $code = 0;
        } else {
            $raw = (string) $row['envelope'];
            $envelope = json_decode($raw, true);
            $timestamp = gmdate('Y-m-d\TH:i:s\Z');
            $signature = pa_al_webhook_signature($timestamp, $raw, $secret);
            $ch = curl_init((string) $row['callback_url']);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $raw,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'X-PA-Event-ID: ' . (string) ($envelope['event_id'] ?? $row['event_id']),
                    'X-PA-Timestamp: ' . $timestamp,
                    'X-PA-Signature: ' . $signature,
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | (filter_var(getenv('ALPHALEDGER_ALLOW_HTTP_CALLBACKS') ?: 'false', FILTER_VALIDATE_BOOLEAN) ? CURLPROTO_HTTP : 0),
            ]);
            $response = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_errno($ch) ? curl_error($ch) : (($code >= 200 && $code < 300) ? '' : 'HTTP ' . $code . ': ' . substr((string) $response, 0, 1000));
            curl_close($ch);
        }
        if ($error === '') {
            $pdo->prepare("UPDATE alphaledger_events SET delivery_state='delivered',delivered_at=UTC_TIMESTAMP(),last_error=NULL WHERE sequence_id=?")->execute([(int) $row['sequence_id']]);
            $pdo->prepare("UPDATE alphaledger_installations SET status='active',consecutive_failures=0,last_success_at=UTC_TIMESTAMP() WHERE id=?")->execute([(int) $row['installation_id']]);
            $sent++;
        } else {
            $attempts = (int) $row['delivery_attempts'] + 1;
            $delay = min(3600, 30 * (2 ** min(7, max(0, $attempts - 1)))) + random_int(0, 15);
            $state = $attempts >= 10 ? 'attention' : 'pending';
            $pdo->prepare('UPDATE alphaledger_events SET delivery_state=?,delivery_attempts=?,next_attempt_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? SECOND),last_error=? WHERE sequence_id=?')->execute([$state, $attempts, $delay, substr($error, 0, 4000), (int) $row['sequence_id']]);
            $pdo->prepare("UPDATE alphaledger_installations SET consecutive_failures=consecutive_failures+1,status=IF(consecutive_failures+1>=5,'degraded',status) WHERE id=?")->execute([(int) $row['installation_id']]);
            $failed++;
        }
    }
    return ['delivered' => $sent, 'failed' => $failed];
}
