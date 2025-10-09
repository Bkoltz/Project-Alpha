<?php
// tools/debug_inspect.php - temporary debug helper
require_once __DIR__ . '/../src/config/db.php';
require_once __DIR__ . '/../src/config/app.php';

try {
    $st = $pdo->prepare("SELECT id, email, role, created_at FROM users ORDER BY id ASC LIMIT 5");
    $st->execute();
    $users = $st->fetchAll(PDO::FETCH_ASSOC);
    echo "Users:\n";
    foreach ($users as $u) {
        echo json_encode($u) . "\n";
    }
} catch (Throwable $e) {
    echo "Users query failed: " . $e->getMessage() . "\n";
}

// Print last 50 lines of today's log if exists
$logDirCandidates = [__DIR__ . '/../config/uploads/logs', __DIR__ . '/../src/uploads/logs', '/var/www/config/uploads/logs'];
$logFile = null;
foreach ($logDirCandidates as $cand) {
    $candReal = realpath($cand);
    if ($candReal && is_dir($candReal)) {
        $f = $candReal . DIRECTORY_SEPARATOR . date('Y-m-d') . '.log';
        if (is_file($f)) { $logFile = $f; break; }
    }
}
if ($logFile) {
    echo "\nLog tail ({$logFile}):\n";
    $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $tail = array_slice($lines, -50);
    foreach ($tail as $l) { echo $l . "\n"; }
} else {
    echo "\nNo log file found in candidates.\n";
}

// Show latest password_resets rows
try {
    $st2 = $pdo->prepare("SELECT id, user_id, token, expires_at, used, attempts, created_at FROM password_resets ORDER BY id DESC LIMIT 10");
    $st2->execute();
    $res = $st2->fetchAll(PDO::FETCH_ASSOC);
    echo "\nPassword resets (latest 10):\n";
    foreach ($res as $r) { echo json_encode($r) . "\n"; }
} catch (Throwable $e) {
    echo "password_resets query failed: " . $e->getMessage() . "\n";
}

?>