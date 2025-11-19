<?php
// src/views/public/doc-wrapper.php
// Expects variables set by controller: $type, $rid, $token, $notice, $err, $pdo, $appConfig
?>
<style>.public-doc-wrap{max-width:816px;margin:24px auto;padding:0 16px 96px}.notice{margin:10px 0;padding:10px 12px;border-radius:8px}.n-ok{background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0}.n-err{background:#fff1f2;color:#881337;border:1px solid #fca5a5}</style>
<div class="public-doc-wrap">
  <?php if (!empty($notice)): ?><div class="notice n-ok">Thank you! Your response has been recorded.</div><?php endif; ?>
  <?php if (!empty($err)): ?><div class="notice n-err"><?php echo htmlspecialchars($err); ?></div><?php endif; ?>

  <?php
    if ($type === 'quote') {
      require __DIR__ . '/../../views/pages/quote/quote-print-wrapper.php';
    } elseif ($type === 'contract') {
      require __DIR__ . '/../../views/pages/contract/contract-print.php';
    } elseif ($type === 'invoice') {
      require __DIR__ . '/../../views/pages/invoice/invoice-print.php';
    }
  ?>

  <?php if ($type === 'quote'):
    // Show approve/deny forms if controller allowed it (controller may set $showActions)
    $showActions = $showActions ?? false;
    if ($showActions):
      require_once __DIR__ . '/../../utils/csrf_sf.php';
      $csrf = csrf_sf_token('public_quote_action');
  ?>
    <div style="margin:16px 0 64px; display:flex; gap:8px">
      <form method="post" action="/?page=public-quote-action">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
        <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
        <input type="hidden" name="action" value="approve">
        <button type="submit" style="padding:8px 12px;border-radius:8px;border:0;background:#16a34a;color:#fff">Approve</button>
      </form>
      <form method="post" action="/?page=public-quote-action">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
        <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
        <input type="hidden" name="action" value="deny">
        <button type="submit" style="padding:8px 12px;border-radius:8px;border:0;background:#ef4444;color:#fff">Deny</button>
      </form>
    </div>
  <?php
    endif;
  endif; ?>

  <?php
    // Contract upload form (controller may set $showUpload true)
    if (!empty($showUpload)):
      require_once __DIR__ . '/../../utils/csrf_sf.php';
      $csrf = csrf_sf_token('public_contract_action');
  ?>
    <div style="margin:16px 0 64px">
      <form method="post" action="/?page=public-contract-action" enctype="multipart/form-data" style="display:flex;gap:8px;align-items:center">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
        <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
        <input type="file" name="signed_pdf" accept="application/pdf" required style="padding:6px">
        <button type="submit" style="padding:8px 12px;border-radius:8px;border:0;background:#0ea5a4;color:#fff">Upload Signed Contract</button>
      </form>
      <div style="margin-top:8px;color:var(--muted);font-size:13px">Upload a signed PDF to mark this contract active. Maximum 25 MB. PDF only.</div>
    </div>
  <?php endif; ?>

</div>
