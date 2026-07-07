<?php
// src/views/pages/api-keys-new.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/api_keys_schema.php';
require_once __DIR__ . '/api_keys_shared.php';

if (!api_keys_require_admin()) {
    return;
}

$schemaError = null;
try {
    pa_ensure_api_keys_schema($pdo);
} catch (Throwable $e) {
    $schemaError = $e->getMessage();
}

$scopeOptions = api_scope_options_for_form();
api_keys_render_styles();
?>

<div class="api-key-page">
  <div class="api-key-header">
    <div>
      <h1>New API Key</h1>
      <p>Create a key for an external integration and choose its access.</p>
    </div>
    <a class="btn" href="/?page=api-keys">Back to API Keys</a>
  </div>

  <?php if ($schemaError): ?>
    <div class="alert alert-danger">API key storage is not ready: <?php echo api_keys_e($schemaError); ?></div>
  <?php endif; ?>
  <?php if (!empty($_GET['error'])): ?>
    <div class="alert alert-danger"><?php echo api_keys_e($_GET['error']); ?></div>
  <?php endif; ?>

  <div class="api-key-card">
    <form class="api-key-form" method="post" action="/?page=api-keys-create">
      <input type="hidden" name="csrf" value="<?php echo api_keys_e(csrf_token()); ?>">
      <div class="api-key-form-grid">
        <div class="api-key-field">
          <label for="api-key-name">Key name</label>
          <input type="text" id="api-key-name" name="name" placeholder="Hermes agent" required>
        </div>
        <div class="api-key-field">
          <label for="api-key-ips">Allowed IPs</label>
          <input type="text" id="api-key-ips" name="allowed_ips" placeholder="Optional, comma separated">
          <small>Leave blank unless the integration has fixed source IPs.</small>
        </div>
      </div>
      <div class="api-key-field">
        <span>API access</span>
        <?php echo api_keys_scope_checkboxes($scopeOptions, ['full'], 'scopes'); ?>
      </div>
      <div class="api-key-form-actions">
        <button type="submit" class="btn btn-primary">Create Key</button>
        <a class="btn" href="/?page=api-keys">Cancel</a>
      </div>
    </form>
  </div>
</div>
