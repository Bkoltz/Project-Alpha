<?php
// src/views/pages/settings.php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/settings/registry.php';

$settingsUserId = (int)($_SESSION['user']['id'] ?? 0);
$settingsUserRole = (string)($_SESSION['user']['role'] ?? '');
$settingsCan = static function (string $permission) use ($pdo, $settingsUserId): bool {
  return $settingsUserId > 0 && user_can($pdo, $settingsUserId, $permission, 0);
};

$allSettingsRegistry = pa_settings_registry();
$settingsRegistry = pa_settings_visible_registry($allSettingsRegistry, $settingsCan, $settingsUserRole);

$requestedTab = isset($_GET['tab']) ? preg_replace('/[^a-z0-9\-]/i', '', (string)$_GET['tab']) : '';
$requestedCategory = isset($_GET['category']) ? preg_replace('/[^a-z0-9\-]/i', '', (string)$_GET['category']) : '';

// Legacy Settings → Account links now land in the account/security category.
if ($requestedTab === 'account') {
  $requestedTab = '';
  $requestedCategory = 'account';
}

// Compatibility for the former standalone customization tab.
if ($requestedTab === 'customization') {
  $requestedTab = 'documents';
  $_GET['doc_tab'] = 'customization';
}

$requestedEntry = $requestedTab !== '' ? pa_settings_find_tab($allSettingsRegistry, $requestedTab) : null;
$visibleEntry = $requestedTab !== '' ? pa_settings_find_tab($settingsRegistry, $requestedTab) : null;
$accessDenied = $requestedEntry !== null && $visibleEntry === null;

$tab = $visibleEntry['item']['tab'] ?? '';
$activeCategoryKey = $visibleEntry['category_key'] ?? '';
$activeCategory = $visibleEntry['category'] ?? null;
$activeItem = $visibleEntry['item'] ?? null;

if ($tab === '' && $requestedCategory !== '' && isset($settingsRegistry[$requestedCategory])) {
  $activeCategoryKey = $requestedCategory;
  $activeCategory = $settingsRegistry[$requestedCategory];
}

$showDashboard = $tab === '' && $activeCategory === null && !$accessDenied;
$docTabQuery = isset($_GET['doc_tab']) ? preg_replace('/[^a-z]/i', '', (string)$_GET['doc_tab']) : '';
?>
<link rel="stylesheet" href="<?php echo htmlspecialchars(asset_url('/assets/settings.css'), ENT_QUOTES, 'UTF-8'); ?>">

<section class="settings-page" data-settings-page>
  <header class="settings-page-header">
    <div>
      <?php if (!$showDashboard): ?>
        <nav class="settings-breadcrumb" aria-label="Breadcrumb">
          <a href="/?page=settings">Settings</a>
          <?php if ($activeCategory): ?>
            <span aria-hidden="true">/</span>
            <span><?php echo htmlspecialchars((string)$activeCategory['short_title']); ?></span>
          <?php endif; ?>
        </nav>
      <?php endif; ?>
      <h1><?php echo $showDashboard ? 'Settings' : htmlspecialchars((string)($activeItem['title'] ?? $activeCategory['title'] ?? 'Settings')); ?></h1>
      <p><?php echo $showDashboard
        ? 'Find and manage account, business, workflow, document, billing, and system preferences.'
        : htmlspecialchars((string)($activeItem['description'] ?? $activeCategory['description'] ?? '')); ?></p>
    </div>
    <?php if (!$showDashboard): ?>
      <a class="settings-header-action" href="/?page=settings">Browse all settings</a>
    <?php endif; ?>
  </header>

  <?php if (isset($_GET['saved']) && $_GET['saved'] === '1'): ?>
    <div class="settings-alert settings-alert-success" role="status">Settings saved.</div>
  <?php elseif (isset($_GET['saved']) && $_GET['saved'] === '0'): ?>
    <div class="settings-alert settings-alert-danger" role="alert">Failed to save settings. <?php echo !empty($_GET['error']) ? htmlspecialchars((string)$_GET['error']) : ''; ?></div>
  <?php elseif (!empty($_GET['error'])): ?>
    <div class="settings-alert settings-alert-danger" role="alert"><?php echo htmlspecialchars((string)$_GET['error']); ?></div>
  <?php endif; ?>

  <?php if (!empty($_GET['fallback']) && $_GET['fallback'] === '1' && empty($appConfig['suppress_assets_warning'])): ?>
    <div class="settings-alert settings-alert-warning" role="status">Settings were saved to the internal configuration because the configured assets location was not writable.</div>
  <?php endif; ?>

  <?php if ($showDashboard): ?>
    <?php include __DIR__ . '/settings/dashboard.php'; ?>
  <?php else: ?>
    <div class="settings-workspace">
      <?php include __DIR__ . '/settings/sidebar.php'; ?>

      <div class="settings-content" data-settings-content>
        <?php if ($accessDenied): ?>
          <?php http_response_code(403); ?>
          <div class="settings-state-panel" role="alert">
            <span class="settings-category-marker" aria-hidden="true">!</span>
            <h2>Setting unavailable</h2>
            <p>You do not have permission to view this installation setting.</p>
            <a class="btn" href="/?page=settings">Return to settings</a>
          </div>
        <?php elseif ($tab !== '' && $activeItem): ?>
          <div class="settings-section-heading">
            <h2><?php echo htmlspecialchars((string)$activeItem['title']); ?></h2>
            <p><?php echo htmlspecialchars((string)$activeItem['description']); ?></p>
          </div>

          <?php if (($activeItem['form_mode'] ?? 'self') === 'wrapped'): ?>
            <form method="post"
                  action="/?page=settings&amp;tab=<?php echo rawurlencode($tab); ?><?php echo $docTabQuery !== '' ? '&amp;doc_tab=' . rawurlencode($docTabQuery) : ''; ?>"
                  enctype="multipart/form-data"
                  class="settings-primary-form"
                  data-settings-primary-form
                  data-settings-track-dirty>
              <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
              <input type="hidden" name="tab" value="<?php echo htmlspecialchars($tab); ?>">

              <div class="settings-form-body">
                <?php
                  $tabFile = __DIR__ . '/settings/' . $tab . '.php';
                  if (is_file($tabFile)) {
                    include $tabFile;
                  } else {
                    echo '<div class="settings-state-panel"><h2>Setting not found</h2><p>This settings section is not available.</p></div>';
                  }
                ?>
              </div>

              <div class="settings-save-bar" data-settings-save-bar>
                <p class="settings-save-status" aria-live="polite" data-settings-save-status>No unsaved changes</p>
                <div class="settings-save-actions">
                  <button type="reset" class="btn settings-cancel-button" data-settings-cancel>Cancel</button>
                  <button type="submit" class="btn btn-primary">Save changes</button>
                </div>
              </div>
            </form>
          <?php else: ?>
            <div class="settings-managed-section">
              <?php
                $tabFile = __DIR__ . '/settings/' . $tab . '.php';
                if (is_file($tabFile)) {
                  include $tabFile;
                } else {
                  echo '<div class="settings-state-panel"><h2>Setting not found</h2><p>This settings section is not available.</p></div>';
                }
              ?>
            </div>
          <?php endif; ?>
        <?php elseif ($activeCategory): ?>
          <div class="settings-section-heading">
            <h2><?php echo htmlspecialchars((string)$activeCategory['title']); ?></h2>
            <p><?php echo htmlspecialchars((string)$activeCategory['description']); ?></p>
          </div>
          <div class="settings-category-detail-grid">
            <?php foreach ($activeCategory['items'] as $item): ?>
              <a class="settings-detail-link" href="<?php echo htmlspecialchars(pa_settings_item_href($item), ENT_QUOTES, 'UTF-8'); ?>">
                <strong><?php echo htmlspecialchars((string)$item['title']); ?></strong>
                <span><?php echo htmlspecialchars((string)$item['description']); ?></span>
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 18 6-6-6-6"></path></svg>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>
</section>

<script src="<?php echo htmlspecialchars(asset_url('/assets/js/settings-page.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
