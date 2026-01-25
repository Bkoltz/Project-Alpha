<?php
// src/controllers/settings/tax-rates-handler.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/csrf.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header('Location: /?page=settings&tab=taxes'); exit;
}

// Manually verify CSRF to avoid redirect issues
$token = $_POST['csrf'] ?? '';
if (empty($_SESSION['csrf']) || !is_string($token) || !hash_equals($_SESSION['csrf'], $token)) {
    header('Location: /?page=settings&tab=taxes&error=' . rawurlencode('Invalid request (CSRF)'));
    exit;
}

$action = $_POST['action'] ?? '';

// Debug logging
@error_log('[tax-rates-handler] Action: ' . $action . ', POST data: ' . print_r($_POST, true));

try {
  if ($action === 'save') {
    $id = (int)($_POST['id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    $country = trim((string)($_POST['country'] ?? '')) ?: 'USA';
    $state = trim((string)($_POST['state'] ?? '')) ?: null;
    $county = trim((string)($_POST['county'] ?? '')) ?: null;
    $rate = (float)($_POST['rate'] ?? 0);
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    $is_default = isset($_POST['is_default']) ? 1 : 0;
    
    // Check if is_default column exists
    $hasDefault = (bool)$pdo->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tax_rates' AND COLUMN_NAME='is_default'")->fetchColumn();
    
    @error_log('[tax-rates-handler] Parsed values - name: ' . $name . ', rate: ' . $rate . ', active: ' . $is_active . ', hasDefault: ' . ($hasDefault ? 'yes' : 'no'));
    
    if ($name === '') throw new Exception('Name required');
    
    // If setting as default, clear all other defaults first (only if column exists)
    if ($is_default && $hasDefault) {
      $pdo->exec('UPDATE tax_rates SET is_default = 0');
    }
    
    if ($id > 0) {
      if ($hasDefault) {
        $st = $pdo->prepare('UPDATE tax_rates SET name=?, country=?, state=?, county=?, rate=?, is_active=?, is_default=? WHERE id=?');
        $st->execute([$name, $country, $state, $county, $rate, $is_active, $is_default, $id]);
      } else {
        $st = $pdo->prepare('UPDATE tax_rates SET name=?, country=?, state=?, county=?, rate=?, is_active=? WHERE id=?');
        $st->execute([$name, $country, $state, $county, $rate, $is_active, $id]);
      }
    } else {
      if ($hasDefault) {
        $st = $pdo->prepare('INSERT INTO tax_rates (name,country,state,county,rate,is_active,is_default) VALUES (?,?,?,?,?,?,?)');
        $st->execute([$name, $country, $state, $county, $rate, $is_active, $is_default]);
      } else {
        $st = $pdo->prepare('INSERT INTO tax_rates (name,country,state,county,rate,is_active) VALUES (?,?,?,?,?,?)');
        $st->execute([$name, $country, $state, $county, $rate, $is_active]);
      }
      @error_log('[tax-rates-handler] INSERT successful, ID: ' . $pdo->lastInsertId());
    }
    header('Location: /?page=settings&tab=taxes&saved=1'); exit;
  } elseif ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
      $st = $pdo->prepare('DELETE FROM tax_rates WHERE id=?');
      $st->execute([$id]);
    }
    header('Location: /?page=settings&tab=taxes&saved=1'); exit;
  }
} catch (Throwable $e) {
  $err = rawurlencode('Tax rates handler error: ' . $e->getMessage());
  header('Location: /?page=settings&tab=taxes&saved=0&error=' . $err);
  exit;
}

header('Location: /?page=settings&tab=taxes'); exit;
