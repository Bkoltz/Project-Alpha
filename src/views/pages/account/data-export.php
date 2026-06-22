<?php
// src/views/pages/account/data-export.php
// GDPR/CCPA "Right to Access" download page.
// Requires authentication (routed view). Uses the app's standard layout.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../utils/csrf.php';

$pageError = $_GET['error'] ?? '';
?>
<section class="data-export-page">
  <div class="page-header">
    <h1>Export Your Data (GDPR/CCPA Right to Access)</h1>
  </div>

  <div class="card">
    <p class="description">
      You can download all data associated with your account in JSON format. This includes your account information, organizations, clients, quotes, contracts, invoices, payments, and audit history. This right is guaranteed under GDPR (Article 15) and CCPA.
    </p>

    <?php if ($pageError !== ''): ?>
      <div class="alert alert-error" role="alert">
        <?php echo htmlspecialchars((string)$pageError, ENT_QUOTES, 'UTF-8'); ?>
      </div>
    <?php endif; ?>

    <form method="post" action="/?page=account/data-export" class="export-form">
      <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">

      <button type="submit" class="btn btn-primary">
        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
          <polyline points="7 10 12 15 17 10"></polyline>
          <line x1="12" y1="15" x2="12" y2="3"></line>
        </svg>
        Download My Data as JSON
      </button>
    </form>
  </div>
</section>

<style>
.data-export-page {
  max-width: 720px;
}

.data-export-page .page-header {
  margin-bottom: 24px;
}

.data-export-page h1 {
  margin: 0;
  font-size: 24px;
  font-weight: 700;
  color: var(--text);
}

.data-export-page .card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: 24px;
  box-shadow: var(--shadow-sm);
}

.data-export-page .description {
  margin: 0 0 20px;
  color: var(--muted);
  font-size: 15px;
  line-height: 1.6;
}

.data-export-page .export-form {
  display: flex;
  gap: 12px;
  flex-wrap: wrap;
}

.data-export-page .btn {
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

.data-export-page .btn-primary {
  background: var(--nav-accent);
  color: #fff;
}

.data-export-page .btn-primary:hover {
  background: #2391c2;
}

.data-export-page .btn:active {
  transform: translateY(1px);
}

.data-export-page .alert {
  margin: 0 0 16px;
  padding: 12px 16px;
  border-radius: var(--radius-sm);
  font-size: 14px;
}

.data-export-page .alert-error {
  background: var(--red-bg);
  color: var(--red-text);
  border: 1px solid #fecaca;
}
</style>
