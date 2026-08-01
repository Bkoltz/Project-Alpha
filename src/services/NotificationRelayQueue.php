<?php

declare(strict_types=1);

require_once __DIR__ . '/../utils/audit.php';

interface NotificationRelayQueueContract
{
    public function markSent(array $job): bool;
    public function markPermanentFailure(array $job, string $errorCode): bool;
    public function markDeliveryFailure(array $job, array $policy): string;
}

final class NotificationRelayQueue implements NotificationRelayQueueContract
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array{id:int,status:string,duplicate:bool}
     */
    public function enqueue(int $apiKeyId, array $request, array $policy, string $sourceIp, string $userAgent): array
    {
        $this->pdo->beginTransaction();
        try {
            $existing = $this->findByIdempotencyKey($apiKeyId, $request['idempotency_key'], true);
            if ($existing !== null) {
                if (!hash_equals((string)$existing['payload_hash'], (string)$request['payload_hash'])) {
                    throw new NotificationRelayRequestException(
                        'idempotency_key was already used for a different request',
                        409,
                        'idempotency_conflict'
                    );
                }
                $this->recordEvent((int)$existing['id'], $apiKeyId, 'duplicate', (string)$existing['status'], 0, null);
                $this->pdo->commit();
                $this->audit('notification_relay.duplicate', (int)$existing['id'], $apiKeyId, $request, (string)$existing['status']);
                return ['id' => (int)$existing['id'], 'status' => (string)$existing['status'], 'duplicate' => true];
            }

            $this->lockAndCheckActiveLimit($apiKeyId, (int)$policy['limits']['max_active_per_key']);
            $now = time();
            $this->consumeRateBucket(
                $apiKeyId,
                'key_minute',
                hash('sha256', (string)$apiKeyId),
                intdiv($now, 60),
                (int)$policy['limits']['per_key_per_minute']
            );
            $this->consumeRateBucket(
                $apiKeyId,
                'recipient_hour',
                hash('sha256', (string)$request['recipient_email']),
                intdiv($now, 3600),
                (int)$policy['limits']['per_key_recipient_per_hour']
            );

            $insert = $this->pdo->prepare(
                'INSERT INTO notification_relay_queue
                 (api_key_id, action_name, template_name, recipient_alias, recipient_email, recipient_hash,
                  variables_json, idempotency_hash, payload_hash, status, next_attempt_at, source_ip, source_user_agent)
                 VALUES (?, ?, ?, ?, ?, ?, CAST(? AS JSON), ?, ?, "pending", NOW(), ?, ?)'
            );
            $insert->execute([
                $apiKeyId,
                $request['action'],
                $request['template'],
                $request['recipient_alias'],
                $request['recipient_email'],
                hash('sha256', (string)$request['recipient_email']),
                $request['variables_json'],
                hash('sha256', (string)$request['idempotency_key']),
                $request['payload_hash'],
                mb_substr($sourceIp, 0, 45),
                mb_substr($userAgent, 0, 255),
            ]);
            $id = (int)$this->pdo->lastInsertId();
            $this->pdo->prepare(
                'UPDATE notification_relay_key_state SET active_count = active_count + 1, updated_at = NOW()
                 WHERE api_key_id = ?'
            )->execute([$apiKeyId]);
            $this->recordEvent($id, $apiKeyId, 'enqueued', 'pending', 0, null);
            $this->pdo->commit();

            $this->audit('notification_relay.enqueued', $id, $apiKeyId, $request, 'pending');
            return ['id' => $id, 'status' => 'pending', 'duplicate' => false];
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function claimNext(int $leaseSeconds, int $maxAttempts): ?array
    {
        $this->pdo->beginTransaction();
        try {
            $leaseSeconds = max(30, min(3600, $leaseSeconds));
            $maxAttempts = max(1, min(10, $maxAttempts));
            $stale = $this->pdo->query(
                'SELECT id, api_key_id, attempt_count FROM notification_relay_queue
                 WHERE status = "processing"
                   AND locked_at < DATE_SUB(NOW(), INTERVAL ' . $leaseSeconds . ' SECOND)
                 ORDER BY locked_at ASC LIMIT 100 FOR UPDATE SKIP LOCKED'
            )->fetchAll(PDO::FETCH_ASSOC);
            $release = $this->pdo->prepare(
                'UPDATE notification_relay_queue
                 SET status = "retry", locked_at = NULL, lock_token = NULL,
                     last_error_code = "lease_expired", next_attempt_at = NOW()
                 WHERE id = ? AND status = "processing"'
            );
            $deadLetter = $this->pdo->prepare(
                'UPDATE notification_relay_queue
                 SET status = "failed", locked_at = NULL, lock_token = NULL,
                     last_error_code = "lease_expired"
                 WHERE id = ? AND status = "processing"'
            );
            $decrementActive = $this->pdo->prepare(
                'UPDATE notification_relay_key_state
                 SET active_count = GREATEST(active_count - 1, 0), updated_at = NOW()
                 WHERE api_key_id = ?'
            );
            foreach ($stale as $staleJob) {
                $atAttemptCap = (int)$staleJob['attempt_count'] >= $maxAttempts;
                $transition = $atAttemptCap ? $deadLetter : $release;
                $transition->execute([(int)$staleJob['id']]);
                if ($transition->rowCount() === 1) {
                    if ($atAttemptCap) {
                        $decrementActive->execute([(int)$staleJob['api_key_id']]);
                    }
                    $this->recordEvent(
                        (int)$staleJob['id'],
                        (int)$staleJob['api_key_id'],
                        $atAttemptCap ? 'failed' : 'lease_expired',
                        $atAttemptCap ? 'failed' : 'retry',
                        (int)$staleJob['attempt_count'],
                        'lease_expired'
                    );
                }
            }
            // A policy can lower its retry count while rows are already waiting.
            // Reconcile those rows before selecting work so none is delivered beyond
            // the currently configured cap.
            $overCap = $this->pdo->query(
                'SELECT id, api_key_id, attempt_count FROM notification_relay_queue
                 WHERE status IN ("pending", "retry")
                   AND attempt_count >= ' . $maxAttempts . '
                 ORDER BY next_attempt_at ASC, id ASC
                 LIMIT 100 FOR UPDATE SKIP LOCKED'
            )->fetchAll(PDO::FETCH_ASSOC);
            $failOverCap = $this->pdo->prepare(
                'UPDATE notification_relay_queue
                 SET status = "failed", locked_at = NULL, lock_token = NULL,
                     last_error_code = "attempt_cap_reduced"
                 WHERE id = ? AND status IN ("pending", "retry")'
            );
            foreach ($overCap as $overCapJob) {
                $failOverCap->execute([(int)$overCapJob['id']]);
                if ($failOverCap->rowCount() === 1) {
                    $decrementActive->execute([(int)$overCapJob['api_key_id']]);
                    $this->recordEvent(
                        (int)$overCapJob['id'],
                        (int)$overCapJob['api_key_id'],
                        'failed',
                        'failed',
                        (int)$overCapJob['attempt_count'],
                        'attempt_cap_reduced'
                    );
                }
            }
            $select = $this->pdo->query(
                'SELECT * FROM notification_relay_queue
                 WHERE status IN ("pending", "retry") AND next_attempt_at <= NOW()
                   AND attempt_count < ' . $maxAttempts . '
                 ORDER BY next_attempt_at ASC, id ASC
                 LIMIT 1 FOR UPDATE SKIP LOCKED'
            );
            $row = $select->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                $this->pdo->commit();
                return null;
            }
            $lockToken = bin2hex(random_bytes(16));
            $update = $this->pdo->prepare(
                'UPDATE notification_relay_queue
                 SET status = "processing", attempt_count = attempt_count + 1,
                     locked_at = NOW(), lock_token = ?, last_error_code = NULL
                 WHERE id = ? AND status IN ("pending", "retry")'
            );
            $update->execute([$lockToken, (int)$row['id']]);
            if ($update->rowCount() !== 1) {
                $this->pdo->rollBack();
                return null;
            }
            $this->recordEvent(
                (int)$row['id'],
                (int)$row['api_key_id'],
                'claimed',
                'processing',
                (int)$row['attempt_count'] + 1,
                null
            );
            $this->pdo->commit();
            $row['status'] = 'processing';
            $row['attempt_count'] = (int)$row['attempt_count'] + 1;
            $row['lock_token'] = $lockToken;
            return $row;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function markSent(array $job): bool
    {
        return $this->markTerminal($job, 'sent', null, 'notification_relay.sent');
    }

    public function markPermanentFailure(array $job, string $errorCode): bool
    {
        return $this->markTerminal($job, 'failed', $this->safeErrorCode($errorCode), 'notification_relay.failed');
    }

    public function markDeliveryFailure(array $job, array $policy): string
    {
        $attempt = (int)$job['attempt_count'];
        $maxAttempts = (int)$policy['limits']['max_attempts'];
        if ($attempt >= $maxAttempts) {
            $this->markPermanentFailure($job, 'delivery_failed');
            return 'failed';
        }
        $delays = $policy['limits']['retry_delays_seconds'];
        $baseDelay = (int)($delays[max(0, $attempt - 1)] ?? end($delays));
        $jitter = random_int(0, max(1, (int)floor($baseDelay / 5)));
        $delay = $baseDelay + $jitter;
        $this->pdo->beginTransaction();
        try {
            $update = $this->pdo->prepare(
                'UPDATE notification_relay_queue
                 SET status = "retry", next_attempt_at = DATE_ADD(NOW(), INTERVAL ? SECOND),
                     locked_at = NULL, lock_token = NULL, last_error_code = "delivery_failed"
                 WHERE id = ? AND status = "processing" AND lock_token = ?'
            );
            $update->execute([$delay, (int)$job['id'], (string)$job['lock_token']]);
            if ($update->rowCount() === 1) {
                $this->recordEvent(
                    (int)$job['id'],
                    (int)$job['api_key_id'],
                    'retry_scheduled',
                    'retry',
                    (int)$job['attempt_count'],
                    'delivery_failed'
                );
                $this->pdo->commit();
                $this->auditFromJob('notification_relay.retry_scheduled', $job, 'retry', 'delivery_failed');
                return 'retry';
            }
            $this->pdo->rollBack();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        return 'lost_lease';
    }

    public function cleanupRateBuckets(): void
    {
        $this->pdo->exec(
            'DELETE FROM notification_relay_rate_buckets
             WHERE (bucket_type = "key_minute" AND window_key < FLOOR(UNIX_TIMESTAMP() / 60) - 2)
                OR (bucket_type = "recipient_hour" AND window_key < FLOOR(UNIX_TIMESTAMP() / 3600) - 2)'
        );
    }

    public function cleanupRetainedData(int $payloadRetentionDays, int $eventRetentionDays): void
    {
        $payloadRetentionDays = max(1, min(365, $payloadRetentionDays));
        $eventRetentionDays = max(30, min(2555, $eventRetentionDays));
        $this->pdo->exec(
            'DELETE FROM notification_relay_queue
             WHERE status IN ("sent", "failed")
               AND updated_at < DATE_SUB(NOW(), INTERVAL ' . $payloadRetentionDays . ' DAY)'
        );
        $this->pdo->exec(
            'DELETE FROM notification_relay_events
             WHERE created_at < DATE_SUB(NOW(), INTERVAL ' . $eventRetentionDays . ' DAY)'
        );
    }

    public function recordRejected(int $apiKeyId, string $reason): void
    {
        $this->recordEvent(null, $apiKeyId, 'rejected', null, 0, $this->safeErrorCode($reason));
    }

    private function findByIdempotencyKey(int $apiKeyId, string $key, bool $forUpdate): ?array
    {
        $sql = 'SELECT id, payload_hash, status FROM notification_relay_queue
                WHERE api_key_id = ? AND idempotency_hash = ?' . ($forUpdate ? ' FOR UPDATE' : '');
        $statement = $this->pdo->prepare($sql);
        $statement->execute([$apiKeyId, hash('sha256', $key)]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function lockAndCheckActiveLimit(int $apiKeyId, int $limit): void
    {
        $this->pdo->prepare(
            'INSERT INTO notification_relay_key_state (api_key_id, active_count)
             VALUES (?, 0) ON DUPLICATE KEY UPDATE api_key_id = VALUES(api_key_id)'
        )->execute([$apiKeyId]);
        $select = $this->pdo->prepare(
            'SELECT active_count FROM notification_relay_key_state WHERE api_key_id = ? FOR UPDATE'
        );
        $select->execute([$apiKeyId]);
        if ((int)$select->fetchColumn() >= $limit) {
            throw new NotificationRelayRequestException('Notification relay queue limit exceeded', 429, 'queue_limit');
        }
    }

    private function consumeRateBucket(
        int $apiKeyId,
        string $type,
        string $subjectHash,
        int $windowKey,
        int $limit
    ): void {
        $this->pdo->prepare(
            'INSERT INTO notification_relay_rate_buckets
             (api_key_id, bucket_type, subject_hash, window_key, request_count)
             VALUES (?, ?, ?, ?, 0)
             ON DUPLICATE KEY UPDATE api_key_id = VALUES(api_key_id)'
        )->execute([$apiKeyId, $type, $subjectHash, $windowKey]);
        $select = $this->pdo->prepare(
            'SELECT request_count FROM notification_relay_rate_buckets
             WHERE api_key_id = ? AND bucket_type = ? AND subject_hash = ? AND window_key = ? FOR UPDATE'
        );
        $select->execute([$apiKeyId, $type, $subjectHash, $windowKey]);
        if ((int)$select->fetchColumn() >= $limit) {
            throw new NotificationRelayRequestException('Notification relay rate limit exceeded', 429, 'rate_limit');
        }
        $this->pdo->prepare(
            'UPDATE notification_relay_rate_buckets SET request_count = request_count + 1
             WHERE api_key_id = ? AND bucket_type = ? AND subject_hash = ? AND window_key = ?'
        )->execute([$apiKeyId, $type, $subjectHash, $windowKey]);
    }

    private function markTerminal(array $job, string $status, ?string $errorCode, string $auditAction): bool
    {
        $this->pdo->beginTransaction();
        try {
            $update = $this->pdo->prepare(
                'UPDATE notification_relay_queue
                 SET status = ?, sent_at = IF(? = "sent", NOW(), sent_at),
                     locked_at = NULL, lock_token = NULL, last_error_code = ?
                 WHERE id = ? AND status = "processing" AND lock_token = ?'
            );
            $update->execute([
                $status,
                $status,
                $errorCode,
                (int)$job['id'],
                (string)$job['lock_token'],
            ]);
            if ($update->rowCount() !== 1) {
                $this->pdo->rollBack();
                return false;
            }
            $this->pdo->prepare(
                'UPDATE notification_relay_key_state
                 SET active_count = GREATEST(active_count - 1, 0), updated_at = NOW()
                 WHERE api_key_id = ?'
            )->execute([(int)$job['api_key_id']]);
            $this->recordEvent(
                (int)$job['id'],
                (int)$job['api_key_id'],
                $status,
                $status,
                (int)$job['attempt_count'],
                $errorCode
            );
            $this->pdo->commit();
            $this->auditFromJob($auditAction, $job, $status, $errorCode);
            return true;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    private function audit(string $action, int $jobId, int $apiKeyId, array $request, string $status): void
    {
        audit_log($this->pdo, $action, 'notification_relay', $jobId, [
            'api_key_id' => $apiKeyId,
            'action' => $request['action'],
            'template' => $request['template'],
            'recipient_alias' => $request['recipient_alias'],
            'recipient_hash' => hash('sha256', (string)$request['recipient_email']),
            'payload_hash' => $request['payload_hash'],
            'idempotency_hash' => hash('sha256', (string)$request['idempotency_key']),
            'status' => $status,
        ], null);
    }

    private function auditFromJob(string $action, array $job, string $status, ?string $errorCode): void
    {
        audit_log($this->pdo, $action, 'notification_relay', (int)$job['id'], [
            'api_key_id' => (int)$job['api_key_id'],
            'action' => (string)$job['action_name'],
            'template' => (string)$job['template_name'],
            'recipient_alias' => (string)$job['recipient_alias'],
            'recipient_hash' => (string)$job['recipient_hash'],
            'payload_hash' => (string)$job['payload_hash'],
            'attempt_count' => (int)$job['attempt_count'],
            'status' => $status,
            'error_code' => $errorCode,
        ], null);
    }

    private function recordEvent(
        ?int $jobId,
        int $apiKeyId,
        string $eventType,
        ?string $status,
        int $attemptCount,
        ?string $errorCode
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO notification_relay_events
             (queue_id, queue_reference, api_key_id, event_type, status, attempt_count, error_code)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $jobId,
            $jobId,
            $apiKeyId,
            mb_substr($eventType, 0, 64),
            $status !== null ? mb_substr($status, 0, 32) : null,
            max(0, $attemptCount),
            $errorCode !== null ? $this->safeErrorCode($errorCode) : null,
        ]);
    }

    private function safeErrorCode(string $errorCode): string
    {
        $errorCode = strtolower(trim($errorCode));
        return preg_match('/^[a-z][a-z0-9_]{0,63}$/', $errorCode) ? $errorCode : 'internal_error';
    }
}
