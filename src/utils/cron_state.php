<?php
// src/utils/cron_state.php
// Shared helpers for recording cron run status and looking up catch-up windows.

function cron_state_last_run(PDO $pdo, string $jobName): ?string {
    try {
        $stmt = $pdo->prepare('SELECT last_run FROM cron_job_runs WHERE job_name = ?');
        $stmt->execute([$jobName]);
        $lastRun = $stmt->fetchColumn();
        return $lastRun ? (string)$lastRun : null;
    } catch (Throwable $e) {
        @error_log("[cron_state] Failed to read last_run for {$jobName}: " . $e->getMessage());
        return null;
    }
}

function cron_state_mark_success(PDO $pdo, string $jobName, ?string $result = null): void {
    try {
        $stmt = $pdo->prepare('
            INSERT INTO cron_job_runs (job_name, last_run, status, completed_at, result, error_message)
            VALUES (?, NOW(), "success", NOW(), ?, NULL)
            ON DUPLICATE KEY UPDATE
                last_run = NOW(),
                status = "success",
                completed_at = NOW(),
                result = VALUES(result),
                error_message = NULL
        ');
        $stmt->execute([$jobName, $result]);
    } catch (Throwable $e) {
        @error_log("[cron_state] Failed to mark success for {$jobName}: " . $e->getMessage());
    }
}

function cron_state_mark_failure(PDO $pdo, string $jobName, Throwable $error): void {
    try {
        $message = substr($error->getMessage(), 0, 1000);
        $stmt = $pdo->prepare('
            INSERT INTO cron_job_runs (job_name, last_run, status, completed_at, error_message)
            VALUES (?, NOW(), "failed", NOW(), ?)
            ON DUPLICATE KEY UPDATE
                last_run = NOW(),
                status = "failed",
                completed_at = NOW(),
                error_message = VALUES(error_message)
        ');
        $stmt->execute([$jobName, $message]);
    } catch (Throwable $e) {
        @error_log("[cron_state] Failed to mark failure for {$jobName}: " . $e->getMessage());
    }
}
