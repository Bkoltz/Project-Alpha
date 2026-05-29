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
<section>
  <!-- Hero -->
  <div class="hero" style="margin-bottom:24px">
    <div>
      <h1 class="h1">Dashboard</h1>
      <p class="lead">Quick glance at your business: revenue, pipelines, and recent activity.</p>
    </div>
  </div>

  <?php if (isset($db_error) && $db_error): ?>
    <div style="margin:24px 0;padding:16px;border-radius:10px;background:#fef3c7;border:2px solid #f59e0b;color:#92400e">
      <h3 style="margin:0 0 8px">⚠️ Database Not Initialized</h3>
      <p style="margin:0">The database tables haven't been created yet. Please initialize the database with <code>database/init.sql</code> or run the active module files in <code>database/migrations</code>.</p>
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

  <!-- Charts Row -->
  <div style="display:grid;gap:24px;grid-template-columns:1fr;margin-top:24px">
    <div class="dash-panel">
      <h3 style="margin:0 0 12px;font-size:16px">Revenue Trend (6 months)</h3>
      <div style="position:relative;height:220px">
        <canvas id="incomeChart"></canvas>
      </div>
    </div>

    <div class="dash-panel">
      <h3 style="margin:0 0 12px;font-size:16px">Status Breakdown</h3>
      <div class="dash-chart-wrap">
        <canvas id="statusChart"></canvas>
      </div>
    </div>
  </div>

  <!-- Activity + Lists Row -->
  <div class="dash-grid-two" style="margin-top:24px">
    <!-- Recent Payments -->
    <div class="dash-panel">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
        <h3 style="margin:0;font-size:16px">Recent Payments</h3>
        <a href="/?page=payments/payments-list" style="font-size:13px;color:var(--nav-accent)">View all</a>
      </div>
      <?php if (empty($payments_recent)): ?>
        <p style="color:var(--muted);font-size:14px;margin:0">No payments yet.</p>
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

    <!-- Recent Clients -->
    <div class="dash-panel">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
        <h3 style="margin:0;font-size:16px">Recent Clients</h3>
        <a href="/?page=client/clients-list" style="font-size:13px;color:var(--nav-accent)">View all</a>
      </div>
      <?php if (empty($clients_recent)): ?>
        <p style="color:var(--muted);font-size:14px;margin:0">No clients yet.</p>
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

  <!-- User Activity + System Health Row -->
  <div class="dash-grid-two" style="margin-top:24px">
    <!-- User Activity -->
    <div class="dash-panel">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
        <h3 style="margin:0;font-size:16px">User Activity</h3>
        <?php if ($failed_logins_24h > 0): ?>
          <span style="font-size:12px;padding:3px 8px;border-radius:4px;background:#fee2e2;color:#991b1b;font-weight:600"><?php echo $failed_logins_24h; ?> failed logins (24h)</span>
        <?php endif; ?>
      </div>
      <?php if (empty($login_recent)): ?>
        <p style="color:var(--muted);font-size:14px;margin:0">No login activity yet.</p>
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

    <!-- System Health -->
    <div class="dash-panel">
      <h3 style="margin:0 0 12px;font-size:16px">System Health</h3>
      <div class="dash-health">
        <div class="dash-health__item">
          <div class="dash-health__label">PHP</div>
          <div class="dash-health__value"><?php echo htmlspecialchars($php_version); ?></div>
        </div>
        <div class="dash-health__item">
          <div class="dash-health__label">Database</div>
          <div class="dash-health__value" style="color:<?php echo $db_status === 'Connected' ? '#065f46' : '#991b1b'; ?>"><?php echo $db_status; ?></div>
        </div>
        <div class="dash-health__item">
          <div class="dash-health__label">Memory</div>
          <div class="dash-health__value"><?php echo fmtBytes($memory_usage); ?> / <?php echo htmlspecialchars($memory_limit); ?></div>
          <div class="dash-health__bar">
            <div class="dash-health__bar-inner" style="width:<?php
                                                              $pct = 0;
                                                              if ($memory_limit !== 'N/A' && $memory_limit !== '-1') {
                                                                $ml = $memory_limit;
                                                                if (preg_match('/^(\d+)([KMGT]?)$/i', $ml, $m)) {
                                                                  $mult = ['' => 1, 'k' => 1024, 'm' => 1024 ** 2, 'g' => 1024 ** 3, 't' => 1024 ** 4];
                                                                  $max = (int)$m[1] * ($mult[strtolower($m[2])] ?? 1);
                                                                  if ($max > 0) $pct = min(100, round(($memory_peak / $max) * 100));
                                                                }
                                                              }
                                                              echo $pct;
                                                              ?>%;background:<?php echo $pct > 80 ? '#dc2626' : ($pct > 60 ? '#f59e0b' : '#10b981'); ?>"></div>
          </div>
        </div>
        <?php if ($disk_total !== false && $disk_total > 0): ?>
          <div class="dash-health__item">
            <div class="dash-health__label">Disk</div>
            <div class="dash-health__value"><?php echo fmtBytes($disk_free); ?> free / <?php echo fmtBytes($disk_total); ?></div>
            <div class="dash-health__bar">
              <div class="dash-health__bar-inner" style="width:<?php
                                                                $dpct = min(100, round((($disk_total - $disk_free) / $disk_total) * 100));
                                                                echo $dpct;
                                                                ?>%;background:<?php echo $dpct > 85 ? '#dc2626' : ($dpct > 70 ? '#f59e0b' : '#10b981'); ?>"></div>
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
</section>

<!-- Chart.js via CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
  (function() {
    const months = <?php echo json_encode($months); ?>;
    const income = <?php echo json_encode($month_income); ?>;

    // Income line chart
    const ctxIncome = document.getElementById('incomeChart');
    if (ctxIncome) {
      new Chart(ctxIncome, {
        type: 'line',
        data: {
          labels: months,
          datasets: [{
            label: 'Revenue',
            data: income,
            borderColor: '#2ea3d6',
            backgroundColor: 'rgba(46,163,214,0.08)',
            borderWidth: 2,
            pointRadius: 3,
            pointBackgroundColor: '#2ea3d6',
            fill: true,
            tension: 0.3
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: {
              display: false
            }
          },
          scales: {
            y: {
              beginAtZero: true,
              ticks: {
                callback: function(v) {
                  return '$' + v.toLocaleString();
                },
                font: {
                  size: 11
                }
              },
              grid: {
                color: 'rgba(0,0,0,0.04)'
              }
            },
            x: {
              ticks: {
                font: {
                  size: 11
                }
              },
              grid: {
                display: false
              }
            }
          }
        }
      });
    }

    // Status doughnut chart (combined quotes + contracts + invoices)
    const ctxStatus = document.getElementById('statusChart');
    if (ctxStatus) {
      const statusData = {
        labels: ['Pending Quotes', 'Active Contracts', 'Unpaid Invoices', 'Paid Invoices', 'Draft / Other'],
        datasets: [{
          data: [
            <?php echo (int)($quote_status['pending'] ?? 0); ?>,
            <?php echo (int)($contract_status['active'] ?? 0); ?>,
            <?php echo (int)($invoice_status['unpaid'] ?? 0) + (int)($invoice_status['partial'] ?? 0); ?>,
            <?php echo (int)($invoice_status['paid'] ?? 0); ?>,
            <?php echo
            (int)($quote_status['draft'] ?? 0) + (int)($quote_status['approved'] ?? 0) + (int)($quote_status['denied'] ?? 0) + (int)($quote_status['rejected'] ?? 0) + (int)($quote_status['expired'] ?? 0) +
              (int)($contract_status['draft'] ?? 0) + (int)($contract_status['pending'] ?? 0) + (int)($contract_status['paused'] ?? 0) + (int)($contract_status['completed'] ?? 0) + (int)($contract_status['cancelled'] ?? 0) + (int)($contract_status['denied'] ?? 0) + (int)($contract_status['void'] ?? 0) +
              (int)($invoice_status['draft'] ?? 0) + (int)($invoice_status['sent'] ?? 0) + (int)($invoice_status['overdue'] ?? 0) + (int)($invoice_status['cancelled'] ?? 0) + (int)($invoice_status['void'] ?? 0);
            ?>
          ],
          backgroundColor: [
            '#f59e0b',
            '#10b981',
            '#ef4444',
            '#22c55e',
            '#9ca3af'
          ],
          borderWidth: 0,
          hoverOffset: 6
        }]
      };
      new Chart(ctxStatus, {
        type: 'doughnut',
        data: statusData,
        options: {
          responsive: true,
          maintainAspectRatio: false,
          cutout: '60%',
          plugins: {
            legend: {
              position: 'bottom',
              labels: {
                boxWidth: 10,
                usePointStyle: true,
                pointStyle: 'circle',
                font: {
                  size: 11
                },
                padding: 12
              }
            }
          }
        }
      });
    }
  })();
</script>

<style>
  .dash-stats {
    display: grid;
    gap: 16px;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
  }

  .dash-chart-wrap {
    position: relative;
    height: 240px;
    overflow: hidden;
  }

  .dash-card {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 18px;
    border-radius: 12px;
    background: #fff;
    box-shadow: 0 1px 3px rgba(11, 18, 32, 0.06), 0 4px 12px rgba(11, 18, 32, 0.04);
    transition: transform .12s, box-shadow .12s;
  }

  .dash-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(11, 18, 32, 0.08), 0 8px 24px rgba(11, 18, 32, 0.06);
  }

  .dash-card__icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 44px;
    height: 44px;
    border-radius: 10px;
    flex-shrink: 0;
  }

  .dash-card--income .dash-card__icon {
    background: #ecfdf5;
    color: #059669;
  }

  .dash-card--pending .dash-card__icon {
    background: #fffbeb;
    color: #d97706;
  }

  .dash-card--active .dash-card__icon {
    background: #eff6ff;
    color: #2563eb;
  }

  .dash-card--unpaid .dash-card__icon {
    background: #fef2f2;
    color: #dc2626;
  }

  .dash-card--clients .dash-card__icon {
    background: #f5f3ff;
    color: #7c3aed;
  }

  .dash-card--users .dash-card__icon {
    background: #f0f9ff;
    color: #0891b2;
  }

  .dash-card__label {
    color: var(--muted);
    font-size: 13px;
    font-weight: 600;
  }

  .dash-card__value {
    font-weight: 700;
    font-size: 24px;
    line-height: 1.2;
    color: var(--text);
  }

  .dash-grid-two {
    display: grid;
    gap: 24px;
    grid-template-columns: 1fr 1fr;
  }

  @media (max-width: 900px) {
    .dash-grid-two {
      grid-template-columns: 1fr;
    }
  }

  .dash-panel {
    background: #fff;
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 1px 3px rgba(11, 18, 32, 0.06), 0 4px 12px rgba(11, 18, 32, 0.04);
  }

  .dash-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
  }

  .dash-list__item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px 12px;
    border-radius: 8px;
    background: #f9fafb;
    transition: background .1s;
  }

  .dash-list__item:hover {
    background: #f3f4f6;
  }

  .dash-list__title {
    font-weight: 600;
    font-size: 14px;
    color: var(--text);
  }

  .dash-list__meta {
    font-size: 12px;
    color: var(--muted);
    margin-top: 2px;
  }

  .dash-list__time {
    font-size: 12px;
    color: var(--muted);
    white-space: nowrap;
    margin-left: 10px;
  }

  .dash-health {
    display: grid;
    gap: 14px;
  }

  .dash-health__item {
    display: grid;
    gap: 4px;
  }

  .dash-health__label {
    font-size: 12px;
    font-weight: 600;
    color: var(--muted);
    text-transform: uppercase;
    letter-spacing: 0.04em;
  }

  .dash-health__value {
    font-size: 14px;
    font-weight: 600;
    color: var(--text);
  }

  .dash-health__bar {
    height: 6px;
    background: #e5e7eb;
    border-radius: 3px;
    overflow: hidden;
  }

  .dash-health__bar-inner {
    height: 100%;
    border-radius: 3px;
    transition: width .3s;
  }
</style>