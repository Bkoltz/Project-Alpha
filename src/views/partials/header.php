<?php require_once __DIR__ . '/../../config/app.php'; ?>
<?php require_once __DIR__ . '/../../utils/format.php'; ?>
<?php if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); } ?>
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
  <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 48 48'%3E%3Cdefs%3E%3ClinearGradient id='g' x1='0' x2='1'%3E%3Cstop offset='0%25' stop-color='%2306b6d4'/%3E%3Cstop offset='100%25' stop-color='%2338bdf8'/%3E%3C/linearGradient%3E%3C/defs%3E%3Crect x='4' y='4' width='40' height='40' rx='8' fill='url(%23g)'/%3E%3Cpath d='M10 26c7-2 12-9 17-9 4 0 7 3 11 3' stroke='%23fff' stroke-width='2' fill='none'/%3E%3Ccircle cx='36' cy='20' r='2' fill='%23fff'/%3E%3C/svg%3E">
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
  <script src="/assets/invoice-edit.js" defer></script>
</head>

<body>
  <header class="site-shell">
    <aside class="side-nav" role="navigation" aria-label="Primary">
      <div class="nav-inner">
        <a class="brand" href="/">
          <?php $brand = $appConfig['brand_name'] ?? 'Project Alpha';
          $logo = $appConfig['logo_path'] ?? null; ?>
          <?php if ($logo): ?>
            <img src="<?php echo htmlspecialchars($logo); ?>" alt="<?php echo htmlspecialchars($brand); ?>" class="brand-logo" loading="eager" fetchpriority="high" />
          <?php else: ?>
            <svg class="brand-logo" viewBox="0 0 48 48" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
              <defs>
                <linearGradient id="g" x1="0" x2="1">
                  <stop offset="0%" stop-color="var(--nav-accent)" />
                  <stop offset="100%" stop-color="#38bdf8" />
                </linearGradient>
              </defs>
              <rect x="4" y="4" width="40" height="40" rx="8" fill="url(#g)" />
              <path d="M10 26c7-2 12-9 17-9 4 0 7 3 11 3" stroke="#fff" stroke-width="2" fill="none" />
              <circle cx="36" cy="20" r="2" fill="#fff" />
            </svg>
          <?php endif; ?>
          <span class="brand-text"><?php echo htmlspecialchars($brand); ?></span>
        </a>

        <nav class="primary-nav">
          <ul>
            <li>
              <a href="/" data-page="home" class="active">Dashboard</a>
            </li>

            <li class="nav-section">
              <div class="section-label">Clients</div>
              <ul>
                <li><a href="/?page=client/clients-list" data-page="client/clients-list">List Clients</a></li>
                <li><a href="/?page=client/clients-create" data-page="client/clients-create">Create Client</a></li>
              </ul>
            </li>

            <li class="nav-section">
              <div class="section-label">Quotes</div>
              <ul>
                <li><a href="/?page=quote/quotes-list" data-page="quote/quotes-list">Quotes</a></li>
                <li><a href="/?page=quote/long-term-quotes-list" data-page="quote/long-term-quotes-list">Long-term Quotes</a></li>
                <li><a href="/?page=quote/quotes-create" data-page="quote/quotes-create">Create Quote</a></li>
              </ul>
            </li>
            <li class="nav-section">
              <div class="section-label">Contracts</div>
              <ul>
                <li><a href="/?page=contract/contracts-list" data-page="contract/contracts-list">Contracts</a></li>
                <li><a href="/?page=contract/long-term-contracts-list" data-page="contract/long-term-contracts-list">Long-term Contracts</a></li>
                <li><a href="/?page=contract/contracts-create" data-page="contract/contracts-create">Create Contract</a></li>
              </ul>
            </li>
            <li class="nav-section">
              <div class="section-label">Invoices</div>
              <ul>
                <li><a href="/?page=invoice/invoices-list" data-page="invoice/invoices-list">Invoices</a></li>
                <li><a href="/?page=invoice/recurring-invoices-list" data-page="invoice/recurring-invoices-list">Recurring Invoices</a></li>
                <li><a href="/?page=invoice/invoices-create" data-page="invoice/invoices-create">Create Invoice</a></li>
              </ul>
            </li>
            <li class="nav-section">
              <div class="section-label">Payments</div>
              <ul>
                <li><a href="/?page=payments/payments-list" data-page="payments/payments-list">List Payments</a></li>
                <li><a href="/?page=payments/payments-create" data-page="payments/payments-create">Record Payment</a></li>
                <!-- <li><a href="/?page=settings&tab=terms" data-page="settings">Terms & Conditions</a></li> -->
              </ul>
            </li>
            <li class="nav-section">
              <div class="section-label">Projects</div>
              <ul>
                <li><a href="/?page=projects-list" data-page="projects-list">Projects</a></li>
              </ul>
            </li>
            <li class="nav-section">
              <div class="section-label">Financial</div>
              <ul>
                <li><a href="/?page=financial/financial-dashboard" data-page="financial/financial-dashboard">Financial Dashboard</a></li>
                <li><a href="/?page=financial/audit" data-page="financial/audit">Audit</a></li>
              </ul>
            </li>
          </ul>
        </nav>

        <div class="nav-footer">
          <!-- <?php $fromPhone = $appConfig['from_phone'] ?? null; ?>
          <?php if ($fromPhone): ?>
            <a class="phone" href="tel:<?php echo htmlspecialchars($fromPhone); ?>"><?php echo htmlspecialchars(format_phone($fromPhone)); ?></a>
          <?php endif; ?> -->
          <a class="settings" href="/?page=settings" data-page="settings">Settings</a>
          <a class="settings" href="/?page=logout" style="margin-top:8px;display:block">Logout</a>
        </div>
      </div>
    </aside>

    <main class="main-content" role="main">
      <!-- existing page content will be injected here -->