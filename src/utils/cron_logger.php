<?php
// src/utils/cron_logger.php
// Unified cron logging that writes to the dedicated cron log directory

/**
 * Log a cron job event to the dedicated cron log directory
 * 
 * @param string $jobName The name of the cron job
 * @param string $message The log message
 * @param array $context Optional context data
 * @param string $level Log level: info, error, warning, debug
 */
function cron_log(string $jobName, string $message, array $context = [], string $level = 'info'): void {
    try {
        $date = new DateTime('now');
        $day = $date->format('Y-m-d');
        $time = $date->format('Y-m-d H:i:s');
        
        // Cron logs live under /config/logs/cron-logs/
        $candidates = [
            '/var/www/config/logs/cron-logs',
            __DIR__ . '/../../config/logs/cron-logs',
            __DIR__ . '/../../logs/cron',
        ];
        
        $logDir = null;
        foreach ($candidates as $path) {
            if (!is_dir($path)) {
                @mkdir($path, 0775, true);
            }
            if (is_dir($path) && is_writable($path)) {
                $logDir = $path;
                break;
            }
        }
        
        if ($logDir === null) {
            // Fallback to stderr
            error_log("[CRON] [$jobName] $message");
            return;
        }
        
        $file = $logDir . DIRECTORY_SEPARATOR . $day . '.log';
        
        // Build context string
        $ctx = '';
        if (!empty($context)) {
            $ctx = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        
        // Format: [timestamp] [cron:jobname] [level] message | context
        $line = sprintf(
            "[%s] [cron:%s] [%s] %s%s\n",
            $time,
            $jobName,
            $level,
            $message,
            $ctx ? (' | ' . $ctx) : ''
        );
        
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
        
    } catch (Throwable $e) {
        // Last resort: write to stderr
        error_log("[CRON] [$jobName] $message");
    }
}

/**
 * Log the start of a cron job
 */
function cron_log_start(string $jobName): void {
    cron_log($jobName, 'Starting cron job', [], 'info');
}

/**
 * Log the end of a cron job
 */
function cron_log_end(string $jobName, array $stats = []): void {
    cron_log($jobName, 'Finished cron job', $stats, 'info');
}

/**
 * Log an error during a cron job
 */
function cron_log_error(string $jobName, string $message, array $context = []): void {
    cron_log($jobName, $message, $context, 'error');
}

/**
 * Log a warning during a cron job
 */
function cron_log_warning(string $jobName, string $message, array $context = []): void {
    cron_log($jobName, $message, $context, 'warning');
}
