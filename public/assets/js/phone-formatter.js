/**
 * Live-format US phone numbers as the user types.
 * 10 digits -> (555) 123-4567
 * 7 digits  -> 123-4567
 */
(function() {
  function phoneDigits(value) {
    let digits = String(value || '').replace(/\D/g, '');
    if (digits.length === 11 && digits.charAt(0) === '1') {
      digits = digits.slice(1);
    }
    return digits.slice(0, 10);
  }

  function formatPhone(value) {
    const digits = phoneDigits(value);
    if (digits.length === 0) return '';
    if (digits.length <= 3) return digits;
    if (digits.length <= 6) {
      return '(' + digits.slice(0, 3) + ') ' + digits.slice(3);
    }
    return '(' + digits.slice(0, 3) + ') ' + digits.slice(3, 6) + '-' + digits.slice(6);
  }

  function isPhoneInput(input) {
    if (!input || input.tagName !== 'INPUT') return false;
    const type = (input.getAttribute('type') || 'text').toLowerCase();
    if (!['', 'text', 'tel', 'search'].includes(type)) return false;
    const name = (input.getAttribute('name') || '').toLowerCase();
    const id = (input.getAttribute('id') || '').toLowerCase();
    const autocomplete = (input.getAttribute('autocomplete') || '').toLowerCase();
    return type === 'tel' || autocomplete === 'tel' || name.includes('phone') || id.includes('phone');
  }

  function formatInput(input) {
    const formatted = formatPhone(input.value);
    if (input.value !== formatted) {
      input.value = formatted;
    }
  }

  function attachPhoneFormatter(input) {
    if (!isPhoneInput(input) || input.dataset.phoneFormatterAttached === '1') return;
    input.dataset.phoneFormatterAttached = '1';
    input.setAttribute('inputmode', 'tel');
    input.setAttribute('autocomplete', input.getAttribute('autocomplete') || 'tel');
    formatInput(input);

    input.addEventListener('input', function() {
      formatInput(input);
    });

    input.addEventListener('blur', function() {
      formatInput(input);
    });
  }

  function attachAll(root) {
    const scope = root || document;
    if (scope.querySelectorAll) {
      scope.querySelectorAll('input').forEach(attachPhoneFormatter);
    }
  }

  window.ProjectAlphaPhone = {
    format: formatPhone,
    digits: phoneDigits,
    attach: attachPhoneFormatter,
    attachAll: attachAll
  };

  document.addEventListener('DOMContentLoaded', function() {
    attachAll(document);

    const observer = new MutationObserver(function(records) {
      records.forEach(function(record) {
        record.addedNodes.forEach(function(node) {
          if (node.nodeType !== 1) return;
          if (node.tagName === 'INPUT') {
            attachPhoneFormatter(node);
          } else {
            attachAll(node);
          }
        });
      });
    });
    observer.observe(document.documentElement, {childList: true, subtree: true});
  });
})();
