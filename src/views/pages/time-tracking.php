<?php
// src/views/pages/time-tracking.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../utils/format.php';

$userId = (int)($_SESSION['user']['id'] ?? 0);

function time_tracking_table_exists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->prepare('SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function time_tracking_table_has_column(PDO $pdo, string $table, string $column): bool
{
    try {
        $stmt = $pdo->prepare('SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $stmt->execute([$table, $column]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function time_tracking_fetch_all(PDO $pdo, string $sql, array $params = []): array
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        @error_log('[TimeTracking] Query failed: ' . $e->getMessage());
        return [];
    }
}

// Active timer for this user (started, not ended)
$activeTimer = null;
try {
    $stmt = $pdo->prepare('SELECT id, started_at, description, client_id, project_id FROM time_entries WHERE user_id = ? AND ended_at IS NULL ORDER BY started_at DESC LIMIT 1');
    $stmt->execute([$userId]);
    $activeTimer = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {
    $activeTimer = null;
}

// Time entries list
$hasTimeEntries = time_tracking_table_exists($pdo, 'time_entries');
$hasServiceItemId = $hasTimeEntries && time_tracking_table_has_column($pdo, 'time_entries', 'service_item_id');
$hasTimeCreatedAt = $hasTimeEntries && time_tracking_table_has_column($pdo, 'time_entries', 'created_at');
$itemLibraryReady = time_tracking_table_exists($pdo, 'item_library')
    && time_tracking_table_has_column($pdo, 'item_library', 'id')
    && time_tracking_table_has_column($pdo, 'item_library', 'item_name')
    && time_tracking_table_has_column($pdo, 'item_library', 'unit_price');

$entries = [];
if ($hasTimeEntries) {
    $serviceSelect = ($itemLibraryReady && $hasServiceItemId) ? 'il.item_name AS service_name' : 'NULL AS service_name';
    $serviceJoin = ($itemLibraryReady && $hasServiceItemId) ? ' LEFT JOIN item_library il ON il.id = te.service_item_id' : '';
    $orderBy = $hasTimeCreatedAt ? 'te.created_at DESC' : 'te.started_at DESC';
    $entries = time_tracking_fetch_all(
        $pdo,
        'SELECT te.*, c.name AS client_name, ' . $serviceSelect . ' FROM time_entries te LEFT JOIN clients c ON te.client_id = c.id' . $serviceJoin . ' WHERE te.user_id = ? ORDER BY ' . $orderBy,
        [$userId]
    );
}

$services = [];
if ($itemLibraryReady) {
    $serviceWhere = time_tracking_table_has_column($pdo, 'item_library', 'is_active') ? 'WHERE is_active = 1' : '';
    if (time_tracking_table_has_column($pdo, 'item_library', 'category')) {
        $serviceWhere .= $serviceWhere === '' ? "WHERE category = 'Hourly'" : " AND category = 'Hourly'";
    }
    $services = time_tracking_fetch_all($pdo, "SELECT id, item_name, unit_price FROM item_library {$serviceWhere} ORDER BY item_name ASC");
}

$totalHours = 0.0;
$totalBillableAmount = 0.0;
$totalUnbilledAmount = 0.0;
foreach ($entries as $e) {
    $h = (float)$e['hours'];
    $amount = $h * (float)$e['rate'];
    $totalHours += $h;
    if ($e['billable']) {
        $totalBillableAmount += $amount;
        if (!$e['billed']) {
            $totalUnbilledAmount += $amount;
        }
    }
}

$today = date('Y-m-d');
$timerStartedAttr = $activeTimer ? ' data-timer-started="' . htmlspecialchars($activeTimer['started_at']) . '"' : '';
?>
<section class="finance-dashboard"<?php echo $timerStartedAttr; ?>>
  <div class="finance-page-head">
    <div>
      <p class="finance-eyebrow">Time Tracking</p>
      <h2>Track Time</h2>
      <p class="finance-subtitle">Clock in/out, add manual entries, and manage billable hours.</p>
    </div>
  </div>

  <?php if (!empty($_GET['error'])): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($_GET['error']); ?></div>
  <?php endif; ?>
  <?php if (!empty($_GET['created'])): ?>
    <div class="alert alert-success">Time entry saved.</div>
  <?php endif; ?>
  <?php if (!empty($_GET['deleted'])): ?>
    <div class="alert alert-success">Time entry deleted.</div>
  <?php endif; ?>

  <div class="finance-grid finance-grid--main">
    <div class="finance-panel">
      <div class="finance-panel__head">
        <h3 class="finance-panel__title">Timer</h3>
      </div>
      <div id="timerDisplay" style="font-size:48px;font-weight:800;letter-spacing:2px;text-align:center;margin:12px 0;font-variant-numeric:tabular-nums">00:00:00</div>
      <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap">
        <form method="post" action="/?page=time-tracking/start-timer" style="display:inline">
          <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">
          <button type="submit" id="btnStartTimer" class="btn btn-primary" <?php echo $activeTimer ? 'disabled' : ''; ?>>Start Timer</button>
        </form>
        <?php if ($activeTimer): ?>
          <form method="post" action="/?page=time-tracking/stop-timer" style="display:inline">
            <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">
            <button type="submit" id="btnStopTimer" class="btn btn-danger">Stop Timer</button>
          </form>
        <?php endif; ?>
      </div>
      <div id="activeTimerInfo" style="margin-top:12px;font-size:13px;color:var(--muted);text-align:center;<?php echo $activeTimer ? '' : 'display:none'; ?>">
        Active since <span id="activeTimerStarted"><?php echo $activeTimer ? htmlspecialchars($activeTimer['started_at']) : ''; ?></span>
        <?php if ($activeTimer && $activeTimer['description']): ?> — <?php echo htmlspecialchars($activeTimer['description']); ?><?php endif; ?>
      </div>
    </div>

    <div class="finance-panel">
      <div class="finance-panel__head">
        <h3 class="finance-panel__title">Summary</h3>
      </div>
      <div class="expense-summary" style="grid-template-columns:1fr 1fr 1fr">
        <div class="expense-stat">
          <span>Total Hours</span>
          <strong><?php echo number_format($totalHours, 2); ?></strong>
        </div>
        <div class="expense-stat">
          <span>Total Billable</span>
          <strong><?php echo '$' . number_format($totalBillableAmount, 2); ?></strong>
        </div>
        <div class="expense-stat">
          <span>Total Unbilled</span>
          <strong><?php echo '$' . number_format($totalUnbilledAmount, 2); ?></strong>
        </div>
      </div>
    </div>
  </div>

  <div class="finance-panel mt-24">
    <div class="finance-panel__head">
      <h3 class="finance-panel__title">Manual Entry</h3>
    </div>
    <form id="manualEntryForm" method="post" action="/?page=time-tracking/create">
      <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">
      <fieldset style="border:1px solid #e5e7eb;border-radius:8px;padding:14px;margin:0 0 14px">
        <legend style="padding:0 8px;font-weight:700">Date / Time</legend>
        <div style="margin:0 0 10px;color:var(--muted);font-size:13px">Use start and end time, or enter manual hours. Do not use both.</div>
        <div class="expense-filter-grid" style="grid-template-columns:repeat(4,minmax(140px,1fr))">
          <label>
            <span class="label">Date</span>
            <input type="date" name="entry_date" class="input" value="<?php echo htmlspecialchars($today); ?>" required>
          </label>
          <label>
            <span class="label">Start Time</span>
            <input type="time" name="start_time" class="input">
          </label>
          <label>
            <span class="label">End Time</span>
            <input type="time" name="end_time" class="input">
          </label>
          <label>
            <span class="label">Manual Hours</span>
            <input type="number" step="0.01" min="0" name="hours" class="input" value="" placeholder="e.g. 2.5">
          </label>
        </div>
      </fieldset>

      <fieldset style="border:1px solid #e5e7eb;border-radius:8px;padding:14px;margin:0 0 14px">
        <legend style="padding:0 8px;font-weight:700">Bill To</legend>
        <div class="expense-filter-grid" style="grid-template-columns:repeat(3,minmax(180px,1fr))">
          <label style="position:relative">
            <span class="label">Client</span>
            <input id="clientInput" type="text" placeholder="Type client name..." autocomplete="off" class="input">
            <input id="clientId" type="hidden" name="client_id">
            <div id="clientSuggest" style="position:absolute;z-index:60;left:0;right:0;top:100%;background:#fff;border:1px solid #eee;border-radius:8px;display:none;max-height:200px;overflow:auto"></div>
          </label>
          <label>
            <span class="label">Job ID</span>
            <select name="project_id" id="timeProjectId" class="input" disabled>
              <option value="">Select a client first</option>
            </select>
            <input type="hidden" name="project_code" id="timeProjectCode" value="">
            <div id="timeProjectHelp" style="display:none;margin-top:4px;font-size:12px;color:var(--muted)"></div>
          </label>
          <label>
            <span class="label">Hourly Contract</span>
            <select name="contract_id" id="timeContractId" class="input" disabled>
              <option value="">Select a client first</option>
            </select>
          </label>
          <label>
            <span class="label">Hourly Invoice</span>
            <select name="invoice_id" id="timeInvoiceId" class="input" disabled>
              <option value="">Select a client first</option>
            </select>
          </label>
          <label>
            <span class="label">Hourly Service</span>
            <select name="service_item_id" id="serviceItemId" class="input">
              <option value="">Manual rate</option>
              <?php foreach ($services as $svc): ?>
                <option value="<?php echo (int)$svc['id']; ?>" data-rate="<?php echo htmlspecialchars((string)$svc['unit_price']); ?>"><?php echo htmlspecialchars($svc['item_name']); ?> - $<?php echo number_format((float)$svc['unit_price'], 2); ?>/hr</option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>
            <span class="label">Rate ($)</span>
            <input type="number" step="0.01" min="0" name="rate" class="input" value="0">
          </label>
        </div>
      </fieldset>

      <fieldset style="border:1px solid #e5e7eb;border-radius:8px;padding:14px;margin:0">
        <legend style="padding:0 8px;font-weight:700">Details</legend>
        <div class="field">
          <span class="label">Description</span>
          <textarea name="description" class="input" rows="2" placeholder="What did you work on?" required></textarea>
        </div>
        <div style="margin-top:12px">
          <label style="display:flex;align-items:center;gap:8px">
            <input type="checkbox" name="billable" value="1" checked>
            <span class="label" style="margin-bottom:0">Billable</span>
          </label>
        </div>
      </fieldset>
      <div class="expense-filter-actions" style="margin-top:8px">
        <button type="submit" class="btn btn-primary">Add Time Entry</button>
      </div>
    </form>
  </div>

  <div class="finance-panel mt-24">
    <div class="finance-panel__head">
      <h3 class="finance-panel__title">Time Entries</h3>
    </div>

    <div class="expense-table-wrap">
      <table class="pa-table expense-table">
        <thead>
          <tr>
            <th>Date</th>
            <th>Client</th>
            <th>Job</th>
            <th>Service</th>
            <th>Description</th>
            <th>Hours</th>
            <th>Rate</th>
            <th>Billable</th>
            <th>Billed</th>
            <th style="text-align:right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($entries)): ?>
            <tr><td colspan="10" style="text-align:center;color:var(--muted)">No time entries found.</td></tr>
          <?php else: ?>
            <?php foreach ($entries as $e): ?>
              <tr>
                <td><?php echo htmlspecialchars(substr($e['started_at'], 0, 10)); ?></td>
                <td><?php echo htmlspecialchars($e['client_name'] ?? ''); ?></td>
                <td><?php echo htmlspecialchars($e['project_code'] ?? ''); ?></td>
                <td><?php echo htmlspecialchars($e['service_name'] ?? ''); ?></td>
                <td><?php echo htmlspecialchars($e['description'] ?? ''); ?></td>
                <td><?php echo number_format((float)$e['hours'], 2); ?></td>
                <td><?php echo '$' . number_format((float)$e['rate'], 2); ?></td>
                <td><?php echo $e['billable'] ? 'Yes' : 'No'; ?></td>
                <td>
                  <?php if ($e['billable']): ?>
                    <span class="status-badge <?php echo $e['billed'] ? 'status-paid' : 'status-pending'; ?>"><?php echo $e['billed'] ? 'Billed' : 'Unbilled'; ?></span>
                  <?php else: ?>
                    <span class="status-badge status-inactive">N/A</span>
                  <?php endif; ?>
                </td>
                <td style="text-align:right">
                  <a href="/?page=time-tracking&amp;edit=<?php echo (int)$e['id']; ?>" class="btn btn-sm">Edit</a>
                  <?php if ($e['billable'] && !$e['billed']): ?>
                    <a href="/?page=invoice/invoices-create&amp;time_entry_id=<?php echo (int)$e['id']; ?>" class="btn btn-sm btn-primary">Add to Invoice</a>
                  <?php endif; ?>
                  <form method="post" action="/?page=time-tracking/delete" style="display:inline" class="delete-entry-form">
                    <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">
                    <input type="hidden" name="id" value="<?php echo (int)$e['id']; ?>">
                    <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</section>

<script src="/assets/js/time-tracking.js" defer></script>

<?php
// Inline edit form for ?edit=ID
$editId = (int)($_GET['edit'] ?? 0);
if ($editId > 0) {
    $entry = null;
    foreach ($entries as $e) {
        if ((int)$e['id'] === $editId) {
            $entry = $e;
            break;
        }
    }
    if (!$entry) {
        try {
            $stmt = $pdo->prepare('SELECT te.*, c.name AS client_name FROM time_entries te LEFT JOIN clients c ON te.client_id = c.id WHERE te.id = ? AND te.user_id = ? AND te.billed = 0');
            $stmt->execute([$editId, $userId]);
            $entry = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {
            $entry = null;
        }
    }
    if ($entry) {
        $editStartValue = '';
        $editEndValue = '';
        $editHoursValue = (string)$entry['hours'];
        if (!empty($entry['started_at']) && !empty($entry['ended_at'])) {
            $startTs = strtotime($entry['started_at']);
            $endTs = strtotime($entry['ended_at']);
            if ($startTs && $endTs && $endTs > $startTs) {
                $durationHours = round(($endTs - $startTs) / 3600, 2);
                if (abs($durationHours - (float)$entry['hours']) < 0.01) {
                    $editStartValue = date('H:i', $startTs);
                    $editEndValue = date('H:i', $endTs);
                    $editHoursValue = '';
                }
            }
        }
?>
<section class="finance-dashboard mt-24">
  <div class="finance-panel">
    <div class="finance-panel__head">
      <h3 class="finance-panel__title">Edit Time Entry</h3>
    </div>
    <form id="editEntryForm" method="post" action="/?page=time-tracking/update">
      <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">
      <input type="hidden" name="id" value="<?php echo (int)$entry['id']; ?>">
      <fieldset style="border:1px solid #e5e7eb;border-radius:8px;padding:14px;margin:0 0 14px">
        <legend style="padding:0 8px;font-weight:700">Date / Time</legend>
        <div style="margin:0 0 10px;color:var(--muted);font-size:13px">Use start and end time, or enter manual hours. Do not use both.</div>
        <div class="expense-filter-grid" style="grid-template-columns:repeat(4,minmax(140px,1fr))">
          <label>
            <span class="label">Date</span>
            <input type="date" name="entry_date" class="input" value="<?php echo htmlspecialchars(substr($entry['started_at'], 0, 10)); ?>" required>
          </label>
          <label>
            <span class="label">Start Time</span>
            <input type="time" name="start_time" class="input" value="<?php echo htmlspecialchars($editStartValue); ?>">
          </label>
          <label>
            <span class="label">End Time</span>
            <input type="time" name="end_time" class="input" value="<?php echo htmlspecialchars($editEndValue); ?>">
          </label>
          <label>
            <span class="label">Manual Hours</span>
            <input type="number" step="0.01" min="0" name="hours" class="input" value="<?php echo htmlspecialchars($editHoursValue); ?>" placeholder="e.g. 2.5">
          </label>
        </div>
      </fieldset>

      <fieldset style="border:1px solid #e5e7eb;border-radius:8px;padding:14px;margin:0 0 14px">
        <legend style="padding:0 8px;font-weight:700">Bill To</legend>
        <div class="expense-filter-grid" style="grid-template-columns:repeat(3,minmax(180px,1fr))">
          <label style="position:relative">
            <span class="label">Client</span>
            <input id="editClientInput" type="text" placeholder="Type client name..." autocomplete="off" class="input" value="<?php echo htmlspecialchars($entry['client_name'] ?? ''); ?>">
            <input id="editClientId" type="hidden" name="client_id" value="<?php echo (int)($entry['client_id'] ?? 0); ?>">
            <div id="editClientSuggest" style="position:absolute;z-index:60;left:0;right:0;top:100%;background:#fff;border:1px solid #eee;border-radius:8px;display:none;max-height:200px;overflow:auto"></div>
          </label>
          <label>
            <span class="label">Job ID</span>
            <select name="project_id" id="editTimeProjectId" class="input" data-selected-id="<?php echo (int)($entry['project_id'] ?? 0); ?>" data-selected-code="<?php echo htmlspecialchars((string)($entry['project_code'] ?? '')); ?>" disabled>
              <option value="">Select a client first</option>
            </select>
            <input type="hidden" name="project_code" id="editTimeProjectCode" value="<?php echo htmlspecialchars((string)($entry['project_code'] ?? '')); ?>">
            <div id="editTimeProjectHelp" style="display:none;margin-top:4px;font-size:12px;color:var(--muted)"></div>
          </label>
          <label>
            <span class="label">Hourly Contract</span>
            <select name="contract_id" id="editTimeContractId" class="input" data-selected-id="<?php echo (int)($entry['contract_id'] ?? 0); ?>" disabled>
              <option value="">Select a client first</option>
            </select>
          </label>
          <label>
            <span class="label">Hourly Invoice</span>
            <select name="invoice_id" id="editTimeInvoiceId" class="input" data-selected-id="<?php echo (int)($entry['invoice_id'] ?? 0); ?>" disabled>
              <option value="">Select a client first</option>
            </select>
          </label>
          <label>
            <span class="label">Hourly Service</span>
            <select name="service_item_id" class="input">
              <option value="">Manual rate</option>
              <?php foreach ($services as $svc): ?>
                <option value="<?php echo (int)$svc['id']; ?>" data-rate="<?php echo htmlspecialchars((string)$svc['unit_price']); ?>" <?php echo (int)($entry['service_item_id'] ?? 0) === (int)$svc['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($svc['item_name']); ?> - $<?php echo number_format((float)$svc['unit_price'], 2); ?>/hr</option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>
            <span class="label">Rate ($)</span>
            <input type="number" step="0.01" min="0" name="rate" class="input" value="<?php echo htmlspecialchars((string)$entry['rate']); ?>">
          </label>
        </div>
      </fieldset>

      <fieldset style="border:1px solid #e5e7eb;border-radius:8px;padding:14px;margin:0">
        <legend style="padding:0 8px;font-weight:700">Details</legend>
        <div class="field">
          <span class="label">Description</span>
          <textarea name="description" class="input" rows="2" placeholder="What did you work on?" required><?php echo htmlspecialchars($entry['description'] ?? ''); ?></textarea>
        </div>
        <div style="margin-top:12px">
          <label style="display:flex;align-items:center;gap:8px">
            <input type="checkbox" name="billable" value="1" <?php echo !empty($entry['billable']) ? 'checked' : ''; ?>>
            <span class="label" style="margin-bottom:0">Billable</span>
          </label>
        </div>
      </fieldset>
      <div class="expense-filter-actions" style="margin-top:16px">
        <button type="submit" class="btn btn-primary">Save Changes</button>
        <a href="/?page=time-tracking" class="btn">Cancel</a>
      </div>
    </form>
  </div>
</section>
<?php
    }
}
?>
