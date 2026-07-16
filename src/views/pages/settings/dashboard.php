<?php
/** @var array $settingsRegistry */
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
          <span class="settings-category-marker" aria-hidden="true"><?php echo htmlspecialchars((string)$category['marker']); ?></span>
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
