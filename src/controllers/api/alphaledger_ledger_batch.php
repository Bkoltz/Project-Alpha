<?php

require_once __DIR__ . '/../../utils/alphaledger_ledger.php';

if (($resource ?? '') !== 'ledger-records/batch' || ($method ?? '') !== 'POST') {
    pa_al_json_response(['error' => 'not_found'], 404);
}

$contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLength > 1048576) {
    pa_al_json_response(['error' => 'request_too_large'], 413);
}
$raw = (string)file_get_contents('php://input');
if (strlen($raw) > 1048576) {
    pa_al_json_response(['error' => 'request_too_large'], 413);
}
$timestamp = trim((string)($_SERVER['HTTP_X_AL_TIMESTAMP'] ?? ''));
$signature = trim((string)($_SERVER['HTTP_X_AL_SIGNATURE'] ?? ''));
if (!pa_al_ledger_verify_signature($installation, $timestamp, $signature, $raw)) {
    if (function_exists('audit_log')) {
        audit_log($pdo, 'alphaledger.ledger_signature_rejected', 'alphaledger_installation', (int)$installation['id']);
    }
    pa_al_json_response(['error' => 'invalid_or_expired_signature'], 401);
}

$idempotencyKey = trim((string)($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ''));
if ($idempotencyKey === '' || strlen($idempotencyKey) > 255) {
    pa_al_json_response(['error' => 'valid_idempotency_key_required'], 400);
}
try {
    $body = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    pa_al_json_response(['error' => 'invalid_json'], 400);
}
if (!is_array($body)) pa_al_json_response(['error' => 'json_object_required'], 400);

$requestHash = hash('sha256', $method . "\n" . $resource . "\n" . $raw);
$idemStmt = $pdo->prepare('SELECT request_hash,response_code,response_body FROM alphaledger_idempotency WHERE api_key_id=? AND idempotency_key=? LIMIT 1');
$idemStmt->execute([(int)$apiKey['id'], $idempotencyKey]);
$prior = $idemStmt->fetch(PDO::FETCH_ASSOC);
if ($prior) {
    if (!hash_equals((string)$prior['request_hash'], $requestHash)) pa_al_json_response(['error'=>'idempotency_key_reused_with_different_request'],409);
    if ($prior['response_body'] !== null) pa_al_json_response(json_decode((string)$prior['response_body'],true,64,JSON_THROW_ON_ERROR),(int)($prior['response_code'] ?: 200));
    pa_al_json_response(['error'=>'request_with_key_is_still_processing'],409);
}
try {
    $pdo->prepare('INSERT INTO alphaledger_idempotency (api_key_id,idempotency_key,request_hash) VALUES (?,?,?)')->execute([(int)$apiKey['id'],$idempotencyKey,$requestHash]);
} catch (PDOException $e) {
    pa_al_json_response(['error'=>'request_with_key_is_still_processing'],409);
}

try {
    pa_al_ledger_assert_keys($body, ['schema_version','mode','snapshot_id','snapshot_started_at','final','records'], ['schema_version','mode','snapshot_id','snapshot_started_at','final','records'], 'request');
    if ($body['schema_version'] !== PA_AL_SCHEMA_VERSION) throw new DomainException('Unsupported schema version.');
    if (!in_array($body['mode'], ['delta','snapshot'], true)) throw new DomainException('Unsupported mode.');
    if (!is_bool($body['final']) || !is_array($body['records']) || count($body['records']) > 100) throw new DomainException('records must contain at most 100 items and final must be boolean.');
    $snapshotId = null;
    $snapshotStartedAt = null;
    if ($body['mode'] === 'snapshot') {
        $snapshotId = pa_al_ledger_uuid($body['snapshot_id'], 'snapshot_id');
        $snapshotStartedAt = pa_al_ledger_datetime($body['snapshot_started_at'], 'snapshot_started_at');
    } elseif ($body['snapshot_id'] !== null || $body['snapshot_started_at'] !== null || $body['final'] !== false) {
        throw new DomainException('Delta requests cannot include snapshot state.');
    }

    if ($snapshotId !== null) {
        $pdo->beginTransaction();
        pa_al_ledger_begin_snapshot($pdo, $installation, $snapshotId, $snapshotStartedAt);
        $pdo->commit();
    }
    $results = [];
    $accepted = 0;
    foreach ($body['records'] as $input) {
        $pdo->beginTransaction();
        try {
            if (!is_array($input)) throw new DomainException('Each record must be an object.');
            $record = pa_al_ledger_validate_record($input, (string)$installation['installation_id']);
            $receivedStmt = $pdo->prepare('SELECT result FROM alphaledger_received_events WHERE installation_id=? AND event_id=? LIMIT 1');
            $receivedStmt->execute([(int)$installation['id'],$record['event_id']]);
            $priorResult = $receivedStmt->fetchColumn();
            if ($priorResult !== false) {
                $result = json_decode((string)$priorResult,true,32,JSON_THROW_ON_ERROR);
                $result['status'] = 'duplicate';
            } else {
                $result = pa_al_ledger_apply_record($pdo,$installation,$record,$snapshotId);
                $result['event_id'] = $record['event_id'];
                $pdo->prepare('INSERT INTO alphaledger_received_events (installation_id,event_id,event_type,aggregate_id,revision,result) VALUES (?,?,?,?,?,?)')->execute([
                    (int)$installation['id'],$record['event_id'],'ledger.'.$record['entity_type'].'.'.$record['operation'],$record['entity_id'],(int)$record['revision'],json_encode($result,JSON_THROW_ON_ERROR),
                ]);
            }
            if (in_array($result['status'] ?? '', ['accepted','duplicate','stale_ignored'], true)) $accepted++;
            $results[] = $result;
            $pdo->commit();
        } catch (DomainException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $results[] = ['event_id'=>is_array($input)?($input['event_id']??null):null,'status'=>'rejected','error'=>$e->getMessage()];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
    if ($snapshotId !== null) {
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE alphaledger_ledger_snapshots SET records_received=records_received+? WHERE installation_id=? AND snapshot_id=?')->execute([$accepted,(int)$installation['id'],$snapshotId]);
        if ($body['final']) {
            pa_al_ledger_complete_snapshot($pdo,$installation,$snapshotId);
            if (function_exists('audit_log')) audit_log($pdo,'alphaledger.ledger_snapshot_completed','alphaledger_installation',(int)$installation['id'],['snapshot_id'=>$snapshotId,'records_received'=>$accepted]);
        }
        $pdo->commit();
    } else {
        $pdo->prepare('UPDATE alphaledger_installations SET last_ledger_sync_at=UTC_TIMESTAMP(),last_success_at=UTC_TIMESTAMP(),status=\'active\',consecutive_failures=0 WHERE id=?')->execute([(int)$installation['id']]);
    }
    $response=['results'=>$results,'snapshot_complete'=>$snapshotId!==null && $body['final']];
    $encoded=json_encode($response,JSON_THROW_ON_ERROR);
    $pdo->prepare('UPDATE alphaledger_idempotency SET response_code=200,response_body=? WHERE api_key_id=? AND idempotency_key=?')->execute([$encoded,(int)$apiKey['id'],$idempotencyKey]);
    pa_al_json_response($response);
} catch (DomainException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $response=['error'=>'invalid_ledger_batch','message'=>$e->getMessage()];
    $pdo->prepare('UPDATE alphaledger_idempotency SET response_code=422,response_body=? WHERE api_key_id=? AND idempotency_key=?')->execute([json_encode($response,JSON_THROW_ON_ERROR),(int)$apiKey['id'],$idempotencyKey]);
    pa_al_json_response($response,422);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $pdo->prepare('DELETE FROM alphaledger_idempotency WHERE api_key_id=? AND idempotency_key=? AND response_body IS NULL')->execute([(int)$apiKey['id'],$idempotencyKey]);
    throw $e;
}
