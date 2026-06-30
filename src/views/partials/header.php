<?php require_once __DIR__ . '/../../config/app.php'; ?>
<?php require_once __DIR__ . '/../../config/db.php'; ?>
<?php require_once __DIR__ . '/../../utils/format.php'; ?>
<?php require_once __DIR__ . '/../../utils/acl.php'; ?>
<?php if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

// Permission-based nav visibility helper
function nav_can(string $permission): bool {
  if (($_SESSION['user']['role'] ?? '') === 'admin') return true;
  $pdo = $GLOBALS['pdo'] ?? null;
  if (!$pdo) {
    // Last-resort load if db isn't global yet
    try {
      require_once __DIR__ . '/../../config/db.php';
      $pdo = $GLOBALS['pdo'] ?? null;
    } catch (Throwable $e) {
      return true; // fail open when DB unavailable
    }
  }
  if (!$pdo) return true;
  return user_can($pdo, (int)($_SESSION['user']['id'] ?? 0), $permission, get_active_org_id());
}
?>
<!doctype html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?php echo htmlspecialchars($appConfig['brand_name'] ?? 'Project Alpha'); ?></title>
  <?php
  $faviconPath = $appConfig['logo_path'] ?? null;
  if ($faviconPath && !empty(trim($faviconPath))): ?>
    <link rel="icon" href="<?php echo htmlspecialchars($faviconPath); ?>">
  <?php else: ?>
    <link rel="icon" type="image/png" href="/assets/favicon-32.png" />
  <?php endif; ?>
  <meta name="csrf-token" content="<?php echo htmlspecialchars($_SESSION['csrf'] ?? ''); ?>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">

  <?php
  // Preload logo for better caching and performance
  $logo = $appConfig['logo_path'] ?? null;
  if ($logo): ?>
    <link rel="preload" href="<?php echo htmlspecialchars($logo); ?>" as="image">
  <?php endif; ?>

  <link rel="stylesheet" href="/assets/styles.css">
  <script src="/assets/navigation.js" defer></script>
  <script src="/assets/item-autocomplete.js" defer></script>
  <script>
    (function() {
      var timer = null;
      function scheduleRefresh() {
        if (timer) clearTimeout(timer);
        timer = setTimeout(function() {
          if (document.visibilityState === 'visible' && !isFormDirty()) {
            location.reload();
          } else {
            scheduleRefresh();
          }
        }, 300000);
      }
      function isFormDirty() {
        var inputs = document.querySelectorAll('input, textarea, select');
        for (var i = 0; i < inputs.length; i++) {
          if (inputs[i].type === 'hidden' || inputs[i].type === 'submit') continue;
          if (inputs[i].value !== inputs[i].defaultValue) return true;
        }
        return false;
      }
      scheduleRefresh();
      document.addEventListener('click', scheduleRefresh);
      document.addEventListener('keypress', scheduleRefresh);
    })();
  </script>
</head>

<body>
  <div class="mobile-topbar">
    <button class="nav-toggle" type="button" aria-label="Open menu" aria-expanded="false" aria-controls="primary-sidebar">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
        <line x1="3" y1="6" x2="21" y2="6"></line>
        <line x1="3" y1="12" x2="21" y2="12"></line>
        <line x1="3" y1="18" x2="21" y2="18"></line>
      </svg>
    </button>
    <a class="topbar-brand" href="<?php echo nav_can('financial.view') ? '/' : '/?page=user-dashboard'; ?>">
      <?php $brandTop = $appConfig['brand_name'] ?? 'Project Alpha';
      $logoTop = $appConfig['logo_path'] ?? null; ?>
      <?php if ($logoTop): ?>
        <img src="<?php echo htmlspecialchars($logoTop); ?>" alt="" />
      <?php endif; ?>
      <span><?php echo htmlspecialchars($brandTop); ?></span>
    </a>
  </div>
  <div class="nav-overlay" aria-hidden="true"></div>
  <header class="site-shell">
    <aside class="side-nav" id="primary-sidebar" role="navigation" aria-label="Primary">
      <div class="nav-inner">
        <a class="brand" href="<?php echo nav_can('financial.view') ? '/' : '/?page=user-dashboard'; ?>">
          <?php $brand = $appConfig['brand_name'] ?? 'Project Alpha';
          $logo = $appConfig['logo_path'] ?? null; ?>
          <?php if ($logo): ?>
            <img src="<?php echo htmlspecialchars($logo); ?>" alt="<?php echo htmlspecialchars($brand); ?>" class="brand-logo" loading="eager" fetchpriority="high" />
          <?php else: ?>
            <img src="/assets/default-logo.png" alt="<?php echo htmlspecialchars($brand); ?>" class="brand-logo" loading="eager" fetchpriority="high" />
          <?php endif; ?>
          <span class="brand-text"><?php echo htmlspecialchars($brand); ?></span>
        </a>

        <nav class="primary-nav">
          <ul>
            <?php $showDashboard = nav_can('financial.view'); ?>
            <?php if ($showDashboard): ?>
            <li>
              <a href="/" data-page="home" class="active">Dashboard</a>
            </li>
            <?php else: ?>
            <li>
              <a href="/?page=user-dashboard" data-page="user-dashboard" class="active">Dashboard</a>
            </li>
            <?php endif; ?>

            <?php if (nav_can('clients.view') || nav_can('clients.create') || nav_can('organizations.view')): ?>
            <li class="nav-section">
              <div class="section-label">Clients</div>
              <ul>
                <?php if (nav_can('clients.view')): ?>
                <li><a href="/?page=client/clients-list" data-page="client/clients-list">List Clients</a></li>
                <?php endif; ?>
                <?php if (nav_can('organizations.view')): ?>
                <li><a href="/?page=organization/organizations-list" data-page="organization/organizations-list">Organizations</a></li>
                <?php endif; ?>
                <?php if (nav_can('clients.view')): ?>
                <li><a href="/?page=client/onboarding" data-page="client/onboarding">Onboarding</a></li>
                <?php endif; ?>
                <?php if (nav_can('clients.create')): ?>
                <li><a href="/?page=client/clients-create" data-page="client/clients-create">Create Clients</a></li>
                <?php endif; ?>
              </ul>
            </li>
            <?php endif; ?>

            <?php if (nav_can('quotes.view') || nav_can('quotes.create')): ?>
            <li class="nav-section">
              <div class="section-label">Quotes</div>
              <ul>
                <?php if (nav_can('quotes.view')): ?>
                <li><a href="/?page=quote/quotes-list" data-page="quote/quotes-list">Quotes</a></li>
                <li><a href="/?page=quote/long-term-quotes-list" data-page="quote/long-term-quotes-list">Long-term Quotes</a></li>
                <li><a href="/?page=quote/on-demand-quotes-list" data-page="quote/on-demand-quotes-list">On-Demand Quotes</a></li>
                <?php endif; ?>
                <?php if (nav_can('quotes.create')): ?>
                <li><a href="/?page=quote/quotes-create" data-page="quote/quotes-create">Create Quote</a></li>
                <?php endif; ?>
              </ul>
            </li>
            <?php endif; ?>

            <?php if (nav_can('contracts.view') || nav_can('contracts.create')): ?>
            <li class="nav-section">
              <div class="section-label">Contracts</div>
              <ul>
                <?php if (nav_can('contracts.view')): ?>
                <li><a href="/?page=contract/contracts-list" data-page="contract/contracts-list">Contracts</a></li>
                <li><a href="/?page=contract/long-term-contracts-list" data-page="contract/long-term-contracts-list">Long-term Contracts</a></li>
                <li><a href="/?page=contract/on-demand-contracts-list" data-page="contract/on-demand-contracts-list">On-Demand Contracts</a></li>
                <?php endif; ?>
                <?php if (nav_can('contracts.create')): ?>
                <li><a href="/?page=contract/contracts-create" data-page="contract/contracts-create">Create Contract</a></li>
                <?php endif; ?>
              </ul>
            </li>
            <?php endif; ?>

            <?php if (nav_can('invoices.view') || nav_can('invoices.create')): ?>
            <li class="nav-section">
              <div class="section-label">Invoices</div>
              <ul>
                <?php if (nav_can('invoices.view')): ?>
                <li><a href="/?page=invoice/invoices-list" data-page="invoice/invoices-list">Invoices</a></li>
                <li><a href="/?page=invoice/recurring-invoices-list" data-page="invoice/recurring-invoices-list">Recurring Invoices</a></li>
                <li><a href="/?page=invoice/on-demand-invoices-list" data-page="invoice/on-demand-invoices-list">On-Demand Invoices</a></li>
                <?php endif; ?>
                <?php if (nav_can('invoices.create')): ?>
                <li><a href="/?page=invoice/invoices-create" data-page="invoice/invoices-create">Create Invoice</a></li>
                <?php endif; ?>
              </ul>
            </li>
            <?php endif; ?>

            <?php if (nav_can('payments.view') || nav_can('invoices.mark_paid')): ?>
            <li class="nav-section">
              <div class="section-label">Payments</div>
              <ul>
                <?php if (nav_can('payments.view')): ?>
                <li><a href="/?page=payments/payments-list" data-page="payments/payments-list">List Payments</a></li>
                <?php endif; ?>
                <?php if (nav_can('invoices.mark_paid')): ?>
                <li><a href="/?page=payments/payments-create" data-page="payments/payments-create">Record Payment</a></li>
                <?php endif; ?>
              </ul>
            </li>
            <?php endif; ?>

            <?php if (nav_can('jobs.view') || nav_can('projects.view') || nav_can('time_tracking.view')): ?>
            <li class="nav-section">
              <div class="section-label">Jobs</div>
              <ul>
                <?php if (nav_can('jobs.view')): ?>
                <li><a href="/?page=jobs/jobs-list" data-page="/jobs/jobs-list">Jobs</a></li>
                <?php endif; ?>
                <?php if (nav_can('projects.view')): ?>
                <li><a href="/?page=project/projects-list" data-page="project/projects-list">Projects</a></li>
                <?php endif; ?>
                <?php if (nav_can('time_tracking.view')): ?>
                <li><a href="/?page=time-tracking" data-page="time-tracking">Time Tracking</a></li>
                <?php endif; ?>
              </ul>
            </li>
            <?php endif; ?>

            <?php if (nav_can('financial.view')): ?>
            <li class="nav-section">
              <div class="section-label">Financial</div>
              <ul>
                <?php if (nav_can('financial.view')): ?>
                <li><a href="/?page=financial/financial-dashboard" data-page="financial/financial-dashboard">Dashboard</a></li>
                <li><a href="/?page=financial/audit" data-page="financial/audit">Audit &amp; Reports</a></li>
                <li><a href="/?page=financial/expenses-list" data-page="financial/expenses-list">Expenses</a></li>
                <li><a href="/?page=financial/forms-list" data-page="financial/forms-list">Forms &amp; Docs</a></li>
                <?php endif; ?>
              </ul>
            </li>
            <?php endif; ?>
          </ul>
        </nav>

        <div class="nav-footer">
          <?php if (nav_can('settings.view')): ?>
          <a class="settings" href="/?page=settings" data-page="settings">Settings</a>
          <?php endif; ?>
          <?php if (!empty($_SESSION['user']['role']) && $_SESSION['user']['role'] === 'admin'): ?>
            <a class="settings" href="/?page=account" data-page="account" style="margin-top:8px;display:block">My Account</a>
            <a class="settings" href="/?page=accounts" data-page="accounts" style="margin-top:8px;display:block">Accounts</a>
          <?php else: ?>
            <a class="settings" href="/?page=account" data-page="account" style="margin-top:8px;display:block">My Account</a>
          <?php endif; ?>
          <a class="settings" href="/?page=logout" data-skip-nav style="margin-top:8px;display:block">Logout</a>
        </div>
      </div>
    </aside>

    <main class="main-content" role="main">
      <?php if (!empty($_SESSION['user']['auth_bypass'])): ?>
        <div style="margin:0 0 14px;padding:10px 12px;border-radius:10px;background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;font-size:13px">
          Development auth bypass is active for this session. Do not expose this environment publicly.
        </div>
      <?php endif; ?>

      <?php if (!empty($_SESSION['two_factor_warning_required'])): ?>
        <div role="dialog" aria-live="polite" aria-label="Two-factor authentication recommended" style="margin:0 0 16px;padding:14px 16px;border-radius:12px;background:#fffbeb;border:1px solid #fbbf24;color:#78350f;box-shadow:0 8px 24px rgba(120,53,15,.08)">
          <div style="display:flex;gap:12px;align-items:flex-start;justify-content:space-between;flex-wrap:wrap">
            <div style="max-width:760px">
              <div style="font-weight:800;margin-bottom:4px">Two-factor authentication is strongly recommended</div>
              <div style="font-size:13px;line-height:1.45">
                Your account has admin or privileged access. 2FA helps protect payments, financial imports, settings, and user management if a password is ever exposed.
              </div>
            </div>
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
              <a href="/?page=2fa-setup" class="btn btn-sm btn-primary">Set up 2FA</a>
              <form method="post" action="/?page=2fa-warning-dismiss" style="margin:0">
                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($_SESSION['csrf'] ?? ''); ?>">
                <input type="hidden" name="return_to" value="<?php echo htmlspecialchars($_SERVER['REQUEST_URI'] ?? '/'); ?>">
                <button type="submit" class="btn btn-sm" style="background:#fff;border:1px solid #f59e0b;color:#78350f">Dismiss</button>
              </form>
            </div>
          </div>
        </div>
      <?php endif; ?>

      <!-- existing page content will be injected here -->
