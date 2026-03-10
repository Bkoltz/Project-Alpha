<?php require_once __DIR__ . '/../../config/app.php'; ?>
<?php require_once __DIR__ . '/../../utils/format.php'; ?>
<?php require_once __DIR__ . '/../../config/db.php'; ?>
<?php require_once __DIR__ . '/../../utils/document_fields.php'; ?>
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
  <script src="/assets/item-autocomplete.js" defer></script>
</head>

<body>
  <header class="site-shell">
    <aside class="side-nav" role="navigation" aria-label="Primary">
      <div class="nav-inner">
        <?php require_once __DIR__ . "/navigation_bar.php" ?>
    </aside>

    <main class="main-content" role="main">
      <!-- existing page content will be injected here -->