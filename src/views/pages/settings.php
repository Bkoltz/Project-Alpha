<?php
// src/views/pages/settings.php
require_once __DIR__ . '/../../config/app.php';
$tab = isset($_GET['tab']) ? preg_replace('/[^a-z0-9\-]/i', '', $_GET['tab']) : 'system';

// Valid tabs
$validTabs = ['system', 'terms', 'billing', 'taxes', 'documents', 'notifications', 'links', 'item-library'];
if (!in_array($tab, $validTabs)) {
  $tab = 'system';
}
?>
<section>
  <h2>Settings</h2>
  <?php if (isset($_GET['saved']) && $_GET['saved'] === '1'): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#e6fffa;color:#065f46;border:1px solid #99f6e4">Saved.</div>
  <?php elseif (isset($_GET['saved']) && $_GET['saved'] === '0'): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#fff1f2;color:#881337;border:1px solid #fca5a5">Failed to save settings. <?php if (!empty($_GET['error'])) {
                                                                                                                                                        echo htmlspecialchars($_GET['error']);
                                                                                                                                                      } ?></div>
  <?php elseif (!empty($_GET['error'])): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#fff1f2;color:#881337;border:1px solid #fca5a5"><?php echo htmlspecialchars($_GET['error']); ?></div>
  <?php endif; ?>
  <?php if (!empty($_GET['fallback']) && $_GET['fallback'] === '1' && empty($appConfig['suppress_assets_warning'])): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#fff7ed;color:#78350f;border:1px solid #ffd8a8">Settings saved to internal config (fallback) because public/assets wasn't writable.</div>
  <?php endif; ?>

  <div style="display:grid;grid-template-columns:220px 1fr;gap:16px;margin-top:12px">
    <aside style="border:1px solid #eee;border-radius:8px;overflow:hidden;background:#fff">
      <a href="/?page=settings&tab=system" style="display:block;padding:10px 12px;border-bottom:1px solid #eee;<?php echo $tab === 'system' ? 'background:#f8fafc;font-weight:600' : ''; ?>">System</a>
      <a href="/?page=settings&tab=terms" style="display:block;padding:10px 12px;border-bottom:1px solid #eee;<?php echo $tab === 'terms' ? 'background:#f8fafc;font-weight:600' : ''; ?>">Terms & Conditions</a>
      <a href="/?page=settings&tab=billing" style="display:block;padding:10px 12px;border-bottom:1px solid #eee;<?php echo $tab === 'billing' ? 'background:#f8fafc;font-weight:600' : ''; ?>">Billing</a>
      <a href="/?page=settings&tab=taxes" style="display:block;padding:10px 12px;border-bottom:1px solid #eee;<?php echo $tab === 'taxes' ? 'background:#f8fafc;font-weight:600' : ''; ?>">Taxes</a>
      <a href="/?page=settings&tab=documents" style="display:block;padding:10px 12px;border-bottom:1px solid #eee;<?php echo $tab === 'documents' ? 'background:#f8fafc;font-weight:600' : ''; ?>">Documents</a>
      <a href="/?page=settings&tab=notifications" style="display:block;padding:10px 12px;border-bottom:1px solid #eee;<?php echo $tab === 'notifications' ? 'background:#f8fafc;font-weight:600' : ''; ?>">Notifications</a>
      <a href="/?page=settings&tab=links" style="display:block;padding:10px 12px;border-bottom:1px solid #eee;<?php echo $tab === 'links' ? 'background:#f8fafc;font-weight:600' : ''; ?>">Links</a>
      <a href="/?page=settings&tab=item-library" style="display:block;padding:10px 12px;border-bottom:1px solid #eee;<?php echo $tab === 'item-library' ? 'background:#f8fafc;font-weight:600' : ''; ?>">Item Library</a>
      <a href="/?page=api-keys" style="display:block;padding:10px 12px;">API Keys</a>
    </aside>

    <div>
      <?php if ($tab === 'taxes' || $tab === 'item-library' || $tab === 'documents' || $tab === 'links'): ?>
        <?php
        // Include tabs without the form wrapper since they have their own forms
        $tabFile = __DIR__ . '/settings/' . $tab . '.php';
        if (file_exists($tabFile)) {
          include $tabFile;
        } else {
          echo '<p style="color:var(--muted)">Settings tab not found.</p>';
        }
        ?>
      <?php else: ?>
      <form method="post" action="/?page=settings&tab=<?php echo $tab; ?><?php echo isset($_GET['doc_tab']) ? '&doc_tab=' . htmlspecialchars($_GET['doc_tab']) : ''; ?>" enctype="multipart/form-data" style="display:grid;gap:16px;max-width:800px">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
        <input type="hidden" name="tab" value="<?php echo $tab; ?>">

        <?php
        // Include the appropriate tab file
        $tabFile = __DIR__ . '/settings/' . $tab . '.php';
        if (file_exists($tabFile)) {
          include $tabFile;
        } else {
          echo '<p style="color:var(--muted)">Settings tab not found.</p>';
        }
        ?>

        <div>
          <button type="submit" style="padding:10px 14px;border-radius:8px;border:0;background:var(--nav-accent);color:#fff;font-weight:600">Save</button>
        </div>
      </form>
      <?php endif; ?>
    </div>
  </div>
</section>
