<?php
// src/utils/csrf_sf.php
// Symfony-backed CSRF helper (keeps backward compatibility with the existing 'csrf' field)

use Symfony\Component\Security\Csrf\CsrfTokenManager;
use Symfony\Component\Security\Csrf\TokenStorage\NativeSessionTokenStorage;
use Symfony\Component\Security\Csrf\CsrfToken;

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

if (!class_exists(CsrfTokenManager::class)) {
  // If Symfony is not installed yet (before rebuild), fallback to legacy functions
  require_once __DIR__ . '/csrf.php';
}

function csrf_sf_manager(): ?CsrfTokenManager {
  if (!class_exists(CsrfTokenManager::class)) { return null; }
  static $mgr = null;
  if ($mgr === null) {
    $mgr = new CsrfTokenManager(null, new NativeSessionTokenStorage());
  }
  return $mgr;
}

function csrf_sf_token(string $formId): string {
  $mgr = csrf_sf_manager();
  if ($mgr === null) { return csrf_token(); } // legacy fallback
  $token = $mgr->getToken($formId);
  return (string)$token->getValue();
}

function csrf_sf_is_valid(string $formId, ?string $submitted): bool {
  $mgr = csrf_sf_manager();
  if ($mgr === null) {
    // legacy fallback to existing token
    return is_string($submitted) && !empty($_SESSION['csrf']) && hash_equals((string)$_SESSION['csrf'], $submitted);
  }
  if (!is_string($submitted) || $submitted === '') { return false; }
  return $mgr->isTokenValid(new CsrfToken($formId, $submitted));
}

function csrf_sf_verify_or_redirect(string $formId, string $page, ?string $submitted): void {
  if (!csrf_sf_is_valid($formId, $submitted)) {
    $err = rawurlencode('Invalid request (CSRF)');
    header('Location: /?page=' . rawurlencode($page) . '&error=' . $err);
    exit;
  }
}