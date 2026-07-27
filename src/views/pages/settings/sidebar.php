<?php
/** @var array $settingsRegistry */
/** @var string $activeCategoryKey */
/** @var string $tab */

$_sidebarIcons = [
  'account'   => '<svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
  'business'  => '<svg viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M9 22V12h6v10"/></svg>',
  'services'  => '<svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M16 13H8M16 17H8M10 9H8"/></svg>',
  'workforce' => '<svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
  'billing'   => '<svg viewBox="0 0 24 24"><rect x="1" y="4" width="22" height="16" rx="2"/><path d="M1 10h22"/></svg>',
  'system'    => '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/></svg>',
];
?>
<aside class="settings-sidebar" aria-label="Settings navigation">
  <a class="settings-all-link" href="/?page=settings">
    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
    All settings
  </a>
  <nav>
    <?php foreach ($settingsRegistry as $categoryKey => $category): ?>
      <div class="settings-nav-group<?php echo $categoryKey === $activeCategoryKey ? ' is-active' : ''; ?>">
        <div class="settings-nav-heading">
          <span class="settings-nav-icon" aria-hidden="true">
            <?php echo $_sidebarIcons[$categoryKey] ?? '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="8"/></svg>'; ?>
          </span>
          <span><?php echo htmlspecialchars((string)$category['short_title']); ?></span>
        </div>
        <ul>
          <?php foreach ($category['items'] as $item): ?>
            <?php $isActive = isset($item['tab']) && $item['tab'] === $tab; ?>
            <li>
              <a href="<?php echo htmlspecialchars(pa_settings_item_href($item), ENT_QUOTES, 'UTF-8'); ?>"<?php echo $isActive ? ' class="is-active" aria-current="page"' : ''; ?>>
                <?php echo htmlspecialchars((string)$item['title']); ?>
              </a>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endforeach; ?>
  </nav>
</aside>
