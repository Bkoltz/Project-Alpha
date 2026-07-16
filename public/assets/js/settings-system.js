// public/assets/js/settings-system.js
// Handles the "Send Test Email" button on Settings > System.

(function () {
    function readJsonResponse(response) {
        return response.text().then(function (body) {
            var payload = null;
            try {
                payload = body ? JSON.parse(body) : {};
            } catch (error) {
                throw new Error(response.ok
                    ? 'The server returned an invalid response.'
                    : 'The request failed (HTTP ' + response.status + ').');
            }
            if (!response.ok) {
                throw new Error(payload.message || payload.error || ('The request failed (HTTP ' + response.status + ').'));
            }
            return payload;
        });
    }

    function initSettingsSystem() {
        var btn = document.getElementById('btnEmailTest');
        var result = document.getElementById('emailTestResult');
        if (!btn || !result) return;
        if (btn.getAttribute('data-settings-system-ready') === '1') return;
        btn.setAttribute('data-settings-system-ready', '1');

        btn.addEventListener('click', function (e) {
            e.preventDefault();

            result.style.display = 'block';
            result.style.padding = '10px 12px';
            result.style.borderRadius = '8px';
            result.style.background = '#fff7ed';
            result.style.color = '#78350f';
            result.style.border = '1px solid #ffd8a8';
            result.textContent = 'Sending test email...';

            var form = btn.closest('form');
            var formData = new FormData();
            formData.append('csrf', form ? String(new FormData(form).get('csrf') || '') : '');
            formData.append('action', 'test');

            fetch('/?page=settings/email-provider-handler', {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(readJsonResponse)
            .then(function (data) {
                if (data.success) {
                    result.style.background = '#ecfdf5';
                    result.style.color = '#065f46';
                    result.style.border = '1px solid #a7f3d0';
                    result.textContent = 'Test email sent successfully.';
                } else {
                    result.style.background = '#fff1f2';
                    result.style.color = '#881337';
                    result.style.border = '1px solid #fca5a5';
                    result.textContent = 'Test email failed: ' + (data.message || 'Unknown error');
                }
            })
            .catch(function (err) {
                result.style.background = '#fff1f2';
                result.style.color = '#881337';
                result.style.border = '1px solid #fca5a5';
                result.textContent = 'Test email failed: ' + err.message;
            });
        });

        document.querySelectorAll('.email-provider-action').forEach(function (actionButton) {
            if (actionButton.dataset.ready === '1') return;
            actionButton.dataset.ready = '1';
            actionButton.addEventListener('click', function () {
                var data = new FormData();
                var parentForm = actionButton.closest('form');
                data.append('csrf', parentForm ? String(new FormData(parentForm).get('csrf') || '') : '');
                data.append('action', actionButton.dataset.action || '');
                data.append('connection_id', actionButton.dataset.id || '0');
                actionButton.disabled = true;
                fetch('/?page=settings/email-provider-handler', { method: 'POST', body: data, headers: {'X-Requested-With':'XMLHttpRequest'} })
                    .then(readJsonResponse)
                    .then(function (payload) {
                        if (!payload.success) throw new Error(payload.message || 'Provider action failed');
                        window.location.reload();
                    })
                    .catch(function (error) {
                        actionButton.disabled = false;
                        result.textContent = error.message;
                        result.style.display = 'block';
                    });
            });
        });
    }
    initSettingsSystem.pageInitializerId = 'settings-system';

    if (window.ProjectAlpha && typeof window.ProjectAlpha.registerPage === 'function') {
        // Settings subsections are query-string tabs of the single `settings`
        // page. Registering `settings/system` never ran after either a full
        // load or AJAX navigation because the router normalizes the active
        // page to `settings`.
        window.ProjectAlpha.registerPage('settings', initSettingsSystem);
    } else if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initSettingsSystem, { once: true });
    } else {
        initSettingsSystem();
    }
})();
