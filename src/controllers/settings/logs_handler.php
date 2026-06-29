<?php
// src/controllers/settings/logs_handler.php
// AJAX / JSON handler for audit row queries and (server-side) log-file reads.
// Strict path whitelisting prevents directory traversal.

if (($_SESSION['user']['role'] ?? '') !== 'admin') {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Permission denied']);
    exit;
}

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/csrf.php';

/**
 * Resolve a requested log filename to a safe, real path under config/logs.
 * Returns null if the request is invalid or attempts traversal.
 */
function allowed_log_path(string $requested): ?string
{
    // Reject path traversal and directory separators
    if (str_contains($requested, '..') || str_contains($requested, '/') || str_contains($requested, '\\') || str_contains($requested, chr(0))) {
        return null;
    }
    // Only allow alphanumeric + underscore + hyphen + dot + .log extension
    if (!preg_match('/^[a-zA-Z0-9_.\-]+\.log$/', $requested)) {
        return null;
    }
    $bases = [
        '/var/www/config/logs/system',
        '/var/www/config/logs/cron',
        __DIR__ . '/../../../config/logs/system',
        __DIR__ . '/../../../config/logs/cron',
    ];
    foreach ($bases as $base) {
        $realBase = realpath($base);
        if ($realBase === false) {
            continue;
        }
        $candidate = $realBase . DIRECTORY_SEPARATOR . $requested;
        $realCandidate = realpath($candidate);
        if ($realCandidate !== false && str_starts_with($realCandidate, $realBase . DIRECTORY_SEPARATOR)) {
            return $realCandidate;
        }
    }
    return null;
}

if (isset($_GET['file'])) {
    $path = allowed_log_path((string)$_GET['file']);
    if ($path === null) {
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode(['error' => 'Invalid log file path']);
        exit;
    }

    if (!is_file($path) || !is_readable($path)) {
        header('Content-Type: application/json');
        http_response_code(404);
        echo json_encode(['error' => 'Log file not found']);
        exit;
    }

    if (!empty($_GET['download'])) {
        header('Content-Type: text/plain; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . basename($path) . '"');
        header('Content-Length: ' . filesize($path));
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }

    header('Content-Type: application/json');

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        $lines = [];
    }

    $levelFilter = isset($_GET['level']) ? strtoupper(preg_replace('/[^A-Z]/', '', (string)$_GET['level'])) : '';

    $output = [];
    $count = 0;
    for ($i = count($lines) - 1; $i >= 0 && $count < 100; $i--) {
        $line = $lines[$i];
        if ($levelFilter !== '' && stripos($line, '"level":"' . $levelFilter . '"') === false && stripos($line, ' ' . $levelFilter . ' ') === false) {
            continue;
        }
        array_unshift($output, $line);
        $count++;
    }

    echo json_encode([
        'file' => basename($path),
        'level' => $levelFilter,
        'lines' => $output,
    ]);
    exit;
}

header('Content-Type: application/json');

// Default: query system_audit rows with optional filters and pagination.
$pageNum = isset($_GET['page_num']) && is_numeric($_GET['page_num']) ? max(1, (int)$_GET['page_num']) : 1;
$perPage = 50;
$offset = ($pageNum - 1) * $perPage;

$where = [];
$params = [];

if (!empty($_GET['user_id']) && is_numeric($_GET['user_id'])) {
    $where[] = 'user_id = ?';
    $params[] = (int)$_GET['user_id'];
}
if (!empty($_GET['action'])) {
    $action = preg_replace('/[^a-zA-Z0-9_.\-]/', '', (string)$_GET['action']);
    if ($action !== '') {
        $where[] = 'action LIKE ?';
        $params[] = '%' . $action . '%';
    }
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$totalRows = 0;
$rows = [];
try {
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM system_audit {$whereSql}");
    $countStmt->execute($params);
    $totalRows = (int)$countStmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT id, user_id, action, entity_type, entity_id, details, ip_address, user_agent, created_at FROM system_audit {$whereSql} ORDER BY created_at DESC LIMIT ? OFFSET ?");
    $stmt->execute(array_merge($params, [$perPage, $offset]));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database query failed']);
    exit;
}

$totalPages = (int)max(1, ceil($totalRows / $perPage));

// Build a simple HTML table for the response
ob_start();
?>
<table style="width:100%;border-collapse:collapse;font-size:13px">
    <thead>
        <tr style="background:#f8fafc">
            <th style="padding:10px;border-bottom:2px solid #e2e8f0;text-align:left">ID</th>
            <th style="padding:10px;border-bottom:2px solid #e2e8f0;text-align:left">User</th>
            <th style="padding:10px;border-bottom:2px solid #e2e8f0;text-align:left">Action</th>
            <th style="padding:10px;border-bottom:2px solid #e2e8f0;text-align:left">Entity</th>
            <th style="padding:10px;border-bottom:2px solid #e2e8f0;text-align:left">Details</th>
            <th style="padding:10px;border-bottom:2px solid #e2e8f0;text-align:left">IP Address</th>
            <th style="padding:10px;border-bottom:2px solid #e2e8f0;text-align:left">Created</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($rows as $row): ?>
        <tr style="border-bottom:1px solid #f1f5f9">
            <td style="padding:10px"><?php echo (int)$row['id']; ?></td>
            <td style="padding:10px"><?php echo $row['user_id'] ? (int)$row['user_id'] : '—'; ?></td>
            <td style="padding:10px"><?php echo htmlspecialchars($row['action']); ?></td>
            <td style="padding:10px"><?php echo $row['entity_type'] ? htmlspecialchars($row['entity_type'] . ':' . $row['entity_id']) : '—'; ?></td>
            <td style="padding:10px;max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                <?php
                $details = is_string($row['details']) ? $row['details'] : json_encode($row['details'], JSON_UNESCAPED_UNICODE);
                echo htmlspecialchars(mb_substr($details, 0, 120) . (mb_strlen($details) > 120 ? '…' : ''));
                ?>
            </td>
            <td style="padding:10px"><?php echo htmlspecialchars($row['ip_address'] ?? ''); ?></td>
            <td style="padding:10px;white-space:nowrap"><?php echo htmlspecialchars($row['created_at'] ?? ''); ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($rows)): ?>
        <tr>
            <td colspan="7" style="padding:24px;text-align:center;color:var(--muted)">No audit records found.</td>
        </tr>
        <?php endif; ?>
    </tbody>
</table>
<?php
$html = ob_get_clean();

echo json_encode([
    'page' => $pageNum,
    'per_page' => $perPage,
    'total' => $totalRows,
    'total_pages' => $totalPages,
    'html' => $html,
]);
exit;
