<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/migrations/migration_lib.php';
require_once dirname(__DIR__, 2) . '/src/services/NotificationRelayPolicy.php';
require_once dirname(__DIR__, 2) . '/src/services/NotificationRelayQueue.php';

use PHPUnit\Framework\TestCase;

final class NotificationRelayDatabaseTest extends TestCase
{
    private PDO $pdo;
    private int $apiKeyId;

    protected function setUp(): void
    {
        try {
            $this->pdo = migration_connection();
            $this->pdo->query('SELECT 1 FROM notification_relay_queue LIMIT 1');
        } catch (Throwable $error) {
            $this->markTestSkipped('MySQL notification relay backend unavailable: ' . $error->getMessage());
        }
        $prefix = 'relaytest' . bin2hex(random_bytes(4));
        $insert = $this->pdo->prepare(
            'INSERT INTO api_keys (name, key_prefix, key_hash, scopes, allowed_ips)
             VALUES (?, ?, ?, "notifications.enqueue", "192.0.2.10")'
        );
        $insert->execute(['Notification relay test', $prefix, hash('sha256', random_bytes(32))]);
        $this->apiKeyId = (int)$this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo, $this->apiKeyId)) {
            return;
        }
        $ids = $this->pdo->prepare('SELECT id FROM notification_relay_queue WHERE api_key_id = ?');
        $ids->execute([$this->apiKeyId]);
        $queueIds = array_map('intval', $ids->fetchAll(PDO::FETCH_COLUMN));
        if ($queueIds !== []) {
            $placeholders = implode(',', array_fill(0, count($queueIds), '?'));
            $this->pdo->prepare("DELETE FROM system_audit WHERE entity_type = 'notification_relay' AND entity_id IN ({$placeholders})")
                ->execute($queueIds);
        }
        foreach (['notification_relay_events', 'notification_relay_queue', 'notification_relay_rate_buckets', 'notification_relay_key_state'] as $table) {
            $this->pdo->prepare("DELETE FROM {$table} WHERE api_key_id = ?")->execute([$this->apiKeyId]);
        }
        $this->pdo->prepare('DELETE FROM api_usage WHERE api_key_id = ?')->execute([$this->apiKeyId]);
        $this->pdo->prepare('DELETE FROM api_keys WHERE id = ?')->execute([$this->apiKeyId]);
    }

    public function testTransactionalIdempotencyRateBacklogAndLeaseCapLifecycle(): void
    {
        $policy = $this->policy();
        $queue = new NotificationRelayQueue($this->pdo);
        $firstRequest = $this->request($policy, 'event:db-test:0001', 'first');

        $first = $queue->enqueue($this->apiKeyId, $firstRequest, $policy, '192.0.2.10', 'relay-test');
        $duplicate = $queue->enqueue($this->apiKeyId, $firstRequest, $policy, '192.0.2.10', 'relay-test');
        self::assertFalse($first['duplicate']);
        self::assertTrue($duplicate['duplicate']);
        self::assertSame($first['id'], $duplicate['id']);

        try {
            $queue->enqueue(
                $this->apiKeyId,
                $this->request($policy, 'event:db-test:0001', 'changed'),
                $policy,
                '192.0.2.10',
                'relay-test'
            );
            self::fail('Expected conflicting idempotency payload to fail');
        } catch (NotificationRelayRequestException $error) {
            self::assertSame(409, $error->httpStatus);
        }

        $second = $queue->enqueue(
            $this->apiKeyId,
            $this->request($policy, 'event:db-test:0002', 'second'),
            $policy,
            '192.0.2.10',
            'relay-test'
        );
        try {
            $queue->enqueue(
                $this->apiKeyId,
                $this->request($policy, 'event:db-test:0003', 'third'),
                $policy,
                '192.0.2.10',
                'relay-test'
            );
            self::fail('Expected atomic per-key rate limit to fail');
        } catch (NotificationRelayRequestException $error) {
            self::assertSame(429, $error->httpStatus);
        }
        self::assertSame(2, (int)$this->pdo->query(
            'SELECT active_count FROM notification_relay_key_state WHERE api_key_id = ' . $this->apiKeyId
        )->fetchColumn());

        $claimed = $queue->claimNext(30, $policy['limits']['max_attempts']);
        self::assertSame($first['id'], (int)$claimed['id']);
        self::assertTrue($queue->markSent($claimed));

        $claimed = $queue->claimNext(30, $policy['limits']['max_attempts']);
        self::assertSame($second['id'], (int)$claimed['id']);
        $this->pdo->prepare(
            'UPDATE notification_relay_queue
             SET locked_at = DATE_SUB(NOW(), INTERVAL 60 SECOND), attempt_count = ? WHERE id = ?'
        )->execute([$policy['limits']['max_attempts'], $second['id']]);
        self::assertNull($queue->claimNext(30, $policy['limits']['max_attempts']));

        $statuses = $this->pdo->prepare('SELECT id, status FROM notification_relay_queue WHERE api_key_id = ? ORDER BY id');
        $statuses->execute([$this->apiKeyId]);
        self::assertSame(['sent', 'failed'], array_column($statuses->fetchAll(PDO::FETCH_ASSOC), 'status'));
        self::assertSame(0, (int)$this->pdo->query(
            'SELECT active_count FROM notification_relay_key_state WHERE api_key_id = ' . $this->apiKeyId
        )->fetchColumn());
        self::assertGreaterThanOrEqual(7, (int)$this->pdo->query(
            'SELECT COUNT(*) FROM notification_relay_events WHERE api_key_id = ' . $this->apiKeyId
        )->fetchColumn());

        $columns = $this->pdo->query(
            "SELECT COLUMN_NAME FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'notification_relay_queue'"
        )->fetchAll(PDO::FETCH_COLUMN);
        self::assertContains('idempotency_hash', $columns);
        self::assertNotContains('idempotency_key', $columns);
    }

    public function testLoweredAttemptCapDeadLettersDueRetryWithoutAnotherDelivery(): void
    {
        $policy = $this->policy();
        $queue = new NotificationRelayQueue($this->pdo);
        $request = $this->request($policy, 'event:db-test:cap-reduction', 'cap reduction');
        $created = $queue->enqueue($this->apiKeyId, $request, $policy, '192.0.2.10', 'relay-test');
        $this->pdo->prepare(
            'UPDATE notification_relay_queue
             SET status = "retry", attempt_count = 2, next_attempt_at = NOW() WHERE id = ?'
        )->execute([$created['id']]);

        self::assertNull($queue->claimNext(30, 2));
        $row = $this->pdo->query(
            'SELECT status, attempt_count, last_error_code FROM notification_relay_queue WHERE id = ' . (int)$created['id']
        )->fetch(PDO::FETCH_ASSOC);
        self::assertSame('failed', $row['status']);
        self::assertSame(2, (int)$row['attempt_count']);
        self::assertSame('attempt_cap_reduced', $row['last_error_code']);
    }

    public function testBacklogSerializationIsVisibleAcrossTwoDatabaseConnections(): void
    {
        $policy = $this->policy();
        $firstQueue = new NotificationRelayQueue($this->pdo);
        $firstQueue->enqueue(
            $this->apiKeyId,
            $this->request($policy, 'event:db-test:contention-1', 'first connection'),
            $policy,
            '192.0.2.10',
            'relay-test'
        );

        $second = migration_connection();
        $second->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $this->pdo->beginTransaction();
        $lock = $this->pdo->prepare(
            'SELECT active_count FROM notification_relay_key_state WHERE api_key_id = ? FOR UPDATE'
        );
        $lock->execute([$this->apiKeyId]);
        try {
            (new NotificationRelayQueue($second))->enqueue(
                $this->apiKeyId,
                $this->request($policy, 'event:db-test:contention-2', 'second connection'),
                $policy,
                '192.0.2.10',
                'relay-test'
            );
            self::fail('Expected the second connection to wait on the backlog serialization row');
        } catch (PDOException $error) {
            self::assertContains((string)$error->getCode(), ['HY000', '40001']);
        } finally {
            $this->pdo->rollBack();
        }

        $created = (new NotificationRelayQueue($second))->enqueue(
            $this->apiKeyId,
            $this->request($policy, 'event:db-test:contention-2', 'second connection'),
            $policy,
            '192.0.2.10',
            'relay-test'
        );
        self::assertFalse($created['duplicate']);
        self::assertSame(2, (int)$second->query(
            'SELECT active_count FROM notification_relay_key_state WHERE api_key_id = ' . $this->apiKeyId
        )->fetchColumn());
    }

    private function policy(): array
    {
        return NotificationRelayPolicy::validate([
            'version' => 1,
            'recipients' => ['operations' => 'operations@example.invalid'],
            'templates' => [
                'event' => [
                    'subject' => 'Event {{reference}}',
                    'text' => '{{summary}}',
                    'required_variables' => ['reference', 'summary'],
                    'optional_variables' => [],
                ],
            ],
            'actions' => [
                'service.event' => ['templates' => ['event'], 'recipients' => ['operations']],
            ],
            'limits' => [
                'per_key_per_minute' => 2,
                'per_key_recipient_per_hour' => 2,
                'max_active_per_key' => 2,
                'worker_batch_size' => 5,
                'lease_seconds' => 30,
                'retry_delays_seconds' => [1],
                'payload_retention_days' => 30,
                'event_retention_days' => 365,
            ],
        ]);
    }

    private function request(array $policy, string $idempotencyKey, string $summary): array
    {
        return NotificationRelayPolicy::prepareRequest([
            'action' => 'service.event',
            'template' => 'event',
            'recipient' => 'operations',
            'variables' => ['reference' => 'db-test', 'summary' => $summary],
            'idempotency_key' => $idempotencyKey,
        ], $policy);
    }
}
