<?php
// src/views/pages/api-keys-edit.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/api_keys_schema.php';
require_once __DIR__ . '/api_keys_shared.php';

if (!api_keys_require_admin()) {
    return;
}

$id = (int)($_GET['id'] ?? 0);
$key = null;
$schemaError = null;
try {
    pa_ensure_api_keys_schema($pdo);
    if ($id > 0) {
        $stmt = $pdo->prepare('SELECT id, name, key_prefix, scopes, allowed_ips, created_at, last_used_at, revoked_at FROM api_keys WHERE id = ?');
        $stmt->execute([$id]);
        $key = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
} catch (Throwable $e) {
    $schemaError = $e->getMessage();
}

$scopeOptions = api_scope_options_for_form();
$selectedScopes = $key ? api_normalize_scopes($key['scopes'] ?? 'full') : ['full'];
api_keys_render_styles();
?>

<div class="api-key-page">
  <div class="api-key-header">
    <div>
      <h1>Edit API Key</h1>
      <p>Adjust the name, network limits, and ACL scopes for this integration.</p>
    </div>
    <a class="btn" href="/?page=api-keys">Back to API Keys</a>
  </div>

  <?php if ($schemaError): ?>
    <div class="alert alert-danger">API key storage is not ready: <?php echo api_keys_e($schemaError); ?></div>
  <?php elseif (!$key): ?>
    <div class="alert alert-danger">API key not found.</div>
  <?php else: ?>
    <?php if (!empty($_GET['error'])): ?>
      <div class="alert alert-danger"><?php echo api_keys_e($_GET['error']); ?></div>
    <?php endif; ?>
    <?php if (!empty($_GET['updated'])): ?>
      <div class="alert alert-success">API key updated.</div>
    <?php endif; ?>

    <div class="api-key-card">
      <div class="api-key-form-grid" style="margin-bottom:14px">
        <div>
          <span class="api-key-muted">Prefix</span><br>
          <span class="api-key-prefix"><?php echo api_keys_e($key['key_prefix'] ?? ''); ?></span>
        </div>
        <div>
          <span class="api-key-muted">Last used</span><br>
          <?php echo api_keys_e($key['last_used_at'] ?: 'Never'); ?>
        </div>
      </div>

      <?php if (!empty($key['revoked_at'])): ?>
        <div class="alert alert-danger">This API key was revoked on <?php echo api_keys_e($key['revoked_at']); ?> and cannot be edited.</div>
      <?php else: ?>
        <form class="api-key-form" method="post" action="/?page=api-keys-update">
          <input type="hidden" name="csrf" value="<?php echo api_keys_e(csrf_token()); ?>">
          <input type="hidden" name="id" value="<?php echo (int)$key['id']; ?>">
          <input type="hidden" name="return_to" value="edit">
          <div class="api-key-form-grid">
            <div class="api-key-field">
              <label for="api-key-name">Key name</label>
              <input type="text" id="api-key-name" name="name" value="<?php echo api_keys_e($key['name'] ?? ''); ?>" required>
            </div>
            <div class="api-key-field">
              <label for="api-key-ips">Allowed IPs</label>
              <input type="text" id="api-key-ips" name="allowed_ips" value="<?php echo api_keys_e($key['allowed_ips'] ?? ''); ?>" placeholder="Any">
              <small>Leave blank to allow any source IP.</small>
            </div>
          </div>
          <div class="api-key-field">
            <span>API access</span>
            <?php echo api_keys_scope_checkboxes($scopeOptions, $selectedScopes ?: ['full'], 'scopes'); ?>
          </div>
          <div class="api-key-form-actions">
            <button type="submit" class="btn btn-primary">Save Changes</button>
            <a class="btn" href="/?page=api-keys">Cancel</a>
          </div>
        </form>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>
