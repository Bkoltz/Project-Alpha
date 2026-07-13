<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/src/config/db.php';

$once = in_array('--once', $argv ?? [], true);
$sleep = max(1, min(30, (int) (getenv('WORKER_SLEEP_SECONDS') ?: 5)));

do {
    $job = null;
    try {
        $pdo->exec(
            "DELETE FROM app_sessions WHERE absolute_expires_at<=UTC_TIMESTAMP(6)
             OR last_activity_at<DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 900 SECOND)"
        );

        $pdo->beginTransaction();
        $job = $pdo->query(
            "SELECT * FROM background_jobs
             WHERE queue_name='maintenance' AND state='pending' AND available_at<=UTC_TIMESTAMP(6)
             ORDER BY id LIMIT 1 FOR UPDATE SKIP LOCKED"
        )->fetch(PDO::FETCH_ASSOC);
        if ($job) {
            $pdo->prepare("UPDATE background_jobs SET state='processing',attempts=attempts+1,reserved_at=UTC_TIMESTAMP(6) WHERE id=?")
                ->execute([$job['id']]);
        }
        $pdo->commit();

        if ($job) {
            if ($job['job_type'] !== 'session.cleanup') {
                throw new RuntimeException('Unsupported background job type: ' . $job['job_type']);
            }
            $pdo->prepare("UPDATE background_jobs SET state='completed',completed_at=UTC_TIMESTAMP(6),last_error=NULL WHERE id=?")
                ->execute([$job['id']]);
        }
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (!empty($job['id'])) {
            $pdo->prepare("UPDATE background_jobs SET state='failed',last_error=? WHERE id=?")
                ->execute([mb_substr($error->getMessage(), 0, 2000), $job['id']]);
        }
        fwrite(STDERR, '[worker] ' . $error->getMessage() . PHP_EOL);
        if ($once) {
            exit(1);
        }
    }
    if (!$once) {
        sleep($sleep);
    }
} while (!$once);

fwrite(STDOUT, "Worker maintenance pass complete.\n");
