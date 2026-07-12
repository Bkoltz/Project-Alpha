<?php
// src/views/pages/api-keys.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/api_keys_schema.php';
require_once __DIR__ . '/api_keys_shared.php';

if (!api_keys_require_admin()) {
    return;
}

$keys = [];
$schemaError = null;
$existingColumns = [];
$alphaLedgerBusinessId = null;
$alphaLedgerEnabled = false;
try {
    pa_ensure_api_keys_schema($pdo);
    $existingColumns = pa_api_keys_existing_columns($pdo);
    $stmt = $pdo->query('SELECT id, name, key_prefix, scopes, allowed_ips, created_at, last_used_at, revoked_at FROM api_keys ORDER BY created_at DESC, id DESC');
    $keys = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $alphaLedgerBusinessId = $pdo->query('SELECT business_id FROM pa_integration_identity WHERE singleton=1')->fetchColumn() ?: null;
    $alphaLedgerEnabled = (bool)$pdo->query('SELECT enabled FROM alphaledger_policy WHERE singleton=1')->fetchColumn();
} catch (Throwable $e) {
    $schemaError = $e->getMessage();
}

$scopeOptions = api_scope_options_for_form();
$newKey = $_SESSION['flash_api_key'] ?? null;
unset($_SESSION['flash_api_key']);
api_keys_render_styles();
?>

<div class="api-key-page">
  <div class="api-key-header">
    <div>
      <h1>API Keys</h1>
      <p>Manage external access for agents and integrations.</p>
    </div>
    <a class="btn btn-primary" href="/?page=api-keys-new">New API Key</a>
  </div>

  <?php if ($schemaError): ?>
    <div class="alert alert-danger">API key storage is not ready: <?php echo api_keys_e($schemaError); ?></div>
  <?php endif; ?>
  <?php if (!empty($_GET['error'])): ?>
    <div class="alert alert-danger"><?php echo api_keys_e($_GET['error']); ?></div>
  <?php endif; ?>
  <?php if (!empty($_GET['updated'])): ?>
    <div class="alert alert-success">API key updated.</div>
  <?php endif; ?>
  <?php if (!empty($_GET['revoked'])): ?>
    <div class="alert alert-success">API key revoked.</div>
  <?php endif; ?>
  <?php if ($newKey): ?>
    <div class="alert alert-success">
      <strong>Copy this key now. It will not be shown again.</strong>
      <div class="api-key-secret"><?php echo api_keys_e($newKey); ?></div>
    </div>
  <?php endif; ?>

  <?php if ($alphaLedgerBusinessId): ?>
    <div class="api-key-card">
      <h2>AlphaLedger Connection</h2>
      <p class="api-key-muted">Use this immutable PA business ID in AlphaLedger, with an API key scoped to <strong>AlphaLedger integration</strong>.</p>
      <div class="api-key-secret"><?php echo api_keys_e($alphaLedgerBusinessId); ?></div>
      <div class="api-key-form-actions" style="margin-top:12px"><span class="api-key-badge <?php echo $alphaLedgerEnabled ? '' : 'revoked'; ?>"><?php echo $alphaLedgerEnabled ? 'Synchronization authorized' : 'Synchronization disabled'; ?></span><a class="btn btn-sm" href="/?page=settings&amp;tab=alphaledger">Manage AlphaLedger</a></div>
    </div>
  <?php endif; ?>

  <div class="api-key-card">
    <?php if (!$keys): ?>
      <div class="api-key-empty">No API keys have been created yet.</div>
    <?php else: ?>
      <div class="api-key-table-wrap">
        <table class="api-key-table">
          <thead>
            <tr>
              <th>Name</th>
              <th>Prefix</th>
              <th>Access</th>
              <th>Allowed IPs</th>
              <th>Last Used</th>
              <th>Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($keys as $key): ?>
            <?php
              $id = (int)($key['id'] ?? 0);
              $revoked = !empty($key['revoked_at']);
              $selectedScopes = api_normalize_scopes($key['scopes'] ?? 'full');
            ?>
            <tr>
              <td>
                <strong><?php echo api_keys_e($key['name'] ?? ''); ?></strong>
                <div class="api-key-muted">Created <?php echo api_keys_e($key['created_at'] ?: 'Unknown'); ?></div>
              </td>
              <td><span class="api-key-prefix"><?php echo api_keys_e($key['key_prefix'] ?? ''); ?></span></td>
              <td><?php echo api_keys_e(api_keys_scope_labels($scopeOptions, $selectedScopes ?: ['full'])); ?></td>
              <td><?php echo api_keys_e($key['allowed_ips'] ?: 'Any'); ?></td>
              <td><?php echo api_keys_e($key['last_used_at'] ?: 'Never'); ?></td>
              <td>
                <?php if ($revoked): ?>
                  <span class="api-key-badge revoked">Revoked</span>
                <?php else: ?>
                  <span class="api-key-badge">Active</span>
                <?php endif; ?>
              </td>
              <td>
                <div class="api-key-actions">
                  <?php if (!$revoked): ?>
                    <a class="btn btn-sm" href="/?page=api-keys-edit&amp;id=<?php echo $id; ?>">Edit</a>
                    <form method="post" action="/?page=api-keys-revoke" onsubmit="return confirm('Revoke this API key? Existing integrations using it will stop working.');">
                      <input type="hidden" name="csrf" value="<?php echo api_keys_e(csrf_token()); ?>">
                      <input type="hidden" name="id" value="<?php echo $id; ?>">
                      <button type="submit" class="btn btn-sm btn-danger">Revoke</button>
                    </form>
                  <?php else: ?>
                    <span class="api-key-muted">No actions</span>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>
