<?php
// src/views/pages/settings/logs.php
// Admin-only audit table + Monolog log file viewer.

if (($_SESSION['user']['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo 'Permission denied';
    exit;
}

require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/csrf.php';

$csrfToken = csrf_token();

// --- Filters / pagination for audit log ---
$pageNum = isset($_GET['page_num']) && is_numeric($_GET['page_num']) ? max(1, (int)$_GET['page_num']) : 1;
$perPage = 50;
$offset = ($pageNum - 1) * $perPage;

$filterUserId = '';
$filterAction = '';
$where = [];
$params = [];

if (!empty($_GET['user_id']) && is_numeric($_GET['user_id'])) {
    $filterUserId = (int)$_GET['user_id'];
    $where[] = 'user_id = ?';
    $params[] = $filterUserId;
}
if (!empty($_GET['action'])) {
    $filterAction = preg_replace('/[^a-zA-Z0-9_.\-]/', '', (string)$_GET['action']);
    if ($filterAction !== '') {
        $where[] = 'action LIKE ?';
        $params[] = '%' . $filterAction . '%';
    }
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$totalRows = 0;
try {
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM system_audit {$whereSql}");
    $countStmt->execute($params);
    $totalRows = (int)$countStmt->fetchColumn();
} catch (Throwable $e) {
    $totalRows = 0;
}

$totalPages = (int)max(1, ceil($totalRows / $perPage));
if ($pageNum > $totalPages) {
    $pageNum = $totalPages;
    $offset = ($pageNum - 1) * $perPage;
}

$auditRows = [];
try {
    $auditStmt = $pdo->prepare("SELECT id, user_id, action, entity_type, entity_id, details, ip_address, user_agent, created_at FROM system_audit {$whereSql} ORDER BY created_at DESC LIMIT ? OFFSET ?");
    $auditStmt->execute(array_merge($params, [$perPage, $offset]));
    $auditRows = $auditStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $auditRows = [];
}

// --- Available log files ---
$logDirs = [
    '/var/www/config/logs/system',
    '/var/www/config/logs/cron',
    __DIR__ . '/../../../../config/logs/system',
    __DIR__ . '/../../../../config/logs/cron',
];
$logFileMap = [];
foreach ($logDirs as $dir) {
    if (!is_dir($dir) || !is_readable($dir)) {
        continue;
    }
    foreach (glob(rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . '*.{log,txt}', GLOB_BRACE) ?: [] as $filePath) {
        if (is_file($filePath) && is_readable($filePath)) {
            $name = basename($filePath);
            if (!isset($logFileMap[$name]) || (filemtime($filePath) ?: 0) > (filemtime($logFileMap[$name]) ?: 0)) {
                $logFileMap[$name] = $filePath;
            }
        }
    }
}
uasort($logFileMap, function (string $a, string $b): int {
    $mtimeCompare = (filemtime($b) ?: 0) <=> (filemtime($a) ?: 0);
    return $mtimeCompare !== 0 ? $mtimeCompare : strnatcasecmp(basename($a), basename($b));
});
$allLogFileMap = $logFileMap;
$visibleLogFileMap = array_slice($allLogFileMap, 0, 5, true);
$logFiles = array_keys($visibleLogFileMap);
$selectedFile = '';
$levelFilter = '';
$logContent = '';
$logError = '';

if (!empty($_GET['file'])) {
    $selectedFile = basename(preg_replace('/[^a-zA-Z0-9_.\-]/', '', (string)$_GET['file']));
    $levelFilter = isset($_GET['level']) ? strtoupper(preg_replace('/[^A-Z]/', '', (string)$_GET['level'])) : '';

    $path = $allLogFileMap[$selectedFile] ?? '';
    if (!str_contains($selectedFile, '..') && !str_contains($selectedFile, '/') && !str_contains($selectedFile, '\\')
        && is_file($path) && is_readable($path)
    ) {
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            $lines = [];
        }
        // Keep last 100 lines matching level filter
        $buffer = [];
        $count = 0;
        for ($i = count($lines) - 1; $i >= 0 && $count < 100; $i--) {
            $line = $lines[$i];
            if ($levelFilter !== '' && stripos($line, '"level":"' . $levelFilter . '"') === false && stripos($line, ' ' . $levelFilter . ' ') === false) {
                continue;
            }
            array_unshift($buffer, $line);
            $count++;
        }
        $logContent = implode("\n", $buffer);
    } else {
        $logError = 'Log file not found or not accessible.';
    }
}

// Helper to produce a query string preserving filters while changing page/file
function logs_page_url(array $overrides): string
{
    $base = '/?page=settings&tab=logs';
    $params = [];
    if (!empty($_GET['user_id']) && is_numeric($_GET['user_id'])) {
        $params['user_id'] = (int)$_GET['user_id'];
    }
    if (!empty($_GET['action'])) {
        $params['action'] = preg_replace('/[^a-zA-Z0-9_.\-]/', '', (string)$_GET['action']);
    }
    if (!empty($_GET['file'])) {
        $params['file'] = basename(preg_replace('/[^a-zA-Z0-9_.\-]/', '', (string)$_GET['file']));
    }
    if (!empty($_GET['level'])) {
        $params['level'] = strtoupper(preg_replace('/[^A-Z]/', '', (string)$_GET['level']));
    }
    $params['page_num'] = $overrides['page_num'] ?? ($_GET['page_num'] ?? 1);
    foreach ($overrides as $k => $v) {
        if ($v === null || $v === '') {
            unset($params[$k]);
        } else {
            $params[$k] = $v;
        }
    }
    foreach ($params as $k => $v) {
        $base .= '&' . urlencode($k) . '=' . urlencode((string)$v);
    }
    return $base;
}

// Common actions for quick dropdown
$commonActions = [];
try {
    $actionStmt = $pdo->query("SELECT DISTINCT action FROM system_audit ORDER BY action LIMIT 200");
    $commonActions = $actionStmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    $commonActions = [];
}
?>
<div style="max-width:1100px">
    <h2 style="margin:0 0 8px 0">Logs</h2>
    <p style="margin:0 0 24px 0;color:var(--muted)">View system audit records and application log files.</p>

    <!-- Audit Log -->
    <fieldset style="border:1px solid #eee;border-radius:8px;padding:16px;margin-bottom:24px">
        <legend style="padding:0 8px;font-weight:600">Audit Log</legend>

        <form method="GET" action="/" style="margin-bottom:16px">
            <input type="hidden" name="page" value="settings">
            <input type="hidden" name="tab" value="logs">
            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrfToken); ?>">
            <div style="display:grid;grid-template-columns:1fr 1fr auto;gap:12px;align-items:end">
                <div>
                    <label style="display:block;font-size:13px;color:var(--muted);margin-bottom:4px">User ID</label>
                    <input type="number" name="user_id" value="<?php echo $filterUserId ? htmlspecialchars((string)$filterUserId) : ''; ?>"
                           placeholder="e.g. 1" min="1" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px">
                </div>
                <div>
                    <label style="display:block;font-size:13px;color:var(--muted);margin-bottom:4px">Action</label>
                    <input type="text" name="action" list="action-list" value="<?php echo htmlspecialchars($filterAction); ?>"
                           placeholder="Filter by action..." style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px">
                    <datalist id="action-list">
                        <?php foreach ($commonActions as $a): ?>
                        <option value="<?php echo htmlspecialchars($a); ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                </div>
                <div>
                    <button type="submit" style="padding:8px 16px;border-radius:6px;border:0;background:var(--nav-accent);color:#fff;font-weight:600;cursor:pointer">Filter</button>
                    <a href="/?page=settings&tab=logs" style="padding:8px 16px;border-radius:6px;border:1px solid #ddd;background:#fff;color:#374151;text-decoration:none;font-size:13px;margin-left:6px">Reset</a>
                </div>
            </div>
        </form>

        <div style="overflow-x:auto">
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
                    <?php foreach ($auditRows as $row): ?>
                    <tr style="border-bottom:1px solid #f1f5f9">
                        <td style="padding:10px"><?php echo (int)$row['id']; ?></td>
                        <td style="padding:10px"><?php echo $row['user_id'] ? (int)$row['user_id'] : '<span style="color:var(--muted)">—</span>'; ?></td>
                        <td style="padding:10px"><?php echo htmlspecialchars($row['action']); ?></td>
                        <td style="padding:10px">
                            <?php if ($row['entity_type']): ?>
                                <?php echo htmlspecialchars($row['entity_type']); ?>:<?php echo (int)$row['entity_id']; ?>
                            <?php else: ?>
                                <span style="color:var(--muted)">—</span>
                            <?php endif; ?>
                        </td>
                        <td style="padding:10px;max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?php echo htmlspecialchars(is_string($row['details']) ? $row['details'] : json_encode($row['details'])); ?>">
                            <?php
                            $details = is_string($row['details']) ? $row['details'] : json_encode($row['details'], JSON_UNESCAPED_UNICODE);
                            echo htmlspecialchars(mb_substr($details, 0, 120) . (mb_strlen($details) > 120 ? '…' : ''));
                            ?>
                        </td>
                        <td style="padding:10px"><?php echo htmlspecialchars($row['ip_address'] ?? ''); ?></td>
                        <td style="padding:10px;white-space:nowrap"><?php echo htmlspecialchars($row['created_at'] ?? ''); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($auditRows)): ?>
                    <tr>
                        <td colspan="7" style="padding:24px;text-align:center;color:var(--muted)">No audit records found.</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <div style="margin-top:16px;display:flex;justify-content:center;align-items:center;gap:8px;flex-wrap:wrap">
            <?php if ($pageNum > 1): ?>
                <a href="<?php echo logs_page_url(['page_num' => $pageNum - 1]); ?>" style="padding:6px 12px;border:1px solid #ddd;border-radius:6px;text-decoration:none;color:#374151">&larr; Prev</a>
            <?php endif; ?>
            <?php for ($p = max(1, $pageNum - 2); $p <= min($totalPages, $pageNum + 2); $p++): ?>
                <?php if ($p === $pageNum): ?>
                    <span style="padding:6px 12px;border-radius:6px;background:var(--nav-accent);color:#fff;font-weight:600"><?php echo $p; ?></span>
                <?php else: ?>
                    <a href="<?php echo logs_page_url(['page_num' => $p]); ?>" style="padding:6px 12px;border:1px solid #ddd;border-radius:6px;text-decoration:none;color:#374151"><?php echo $p; ?></a>
                <?php endif; ?>
            <?php endfor; ?>
            <?php if ($pageNum < $totalPages): ?>
                <a href="<?php echo logs_page_url(['page_num' => $pageNum + 1]); ?>" style="padding:6px 12px;border:1px solid #ddd;border-radius:6px;text-decoration:none;color:#374151">Next &rarr;</a>
            <?php endif; ?>
            <span style="color:var(--muted);font-size:13px;margin-left:8px"><?php echo $totalRows; ?> total</span>
        </div>
        <?php endif; ?>
    </fieldset>

    <!-- Log Files -->
    <fieldset style="border:1px solid #eee;border-radius:8px;padding:16px">
        <legend style="padding:0 8px;font-weight:600">Log Files</legend>

        <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:16px;flex-wrap:wrap">
            <p style="margin:0;color:var(--muted);font-size:13px">Showing the five most recent readable system and cron log files.</p>
            <?php if (!empty($allLogFileMap)): ?>
                <a class="btn btn-sm" data-skip-nav href="/?page=settings/logs-handler&amp;bulk=1">Download All ZIP</a>
            <?php endif; ?>
        </div>

        <div style="overflow-x:auto;margin-bottom:16px">
            <table style="width:100%;border-collapse:collapse;font-size:13px">
                <thead>
                    <tr style="background:#f8fafc">
                        <th style="padding:10px;border-bottom:2px solid #e2e8f0;text-align:left">File</th>
                        <th style="padding:10px;border-bottom:2px solid #e2e8f0;text-align:left">Size</th>
                        <th style="padding:10px;border-bottom:2px solid #e2e8f0;text-align:left">Modified</th>
                        <th style="padding:10px;border-bottom:2px solid #e2e8f0;text-align:left">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logFiles as $file): ?>
                        <?php $logPath = $visibleLogFileMap[$file] ?? ''; ?>
                        <tr style="border-bottom:1px solid #f1f5f9;<?php echo $selectedFile === $file ? 'background:#f8fafc' : ''; ?>">
                            <td style="padding:10px;font-weight:600"><?php echo htmlspecialchars($file); ?></td>
                            <td style="padding:10px;color:var(--muted)"><?php echo $logPath && is_file($logPath) ? number_format((float)filesize($logPath) / 1024, 1) . ' KB' : '-'; ?></td>
                            <td style="padding:10px;color:var(--muted);white-space:nowrap"><?php echo $logPath && is_file($logPath) ? htmlspecialchars(date('Y-m-d H:i:s', filemtime($logPath))) : '-'; ?></td>
                            <td style="padding:10px;white-space:nowrap">
                                <a class="btn btn-sm" data-skip-nav href="/?page=settings&amp;tab=logs&amp;file=<?php echo urlencode($file); ?>">View</a>
                                <a class="btn btn-sm" data-skip-nav href="/?page=settings/logs-handler&amp;file=<?php echo urlencode($file); ?>&amp;download=1">Download</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($logFiles)): ?>
                        <tr>
                            <td colspan="4" style="padding:18px;text-align:center;color:var(--muted)">No log files found yet.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($selectedFile): ?>
        <div style="margin-bottom:12px;display:flex;align-items:center;gap:12px;flex-wrap:wrap">
            <strong><?php echo htmlspecialchars($selectedFile); ?></strong>
            <a class="btn btn-sm" data-skip-nav href="/?page=settings/logs-handler&amp;file=<?php echo urlencode($selectedFile); ?>&amp;download=1">Download</a>
            <form method="GET" action="/" style="display:inline">
                <input type="hidden" name="page" value="settings">
                <input type="hidden" name="tab" value="logs">
                <input type="hidden" name="file" value="<?php echo htmlspecialchars($selectedFile); ?>">
                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrfToken); ?>">
                <label style="font-size:13px;color:var(--muted)">Level:
                    <select name="level" onchange="this.form.submit()" style="padding:6px;border:1px solid #ddd;border-radius:6px;margin-left:4px">
                        <option value="">All</option>
                        <option value="DEBUG" <?php echo $levelFilter === 'DEBUG' ? 'selected' : ''; ?>>DEBUG</option>
                        <option value="INFO" <?php echo $levelFilter === 'INFO' ? 'selected' : ''; ?>>INFO</option>
                        <option value="WARNING" <?php echo $levelFilter === 'WARNING' ? 'selected' : ''; ?>>WARNING</option>
                        <option value="ERROR" <?php echo $levelFilter === 'ERROR' ? 'selected' : ''; ?>>ERROR</option>
                    </select>
                </label>
            </form>
        </div>

        <?php if ($logError): ?>
            <div style="padding:12px;background:#fff1f2;color:#881337;border:1px solid #fca5a5;border-radius:6px"><?php echo htmlspecialchars($logError); ?></div>
        <?php elseif ($logContent === ''): ?>
            <p style="color:var(--muted)">No matching entries in the last 100 lines.</p>
        <?php else: ?>
            <pre style="background:#0f172a;color:#e2e8f0;padding:14px;border-radius:8px;overflow-x:auto;max-height:500px;font-size:12px;line-height:1.5"><?php echo htmlspecialchars($logContent); ?></pre>
        <?php endif; ?>
        <?php else: ?>
            <p style="color:var(--muted)">Select a log file above to view the last 100 lines.</p>
        <?php endif; ?>
    </fieldset>
</div>
