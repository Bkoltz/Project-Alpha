<?php
// src/views/pages/account/account-delete.php
// GDPR/CCPA "Right to Erasure" confirmation form.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../utils/csrf.php';

$pageError = $_GET['error'] ?? '';
?>
<section class="account-delete-page">
  <div class="page-header">
    <h1>Delete Your Account</h1>
  </div>

  <div class="card">
    <div class="alert alert-warning" role="alert">
      <strong>WARNING:</strong> This action is permanent and cannot be undone. All your data including clients, quotes, contracts, invoices, payments, and uploaded files will be permanently deleted.
    </div>

    <?php if ($pageError !== ''): ?>
      <div class="alert alert-error" role="alert">
        <?php echo htmlspecialchars((string)$pageError, ENT_QUOTES, 'UTF-8'); ?>
      </div>
    <?php endif; ?>

    <form method="post" action="/?page=account/delete" class="delete-form">
      <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">

      <div class="form-group">
        <label for="password">Enter your password to confirm</label>
        <input type="password" id="password" name="password" required autocomplete="current-password">
      </div>

      <div class="form-group">
        <label for="confirm">Type <strong>DELETE MY ACCOUNT</strong> to confirm</label>
        <input type="text" id="confirm" name="confirm" required autocomplete="off" placeholder="DELETE MY ACCOUNT">
      </div>

      <button type="submit" class="btn btn-danger">Delete My Account</button>
    </form>

    <p class="note">
      Your data will be permanently deleted. Backup copies may persist for up to 14 days. If you only want to export your data instead, use the <a href="/?page=account/data-export">Data Export</a> page.
    </p>
  </div>
</section>

<style>
.account-delete-page {
  max-width: 720px;
}

.account-delete-page .page-header {
  margin-bottom: 24px;
}

.account-delete-page h1 {
  margin: 0;
  font-size: 24px;
  font-weight: 700;
  color: var(--text);
}

.account-delete-page .card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: 24px;
  box-shadow: var(--shadow-sm);
}

.account-delete-page .alert {
  margin: 0 0 20px;
  padding: 12px 16px;
  border-radius: var(--radius-sm);
  font-size: 14px;
}

.account-delete-page .alert-warning {
  background: #fee2e2;
  color: #991b1b;
  border: 1px solid #f87171;
}

.account-delete-page .alert-error {
  background: var(--red-bg);
  color: var(--red-text);
  border: 1px solid #fecaca;
}

.account-delete-page .delete-form {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.account-delete-page .form-group {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.account-delete-page label {
  font-size: 14px;
  font-weight: 600;
  color: var(--text);
}

.account-delete-page input[type="password"],
.account-delete-page input[type="text"] {
  padding: 10px 12px;
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  background: var(--background);
  color: var(--text);
  font-size: 14px;
}

.account-delete-page .btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  padding: 10px 16px;
  min-height: 44px;
  border-radius: var(--radius-sm);
  border: 1px solid transparent;
  font-weight: 600;
  font-size: 14px;
  font-family: inherit;
  cursor: pointer;
  transition: background 0.12s, box-shadow 0.12s, transform 0.08s;
}

.account-delete-page .btn-danger {
  background: #dc2626;
  color: #fff;
}

.account-delete-page .btn-danger:hover {
  background: #b91c1c;
}

.account-delete-page .btn:active {
  transform: translateY(1px);
}

.account-delete-page .note {
  margin: 20px 0 0;
  color: var(--muted);
  font-size: 14px;
  line-height: 1.6;
}

.account-delete-page .note a {
  color: var(--nav-accent);
  text-decoration: underline;
}
</style>
