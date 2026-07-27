<?php
/** @var array $settingsRegistry */

$_dashIcons = [
  'account'   => '<svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
  'business'  => '<svg viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M9 22V12h6v10"/></svg>',
  'services'  => '<svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M16 13H8M16 17H8M10 9H8"/></svg>',
  'workforce' => '<svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
  'billing'   => '<svg viewBox="0 0 24 24"><rect x="1" y="4" width="22" height="16" rx="2"/><path d="M1 10h22"/></svg>',
  'system'    => '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/></svg>',
];
?>
<div class="settings-dashboard" data-settings-dashboard>
  <div class="settings-search-wrap">
    <label for="settingsSearch" class="settings-search-label">Find a setting</label>
    <div class="settings-search-control">
      <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-3.2-3.2"></path></svg>
      <input id="settingsSearch" type="search" autocomplete="off" placeholder="Search settings, such as mileage, taxes, email, or permissions" data-settings-search>
    </div>
    <p class="settings-search-status" role="status" aria-live="polite" data-settings-search-status></p>
  </div>

  <div class="settings-category-grid" data-settings-category-grid>
    <?php foreach ($settingsRegistry as $categoryKey => $category): ?>
      <?php
        $searchText = trim((string)$category['title'] . ' ' . (string)$category['description'] . ' ' . (string)($category['keywords'] ?? ''));
        foreach ($category['items'] as $item) {
          $searchText .= ' ' . (string)$item['title'] . ' ' . (string)$item['description'] . ' ' . (string)($item['keywords'] ?? '');
        }
      ?>
      <article class="settings-category-card" data-settings-card data-settings-search-text="<?php echo htmlspecialchars(strtolower($searchText), ENT_QUOTES, 'UTF-8'); ?>">
        <div class="settings-card-heading">
          <span class="settings-category-marker" aria-hidden="true">
            <?php echo $_dashIcons[$categoryKey] ?? htmlspecialchars((string)$category['marker']); ?>
          </span>
          <div>
            <h2><?php echo htmlspecialchars((string)$category['title']); ?></h2>
            <p><?php echo htmlspecialchars((string)$category['description']); ?></p>
          </div>
        </div>
        <ul class="settings-card-links" aria-label="<?php echo htmlspecialchars((string)$category['title']); ?> sections">
          <?php foreach ($category['items'] as $item): ?>
            <li>
              <a href="<?php echo htmlspecialchars(pa_settings_item_href($item), ENT_QUOTES, 'UTF-8'); ?>">
                <span><?php echo htmlspecialchars((string)$item['title']); ?></span>
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 18 6-6-6-6"></path></svg>
              </a>
            </li>
          <?php endforeach; ?>
        </ul>
      </article>
    <?php endforeach; ?>
  </div>

  <div class="settings-empty-search" hidden data-settings-empty>
    <strong>No matching settings</strong>
    <p>Try a broader word or browse the categories above.</p>
  </div>
</div>
