<?php
// src/views/pages/legal/tos-accept.php
// ToS acceptance gate — shown after login if tos_accepted_at is NULL
// This page renders inside the app's standard layout (header/footer partials included by router)
require_once __DIR__ . '/../../../utils/csrf_sf.php';
?>
<div style="max-width:700px;margin:0 auto;padding:24px">
  <h1 style="font-size:1.5rem;color:var(--nav-text,#0f1720);margin-bottom:0.5rem">Terms of Service Agreement</h1>
  <p style="color:var(--muted,#64748b);margin-bottom:1.5rem">
    Our Terms of Service have been updated. Please review and accept them to continue using Project Alpha.
  </p>

  <div style="border:1px solid #e2e8f0;border-radius:8px;padding:16px;margin-bottom:1.5rem;max-height:300px;overflow-y:auto;background:#f8fafc;font-size:0.9rem;line-height:1.6">
    <h2 style="font-size:1.1rem;margin-top:0">Terms of Service — Summary</h2>
    <p>By using Project Alpha, you agree to our Terms of Service, Privacy Policy, and Acceptable Use Policy.</p>
    <ul>
      <li>You are responsible for your account credentials and all activity under your account.</li>
      <li>You retain ownership of all data you upload (quotes, contracts, invoices, client information).</li>
      <li>You grant Project Alpha a limited license to process your data solely to provide the service.</li>
      <li>You must not upload illegal or copyright-infringing content.</li>
      <li>Project Alpha is provided on a best-effort basis without a guaranteed uptime SLA.</li>
      <li>Liability is capped at fees paid in the prior 12 months.</li>
      <li>Governing law: State of Wisconsin, USA.</li>
    </ul>
    <p>
      <a href="/?page=legal/terms-of-service" target="_blank">Read full Terms of Service</a> ·
      <a href="/?page=legal/privacy-policy" target="_blank">Privacy Policy</a> ·
      <a href="/?page=legal/acceptable-use-policy" target="_blank">Acceptable Use Policy</a>
    </p>
  </div>

  <?php if (!empty($_GET['error'])): ?>
    <div style="background:#fef2f2;border:1px solid #fecaca;color:#dc2626;padding:12px;border-radius:8px;margin-bottom:1rem">
      <?php echo htmlspecialchars($_GET['error']); ?>
    </div>
  <?php endif; ?>

  <form method="POST" action="/?page=legal/tos-accept">
    <input type="hidden" name="_token" value="<?php echo htmlspecialchars(csrf_sf_token('auth')); ?>">
    <label style="display:flex;align-items:flex-start;gap:8px;margin-bottom:1rem;cursor:pointer">
      <input type="checkbox" name="tos_accepted" value="1" required style="margin-top:4px">
      <span>I have read and agree to the <a href="/?page=legal/terms-of-service" target="_blank">Terms of Service</a>,
      <a href="/?page=legal/privacy-policy" target="_blank">Privacy Policy</a>, and
      <a href="/?page=legal/acceptable-use-policy" target="_blank">Acceptable Use Policy</a>.</span>
    </label>
    <button type="submit" style="background:var(--nav-accent,#2ea3d6);color:#fff;border:none;padding:12px 24px;border-radius:8px;cursor:pointer;font-size:1rem;font-weight:600">
      Accept and Continue
    </button>
  </form>
</div>