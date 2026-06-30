(function () {
  function csrfToken() {
    var input = document.querySelector('form[action="/?page=settings/links-handler"] input[name="csrf"]');
    if (input && input.value) return input.value;
    var meta = document.querySelector('meta[name="csrf-token"]');
    if (meta && meta.content) return meta.content;
    return window.csrfToken || '';
  }

  window.toggleProviderFields = function (provider) {
    var checkbox = document.querySelector('input[name="provider_enabled_' + provider + '"]');
    var fields = document.getElementById('fields_' + provider);
    if (!checkbox || !fields) return;
    fields.style.display = checkbox.checked ? 'block' : 'none';
  };

  window.testConnection = function (event, provider) {
    var btn = event.currentTarget;
    var originalText = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Testing...';

    var formData = new FormData();
    formData.append('provider', provider);
    formData.append('csrf', csrfToken());
    var rootPathField = document.querySelector('input[name="' + provider + '_root_path"]');
    formData.append('root_path', rootPathField ? rootPathField.value : '');

    if (provider === 'dropbox') {
      var tokenField = document.querySelector('input[name="' + provider + '_access_token"]');
      formData.append('access_token', tokenField ? tokenField.value : '');
    } else if (provider === 'gdrive') {
      var credentialsField = document.querySelector('textarea[name="' + provider + '_credentials"]');
      formData.append('credentials', credentialsField ? credentialsField.value : '');
    } else if (provider === 's3') {
      ['access_key', 'secret_key', 'bucket', 'region'].forEach(function (field) {
        var input = document.querySelector('input[name="' + provider + '_' + field + '"]');
        formData.append(field, input ? input.value : '');
      });
    }

    fetch('/?page=settings/link-test-connection', { method: 'POST', body: formData })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        btn.disabled = false;
        btn.textContent = originalText;
        if (data.success) {
          alert('Connection successful!');
        } else {
          alert('Connection failed: ' + (data.error || 'Unknown error'));
        }
      })
      .catch(function (err) {
        btn.disabled = false;
        btn.textContent = originalText;
        alert('Connection test failed: ' + err.message);
      });
  };

  window.runProviderScan = function (event, provider) {
    var btn = event.currentTarget;
    var originalText = btn.textContent;
    var providerName = originalText.replace(/^Run\s+|\s+Now$/g, '').trim();
    if (!confirm('Run the ' + providerName + ' resolver scan now?')) return;

    btn.disabled = true;
    btn.textContent = 'Running...';

    var formData = new FormData();
    formData.append('provider', provider);
    formData.append('csrf', csrfToken());

    fetch('/?page=settings/link-resolver-run', { method: 'POST', body: formData })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        btn.disabled = false;
        btn.textContent = originalText;
        if (data.success) {
          var details = Array.isArray(data.details) && data.details.length ? '\n\n' + data.details.join('\n') : '';
          alert('Scan complete. ' + (data.message || '') + details);
        } else {
          alert('Scan failed: ' + (data.message || 'Unknown error'));
        }
      })
      .catch(function (err) {
        btn.disabled = false;
        btn.textContent = originalText;
        alert('Scan failed: ' + err.message);
      });
  };
})();
