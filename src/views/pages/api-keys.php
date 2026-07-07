<?php
// src/views/pages/api-keys.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/api_keys_schema.php';
require_once __DIR__ . '/../../utils/api_scopes.php';

if (!function_exists('api_keys_e')) {
    function api_keys_e($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('api_keys_scope_checkboxes')) {
    function api_keys_scope_checkboxes(array $scopeOptions, array $selectedScopes, string $inputName, string $formId = ''): string
    {
        $selected = array_fill_keys(api_normalize_scopes($selectedScopes), true);
        if (!$selected) {
            $selected = ['full' => true];
        }
        $formAttr = $formId !== '' ? ' form="' . api_keys_e($formId) . '"' : '';
        $html = '<div class="api-scope-grid">';
        foreach ($scopeOptions as $scope => $option) {
            $id = preg_replace('/[^a-z0-9_-]+/i', '-', $inputName . '-' . $formId . '-' . $scope);
            $checked = isset($selected[$scope]) ? ' checked' : '';
            $html .= '<label class="api-scope-option" for="' . api_keys_e($id) . '">';
            $html .= '<input type="checkbox" id="' . api_keys_e($id) . '" name="' . api_keys_e($inputName) . '[]" value="' . api_keys_e($scope) . '"' . $checked . $formAttr . '>';
            $html .= '<span><strong>' . api_keys_e($option['label'] ?? $scope) . '</strong>';
            $html .= '<small>' . api_keys_e($option['description'] ?? '') . '</small></span>';
            $html .= '</label>';
        }
        $html .= '</div>';
        return $html;
    }
}

$user = $_SESSION['user'] ?? [];
if (($user['role'] ?? 'user') !== 'admin') {
    echo '<div class="alert alert-danger">Only admins can manage API keys.</div>';
    return;
}

$keys = [];
$schemaError = null;
$existingColumns = [];
try {
    pa_ensure_api_keys_schema($pdo);
    $existingColumns = pa_api_keys_existing_columns($pdo);
    $stmt = $pdo->query('SELECT id, name, key_prefix, scopes, allowed_ips, created_at, last_used_at, revoked_at FROM api_keys ORDER BY created_at DESC, id DESC');
    $keys = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $schemaError = $e->getMessage();
}

$scopeOptions = api_scope_options_for_form();
$newKey = $_SESSION['flash_api_key'] ?? null;
unset($_SESSION['flash_api_key']);
?>

<style>
.api-key-page { max-width: 1180px; margin: 0 auto; }
.api-key-header { display: flex; justify-content: space-between; gap: 16px; align-items: flex-start; margin-bottom: 18px; }
.api-key-header h1 { margin: 0 0 6px; font-size: 28px; }
.api-key-header p { margin: 0; color: #64748b; }
.api-key-card { background: #fff; border: 1px solid #d9e2ec; border-radius: 8px; padding: 18px; margin-bottom: 18px; box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04); }
.api-key-card h2 { margin: 0 0 14px; font-size: 18px; }
.api-key-form-grid { display: grid; grid-template-columns: minmax(180px, 1fr) minmax(220px, 1.2fr); gap: 14px; align-items: start; }
.api-key-field label { display: block; font-weight: 600; font-size: 13px; margin-bottom: 6px; color: #334155; }
.api-key-field input[type="text"] { width: 100%; min-height: 38px; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 10px; }
.api-key-field small { display: block; margin-top: 5px; color: #64748b; }
.api-scope-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 8px; }
.api-scope-option { display: flex; gap: 8px; align-items: flex-start; min-height: 64px; border: 1px solid #d9e2ec; border-radius: 8px; padding: 10px; background: #f8fafc; cursor: pointer; }
.api-scope-option input { margin-top: 3px; }
.api-scope-option strong { display: block; font-size: 13px; color: #0f172a; }
.api-scope-option small { display: block; margin-top: 3px; color: #64748b; line-height: 1.3; }
.api-key-table-wrap { overflow-x: auto; }
.api-key-table { width: 100%; border-collapse: collapse; font-size: 14px; }
.api-key-table th, .api-key-table td { border-bottom: 1px solid #e2e8f0; padding: 10px; text-align: left; vertical-align: top; }
.api-key-table th { color: #475569; font-size: 12px; text-transform: uppercase; letter-spacing: .04em; background: #f8fafc; }
.api-key-table input[type="text"] { width: 100%; min-width: 150px; min-height: 34px; border: 1px solid #cbd5e1; border-radius: 6px; padding: 7px 9px; }
.api-key-prefix { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; color: #0f172a; white-space: nowrap; }
.api-key-actions { display: flex; flex-wrap: wrap; gap: 8px; }
.api-key-empty { color: #64748b; padding: 18px; text-align: center; }
.api-key-secret { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; overflow-wrap: anywhere; background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; border-radius: 8px; padding: 12px; }
.api-key-badge { display: inline-flex; align-items: center; border-radius: 999px; padding: 4px 8px; font-size: 12px; background: #e0f2fe; color: #075985; }
.api-key-badge.revoked { background: #fee2e2; color: #991b1b; }
.alert { padding: 12px 14px; border-radius: 8px; margin-bottom: 14px; }
.alert-info { background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; }
.alert-success { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
.alert-danger { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
@media (max-width: 760px) {
    .api-key-header, .api-key-form-grid { display: block; }
    .api-key-field { margin-bottom: 12px; }
}
</style>

<div class="api-key-page">
  <div class="api-key-header">
    <div>
      <h1>API Keys</h1>
      <p>Create keys for external agents and choose exactly which API areas they can read.</p>
    </div>
    <span class="api-key-badge">Beta</span>
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
  <?php if ($newKey): ?>
    <div class="alert alert-success">
      <strong>Copy this key now. It will not be shown again.</strong>
      <div class="api-key-secret"><?php echo api_keys_e($newKey); ?></div>
    </div>
  <?php endif; ?>

  <div class="api-key-card">
    <h2>Create API Key</h2>
    <form method="post" action="/?page=api-keys-create">
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
      <div class="api-key-field" style="margin-top:14px;">
        <label>API access</label>
        <?php echo api_keys_scope_checkboxes($scopeOptions, ['full'], 'scopes'); ?>
      </div>
      <button type="submit" class="btn btn-primary" style="margin-top:14px;">Create Key</button>
    </form>
  </div>

  <div class="api-key-card">
    <h2>Existing API Keys</h2>
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
              $formId = 'api-key-update-' . $id;
              $revoked = !empty($key['revoked_at']);
              $selectedScopes = api_normalize_scopes($key['scopes'] ?? 'full');
            ?>
            <tr>
              <td>
                <?php if ($revoked): ?>
                  <?php echo api_keys_e($key['name'] ?? ''); ?>
                <?php else: ?>
                  <input type="text" name="name" value="<?php echo api_keys_e($key['name'] ?? ''); ?>" form="<?php echo api_keys_e($formId); ?>" required>
                <?php endif; ?>
              </td>
              <td><span class="api-key-prefix"><?php echo api_keys_e($key['key_prefix'] ?? ''); ?></span></td>
              <td>
                <?php if ($revoked): ?>
                  <?php echo api_keys_e(implode(', ', $selectedScopes ?: ['full'])); ?>
                <?php else: ?>
                  <?php echo api_keys_scope_checkboxes($scopeOptions, $selectedScopes ?: ['full'], 'scopes', $formId); ?>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($revoked): ?>
                  <?php echo api_keys_e($key['allowed_ips'] ?: 'Any'); ?>
                <?php else: ?>
                  <input type="text" name="allowed_ips" value="<?php echo api_keys_e($key['allowed_ips'] ?? ''); ?>" placeholder="Any" form="<?php echo api_keys_e($formId); ?>">
                <?php endif; ?>
              </td>
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
                    <form id="<?php echo api_keys_e($formId); ?>" method="post" action="/?page=api-keys-update">
                      <input type="hidden" name="csrf" value="<?php echo api_keys_e(csrf_token()); ?>">
                      <input type="hidden" name="id" value="<?php echo $id; ?>">
                      <button type="submit" class="btn btn-sm btn-primary">Save</button>
                    </form>
                    <form method="post" action="/?page=api-keys-revoke" onsubmit="return confirm('Revoke this API key? Existing integrations using it will stop working.');">
                      <input type="hidden" name="csrf" value="<?php echo api_keys_e(csrf_token()); ?>">
                      <input type="hidden" name="id" value="<?php echo $id; ?>">
                      <button type="submit" class="btn btn-sm btn-danger">Revoke</button>
                    </form>
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
