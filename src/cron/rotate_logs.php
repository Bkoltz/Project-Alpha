<?php

$roots = ['/var/www/config/logs/system', '/var/www/config/logs/cron'];
$maxBytes = 10 * 1024 * 1024;
$keepPerFile = 5;

foreach ($roots as $root) {
    if (!is_dir($root)) {
        continue;
    }
    foreach (glob($root . '/*.log') ?: [] as $path) {
        if (!is_file($path) || filesize($path) < $maxBytes || preg_match('/\.\d{8}_\d{6}\.log$/', $path)) {
            continue;
        }
        $base = substr($path, 0, -4);
        $rotated = $base . '.' . date('Ymd_His') . '.log';
        if (!rename($path, $rotated)) {
            @error_log('[rotate_logs] Could not rotate ' . $path);
            continue;
        }
        touch($path);
        chmod($path, 0660);

        $archives = glob($base . '.*.log') ?: [];
        usort($archives, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));
        foreach (array_slice($archives, $keepPerFile) as $old) {
            @unlink($old);
        }
    }
}
