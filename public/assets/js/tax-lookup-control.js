(function () {
  function qs(root, selector) {
    return root.querySelector(selector);
  }

  function qsa(root, selector) {
    return Array.prototype.slice.call(root.querySelectorAll(selector));
  }

  function escapeHtml(value) {
    return String(value || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function setMode(root, mode) {
    qsa(root, '[data-tax-mode]').forEach(function (button) {
      var active = button.getAttribute('data-tax-mode') === mode;
      button.setAttribute('aria-pressed', active ? 'true' : 'false');
      button.style.background = active ? '#111827' : '#fff';
      button.style.color = active ? '#fff' : '#374151';
    });
    qsa(root, '[data-tax-panel]').forEach(function (panel) {
      panel.style.display = panel.getAttribute('data-tax-panel') === mode ? 'grid' : 'none';
    });
    clearChoices(root);
    setStatus(root, mode === 'manual' ? 'Enter a tax percentage directly.' : '');
  }

  function setStatus(root, message, tone) {
    var status = qs(root, '[data-tax-status]');
    if (!status) return;
    status.textContent = message || '';
    status.style.color = tone === 'warn' ? '#92400e' : tone === 'error' ? '#991b1b' : '#6b7280';
  }

  function clearChoices(root) {
    var choices = qs(root, '[data-tax-choices]');
    if (!choices) return;
    choices.innerHTML = '';
    choices.style.display = 'none';
  }

  function applyChoice(root, choice) {
    var id = root.getAttribute('data-tax-input-id');
    var input = id ? document.getElementById(id) : null;
    if (!input) return;
    input.value = Number(choice.rate || 0).toFixed(2);
    input.dispatchEvent(new Event('input', { bubbles: true }));
    input.dispatchEvent(new Event('change', { bubbles: true }));
    setStatus(root, 'Applied tax: ' + (choice.rate_display || input.value + '%') + ' - ' + (choice.label || 'Imported rate'));
    clearChoices(root);
  }

  function renderChoices(root, choices, message) {
    var box = qs(root, '[data-tax-choices]');
    if (!box) return;
    box.innerHTML = '';
    if (!choices || choices.length === 0) {
      box.style.display = 'none';
      setStatus(root, message || 'No matching imported tax rate found.', 'warn');
      return;
    }

    if (choices.length === 1) {
      applyChoice(root, choices[0]);
      return;
    }

    setStatus(root, message || 'Choose the matching tax jurisdiction.', 'warn');
    choices.forEach(function (choice) {
      var button = document.createElement('button');
      button.type = 'button';
      button.style.cssText = 'display:flex;width:100%;justify-content:space-between;gap:12px;text-align:left;padding:10px 12px;border:0;border-bottom:1px solid #f3f4f6;background:#fff;cursor:pointer';
      button.innerHTML = '<span>' + escapeHtml(choice.label) + '</span><strong>' + escapeHtml(choice.rate_display || '') + '</strong>';
      button.addEventListener('click', function () {
        applyChoice(root, choice);
      });
      box.appendChild(button);
    });
    box.style.display = 'block';
  }

  function fetchLookup(root, params) {
    setStatus(root, 'Looking up tax rate...');
    clearChoices(root);
    fetch('/?page=tax-lookup&' + new URLSearchParams(params).toString(), {
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
      .then(function (response) { return response.json(); })
      .then(function (result) {
        renderChoices(root, result.choices || [], result.message || '');
      })
      .catch(function () {
        setStatus(root, 'Tax lookup failed. Enter the percentage manually.', 'error');
      });
  }

  function debounce(fn, delay) {
    var timer = null;
    return function () {
      var args = arguments;
      clearTimeout(timer);
      timer = setTimeout(function () { fn.apply(null, args); }, delay);
    };
  }

  function init(root) {
    qsa(root, '[data-tax-mode]').forEach(function (button) {
      button.addEventListener('click', function () {
        setMode(root, button.getAttribute('data-tax-mode') || 'manual');
      });
    });

    var zipInput = qs(root, '[data-tax-zip]');
    if (zipInput) {
      zipInput.addEventListener('input', debounce(function () {
        var digits = zipInput.value.replace(/\D+/g, '');
        if (digits.length < 5) {
          clearChoices(root);
          setStatus(root, 'Enter a 5 digit ZIP code.');
          return;
        }
        fetchLookup(root, { mode: 'zip', zip: digits.slice(0, 5), zip4: digits.length >= 9 ? digits.slice(5, 9) : '' });
      }, 250));
    }

    var countyInput = qs(root, '[data-tax-county]');
    if (countyInput) {
      countyInput.addEventListener('input', debounce(function () {
        var query = countyInput.value.trim();
        if (query.length < 2) {
          clearChoices(root);
          setStatus(root, 'Type at least 2 letters of a county name.');
          return;
        }
        fetchLookup(root, { mode: 'county', q: query });
      }, 250));
    }

    setMode(root, 'manual');
  }

  document.addEventListener('DOMContentLoaded', function () {
    qsa(document, '.pa-tax-lookup').forEach(init);
  });
})();
