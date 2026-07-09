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
    if (mode === 'zip') {
      fillZipFromSelectedClient(root);
    }
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

  function uniqueChoices(choices) {
    var seen = {};
    return (choices || []).filter(function (choice) {
      var rate = Number(choice.rate || 0).toFixed(4);
      var label = String(choice.label || '').trim().replace(/\s+/g, ' ').toLowerCase();
      var key = rate + '|' + label;
      if (seen[key]) return false;
      seen[key] = true;
      return true;
    });
  }

  function renderChoices(root, choices, message) {
    var box = qs(root, '[data-tax-choices]');
    if (!box) return;
    choices = uniqueChoices(choices);
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
      button.className = 'pa-tax-lookup__choice';
      button.innerHTML = '<span>' + escapeHtml(choice.label) + '</span><strong>' + escapeHtml(choice.rate_display || '') + '</strong>';
      button.addEventListener('click', function () {
        applyChoice(root, choice);
      });
      box.appendChild(button);
    });
    box.style.display = 'block';
  }

  function fetchLookup(root, params) {
    var stateHint = root.getAttribute('data-tax-state-hint') || '';
    if (stateHint && !params.state) {
      params.state = stateHint;
    }
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

  function selectedClientId(root) {
    var form = root.closest('form') || document;
    var selectors = [
      '#clientId',
      '#clientIdInv',
      '#clientIdCo',
      '#contractEditClientId',
      'input[name="client_id"]'
    ];
    for (var i = 0; i < selectors.length; i += 1) {
      var input = form.querySelector(selectors[i]);
      if (input && input.value) return input.value;
    }
    return '';
  }

  function fillZipFromSelectedClient(root) {
    var zipInput = qs(root, '[data-tax-zip]');
    if (!zipInput || zipInput.value.trim() !== '') return;

    var clientId = selectedClientId(root);
    if (!clientId) {
      setStatus(root, 'Enter a 5 digit ZIP code.');
      return;
    }

    fetch('/?page=clients-search&id=' + encodeURIComponent(clientId), {
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
      .then(function (response) { return response.json(); })
      .then(function (rows) {
        var client = Array.isArray(rows) && rows.length ? rows[0] : null;
        var zip = client ? String(client.preferred_tax_zip || client.organization_postal_code || client.postal_code || '').replace(/\D+/g, '').slice(0, 5) : '';
        var stateHint = client ? String(client.preferred_tax_state || client.organization_state || client.state || '').trim() : '';
        if (stateHint) {
          root.setAttribute('data-tax-state-hint', stateHint);
        } else {
          root.removeAttribute('data-tax-state-hint');
        }
        if (zip.length !== 5 || zipInput.value.trim() !== '') {
          setStatus(root, 'Enter a 5 digit ZIP code.');
          return;
        }
        zipInput.value = zip;
        fetchLookup(root, { mode: 'zip', zip: zip, state: stateHint });
      })
      .catch(function () {
        setStatus(root, 'Enter a 5 digit ZIP code.');
      });
  }

  function activeMode(root) {
    var active = qs(root, '[data-tax-mode][aria-pressed="true"]');
    return active ? active.getAttribute('data-tax-mode') || 'manual' : 'manual';
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
    if (!root || root.dataset.taxLookupReady === '1') return;
    root.dataset.taxLookupReady = '1';
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

    var form = root.closest('form');
    if (form) {
      form.addEventListener('click', function (event) {
        if (!event.target.closest('[data-id], [data-client-id]')) return;
        window.setTimeout(function () {
          if (activeMode(root) !== 'zip') return;
          var input = qs(root, '[data-tax-zip]');
          if (input) input.value = '';
          fillZipFromSelectedClient(root);
        }, 0);
      });
    }

    setMode(root, 'manual');
  }

  function initTaxLookupPage(context) {
    var root = context && context.root ? context.root : document;
    qsa(root, '.pa-tax-lookup').forEach(init);
  }
  initTaxLookupPage.pageInitializerId = 'tax-lookup-control';

  if (window.ProjectAlpha && typeof window.ProjectAlpha.registerPage === 'function') {
    window.ProjectAlpha.registerPage([
      'quote/quotes-create',
      'quotes-create',
      'quote/quotes-edit',
      'quotes-edit',
      'invoice/invoices-create',
      'invoices-create',
      'invoice/invoices-edit',
      'invoices-edit',
      'contract/contracts-create',
      'contracts-create',
      'contract/contracts-edit',
      'contracts-edit'
    ], initTaxLookupPage);
  } else if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { initTaxLookupPage({ root: document }); }, { once: true });
  } else {
    initTaxLookupPage({ root: document });
  }
})();
