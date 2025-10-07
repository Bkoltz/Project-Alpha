<?php
// src/utils/logger.php
// Simple application logger writing to config/uploads/logs/YYYY-MM-DD.log

function app_log(string $category, string $message, array $context = []): void {
    try {
        $date = new DateTime('now');
        $day = $date->format('Y-m-d');
        $time = $date->format('Y-m-d H:i:s');
        // Determine base config path preference: external mount, then project config, then src/uploads
        $candidates = [
            ['/var/www/config/uploads/logs', true],
            [__DIR__ . '/../config/../../config/uploads/logs', false], // resolve to project config/uploads/logs
            [__DIR__ . '/../uploads/logs', false],
        ];
        $logDir = null;
        foreach ($candidates as [$p, $ensure]) {
            $full = realpath(dirname($p)) !== false && strpos($p, '..') === false ? $p : $p; // keep as-is; we will mkdir as needed
            if (!is_dir($full)) {
                @mkdir($full, 0775, true);
            }
            if (is_dir($full) && is_writable($full)) { $logDir = $full; break; }
        }
        if ($logDir === null) { return; }
        $file = $logDir . DIRECTORY_SEPARATOR . $day . '.log';
        $ip = $_SERVER['REMOTE_ADDR'] ?? '-';
        $uid = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : 0;
        $ctx = $context ? json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '';
        $line = sprintf("[%s] [%s] [uid:%s] [ip:%s] %s%s\n", $time, $category, $uid ?: '-', $ip, $message, $ctx ? (' | ' . $ctx) : '');
        @file_put_contents($file, $line, FILE_APPEND);
    } catch (Throwable $e) {
        // swallow logging errors
    }
}
