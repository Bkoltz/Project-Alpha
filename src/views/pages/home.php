<?php
require_once __DIR__ . '/../../config/db.php';

// ------------------------------------------------------------------
// Data queries
// ------------------------------------------------------------------
try {
  // Core stats
  $pending_quotes     = (int)$pdo->query("SELECT COUNT(*) FROM quotes WHERE status='pending'")->fetchColumn();
  $active_contracts   = (int)$pdo->query("SELECT COUNT(*) FROM contracts WHERE status IN ('draft','active')")->fetchColumn();
  $unpaid_invoices    = (int)$pdo->query("SELECT COUNT(*) FROM invoices WHERE status IN ('unpaid','partial')")->fetchColumn();
  $income_30          = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE created_at >= NOW() - INTERVAL 30 DAY AND status='succeeded'")->fetchColumn();
  $income_90          = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE created_at >= NOW() - INTERVAL 90 DAY AND status='succeeded'")->fetchColumn();
  $total_clients      = (int)$pdo->query("SELECT COUNT(*) FROM clients WHERE archived=0 AND deleted_at IS NULL")->fetchColumn();
  $total_users        = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE is_disabled=0")->fetchColumn();

  // Charts data — monthly income last 6 months
  $income_monthly = $pdo->query("
    SELECT DATE_FORMAT(created_at, '%Y-%m') AS month,
           COALESCE(SUM(amount),0) AS total
    FROM payments
    WHERE created_at >= DATE_FORMAT(NOW() - INTERVAL 5 MONTH, '%Y-%m-01')
      AND status='succeeded'
    GROUP BY month
    ORDER BY month
  ")->fetchAll(PDO::FETCH_ASSOC);

  // Status breakdowns
  $quote_status = $pdo->query("SELECT status, COUNT(*) AS cnt FROM quotes GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
  $contract_status = $pdo->query("SELECT status, COUNT(*) AS cnt FROM contracts GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
  $invoice_status = $pdo->query("SELECT status, COUNT(*) AS cnt FROM invoices GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);

  // Recent lists
  $clients_recent = $pdo->query("SELECT id,name,created_at FROM clients WHERE deleted_at IS NULL ORDER BY created_at DESC LIMIT 6")->fetchAll();
  $payments_recent = $pdo->query("
    SELECT p.id, p.amount, p.created_at, p.payment_method, c.name AS client_name
    FROM payments p
    LEFT JOIN clients c ON c.id=p.client_id
    WHERE p.status='succeeded'
    ORDER BY p.created_at DESC LIMIT 6
  ")->fetchAll();

  // User activity
  $login_recent = $pdo->query("
    SELECT ip, attempted_at, email,
           (SELECT email FROM users WHERE users.email = login_attempts.email LIMIT 1) AS known_user
    FROM login_attempts
    ORDER BY attempted_at DESC LIMIT 8
  ")->fetchAll();
  $failed_logins_24h = (int)$pdo->query("
    SELECT COUNT(*) FROM login_attempts
    WHERE attempted_at >= NOW() - INTERVAL 24 HOUR
      AND email IS NOT NULL
  ")->fetchColumn();

  // System health
  $db_status = 'Connected';
  $php_version = PHP_VERSION;
  $memory_usage = memory_get_usage(true);
  $memory_peak  = memory_get_peak_usage(true);
  $memory_limit = ini_get('memory_limit');
  $disk_free = @disk_free_space(__DIR__);
  $disk_total = @disk_total_space(__DIR__);
  $uptime = '';
  if (function_exists('shell_exec') && stripos(PHP_OS, 'win') === false) {
    $uptime_raw = @shell_exec('uptime -p 2>/dev/null');
    if ($uptime_raw) $uptime = trim($uptime_raw);
  }
} catch (PDOException $e) {
  $db_error = true;
  $pending_quotes = $active_contracts = $unpaid_invoices = $total_clients = $total_users = 0;
  $income_30 = $income_90 = 0;
  $income_monthly = [];
  $quote_status = $contract_status = $invoice_status = [];
  $clients_recent = $payments_recent = $login_recent = [];
  $failed_logins_24h = 0;
  $db_status = 'Disconnected';
  $php_version = PHP_VERSION;
  $memory_usage = $memory_peak = 0;
  $memory_limit = 'N/A';
  $disk_free = $disk_total = false;
  $uptime = '';
}

// Build chart-ready arrays
$months = [];
$month_income = [];
$now = new DateTime('first day of this month');
for ($i = 5; $i >= 0; $i--) {
  $d = clone $now;
  $d->modify("-{$i} month");
  $key = $d->format('Y-m');
  $months[] = $d->format('M Y');
  $found = 0.0;
  foreach ($income_monthly as $row) {
    if ($row['month'] === $key) {
      $found = (float)$row['total'];
      break;
    }
  }
  $month_income[] = $found;
}

function fmtBytes($size)
{
  if ($size <= 0) return '0 B';
  $units = ['B', 'KB', 'MB', 'GB', 'TB'];
  $u = (int) floor(log($size, 1024));
  $u = max(0, min($u, count($units) - 1));
  return round($size / pow(1024, $u), 1) . ' ' . $units[$u];
}
?>

<?php
// ------------------------------------------------------------------
// Presentation helpers (read-only; no business logic)
// ------------------------------------------------------------------

// Daily income (last 30 days) for the trend chart — daily deltas, not cumulative
$daily_labels = [];
$daily_values = [];
try {
  $income_daily_rows = $pdo->query("
    SELECT DATE(created_at) AS d, COALESCE(SUM(amount),0) AS total
    FROM payments
    WHERE created_at >= CURDATE() - INTERVAL 29 DAY
      AND status='succeeded'
    GROUP BY d
    ORDER BY d
  ")->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Throwable $e) {
  $income_daily_rows = [];
}
for ($i = 29; $i >= 0; $i--) {
  $d = new DateTime("-{$i} days");
  $key = $d->format('Y-m-d');
  $daily_labels[] = $d->format('M j');
  $daily_values[] = (float)($income_daily_rows[$key] ?? 0.0);
}

/**
 * Render an inline SVG line chart with dot markers. Dependency-free.
 */
function svg_line_chart(array $labels, array $values, int $w = 720, int $h = 220): string
{
  $n = count($values);
  if ($n < 2) return '<p class="dash-empty">Not enough data yet.</p>';
  $padL = 46; $padR = 12; $padT = 12; $padB = 26;
  $cw = $w - $padL - $padR;
  $ch = $h - $padT - $padB;
  $max = max($values); if ($max <= 0) $max = 1;
  // round max up to a friendly number
  $pow = pow(10, floor(log10($max)));
  $max = ceil($max / $pow) * $pow;

  $pts = [];
  foreach ($values as $i => $v) {
    $x = $padL + ($cw * $i / ($n - 1));
    $y = $padT + $ch - ($ch * $v / $max);
    $pts[] = [round($x, 1), round($y, 1)];
  }
  $poly = implode(' ', array_map(fn($p) => $p[0] . ',' . $p[1], $pts));
  $areaPts = $poly . ' ' . $pts[$n-1][0] . ',' . ($padT + $ch) . ' ' . $pts[0][0] . ',' . ($padT + $ch);

  $svg  = '<svg class="dash-svg-chart" viewBox="0 0 ' . $w . ' ' . $h . '" role="img" aria-label="Revenue trend">';
  // horizontal grid lines + y labels
  for ($g = 0; $g <= 4; $g++) {
    $gy = round($padT + $ch - ($ch * $g / 4), 1);
    $gv = $max * $g / 4;
    $svg .= '<line class="grid-line" x1="' . $padL . '" y1="' . $gy . '" x2="' . ($w - $padR) . '" y2="' . $gy . '"/>';
    $svg .= '<text class="axis-label" x="' . ($padL - 6) . '" y="' . ($gy + 3) . '" text-anchor="end">$' . number_format($gv, 0) . '</text>';
  }
  // x labels (about 6 evenly spaced)
  $step = max(1, (int)floor($n / 6));
  for ($i = 0; $i < $n; $i += $step) {
    $svg .= '<text class="axis-label" x="' . $pts[$i][0] . '" y="' . ($h - 8) . '" text-anchor="middle">' . htmlspecialchars($labels[$i]) . '</text>';
  }
  $svg .= '<polygon class="data-area" points="' . $areaPts . '"/>';
  $svg .= '<polyline class="data-line" points="' . $poly . '"/>';
  foreach ($pts as $i => $p) {
    $svg .= '<circle class="data-dot" cx="' . $p[0] . '" cy="' . $p[1] . '" r="3"><title>' . htmlspecialchars($labels[$i]) . ': $' . number_format($values[$i], 2) . '</title></circle>';
  }
  $svg .= '</svg>';
  return $svg;
}

// Status breakdown rows for the horizontal bars
$status_rows = [
  ['Pending Quotes',   (int)($quote_status['pending'] ?? 0),  '#f59e0b'],
  ['Active Contracts', (int)($contract_status['active'] ?? 0), '#10b981'],
  ['Unpaid Invoices',  (int)($invoice_status['unpaid'] ?? 0) + (int)($invoice_status['partial'] ?? 0), '#ef4444'],
  ['Paid Invoices',    (int)($invoice_status['paid'] ?? 0),   '#22c55e'],
  ['Draft / Other',
    (int)($quote_status['draft'] ?? 0) + (int)($quote_status['approved'] ?? 0) + (int)($quote_status['denied'] ?? 0) + (int)($quote_status['rejected'] ?? 0) + (int)($quote_status['expired'] ?? 0) +
    (int)($contract_status['draft'] ?? 0) + (int)($contract_status['pending'] ?? 0) + (int)($contract_status['paused'] ?? 0) + (int)($contract_status['completed'] ?? 0) + (int)($contract_status['cancelled'] ?? 0) + (int)($contract_status['denied'] ?? 0) + (int)($contract_status['void'] ?? 0) +
    (int)($invoice_status['draft'] ?? 0) + (int)($invoice_status['sent'] ?? 0) + (int)($invoice_status['overdue'] ?? 0) + (int)($invoice_status['cancelled'] ?? 0) + (int)($invoice_status['void'] ?? 0),
    '#9ca3af'],
];
$status_max = max(1, max(array_column($status_rows, 1)));

// Memory / disk bar math (moved out of markup)
$mem_pct = 0;
if ($memory_limit !== 'N/A' && $memory_limit !== '-1' && preg_match('/^(\d+)([KMGT]?)$/i', (string)$memory_limit, $m)) {
  $mult = ['' => 1, 'k' => 1024, 'm' => 1024 ** 2, 'g' => 1024 ** 3, 't' => 1024 ** 4];
  $mx = (int)$m[1] * ($mult[strtolower($m[2])] ?? 1);
  if ($mx > 0) $mem_pct = min(100, (int)round(($memory_peak / $mx) * 100));
}
$mem_color = $mem_pct > 80 ? '#dc2626' : ($mem_pct > 60 ? '#f59e0b' : '#10b981');
$disk_pct = 0; $disk_color = '#10b981';
if ($disk_total !== false && $disk_total > 0) {
  $disk_pct = min(100, (int)round((($disk_total - $disk_free) / $disk_total) * 100));
  $disk_color = $disk_pct > 85 ? '#dc2626' : ($disk_pct > 70 ? '#f59e0b' : '#10b981');
}
?>
<section>
  <!-- Hero -->
  <div class="hero">
    <div>
      <h1 class="h1">Dashboard</h1>
      <p class="lead">Quick glance at your business: revenue, pipelines, and recent activity.</p>
    </div>
    <div class="hero-meta">
      <?php echo date('l, F j, Y'); ?><br>
      Income (90d): <strong>$<?php echo number_format($income_90, 2); ?></strong>
    </div>
  </div>

  <?php if (isset($db_error) && $db_error): ?>
    <div class="alert alert-warning">
      <strong>Database Not Initialized</strong> — the database tables haven't been created yet.
      Initialize with <code>database/init.sql</code> or run the active module files in <code>database/migrations</code>.
    </div>
  <?php endif; ?>

  <!-- Stat Cards -->
  <div class="dash-stats">
    <article class="dash-card dash-card--income">
      <div class="dash-card__icon">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <line x1="12" y1="1" x2="12" y2="23"></line>
          <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path>
        </svg>
      </div>
      <div>
        <div class="dash-card__label">Income (30d)</div>
        <div class="dash-card__value">$<?php echo number_format($income_30, 2); ?></div>
      </div>
    </article>

    <article class="dash-card dash-card--pending">
      <div class="dash-card__icon">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
          <polyline points="14 2 14 8 20 8"></polyline>
          <line x1="12" y1="18" x2="12" y2="12"></line>
          <line x1="9" y1="15" x2="15" y2="15"></line>
        </svg>
      </div>
      <div>
        <div class="dash-card__label">Pending Quotes</div>
        <div class="dash-card__value"><?php echo $pending_quotes; ?></div>
      </div>
    </article>

    <article class="dash-card dash-card--active">
      <div class="dash-card__icon">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M20 7h-9"></path>
          <path d="M14 17H5"></path>
          <circle cx="17" cy="17" r="3"></circle>
          <circle cx="7" cy="7" r="3"></circle>
        </svg>
      </div>
      <div>
        <div class="dash-card__label">Active Contracts</div>
        <div class="dash-card__value"><?php echo $active_contracts; ?></div>
      </div>
    </article>

    <article class="dash-card dash-card--unpaid">
      <div class="dash-card__icon">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect>
          <line x1="1" y1="10" x2="23" y2="10"></line>
        </svg>
      </div>
      <div>
        <div class="dash-card__label">Unpaid Invoices</div>
        <div class="dash-card__value"><?php echo $unpaid_invoices; ?></div>
      </div>
    </article>

    <article class="dash-card dash-card--clients">
      <div class="dash-card__icon">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
          <circle cx="9" cy="7" r="4"></circle>
          <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
          <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
        </svg>
      </div>
      <div>
        <div class="dash-card__label">Total Clients</div>
        <div class="dash-card__value"><?php echo $total_clients; ?></div>
      </div>
    </article>

    <article class="dash-card dash-card--users">
      <div class="dash-card__icon">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
          <circle cx="12" cy="7" r="4"></circle>
        </svg>
      </div>
      <div>
        <div class="dash-card__label">Active Users</div>
        <div class="dash-card__value"><?php echo $total_users; ?></div>
      </div>
    </article>
  </div>

  <!-- Two-column main layout -->
  <div class="dash-cols">
    <!-- LEFT column: revenue + activity lists -->
    <div class="dash-col">
      <div class="dash-panel">
        <div class="dash-panel__head">
          <h3 class="dash-panel__title">Revenue — daily (30 days)</h3>
          <a class="dash-panel__link" href="/?page=financial/financial-dashboard">Financial dashboard</a>
        </div>
        <?php echo svg_line_chart($daily_labels, $daily_values); ?>
      </div>

      <div class="dash-grid-two">
        <div class="dash-panel">
          <div class="dash-panel__head">
            <h3 class="dash-panel__title">Recent Payments</h3>
            <a class="dash-panel__link" href="/?page=payments/payments-list">View all</a>
          </div>
          <?php if (empty($payments_recent)): ?>
            <p class="dash-empty">No payments yet.</p>
          <?php else: ?>
            <div class="dash-list">
              <?php foreach ($payments_recent as $p): ?>
                <div class="dash-list__item">
                  <div class="dash-list__left">
                    <div class="dash-list__title">$<?php echo number_format((float)$p['amount'], 2); ?></div>
                    <div class="dash-list__meta"><?php echo htmlspecialchars($p['client_name'] ?? '—'); ?> · <?php echo htmlspecialchars($p['payment_method']); ?></div>
                  </div>
                  <div class="dash-list__time"><?php echo date('M j', strtotime($p['created_at'])); ?></div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

        <div class="dash-panel">
          <div class="dash-panel__head">
            <h3 class="dash-panel__title">Recent Clients</h3>
            <a class="dash-panel__link" href="/?page=client/clients-list">View all</a>
          </div>
          <?php if (empty($clients_recent)): ?>
            <p class="dash-empty">No clients yet.</p>
          <?php else: ?>
            <div class="dash-list">
              <?php foreach ($clients_recent as $c): ?>
                <div class="dash-list__item">
                  <div class="dash-list__left">
                    <div class="dash-list__title"><?php echo htmlspecialchars($c['name']); ?></div>
                  </div>
                  <div class="dash-list__time"><?php echo date('M j', strtotime($c['created_at'])); ?></div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- RIGHT column: pipeline status, user activity, system health -->
    <div class="dash-col">
      <div class="dash-panel">
        <div class="dash-panel__head">
          <h3 class="dash-panel__title">Pipeline Status</h3>
        </div>
        <div class="dash-bars">
          <?php foreach ($status_rows as [$label, $count, $color]): ?>
            <div class="dash-bar__row">
              <div class="dash-bar__label"><?php echo htmlspecialchars($label); ?></div>
              <div class="dash-bar__track">
                <div class="dash-bar__fill" style="width:<?php echo round($count / $status_max * 100); ?>%;background:<?php echo $color; ?>"></div>
              </div>
              <div class="dash-bar__count"><?php echo $count; ?></div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="dash-panel">
        <div class="dash-panel__head">
          <h3 class="dash-panel__title">User Activity</h3>
          <?php if ($failed_logins_24h > 0): ?>
            <span class="badge badge-red"><?php echo $failed_logins_24h; ?> failed logins (24h)</span>
          <?php endif; ?>
        </div>
        <?php if (empty($login_recent)): ?>
          <p class="dash-empty">No login activity yet.</p>
        <?php else: ?>
          <div class="dash-list">
            <?php foreach ($login_recent as $l): ?>
              <div class="dash-list__item">
                <div class="dash-list__left">
                  <div class="dash-list__title"><?php echo htmlspecialchars($l['email'] ?? 'Unknown'); ?></div>
                  <div class="dash-list__meta">IP <?php echo htmlspecialchars($l['ip']); ?></div>
                </div>
                <div class="dash-list__time"><?php echo date('M j g:i A', strtotime($l['attempted_at'])); ?></div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <div class="dash-panel">
        <div class="dash-panel__head">
          <h3 class="dash-panel__title">System Health</h3>
        </div>
        <div class="dash-health">
          <div class="dash-health__item">
            <div class="dash-health__label">PHP</div>
            <div class="dash-health__value"><?php echo htmlspecialchars($php_version); ?></div>
          </div>
          <div class="dash-health__item">
            <div class="dash-health__label">Database</div>
            <div class="dash-health__value <?php echo $db_status === 'Connected' ? 'dash-health__value--ok' : 'dash-health__value--bad'; ?>"><?php echo $db_status; ?></div>
          </div>
          <div class="dash-health__item">
            <div class="dash-health__label">Memory</div>
            <div class="dash-health__value"><?php echo fmtBytes($memory_usage); ?> / <?php echo htmlspecialchars($memory_limit); ?></div>
            <div class="dash-health__bar">
              <div class="dash-health__bar-inner" style="width:<?php echo $mem_pct; ?>%;background:<?php echo $mem_color; ?>"></div>
            </div>
          </div>
          <?php if ($disk_total !== false && $disk_total > 0): ?>
            <div class="dash-health__item">
              <div class="dash-health__label">Disk</div>
              <div class="dash-health__value"><?php echo fmtBytes($disk_free); ?> free / <?php echo fmtBytes($disk_total); ?></div>
              <div class="dash-health__bar">
                <div class="dash-health__bar-inner" style="width:<?php echo $disk_pct; ?>%;background:<?php echo $disk_color; ?>"></div>
              </div>
            </div>
          <?php endif; ?>
          <?php if ($uptime): ?>
            <div class="dash-health__item">
              <div class="dash-health__label">Uptime</div>
              <div class="dash-health__value"><?php echo htmlspecialchars($uptime); ?></div>
            </div>
          <?php endif; ?>
          <div class="dash-health__item">
            <div class="dash-health__label">Income (90d)</div>
            <div class="dash-health__value">$<?php echo number_format($income_90, 2); ?></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>
