<?php require_once __DIR__ . '/../../config/app.php'; ?>
<?php require_once __DIR__ . '/../../utils/format.php'; ?>
<!doctype html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?php echo htmlspecialchars($appConfig['brand_name'] ?? 'Project Alpha'); ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assests/styles.css">
</head>

<body>
  <header class="site-shell">
    <aside class="side-nav" role="navigation" aria-label="Primary">
      <div class="nav-inner">
        <a class="brand" href="/">
          <?php $brand = $appConfig['brand_name'] ?? 'Project Alpha';
          $logo = $appConfig['logo_path'] ?? null; ?>
          <?php if ($logo): ?>
            <img src="<?php echo htmlspecialchars($logo); ?>" alt="<?php echo htmlspecialchars($brand); ?>" class="brand-logo" />
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
                <li><a href="/?page=clients-list" data-page="clients-list">List Clients</a></li>
                <li><a href="/?page=clients-create" data-page="clients-create">Create Client</a></li>
              </ul>
            </li>

            <li class="nav-section">
              <div class="section-label">Quotes</div>
              <ul>
                <li><a href="/?page=quotes-list" data-page="quotes-list">List Quotes</a></li>
                <li><a href="/?page=quotes-create" data-page="quotes-create">Create Quote</a></li>
              </ul>
            </li>
            <li class="nav-section">
              <div class="section-label">Contracts</div>
              <ul>
                <li><a href="/?page=contracts-list" data-page="contracts-list">List Contracts</a></li>
                <li><a href="/?page=contracts-create" data-page="contracts-create">Create Contract</a></li>
              </ul>
            </li>
            <li class="nav-section">
              <div class="section-label">Invoices</div>
              <ul>
                <li><a href="/?page=invoices-list" data-page="invoices-list">List Invoices</a></li>
                <li><a href="/?page=invoices-create" data-page="invoices-create">Create Invoice</a></li>
              </ul>
            </li>
            <li class="nav-section">
              <div class="section-label">Payments</div>
              <ul>
                <li><a href="/?page=payments-list" data-page="payments-list">List Payments</a></li>
                <li><a href="/?page=payments-create" data-page="payments-create">Record Payment</a></li>
                <!-- <li><a href="/?page=settings&tab=terms" data-page="settings">Terms & Conditions</a></li> -->
              </ul>
            </li>
            <li class="nav-section">
              <div class="section-label">Projects</div>
              <ul>
                <li><a href="/?page=projects-list" data-page="projects-list">Projects</a></li>
                <li><a href="/?page=calendar" data-page="calendar">Calendar</a></li>
              </ul>
            </li>
          </ul>
        </nav>

        <div class="nav-footer">
          <?php $fromPhone = $appConfig['from_phone'] ?? null; ?>
          <?php if ($fromPhone): ?>
            <a class="phone" href="tel:<?php echo htmlspecialchars($fromPhone); ?>"><?php echo htmlspecialchars(format_phone($fromPhone)); ?></a>
          <?php endif; ?>
          <a class="settings" href="/?page=settings" data-page="settings">Settings</a>
        </div>
      </div>
    </aside>

    <main class="main-content" role="main">
      <!-- existing page content will be injected here -->