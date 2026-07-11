<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/alphaledger_integration.php';
require_once __DIR__ . '/../../utils/alphaledger_ledger.php';

$apiKey = $GLOBALS['pa_api_key'] ?? null;
if (!is_array($apiKey) || empty($apiKey['id'])) {
    pa_al_json_response(['error' => 'unauthorized'], 401);
}

$resource = trim((string) ($_GET['resource'] ?? ''), '/');
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

try {
    if (api_normalize_scopes($apiKey['scopes'] ?? '') !== ['alphaledger.sync']) {
        pa_al_json_response(['error' => 'dedicated_alphaledger_scope_required'], 403);
    }
    $policy = pa_al_policy($pdo);
    if (empty($policy['enabled'])) {
        pa_al_json_response(['error' => 'integration_disabled'], 403);
    }
    if ((int) ($policy['approved_api_key_id'] ?? 0) !== (int) $apiKey['id']) {
        pa_al_json_response(['error' => 'api_key_not_approved_for_alphaledger'], 403);
    }
    if (trim((string) ($apiKey['allowed_ips'] ?? '')) === '' && empty($policy['allow_unrestricted_key'])) {
        pa_al_json_response(['error' => 'api_key_ip_allowlist_required'], 403);
    }

    if ($resource === 'manifest' && $method === 'GET') {
        pa_al_json_response([
            'business_id' => pa_al_business_id($pdo),
            'capabilities' => [
                'schema_version' => PA_AL_SCHEMA_VERSION,
                'time_records' => true,
                'pay_accruals' => true,
                'reconciliation' => true,
                'signed_webhooks' => true,
                'operational_ledger_v1' => true,
                'events' => ['person', 'project', 'assignment', 'pay_accrual.status_changed', 'financial_summary.updated'],
            ],
        ]);
    }

    if ($resource === 'installations' && $method === 'POST') {
        $body = pa_al_request_json();
        if (($body['schema_version'] ?? '') !== PA_AL_SCHEMA_VERSION) {
            pa_al_json_response(['error' => 'unsupported_schema_version'], 422);
        }
        try {
            $callback = pa_al_validate_callback_url((string) ($body['callback_url'] ?? ''));
        } catch (DomainException $e) {
            pa_al_json_response(['error' => 'invalid_callback_url', 'message' => $e->getMessage()], 422);
        }
        $hash = hash('sha256', $callback);
        if (empty($policy['approved_callback_hash']) || !hash_equals((string) $policy['approved_callback_hash'], $hash) || !hash_equals((string) $policy['approved_callback_url'], $callback)) {
            pa_al_json_response(['error' => 'callback_url_not_approved_by_pa_admin'], 403);
        }
        $stmt = $pdo->prepare('SELECT * FROM alphaledger_installations WHERE api_key_id=? AND callback_hash=? LIMIT 1');
        $stmt->execute([(int) $apiKey['id'], $hash]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            $pdo->prepare("UPDATE alphaledger_installations SET status='disabled' WHERE api_key_id=? AND id<>?")->execute([(int) $apiKey['id'], (int) $existing['id']]);
            $secret = crypto_decrypt((string) $existing['webhook_secret_enc']);
            if (!$secret) {
                $secret = bin2hex(random_bytes(32));
                $encrypted = crypto_encrypt($secret);
                if (!$encrypted) {
                    throw new RuntimeException('APP_ENCRYPTION_KEY is required for AlphaLedger integration.');
                }
                $pdo->prepare("UPDATE alphaledger_installations SET webhook_secret_enc=?,status='active',consecutive_failures=0 WHERE id=?")->execute([$encrypted, (int) $existing['id']]);
            } else {
                $pdo->prepare("UPDATE alphaledger_installations SET status='active',consecutive_failures=0 WHERE id=?")->execute([(int) $existing['id']]);
            }
            pa_al_json_response(['installation_id' => $existing['installation_id'], 'webhook_secret' => $secret], 201);
        }
        $secret = bin2hex(random_bytes(32));
        $encrypted = crypto_encrypt($secret);
        if (!$encrypted) {
            throw new RuntimeException('APP_ENCRYPTION_KEY is required for AlphaLedger integration.');
        }
        $installationId = pa_al_uuid();
        $stmt = $pdo->prepare("INSERT INTO alphaledger_installations (installation_id,api_key_id,organization_id,callback_url,callback_hash,webhook_secret_enc,schema_version,status) VALUES (?,?,?,?,?,?,?,'active')");
        $stmt->execute([$installationId, (int) $apiKey['id'], $apiKey['organization_id'] ?? null, $callback, $hash, $encrypted, PA_AL_SCHEMA_VERSION]);
        $newInstallationDbId = (int) $pdo->lastInsertId();
        $pdo->prepare("UPDATE alphaledger_installations SET status='disabled' WHERE api_key_id=? AND id<>?")->execute([(int) $apiKey['id'], $newInstallationDbId]);
        $installation = pa_al_installation_for_api_key($pdo, (int) $apiKey['id']);
        if ($installation) {
            pa_al_capture_owned_state($pdo, $installation);
        }
        pa_al_json_response(['installation_id' => $installationId, 'webhook_secret' => $secret], 201);
    }

    $installation = pa_al_installation_for_api_key($pdo, (int) $apiKey['id']);
    if (!$installation) {
        pa_al_json_response(['error' => 'installation_required'], 409);
    }

    if ($resource === 'ledger-records/batch') {
        require __DIR__ . '/alphaledger_ledger_batch.php';
        exit;
    }

    if ($resource === 'changes' && $method === 'GET') {
        pa_al_capture_owned_state($pdo, $installation);
        $cursorRaw = trim((string) ($_GET['cursor'] ?? '0'));
        if ($cursorRaw !== '' && !ctype_digit($cursorRaw)) {
            pa_al_json_response(['error' => 'invalid_cursor'], 400);
        }
        $cursor = (int) ($cursorRaw === '' ? 0 : $cursorRaw);
        if ($cursor > 0) {
            // AL may have received the first assignment before its admin confirmed
            // the PA-person link. A throttled reconciliation replay makes it converge.
            pa_al_refresh_assignments($pdo, $installation);
        }
        $stmt = $pdo->prepare('SELECT sequence_id,envelope FROM alphaledger_events WHERE installation_id=? AND sequence_id>? ORDER BY sequence_id LIMIT 500');
        $stmt->bindValue(1, (int) $installation['id'], PDO::PARAM_INT);
        $stmt->bindValue(2, $cursor, PDO::PARAM_INT);
        $stmt->execute();
        $events = [];
        $next = $cursor;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $events[] = json_decode((string) $row['envelope'], true, 64, JSON_THROW_ON_ERROR);
            $next = (int) $row['sequence_id'];
        }
        pa_al_json_response(['events' => $events, 'next_cursor' => (string) $next]);
    }

    if ($resource === 'reconciliation' && $method === 'POST') {
        pa_al_capture_owned_state($pdo, $installation);
        pa_al_json_response(['status' => 'accepted'], 202);
    }

    $isTimeBatch = $resource === 'time-records/batch';
    $isPayBatch = $resource === 'pay-accruals/batch';
    if (($isTimeBatch || $isPayBatch) && $method === 'POST') {
        $idempotencyKey = trim((string) ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ''));
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 255) {
            pa_al_json_response(['error' => 'valid_idempotency_key_required'], 400);
        }
        $raw = (string) file_get_contents('php://input');
        try {
            $body = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            pa_al_json_response(['error' => 'invalid_json'], 400);
        }
        if (!is_array($body)) {
            pa_al_json_response(['error' => 'json_object_required'], 400);
        }
        $requestHash = hash('sha256', $method . "\n" . $resource . "\n" . $raw);
        $idemStmt = $pdo->prepare('SELECT request_hash,response_code,response_body FROM alphaledger_idempotency WHERE api_key_id=? AND idempotency_key=? LIMIT 1');
        $idemStmt->execute([(int) $apiKey['id'], $idempotencyKey]);
        $prior = $idemStmt->fetch(PDO::FETCH_ASSOC);
        if ($prior) {
            if (!hash_equals((string) $prior['request_hash'], $requestHash)) {
                pa_al_json_response(['error' => 'idempotency_key_reused_with_different_request'], 409);
            }
            if ($prior['response_body'] !== null) {
                pa_al_json_response(json_decode((string) $prior['response_body'], true, 64, JSON_THROW_ON_ERROR), (int) ($prior['response_code'] ?: 200));
            }
            pa_al_json_response(['error' => 'request_with_key_is_still_processing'], 409);
        }
        try {
            $pdo->prepare('INSERT INTO alphaledger_idempotency (api_key_id,idempotency_key,request_hash) VALUES (?,?,?)')->execute([(int) $apiKey['id'], $idempotencyKey, $requestHash]);
        } catch (PDOException $e) {
            pa_al_json_response(['error' => 'request_with_key_is_still_processing'], 409);
        }
        if (!isset($body['events']) || !is_array($body['events']) || count($body['events']) < 1 || count($body['events']) > 100) {
            $response = ['error' => 'events_must_contain_between_1_and_100_items'];
            $pdo->prepare('UPDATE alphaledger_idempotency SET response_code=422,response_body=? WHERE api_key_id=? AND idempotency_key=?')->execute([json_encode($response), (int) $apiKey['id'], $idempotencyKey]);
            pa_al_json_response($response, 422);
        }
        $results = [];
        foreach ($body['events'] as $event) {
            if (!is_array($event)) {
                $results[] = ['event_id' => null, 'status' => 'rejected', 'error' => 'Event must be an object.'];
                continue;
            }
            $pdo->beginTransaction();
            try {
                $eventId = (string) ($event['event_id'] ?? '');
                $receivedStmt = $pdo->prepare('SELECT result FROM alphaledger_received_events WHERE installation_id=? AND event_id=? LIMIT 1');
                $receivedStmt->execute([(int) $installation['id'], $eventId]);
                $received = $receivedStmt->fetchColumn();
                if ($received !== false) {
                    $result = json_decode((string) $received, true, 32, JSON_THROW_ON_ERROR);
                    $result['status'] = 'duplicate';
                } else {
                    $result = $isTimeBatch
                        ? pa_al_ingest_time_event($pdo, $installation, $event)
                        : pa_al_ingest_pay_event($pdo, $installation, $event);
                    $pdo->prepare('INSERT INTO alphaledger_received_events (installation_id,event_id,event_type,aggregate_id,revision,result) VALUES (?,?,?,?,?,?)')->execute([
                        (int) $installation['id'],
                        $eventId,
                        (string) ($event['event_type'] ?? ''),
                        (string) ($event['aggregate_id'] ?? ''),
                        (int) ($event['revision'] ?? 0),
                        json_encode($result, JSON_THROW_ON_ERROR),
                    ]);
                }
                $results[] = $result;
                $pdo->commit();
            } catch (DomainException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $results[] = ['event_id' => $event['event_id'] ?? null, 'status' => 'rejected', 'error' => $e->getMessage()];
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $pdo->prepare('DELETE FROM alphaledger_idempotency WHERE api_key_id=? AND idempotency_key=? AND response_body IS NULL')->execute([(int) $apiKey['id'], $idempotencyKey]);
                throw $e;
            }
        }
        $response = ['results' => $results];
        $pdo->prepare('UPDATE alphaledger_idempotency SET response_code=200,response_body=? WHERE api_key_id=? AND idempotency_key=?')->execute([json_encode($response, JSON_THROW_ON_ERROR), (int) $apiKey['id'], $idempotencyKey]);
        pa_al_json_response($response);
    }

    pa_al_json_response(['error' => 'not_found'], 404);
} catch (Throwable $e) {
    @error_log('[AlphaLedgerIntegration] ' . $e->getMessage());
    pa_al_json_response(['error' => 'integration_error'], 500);
}
