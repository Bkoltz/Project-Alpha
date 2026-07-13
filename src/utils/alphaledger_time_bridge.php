<?php
http_response_code(410);
exit('Retired integration code. Use the built-in Workforce modules.');

require_once __DIR__ . '/alphaledger_integration.php';
require_once __DIR__ . '/crypto.php';

function pa_al_time_validate_remote_url(string $url, string $field): string
{
    $url=trim($url); $parts=filter_var($url,FILTER_VALIDATE_URL)?parse_url($url):false;
    if(!$parts||empty($parts['scheme'])||empty($parts['host'])||isset($parts['user'])||isset($parts['pass'])||isset($parts['fragment'])) throw new DomainException($field.' must be an absolute URL without credentials or a fragment.');
    $scheme=strtolower((string)$parts['scheme']); $allowHttp=filter_var(getenv('ALPHALEDGER_ALLOW_HTTP_CALLBACKS')?:'false',FILTER_VALIDATE_BOOLEAN);
    if($scheme!=='https'&&!($allowHttp&&$scheme==='http')) throw new DomainException($field.' must use HTTPS.');
    $allowed=array_values(array_filter(array_map('strtolower',array_map('trim',explode(',',(string)(getenv('ALPHALEDGER_CALLBACK_HOSTS')?:''))))));
    if($allowed&&!in_array(strtolower((string)$parts['host']),$allowed,true)) throw new DomainException($field.' host is not in ALPHALEDGER_CALLBACK_HOSTS.');
    return $url;
}

function pa_al_time_capabilities(array $installation): array
{
    $raw = $installation['capabilities'] ?? null;
    if (is_array($raw)) return $raw;
    if (!is_string($raw) || $raw === '') return [];
    try {
        $value = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    } catch (Throwable $e) {
        return [];
    }
}

function pa_al_time_active_installation(PDO $pdo): ?array
{
    try {
        $policy = pa_al_policy($pdo);
        if (empty($policy['enabled']) || empty($policy['approved_api_key_id'])) return null;
        $stmt = $pdo->prepare("SELECT * FROM alphaledger_installations WHERE api_key_id=? AND status IN ('active','degraded') ORDER BY id DESC LIMIT 1");
        $stmt->execute([(int)$policy['approved_api_key_id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function pa_al_time_commands_available(array $installation): bool
{
    return !empty($installation['al_business_id'])
        && !empty($installation['command_api_url'])
        && in_array('time_commands_v1', pa_al_time_capabilities($installation), true);
}

function pa_al_time_admin_context(PDO $pdo, int $userId): ?array
{
    if ($userId <= 0) return null;
    $installation = pa_al_time_active_installation($pdo);
    if (!$installation || !pa_al_time_commands_available($installation)) return null;
    $stmt = $pdo->prepare(
        "SELECT u.id user_id,u.role,tm.id team_member_id,tm.display_name,m.al_employee_id
         FROM users u
         JOIN team_members tm ON tm.user_id=u.id AND tm.is_active=1
         JOIN alphaledger_employee_mappings m ON m.team_member_id=tm.id AND m.al_business_id=?
         WHERE u.id=? AND u.role='admin' AND u.is_disabled=0 AND u.deleted_at IS NULL LIMIT 1"
    );
    $stmt->execute([(string)$installation['al_business_id'], $userId]);
    $identity = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$identity) return null;
    return ['installation' => $installation] + $identity;
}

function pa_al_time_record_exception(PDO $pdo, array $installation, string $type, string $objectType, string $objectId, string $reason, array $details = []): void
{
    $detailsJson = json_encode($details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $find=$pdo->prepare("SELECT id FROM alphaledger_integration_exceptions WHERE installation_id=? AND exception_type=? AND source_object_type=? AND source_object_id=? AND status='open' ORDER BY id DESC LIMIT 1");
    $find->execute([(int)$installation['id'],$type,$objectType,$objectId]);$existing=$find->fetchColumn();
    if($existing!==false)$pdo->prepare('UPDATE alphaledger_integration_exceptions SET reason=?,details=?,occurrences=occurrences+1,last_seen_at=UTC_TIMESTAMP() WHERE id=?')->execute([$reason,$detailsJson,(int)$existing]);
    else $pdo->prepare("INSERT INTO alphaledger_integration_exceptions (installation_id,exception_type,source_object_type,source_object_id,reason,details,status,last_seen_at) VALUES (?,?,?,?,?,?,'open',UTC_TIMESTAMP())")->execute([(int)$installation['id'],$type,$objectType,$objectId,$reason,$detailsJson]);
}

function pa_al_time_resolve_employee(PDO $pdo, array $installation, string $alEmployeeId): ?array
{
    if ($alEmployeeId === '' || empty($installation['al_business_id'])) return null;
    $stmt = $pdo->prepare(
        'SELECT tm.* FROM alphaledger_employee_mappings m JOIN team_members tm ON tm.id=m.team_member_id
         WHERE m.al_business_id=? AND m.al_employee_id=? AND tm.is_active=1 LIMIT 1'
    );
    $stmt->execute([(string)$installation['al_business_id'], $alEmployeeId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function pa_al_time_resolve_project(PDO $pdo, array $installation, string $alProjectId): ?array
{
    if ($alProjectId === '' || empty($installation['al_business_id'])) return null;
    $stmt = $pdo->prepare(
        'SELECT p.* FROM alphaledger_project_mappings m JOIN projects p ON p.id=m.project_id
         WHERE m.al_business_id=? AND m.al_project_id=? LIMIT 1'
    );
    $stmt->execute([(string)$installation['al_business_id'], $alProjectId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function pa_al_time_al_project_id(PDO $pdo, array $installation, ?int $projectId): ?string
{
    if(!$projectId) return null;
    $stmt=$pdo->prepare('SELECT al_project_id FROM alphaledger_project_mappings WHERE al_business_id=? AND project_id=? LIMIT 1');
    $stmt->execute([(string)$installation['al_business_id'],$projectId]);
    $value=$stmt->fetchColumn();
    if(!is_string($value)||$value==='') throw new DomainException('The selected PA project is not mapped to AlphaLedger.');
    return $value;
}

function pa_al_time_resolve_rates(PDO $pdo, int $teamMemberId, ?array $project, string $workDate, ?float $alCostSnapshot, ?int $serviceItemId = null): array
{
    $billing = null;
    $billingSource = null;
    if ($project) {
        $stmt = $pdo->prepare("SELECT amount,currency FROM billing_rate_rules WHERE scope_type='project' AND project_id=? AND effective_from<=? AND (effective_until IS NULL OR effective_until>=?) ORDER BY effective_from DESC,id DESC LIMIT 1");
        $stmt->execute([(int)$project['id'], $workDate, $workDate]);
        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) { $billing=(float)$row['amount']; $billingSource='project'; $currency=(string)$row['currency']; }
        if ($billing === null && !empty($project['client_id'])) {
            $stmt = $pdo->prepare("SELECT amount,currency FROM billing_rate_rules WHERE scope_type='client' AND client_id=? AND effective_from<=? AND (effective_until IS NULL OR effective_until>=?) ORDER BY effective_from DESC,id DESC LIMIT 1");
            $stmt->execute([(int)$project['client_id'], $workDate, $workDate]);
            if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) { $billing=(float)$row['amount']; $billingSource='client'; $currency=(string)$row['currency']; }
        }
    }
    if ($billing === null) {
        $stmt = $pdo->prepare("SELECT amount,currency FROM team_member_rates WHERE team_member_id=? AND rate_type='billing' AND effective_from<=? AND (effective_until IS NULL OR effective_until>=?) ORDER BY effective_from DESC,id DESC LIMIT 1");
        $stmt->execute([$teamMemberId, $workDate, $workDate]);
        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) { $billing=(float)$row['amount']; $billingSource='team_member'; $currency=(string)$row['currency']; }
    }
    if ($billing === null && $serviceItemId) {
        $stmt=$pdo->prepare('SELECT unit_price FROM item_library WHERE id=? AND is_active=1'); $stmt->execute([$serviceItemId]);
        $value=$stmt->fetchColumn(); if ($value !== false) { $billing=(float)$value; $billingSource='service_item'; }
    }
    $cost = $alCostSnapshot;
    $costSource = $cost !== null ? 'alphaledger' : null;
    if ($cost === null) {
        $stmt = $pdo->prepare("SELECT amount FROM team_member_rates WHERE team_member_id=? AND rate_type='cost' AND effective_from<=? AND (effective_until IS NULL OR effective_until>=?) ORDER BY effective_from DESC,id DESC LIMIT 1");
        $stmt->execute([$teamMemberId, $workDate, $workDate]);
        $value=$stmt->fetchColumn(); if ($value !== false) { $cost=(float)$value; $costSource='team_member'; }
    }
    return ['billing_rate'=>$billing,'cost_rate'=>$cost,'currency'=>$currency ?? 'USD','source'=>trim(($billingSource ?? 'missing').'+'.($costSource ?? 'missing'))];
}

function pa_al_time_queue_command(PDO $pdo, array $context, string $operation, array $payload, ?string $startedAt = null, ?string $endedAt = null, ?string $alEntryId = null): int
{
    $allowed=['start','stop','create','update','assign','submit','cancel','backfill_preview','backfill_request'];
    if (!in_array($operation,$allowed,true)) throw new DomainException('Unsupported AlphaLedger time operation.');
    $operationId=pa_al_uuid();
    $payload += [
        'schema_version'=>'1.1', 'operation_id'=>$operationId, 'operation'=>$operation,
        'installation_id'=>(string)$context['installation']['installation_id'],
        'pa_actor_user_id'=>(string)$context['user_id'], 'al_employee_id'=>(string)$context['al_employee_id'],
        'occurred_at'=>gmdate('Y-m-d\TH:i:s\Z'),
    ];
    $encrypted=crypto_encrypt(json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
    if (!$encrypted) throw new RuntimeException('APP_ENCRYPTION_KEY is required to cache AlphaLedger commands.');
    $stmt=$pdo->prepare("INSERT INTO alphaledger_command_outbox
        (installation_id,operation_id,idempotency_key,operation_type,actor_user_id,team_member_id,al_employee_id,al_entry_id,started_at,ended_at,payload_enc)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)");
    $stmt->execute([(int)$context['installation']['id'],$operationId,'pa-time-'.$operationId,$operation,(int)$context['user_id'],(int)$context['team_member_id'],(string)$context['al_employee_id'],$alEntryId,$startedAt,$endedAt,$encrypted]);
    return (int)$pdo->lastInsertId();
}

function pa_al_time_pending_start(PDO $pdo, int $userId): ?array
{
    $stmt=$pdo->prepare("SELECT s.* FROM alphaledger_command_outbox s WHERE s.actor_user_id=? AND s.operation_type='start' AND s.state IN ('pending','attention','delivered') AND NOT EXISTS (SELECT 1 FROM alphaledger_command_outbox x WHERE x.actor_user_id=s.actor_user_id AND x.id>s.id AND x.operation_type IN ('stop','cancel') AND x.state<>'cancelled') ORDER BY s.id DESC LIMIT 1");
    $stmt->execute([$userId]); $row=$stmt->fetch(PDO::FETCH_ASSOC); return $row ?: null;
}

function pa_al_time_coalesce_stop(PDO $pdo, array $pendingStart, string $endedAt): bool
{
    if ($pendingStart['state'] !== 'pending' || (int)$pendingStart['attempts'] !== 0) return false;
    $json=crypto_decrypt((string)$pendingStart['payload_enc']); if (!$json) return false;
    $payload=json_decode($json,true,32,JSON_THROW_ON_ERROR);
    $payload['operation']='create'; $payload['start_time']=gmdate('c',strtotime((string)$pendingStart['started_at'])); $payload['end_time']=gmdate('c',strtotime($endedAt));
    $payload['duration_seconds']=max(0,strtotime($endedAt)-strtotime((string)$pendingStart['started_at']));
    $encrypted=crypto_encrypt(json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)); if (!$encrypted) return false;
    $stmt=$pdo->prepare("UPDATE alphaledger_command_outbox SET operation_type='create',ended_at=?,payload_enc=?,next_attempt_at=UTC_TIMESTAMP() WHERE id=? AND state='pending' AND attempts=0");
    $stmt->execute([$endedAt,$encrypted,(int)$pendingStart['id']]); return $stmt->rowCount()===1;
}

function pa_al_time_command_signature(string $timestamp, string $method, string $path, string $body, string $secret): string
{
    return hash_hmac('sha256',$timestamp."\n".strtoupper($method)."\n".$path."\n".hash('sha256',$body),$secret);
}

function pa_al_time_deliver_commands(PDO $pdo, int $limit=50): array
{
    $stmt=$pdo->prepare("SELECT o.*,i.command_api_url,i.webhook_secret_enc,i.installation_id external_installation_id
        FROM alphaledger_command_outbox o JOIN alphaledger_installations i ON i.id=o.installation_id
        WHERE o.state='pending' AND o.next_attempt_at<=UTC_TIMESTAMP() AND i.status IN ('active','degraded') ORDER BY o.id LIMIT ?");
    $stmt->bindValue(1,max(1,min(200,$limit)),PDO::PARAM_INT); $stmt->execute();
    $result=['delivered'=>0,'failed'=>0];
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row){
        $url=(string)$row['command_api_url']; $body=crypto_decrypt((string)$row['payload_enc']); $secret=crypto_decrypt((string)$row['webhook_secret_enc']);
        if($url===''||$body===null||$secret===null){ $error='Command endpoint or encrypted integration data is unavailable.'; $code=0; }
        else {
            $parts=parse_url($url); $path=(string)($parts['path']??'/'); if(isset($parts['query']))$path.='?'.$parts['query'];
            $timestamp=gmdate('Y-m-d\TH:i:s\Z'); $signature=pa_al_time_command_signature($timestamp,'POST',$path,$body,$secret);
            $ch=curl_init($url); curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,CURLOPT_HTTPHEADER=>['Content-Type: application/json','Idempotency-Key: '.$row['idempotency_key'],'X-PA-Installation: '.$row['external_installation_id'],'X-PA-Timestamp: '.$timestamp,'X-PA-Signature: '.$signature]]);
            $response=curl_exec($ch); $code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE); $error=$response===false?(curl_error($ch)?:'Connection failed.'):(string)$response; curl_close($ch);
        }
        if($code>=200&&$code<300){
            $decoded=json_decode($error,true); $alEntry=is_array($decoded)?($decoded['entry_id']??$decoded['id']??null):null;
            $pdo->prepare("UPDATE alphaledger_command_outbox SET state='delivered',attempts=attempts+1,delivered_at=UTC_TIMESTAMP(),last_error=NULL,al_entry_id=COALESCE(?,al_entry_id) WHERE id=?")->execute([$alEntry,(int)$row['id']]);
            $pdo->prepare('UPDATE alphaledger_installations SET last_command_sync_at=UTC_TIMESTAMP(),last_success_at=UTC_TIMESTAMP(),consecutive_failures=0 WHERE id=?')->execute([(int)$row['installation_id']]); $result['delivered']++;
        } else {
            $attempt=(int)$row['attempts']+1; $attention=$code>=400&&$code<500&&$code!==408&&$code!==429||$attempt>=8; $delay=min(3600,(2**min($attempt,10))*15+random_int(0,15));
            $pdo->prepare('UPDATE alphaledger_command_outbox SET state=?,attempts=?,next_attempt_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? SECOND),last_error=? WHERE id=?')->execute([$attention?'attention':'pending',$attempt,$delay,mb_substr($error,0,2000),(int)$row['id']]);
            $pdo->prepare("UPDATE alphaledger_installations SET status=CASE WHEN status='active' THEN 'degraded' ELSE status END,consecutive_failures=consecutive_failures+1 WHERE id=?")->execute([(int)$row['installation_id']]);
            if($attention) pa_al_time_record_exception($pdo,['id'=>(int)$row['installation_id']],'rejected_command','time_command',(string)$row['operation_id'],'AlphaLedger rejected or repeatedly failed the queued command.',['http_status'=>$code,'error'=>mb_substr($error,0,1000)]);
            $result['failed']++;
        }
    }
    return $result;
}

function pa_al_time_refresh_mapping_exceptions(PDO $pdo, array $installation): void
{
    if(empty($installation['al_business_id'])) return;
    $pdo->prepare('UPDATE team_members tm JOIN alphaledger_employee_mappings m ON m.team_member_id=tm.id AND m.al_business_id=? JOIN alphaledger_ledger_people p ON p.installation_id=? AND p.external_id=m.al_employee_id SET tm.display_name=p.display_name,tm.email=NULLIF(p.email,\'\'),tm.is_active=IF(p.deleted_at IS NULL AND p.is_active=1,1,0),tm.profile_source=\'alphaledger\',tm.last_synced_at=UTC_TIMESTAMP()')->execute([(string)$installation['al_business_id'],(int)$installation['id']]);
    $people=$pdo->prepare('SELECT external_id,display_name,email FROM alphaledger_ledger_people p WHERE p.installation_id=? AND p.deleted_at IS NULL AND NOT EXISTS (SELECT 1 FROM alphaledger_employee_mappings m WHERE m.al_business_id=? AND m.al_employee_id=p.external_id)');
    $people->execute([(int)$installation['id'],(string)$installation['al_business_id']]);
    foreach($people->fetchAll(PDO::FETCH_ASSOC) as $row) pa_al_time_record_exception($pdo,$installation,'unmapped_employee','employee',(string)$row['external_id'],'AlphaLedger employee requires a PA team-member mapping.',$row);
    $projects=$pdo->prepare('SELECT external_id,name FROM alphaledger_ledger_projects p WHERE p.installation_id=? AND p.deleted_at IS NULL AND NOT EXISTS (SELECT 1 FROM alphaledger_project_mappings m WHERE m.al_business_id=? AND m.al_project_id=p.external_id)');
    $projects->execute([(int)$installation['id'],(string)$installation['al_business_id']]);
    foreach($projects->fetchAll(PDO::FETCH_ASSOC) as $row) pa_al_time_record_exception($pdo,$installation,'unmapped_project','project',(string)$row['external_id'],'AlphaLedger project requires a PA billing-project mapping.',$row);
}
