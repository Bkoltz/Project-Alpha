// public/assets/js/settings-system.js
// Handles the "Send Test Email" button on Settings > System and the per-org brand editor toggle.

document.addEventListener('DOMContentLoaded', function () {
    const btn = document.getElementById('btnEmailTest');
    const result = document.getElementById('emailTestResult');
    if (btn && result) {
        btn.addEventListener('click', function (e) {
            e.preventDefault();

            result.style.display = 'block';
            result.style.padding = '10px 12px';
            result.style.borderRadius = '8px';
            result.style.background = '#fff7ed';
            result.style.color = '#78350f';
            result.style.border = '1px solid #ffd8a8';
            result.textContent = 'Sending test email...';

            const form = btn.closest('form');
            const formData = form ? new FormData(form) : new FormData();
            formData.append('ajax', '1');

            fetch('/?page=email-test', {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }
                return response.json();
            })
            .then(function (data) {
                if (data.ok) {
                    result.style.background = '#ecfdf5';
                    result.style.color = '#065f46';
                    result.style.border = '1px solid #a7f3d0';
                    result.textContent = 'Test email sent successfully.';
                } else {
                    result.style.background = '#fff1f2';
                    result.style.color = '#881337';
                    result.style.border = '1px solid #fca5a5';
                    result.textContent = 'Test email failed: ' + (data.error || 'Unknown error');
                }
            })
            .catch(function (err) {
                result.style.background = '#fff1f2';
                result.style.color = '#881337';
                result.style.border = '1px solid #fca5a5';
                result.textContent = 'Test email failed: ' + err.message;
            });
        });
    }

    const toggle = document.getElementById('multiBrandToggle');
    const editors = document.getElementById('perOrgBrandEditors');
    if (toggle && editors) {
        editors.style.display = toggle.checked ? '' : 'none';
        toggle.addEventListener('change', function () {
            editors.style.display = toggle.checked ? '' : 'none';
        });
    }

    const termsToggle = document.getElementById('termsOrgToggle');
    const termsEditors = document.getElementById('perOrgTermsEditors');
    if (termsToggle && termsEditors) {
        termsEditors.style.display = termsToggle.checked ? '' : 'none';
        termsToggle.addEventListener('change', function () {
            termsEditors.style.display = termsToggle.checked ? '' : 'none';
        });
    }
});
