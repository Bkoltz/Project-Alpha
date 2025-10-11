<?php
// src/views/partials/auth_header.php
require_once __DIR__ . '/../../config/app.php';
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?php echo htmlspecialchars($appConfig['brand_name'] ?? 'Project Alpha'); ?> · Auth</title>
  <?php if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); } ?>
  <?php if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(32)); } ?>
  <meta name="csrf-token" content="<?php echo htmlspecialchars($_SESSION['csrf']); ?>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
  
  <?php 
  // Preload logo for better caching and performance
  $brand = $appConfig['brand_name'] ?? 'Project Alpha'; $logo = $appConfig['logo_path'] ?? null;
  if ($logo): ?>
  <link rel="preload" href="<?php echo htmlspecialchars($logo); ?>" as="image">
  <?php endif; ?>
  
  <link rel="stylesheet" href="/assets/styles.css">
  <style>
    .auth-topbar{display:flex;align-items:center;gap:12px;padding:12px 16px;border-bottom:1px solid #eee;background:#fff}
    .auth-brand{display:flex;align-items:center;gap:10px;text-decoration:none;color:inherit}
    .auth-logo{height:36px;width:auto;object-fit:contain;border-radius:6px;background:#fff}
    .auth-wrap{max-width:420px;margin:48px auto;padding:24px;border-radius:12px;background:#fff;box-shadow:0 6px 24px rgba(11,18,32,0.08)}
  </style>
</head>
<body>
  <div class="auth-topbar">
    <a class="auth-brand" href="/">
      <?php if ($logo): ?>
        <img src="<?php echo htmlspecialchars($logo); ?>" alt="<?php echo htmlspecialchars($brand); ?>" class="auth-logo" loading="eager" fetchpriority="high" />
      <?php else: ?>
        <svg class="auth-logo" viewBox="0 0 48 48" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
          <defs>
            <linearGradient id="g" x1="0" x2="1"><stop offset="0%" stop-color="var(--nav-accent)" /><stop offset="100%" stop-color="#38bdf8" /></linearGradient>
          </defs>
          <rect x="4" y="4" width="40" height="40" rx="8" fill="url(#g)" />
          <path d="M10 26c7-2 12-9 17-9 4 0 7 3 11 3" stroke="#fff" stroke-width="2" fill="none" />
          <circle cx="36" cy="20" r="2" fill="#fff" />
        </svg>
      <?php endif; ?>
      <span style="font-weight:700;font-size:18px"><?php echo htmlspecialchars($brand); ?></span>
    </a>
  </div>
