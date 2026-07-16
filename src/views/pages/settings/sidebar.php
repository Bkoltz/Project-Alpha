<?php
/** @var array $settingsRegistry */
/** @var string $activeCategoryKey */
/** @var string $tab */
?>
<aside class="settings-sidebar" aria-label="Settings navigation">
  <a class="settings-all-link" href="/?page=settings">
    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m15 18-6-6 6-6"></path></svg>
    All settings
  </a>
  <nav>
    <?php foreach ($settingsRegistry as $categoryKey => $category): ?>
      <div class="settings-nav-group<?php echo $categoryKey === $activeCategoryKey ? ' is-active' : ''; ?>">
        <div class="settings-nav-heading">
          <span class="settings-nav-marker" aria-hidden="true"><?php echo htmlspecialchars((string)$category['marker']); ?></span>
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
