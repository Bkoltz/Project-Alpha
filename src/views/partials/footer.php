<?php require_once __DIR__ . '/../../utils/app_version.php'; ?>
  <footer class="site-footer" role="contentinfo">
    <a href="/?page=legal/terms-of-service">Terms of Service</a>
    <span class="sep">·</span>
    <a href="/?page=legal/privacy-policy">Privacy Policy</a>
    <span class="sep">·</span>
    <a href="/?page=legal/acceptable-use-policy">Acceptable Use</a>
    <span class="sep">·</span>
    <a href="/?page=legal/dmca-policy">DMCA / Copyright</a>
    <span class="sep">·</span>
    <a href="/?page=legal/data-retention-policy">Data Retention</a>
    <span class="sep">·</span>
    <span class="site-footer__version" style="opacity:.6">v<?php echo htmlspecialchars(app_version()); ?></span>
  </footer>
</main>
<script src="<?php echo htmlspecialchars(asset_url('/assets/js/phone-formatter.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
<script src="<?php echo htmlspecialchars(asset_url('/assets/js/csrf-auto-link.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
<script src="<?php echo htmlspecialchars(asset_url('/assets/js/settings-links.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
<?php if (!empty($appConfig['address_route_assistance_enabled']) && !empty($appConfig['google_maps_browser_key'])): ?>
<div id="paAddressAssistanceConfig" hidden data-enabled="1" data-key="<?php echo htmlspecialchars((string)$appConfig['google_maps_browser_key'], ENT_QUOTES, 'UTF-8'); ?>"></div>
<script src="<?php echo htmlspecialchars(asset_url('/assets/js/address-assistance.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
<?php endif; ?>
<script>
(function() {
  function inferShareContext(modal) {
    var params = new URLSearchParams(window.location.search);
    var page = params.get('page') || '';
    var type = modal && modal.dataset.docType ? modal.dataset.docType : '';
    if (!type) {
      if (page.indexOf('quote/') === 0) type = 'quote';
      else if (page.indexOf('invoice/') === 0) type = 'invoice';
      else if (page.indexOf('contract/') === 0) type = 'contract';
    }
    return {
      type: type || 'contract',
      id: (modal && modal.dataset.docId) || params.get('id') || '',
      days: (modal && modal.dataset.defaultDays) || '14',
      csrf: (modal && modal.dataset.csrf) || ''
    };
  }
  if (!window.generatePublicLink) window.generatePublicLink = function() {
    var modal = document.getElementById('shareLinkModal');
    if (modal) modal.style.display = 'flex';
  };
  if (!window.closeShareModal) window.closeShareModal = function() {
    var modal = document.getElementById('shareLinkModal');
    if (modal) modal.style.display = 'none';
  };
  if (!window.createPublicLink) window.createPublicLink = function() {
    var modal = document.getElementById('shareLinkModal');
    if (!modal) return;
    var ctx = inferShareContext(modal);
    var formData = new FormData();
    formData.append('type', ctx.type);
    formData.append('id', ctx.id);
    var daysInput = document.getElementById('linkDays');
    formData.append('days', (daysInput && daysInput.value) || ctx.days);
    if (ctx.csrf) formData.append('csrf', ctx.csrf);
    fetch('/?page=public-link-create', { method: 'POST', body: formData })
      .then(function(r) { return r.json(); })
      .then(function(data) {
        if (!data.success) { alert(data.error || 'Failed to create link'); return; }
        var content = document.getElementById('shareLinkContent');
        var result = document.getElementById('shareLinkResult');
        var link = document.getElementById('generatedLink');
        var expiry = document.getElementById('linkExpiry');
        if (content) content.style.display = 'none';
        if (result) result.style.display = 'block';
        if (link) link.value = data.url || '';
        if (expiry) expiry.textContent = data.expires_at ? 'Expires: ' + data.expires_at : '';
      })
      .catch(function() { alert('Failed to create link'); });
  };
  if (!window.copyLink) window.copyLink = function() {
    var input = document.getElementById('generatedLink');
    if (!input) return;
    input.select();
    document.execCommand('copy');
  };
})();
</script>
<?php if (!empty($_SESSION['user']['id'])): ?>
<script>
(function() {
  var banner;
  var checking = false;

  function showSessionExpiredBanner() {
    if (banner) return;
    banner = document.createElement('div');
    banner.setAttribute('role', 'alert');
    banner.style.cssText = 'position:fixed;left:16px;right:16px;top:16px;z-index:10000;display:flex;gap:12px;align-items:center;justify-content:space-between;padding:12px 14px;border:1px solid #fecaca;border-radius:8px;background:#fff1f2;color:#7f1d1d;box-shadow:0 10px 30px rgba(15,23,42,.18);font-size:14px';
    banner.innerHTML = '<span><strong>Session expired.</strong> Please refresh and sign in again before continuing.</span><button type="button" style="padding:8px 12px;border:0;border-radius:6px;background:#991b1b;color:#fff;font-weight:600;cursor:pointer">Refresh</button>';
    banner.querySelector('button').addEventListener('click', function() {
      window.location.reload();
    });
    document.body.appendChild(banner);
  }

  async function checkSession() {
    if (checking || document.hidden) return;
    checking = true;
    try {
      var response = await fetch('/?page=session-status', {
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { 'Accept': 'application/json' }
      });
      if (!response.ok) return;
      var data = await response.json();
      if (!data.authenticated) {
        showSessionExpiredBanner();
      }
    } catch (err) {
      // Network hiccups should not interrupt the current page.
    } finally {
      checking = false;
    }
  }

  window.addEventListener('focus', checkSession);
  document.addEventListener('visibilitychange', function() {
    if (!document.hidden) checkSession();
  });
  setInterval(checkSession, 60000);
})();
</script>
<?php endif; ?>
</body>

</html>
