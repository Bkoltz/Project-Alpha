<?php
http_response_code(410);
exit('Retired integration code. Use the built-in Workforce modules.');

require_once __DIR__ . '/alphaledger_integration.php';

const PA_AL_LEDGER_CAPABILITY = 'operational_ledger_v1';
const PA_AL_LEDGER_PATH = '/api/v1/integrations/alphaledger/ledger-records/batch';

function pa_al_ledger_signature(string $timestamp, string $method, string $path, string $rawBody, string $secret): string
{
    $canonical = $timestamp . "\n" . strtoupper($method) . "\n" . $path . "\n" . hash('sha256', $rawBody);
    return hash_hmac('sha256', $canonical, $secret);
}

function pa_al_ledger_verify_signature(array $installation, string $timestamp, string $signature, string $rawBody): bool
{
    $when = strtotime($timestamp);
    if ($when === false || abs(time() - $when) > 300 || !preg_match('/^[a-f0-9]{64}$/i', $signature)) {
        return false;
    }
    $secret = crypto_decrypt((string)($installation['webhook_secret_enc'] ?? ''));
    if (!is_string($secret) || $secret === '') {
        return false;
    }
    return hash_equals(pa_al_ledger_signature($timestamp, 'POST', PA_AL_LEDGER_PATH, $rawBody, $secret), strtolower($signature));
}

function pa_al_ledger_assert_keys(array $value, array $allowed, array $required, string $context): void
{
    $unknown = array_diff(array_keys($value), $allowed);
    if ($unknown) {
        throw new DomainException($context . ' contains unknown field: ' . reset($unknown));
    }
    foreach ($required as $field) {
        if (!array_key_exists($field, $value)) {
            throw new DomainException($context . ' is missing field: ' . $field);
        }
    }
}

function pa_al_ledger_uuid($value, string $field, bool $nullable = false): ?string
{
    if ($nullable && ($value === null || $value === '')) {
        return null;
    }
    if (!is_string($value) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value)) {
        throw new DomainException($field . ' must be a UUID.');
    }
    return strtolower($value);
}

function pa_al_ledger_datetime($value, string $field, bool $nullable = false): ?string
{
    if ($nullable && ($value === null || $value === '')) {
        return null;
    }
    if (!is_string($value) || ($timestamp = strtotime($value)) === false) {
        throw new DomainException($field . ' must be an ISO-8601 timestamp.');
    }
    return gmdate('Y-m-d H:i:s', $timestamp);
}

function pa_al_ledger_bool($value, string $field): int
{
    if (!is_bool($value) && $value !== 0 && $value !== 1) {
        throw new DomainException($field . ' must be boolean.');
    }
    return $value ? 1 : 0;
}

function pa_al_ledger_decimal($value, string $field, bool $nullable = false): ?string
{
    if ($nullable && ($value === null || $value === '')) {
        return null;
    }
    if ((!is_string($value) && !is_int($value)) || !preg_match('/^-?\d{1,12}(?:\.\d{1,4})?$/', (string)$value)) {
        throw new DomainException($field . ' must be a fixed-precision decimal.');
    }
    return (string)$value;
}

function pa_al_ledger_validate_record(array $record, string $installationId): array
{
    $recordFields = ['event_id','entity_type','entity_id','revision','occurred_at','operation','data'];
    pa_al_ledger_assert_keys($record, $recordFields, $recordFields, 'record');
    $record['event_id'] = pa_al_ledger_uuid($record['event_id'], 'event_id');
    $record['entity_id'] = is_string($record['entity_id']) && strlen($record['entity_id']) <= 128 ? $record['entity_id'] : '';
    if ($record['entity_id'] === '') throw new DomainException('entity_id is required.');
    $types = ['employee','project','assignment','time_entry','break','revision','pay_accrual'];
    if (!in_array($record['entity_type'], $types, true)) throw new DomainException('Unsupported entity_type.');
    if (!is_int($record['revision']) || $record['revision'] < 1) throw new DomainException('revision must be a positive integer.');
    $record['occurred_sql'] = pa_al_ledger_datetime($record['occurred_at'], 'occurred_at');
    if (!in_array($record['operation'], ['upsert','tombstone'], true)) throw new DomainException('Unsupported operation.');
    if (!is_array($record['data'])) throw new DomainException('data must be an object.');
    if ($record['operation'] === 'tombstone') {
        if ($record['data'] !== []) throw new DomainException('A tombstone data object must be empty.');
        return $record;
    }

    $schemas = [
        'employee' => [
            ['email','display_name','role','pa_person_id','is_active'],
            ['email','display_name','role','pa_person_id','is_active'],
        ],
        'project' => [
            ['pa_project_id','name','origin','is_archived'],
            ['pa_project_id','name','origin','is_archived'],
        ],
        'assignment' => [
            ['project_id','employee_id','is_active'],
            ['project_id','employee_id','is_active'],
        ],
        'time_entry' => [
            ['employee_id','project_id','start_time','end_time','duration_seconds','description','tags','billable','is_payable','billing_rate_snapshot','pay_rate_snapshot','pay_amount_snapshot','currency','status','reviewed_by','reviewed_at','rejection_reason'],
            ['employee_id','project_id','start_time','end_time','duration_seconds','description','tags','billable','is_payable','billing_rate_snapshot','pay_rate_snapshot','pay_amount_snapshot','currency','status','reviewed_by','reviewed_at','rejection_reason'],
        ],
        'break' => [
            ['time_entry_id','start_time','end_time','duration_seconds'],
            ['time_entry_id','start_time','end_time','duration_seconds'],
        ],
        'revision' => [
            ['time_entry_id','entry_revision','reason','created_by','snapshot'],
            ['time_entry_id','entry_revision','reason','created_by','snapshot'],
        ],
        'pay_accrual' => [
            ['time_entry_id','entry_revision','employee_id','employee_name','hours','rate','amount','currency','status','paid_at','accrued_at'],
            ['time_entry_id','entry_revision','employee_id','employee_name','hours','rate','amount','currency','status','paid_at','accrued_at'],
        ],
    ];
    pa_al_ledger_assert_keys($record['data'], $schemas[$record['entity_type']][0], $schemas[$record['entity_type']][1], $record['entity_type'] . '.data');
    return $record;
}

function pa_al_ledger_existing(PDO $pdo, string $table, int $installationId, string $externalId): ?array
{
    $revisionColumn = $table === 'employee_pay_records' ? 'external_revision' : 'revision';
    $stmt = $pdo->prepare("SELECT {$revisionColumn} AS revision,payload_hash FROM {$table} WHERE installation_id=? AND external_id=? FOR UPDATE");
    $stmt->execute([$installationId, $externalId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function pa_al_ledger_check_revision(PDO $pdo, array $installation, string $table, array $record, string $hash): string
{
    $existing = pa_al_ledger_existing($pdo, $table, (int)$installation['id'], (string)$record['entity_id']);
    if (!$existing) return 'apply';
    if ((int)$existing['revision'] > (int)$record['revision']) return 'stale';
    if ((int)$existing['revision'] === (int)$record['revision']) {
        if ((string)$existing['payload_hash'] === '') return 'apply';
        if (hash_equals((string)$existing['payload_hash'], $hash)) return 'duplicate';
        $pdo->prepare('INSERT INTO alphaledger_sync_conflicts (installation_id,object_type,object_id,local_revision,remote_revision,reason,details) VALUES (?,?,?,?,?,?,?)')->execute([
            (int)$installation['id'], (string)$record['entity_type'], (string)$record['entity_id'],
            (string)$existing['revision'], (string)$record['revision'], 'same_revision_different_payload',
            json_encode(['event_id' => $record['event_id']], JSON_THROW_ON_ERROR),
        ]);
        throw new DomainException('A different payload already exists at this revision.');
    }
    return 'apply';
}

function pa_al_ledger_apply_record(PDO $pdo, array $installation, array $record, ?string $snapshotId): array
{
    $tables = [
        'employee'=>'alphaledger_ledger_people', 'project'=>'alphaledger_ledger_projects',
        'assignment'=>'alphaledger_ledger_assignments', 'time_entry'=>'alphaledger_ledger_time_entries',
        'break'=>'alphaledger_ledger_breaks', 'revision'=>'alphaledger_ledger_revisions',
        'pay_accrual'=>'employee_pay_records',
    ];
    $table = $tables[$record['entity_type']];
    $hash = hash('sha256', $record['operation'] . ':' . json_encode($record['data'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    $decision = pa_al_ledger_check_revision($pdo, $installation, $table, $record, $hash);
    if ($decision !== 'apply') return ['status'=>$decision === 'stale' ? 'stale_ignored' : 'duplicate'];
    $installationDbId = (int)$installation['id'];
    $organizationId = $installation['organization_id'] !== null ? (int)$installation['organization_id'] : null;
    $externalId = (string)$record['entity_id'];
    $revision = (int)$record['revision'];
    if ($record['operation'] === 'tombstone') {
        $snapshotColumn = $record['entity_type'] === 'pay_accrual' ? 'ledger_snapshot_id' : 'snapshot_id';
        $revisionColumn = $record['entity_type'] === 'pay_accrual' ? 'external_revision' : 'revision';
        $stmt = $pdo->prepare("UPDATE {$table} SET {$revisionColumn}=?,payload_hash=?,{$snapshotColumn}=?,deleted_at=UTC_TIMESTAMP() WHERE installation_id=? AND external_id=?");
        $stmt->execute([$revision,$hash,$snapshotId,$installationDbId,$externalId]);
        return ['status'=>'accepted'];
    }
    $d = $record['data'];
    $raw = json_encode($d, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $occurred = $record['occurred_sql'];
    if ($record['entity_type'] === 'employee') {
        if (!filter_var($d['email'], FILTER_VALIDATE_EMAIL) || mb_strlen((string)$d['display_name']) > 255 || !in_array($d['role'], ['admin','employee'], true)) throw new DomainException('Invalid employee data.');
        $sql = 'INSERT INTO alphaledger_ledger_people (installation_id,organization_id,external_id,revision,payload_hash,email,display_name,role,pa_person_id,is_active,occurred_at,snapshot_id,deleted_at,raw_data) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NULL,?) ON DUPLICATE KEY UPDATE organization_id=VALUES(organization_id),revision=VALUES(revision),payload_hash=VALUES(payload_hash),email=VALUES(email),display_name=VALUES(display_name),role=VALUES(role),pa_person_id=VALUES(pa_person_id),is_active=VALUES(is_active),occurred_at=VALUES(occurred_at),snapshot_id=VALUES(snapshot_id),deleted_at=NULL,raw_data=VALUES(raw_data)';
        $params=[$installationDbId,$organizationId,$externalId,$revision,$hash,strtolower((string)$d['email']),(string)$d['display_name'],$d['role'],$d['pa_person_id'] === null ? null : (string)$d['pa_person_id'],pa_al_ledger_bool($d['is_active'],'is_active'),$occurred,$snapshotId,$raw];
    } elseif ($record['entity_type'] === 'project') {
        if ($d['name']==='' || mb_strlen((string)$d['name'])>255 || !in_array($d['origin'],['internal','pa'],true)) throw new DomainException('Invalid project data.');
        $sql='INSERT INTO alphaledger_ledger_projects (installation_id,organization_id,external_id,revision,payload_hash,pa_project_id,name,origin,is_archived,occurred_at,snapshot_id,deleted_at,raw_data) VALUES (?,?,?,?,?,?,?,?,?,?,?,NULL,?) ON DUPLICATE KEY UPDATE organization_id=VALUES(organization_id),revision=VALUES(revision),payload_hash=VALUES(payload_hash),pa_project_id=VALUES(pa_project_id),name=VALUES(name),origin=VALUES(origin),is_archived=VALUES(is_archived),occurred_at=VALUES(occurred_at),snapshot_id=VALUES(snapshot_id),deleted_at=NULL,raw_data=VALUES(raw_data)';
        $params=[$installationDbId,$organizationId,$externalId,$revision,$hash,$d['pa_project_id']===null?null:(string)$d['pa_project_id'],(string)$d['name'],$d['origin'],pa_al_ledger_bool($d['is_archived'],'is_archived'),$occurred,$snapshotId,$raw];
    } elseif ($record['entity_type'] === 'assignment') {
        $project=pa_al_ledger_uuid($d['project_id'],'project_id'); $employee=pa_al_ledger_uuid($d['employee_id'],'employee_id');
        $sql='INSERT INTO alphaledger_ledger_assignments (installation_id,organization_id,external_id,revision,payload_hash,project_external_id,employee_external_id,is_active,occurred_at,snapshot_id,deleted_at,raw_data) VALUES (?,?,?,?,?,?,?,?,?,?,NULL,?) ON DUPLICATE KEY UPDATE organization_id=VALUES(organization_id),revision=VALUES(revision),payload_hash=VALUES(payload_hash),project_external_id=VALUES(project_external_id),employee_external_id=VALUES(employee_external_id),is_active=VALUES(is_active),occurred_at=VALUES(occurred_at),snapshot_id=VALUES(snapshot_id),deleted_at=NULL,raw_data=VALUES(raw_data)';
        $params=[$installationDbId,$organizationId,$externalId,$revision,$hash,$project,$employee,pa_al_ledger_bool($d['is_active'],'is_active'),$occurred,$snapshotId,$raw];
    } elseif ($record['entity_type'] === 'time_entry') {
        $employee=pa_al_ledger_uuid($d['employee_id'],'employee_id'); $project=pa_al_ledger_uuid($d['project_id'],'project_id',true);
        if (!in_array($d['status'],['running','review','approved','rejected','voided'],true) || !is_array($d['tags']) || !preg_match('/^[A-Z]{3}$/',(string)$d['currency'])) throw new DomainException('Invalid time entry data.');
        $start=pa_al_ledger_datetime($d['start_time'],'start_time'); $end=pa_al_ledger_datetime($d['end_time'],'end_time',true); $reviewed=pa_al_ledger_datetime($d['reviewed_at'],'reviewed_at',true);
        $duration=$d['duration_seconds']===null?null:filter_var($d['duration_seconds'],FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]); if($d['duration_seconds']!==null&&$duration===false)throw new DomainException('Invalid duration_seconds.');
        $sql='INSERT INTO alphaledger_ledger_time_entries (installation_id,organization_id,external_id,revision,payload_hash,employee_external_id,project_external_id,start_time,end_time,duration_seconds,description,billable,is_payable,status,rejection_reason,reviewed_at,occurred_at,snapshot_id,deleted_at,raw_data) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NULL,?) ON DUPLICATE KEY UPDATE organization_id=VALUES(organization_id),revision=VALUES(revision),payload_hash=VALUES(payload_hash),employee_external_id=VALUES(employee_external_id),project_external_id=VALUES(project_external_id),start_time=VALUES(start_time),end_time=VALUES(end_time),duration_seconds=VALUES(duration_seconds),description=VALUES(description),billable=VALUES(billable),is_payable=VALUES(is_payable),status=VALUES(status),rejection_reason=VALUES(rejection_reason),reviewed_at=VALUES(reviewed_at),occurred_at=VALUES(occurred_at),snapshot_id=VALUES(snapshot_id),deleted_at=NULL,raw_data=VALUES(raw_data)';
        $params=[$installationDbId,$organizationId,$externalId,$revision,$hash,$employee,$project,$start,$end,$duration,(string)$d['description'],pa_al_ledger_bool($d['billable'],'billable'),pa_al_ledger_bool($d['is_payable'],'is_payable'),$d['status'],(string)$d['rejection_reason'],$reviewed,$occurred,$snapshotId,$raw];
    } elseif ($record['entity_type'] === 'break') {
        $entry=pa_al_ledger_uuid($d['time_entry_id'],'time_entry_id'); $start=pa_al_ledger_datetime($d['start_time'],'start_time'); $end=pa_al_ledger_datetime($d['end_time'],'end_time',true);
        $duration=$d['duration_seconds']===null?null:filter_var($d['duration_seconds'],FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]); if($d['duration_seconds']!==null&&$duration===false)throw new DomainException('Invalid duration_seconds.');
        $sql='INSERT INTO alphaledger_ledger_breaks (installation_id,organization_id,external_id,revision,payload_hash,time_entry_external_id,start_time,end_time,duration_seconds,occurred_at,snapshot_id,deleted_at,raw_data) VALUES (?,?,?,?,?,?,?,?,?,?,?,NULL,?) ON DUPLICATE KEY UPDATE organization_id=VALUES(organization_id),revision=VALUES(revision),payload_hash=VALUES(payload_hash),time_entry_external_id=VALUES(time_entry_external_id),start_time=VALUES(start_time),end_time=VALUES(end_time),duration_seconds=VALUES(duration_seconds),occurred_at=VALUES(occurred_at),snapshot_id=VALUES(snapshot_id),deleted_at=NULL,raw_data=VALUES(raw_data)';
        $params=[$installationDbId,$organizationId,$externalId,$revision,$hash,$entry,$start,$end,$duration,$occurred,$snapshotId,$raw];
    } elseif ($record['entity_type'] === 'revision') {
        $entry=pa_al_ledger_uuid($d['time_entry_id'],'time_entry_id'); $creator=pa_al_ledger_uuid($d['created_by'],'created_by'); if(!is_int($d['entry_revision'])||$d['entry_revision']<1||!is_array($d['snapshot']))throw new DomainException('Invalid revision data.');
        $snapshot=json_encode($d['snapshot'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
        $sql='INSERT INTO alphaledger_ledger_revisions (installation_id,organization_id,external_id,revision,payload_hash,time_entry_external_id,entry_revision,reason,created_by_external_id,revision_snapshot,occurred_at,snapshot_id,deleted_at,raw_data) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NULL,?) ON DUPLICATE KEY UPDATE organization_id=VALUES(organization_id),revision=VALUES(revision),payload_hash=VALUES(payload_hash),time_entry_external_id=VALUES(time_entry_external_id),entry_revision=VALUES(entry_revision),reason=VALUES(reason),created_by_external_id=VALUES(created_by_external_id),revision_snapshot=VALUES(revision_snapshot),occurred_at=VALUES(occurred_at),snapshot_id=VALUES(snapshot_id),deleted_at=NULL,raw_data=VALUES(raw_data)';
        $params=[$installationDbId,$organizationId,$externalId,$revision,$hash,$entry,$d['entry_revision'],(string)$d['reason'],$creator,$snapshot,$occurred,$snapshotId,$raw];
    } else {
        $entry=pa_al_ledger_uuid($d['time_entry_id'],'time_entry_id'); $employee=pa_al_ledger_uuid($d['employee_id'],'employee_id'); if(!is_int($d['entry_revision'])||$d['entry_revision']<1||!preg_match('/^[A-Z]{3}$/',(string)$d['currency'])||!in_array($d['status'],['pending','paid','voided'],true))throw new DomainException('Invalid pay accrual data.');
        $userId=null; $personStmt=$pdo->prepare('SELECT pa_person_id FROM alphaledger_ledger_people WHERE installation_id=? AND external_id=? AND deleted_at IS NULL'); $personStmt->execute([$installationDbId,$employee]); $paPerson=$personStmt->fetchColumn(); if(is_string($paPerson)&&ctype_digit($paPerson)&&((int)$paPerson)>0)$userId=(int)$paPerson;
        $sql='INSERT INTO employee_pay_records (organization_id,installation_id,external_id,external_time_entry_id,external_revision,payload_hash,external_employee_id,employee_name_snapshot,user_id,hours,rate,amount,currency,status,paid_at,accrued_at,ledger_snapshot_id,deleted_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NULL) ON DUPLICATE KEY UPDATE organization_id=VALUES(organization_id),external_time_entry_id=VALUES(external_time_entry_id),external_revision=VALUES(external_revision),payload_hash=VALUES(payload_hash),external_employee_id=VALUES(external_employee_id),employee_name_snapshot=VALUES(employee_name_snapshot),user_id=COALESCE(VALUES(user_id),user_id),hours=VALUES(hours),rate=VALUES(rate),amount=VALUES(amount),currency=VALUES(currency),status=VALUES(status),paid_at=VALUES(paid_at),accrued_at=VALUES(accrued_at),ledger_snapshot_id=VALUES(ledger_snapshot_id),deleted_at=NULL';
        $params=[$organizationId,$installationDbId,$externalId,$entry,$revision,$hash,$employee,mb_substr((string)$d['employee_name'],0,255),$userId,pa_al_ledger_decimal($d['hours'],'hours'),pa_al_ledger_decimal($d['rate'],'rate'),pa_al_ledger_decimal($d['amount'],'amount'),$d['currency'],$d['status'],pa_al_ledger_datetime($d['paid_at'],'paid_at',true),pa_al_ledger_datetime($d['accrued_at'],'accrued_at'),$snapshotId];
    }
    $pdo->prepare($sql)->execute($params);
    return ['status'=>'accepted'];
}

function pa_al_ledger_begin_snapshot(PDO $pdo, array $installation, string $snapshotId, string $startedAt): void
{
    $pdo->prepare("INSERT INTO alphaledger_ledger_snapshots (installation_id,snapshot_id,state,started_at) VALUES (?,?, 'receiving',?) ON DUPLICATE KEY UPDATE started_at=IF(state='receiving',VALUES(started_at),started_at)")
        ->execute([(int)$installation['id'],$snapshotId,$startedAt]);
}

function pa_al_ledger_complete_snapshot(PDO $pdo, array $installation, string $snapshotId): void
{
    $stmt=$pdo->prepare("SELECT started_at,state FROM alphaledger_ledger_snapshots WHERE installation_id=? AND snapshot_id=? FOR UPDATE"); $stmt->execute([(int)$installation['id'],$snapshotId]); $snapshot=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!$snapshot||$snapshot['state']!=='receiving')throw new DomainException('Snapshot is not receiving.');
    foreach(['alphaledger_ledger_people','alphaledger_ledger_projects','alphaledger_ledger_assignments','alphaledger_ledger_time_entries','alphaledger_ledger_breaks','alphaledger_ledger_revisions'] as $table){
        $pdo->prepare("UPDATE {$table} SET deleted_at=UTC_TIMESTAMP() WHERE installation_id=? AND deleted_at IS NULL AND (snapshot_id IS NULL OR snapshot_id<>?) AND updated_at<=?")->execute([(int)$installation['id'],$snapshotId,$snapshot['started_at']]);
    }
    $pdo->prepare("UPDATE employee_pay_records SET deleted_at=UTC_TIMESTAMP() WHERE installation_id=? AND deleted_at IS NULL AND (ledger_snapshot_id IS NULL OR ledger_snapshot_id<>?) AND updated_at<=?")->execute([(int)$installation['id'],$snapshotId,$snapshot['started_at']]);
    $pdo->prepare("UPDATE alphaledger_ledger_snapshots SET state='complete',completed_at=UTC_TIMESTAMP() WHERE installation_id=? AND snapshot_id=?")->execute([(int)$installation['id'],$snapshotId]);
    $pdo->prepare('UPDATE alphaledger_installations SET last_ledger_sync_at=UTC_TIMESTAMP(),last_success_at=UTC_TIMESTAMP(),status=\'active\',consecutive_failures=0 WHERE id=?')->execute([(int)$installation['id']]);
}

function pa_al_ledger_has_history(PDO $pdo): bool
{
    try {
        foreach (['alphaledger_ledger_time_entries','alphaledger_ledger_people','employee_pay_records'] as $table) {
            if ($pdo->query("SELECT 1 FROM {$table} LIMIT 1")->fetchColumn()) return true;
        }
    } catch (Throwable $ignored) {}
    return false;
}
