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
<script src="/assets/js/phone-formatter.js" defer></script>
<script src="/assets/js/csrf-auto-link.js" defer></script>
<?php if (!empty($_SESSION['user']['id'])): ?>
<script>
(function() {
  var banner;
  var checking = false;

  function showSessionExpiredBanner() {
    if (banner) return;
    banner = document.createElement('div');
    banner.setAttribute('role', 'alert');
    banner.style.cssText = 'position:fixed;left:16px;right:16px;bottom:16px;z-index:10000;display:flex;gap:12px;align-items:center;justify-content:space-between;padding:12px 14px;border:1px solid #fecaca;border-radius:8px;background:#fff1f2;color:#7f1d1d;box-shadow:0 10px 30px rgba(15,23,42,.18);font-size:14px';
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
