<?php
// src/views/pages/settings.php
$tab = isset($_GET['tab']) ? preg_replace('/[^a-z0-9\-]/i','', $_GET['tab']) : 'customize';
?>
<section>
  <h2>Settings</h2>
  <?php if (!empty($_GET['saved'])): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#e6fffa;color:#065f46;border:1px solid #99f6e4">Saved.</div>
  <?php endif; ?>

  <div style="display:grid;grid-template-columns:220px 1fr;gap:16px;margin-top:12px">
    <aside style="border:1px solid #eee;border-radius:8px;overflow:hidden;background:#fff">
      <a href="/?page=settings&tab=customize" style="display:block;padding:10px 12px;border-bottom:1px solid #eee;<?php echo $tab==='customize'?'background:#f8fafc;font-weight:600':''; ?>">Customize App</a>
      <a href="/?page=settings&tab=user" style="display:block;padding:10px 12px;border-bottom:1px solid #eee;<?php echo $tab==='user'?'background:#f8fafc;font-weight:600':''; ?>">User Info</a>
      <a href="/?page=settings&tab=terms" style="display:block;padding:10px 12px;<?php echo $tab==='terms'?'background:#f8fafc;font-weight:600':''; ?>">Terms & Conditions</a>
    </aside>

    <div>
      <form method="post" action="/?page=settings&tab=<?php echo $tab; ?>" enctype="multipart/form-data" style="display:grid;gap:16px;max-width:800px">
        <input type="hidden" name="tab" value="<?php echo $tab; ?>">

        <?php if ($tab==='customize'): ?>
          <fieldset style="border:1px solid #eee;border-radius:8px;padding:12px">
            <legend style="padding:0 6px;color:var(--muted)">Brand</legend>
            <label>
              <div>Brand Name</div>
              <input type="text" name="brand_name" value="<?php echo htmlspecialchars(($appConfig['brand_name'] ?? 'Project Alpha')); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
            </label>

            <div>
              <div>Logo (PNG, JPG, SVG, WEBP)</div>
              <?php if (!empty($appConfig['logo_path'])): ?>
                <div style="margin:8px 0"><img alt="Current logo" src="<?php echo htmlspecialchars($appConfig['logo_path']); ?>" style="max-width:240px;max-height:120px;object-fit:contain;border-radius:6px;background:#fff;padding:8px"></div>
              <?php endif; ?>
              <input type="file" name="logo" accept="image/png,image/jpeg,image/svg+xml,image/webp">
            </div>
          </fieldset>
        <?php endif; ?>

        <?php if ($tab==='user'): ?>
          <fieldset style="border:1px solid #eee;border-radius:8px;padding:12px">
            <legend style="padding:0 6px;color:var(--muted)">User Info (From)</legend>
            <label><div>Name</div><input name="from_name" value="<?php echo htmlspecialchars($appConfig['from_name'] ?? ''); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd"></label>
            <label><div>Address line 1</div><input name="from_address_line1" value="<?php echo htmlspecialchars($appConfig['from_address_line1'] ?? ''); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd"></label>
            <label><div>Address line 2</div><input name="from_address_line2" value="<?php echo htmlspecialchars($appConfig['from_address_line2'] ?? ''); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd"></label>
            <div style="display:grid;gap:8px;grid-template-columns:1fr 1fr 1fr">
              <label><div>City</div><input name="from_city" value="<?php echo htmlspecialchars($appConfig['from_city'] ?? ''); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd"></label>
              <label><div>State</div><input name="from_state" value="<?php echo htmlspecialchars($appConfig['from_state'] ?? ''); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd"></label>
              <label><div>Postal</div><input name="from_postal" value="<?php echo htmlspecialchars($appConfig['from_postal'] ?? ''); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd"></label>
              <!-- <label><div>Country</div><input name="from_country" value="<?php echo htmlspecialchars($appConfig['from_country'] ?? ''); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd"></label> -->
            </div>
            <div style="display:grid;gap:8px;grid-template-columns:1fr 1fr">
              <label><div>Email</div><input name="from_email" value="<?php echo htmlspecialchars($appConfig['from_email'] ?? ''); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd"></label>
              <label><div>Phone</div><input name="from_phone" value="<?php echo htmlspecialchars($appConfig['from_phone'] ?? ''); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd" placeholder="(123) 456-7890"></label>
            </div>
          </fieldset>
        <?php endif; ?>

        <?php if ($tab==='terms'): ?>
          <fieldset style="border:1px solid #eee;border-radius:8px;padding:12px">
            <legend style="padding:0 6px;color:var(--muted)">Terms & Conditions (used in Contracts)</legend>
            <textarea name="terms" rows="12" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd" placeholder="Enter your terms..."><?php echo htmlspecialchars($appConfig['terms'] ?? ''); ?></textarea>
          </fieldset>
        <?php endif; ?>

        <div>
          <button type="submit" style="padding:10px 14px;border-radius:8px;border:0;background:var(--nav-accent);color:#fff;font-weight:600">Save</button>
        </div>
      </form>
    </div>
  </div>
</section>
