var pmList = document.getElementById('pmList');
var pmAdd = document.getElementById('pmAdd');
var pmSelect = document.getElementById('pmSelect');
var pmCustom = document.getElementById('pmCustom');
var hiddenJson = document.getElementById('paymentMethodsJson');

function sync() {
    var items = [];
    Array.from(pmList.querySelectorAll('.pm-item')).forEach(function (el) {
        var name = el.querySelector('input[type="hidden"]').value || el.querySelector('span').textContent.trim();
        items.push({
            name: name
        });
    });
    hiddenJson.value = JSON.stringify(items);
    var fallback = document.querySelector('textarea[name="payment_methods"]');
    if (fallback) {
        fallback.value = items.map(function (i) {
            return i.name;
        }).join('\n');
    }

    // Show/hide Stripe config based on whether Stripe is in the list
    var hasStripe = items.some(function (i) {
        return i.name.toLowerCase() === 'stripe';
    });
    var stripeConfig = document.getElementById('stripeConfig');
    if (stripeConfig) {
        stripeConfig.style.display = hasStripe ? 'block' : 'none';
    }
}

function removeHandler(e) {
    var btn = e.currentTarget;
    var row = btn.closest('.pm-item');
    if (row) {
        row.remove();
        sync();
    }
}

function addMethod(name) {
    if (!name) return;
    // prevent duplicates (case-insensitive)
    var existing = Array.from(pmList.querySelectorAll('input[type="hidden"]')).some(function (h) {
        return h.value.toLowerCase() === name.toLowerCase();
    });
    if (existing) return;

    var div = document.createElement('div');
    div.className = 'pm-item';
    div.style.display = 'flex';
    div.style.alignItems = 'center';
    div.style.gap = '8px';
    div.innerHTML = '<input type="hidden" name="payment_methods_backup[]" value="' + htmlEscape(name) + '">' +
        '<span style="padding:8px 10px;border:1px solid #ddd;border-radius:6px;background:#fafafa">' + escapeHtml(name) + '</span>' +
        '<button type="button" class="pm-remove" style="margin-left:auto;padding:6px 8px;border-radius:6px;border:1px solid #ddd;background:#fff">Remove</button>';

    pmList.appendChild(div);
    var btn = div.querySelector('.pm-remove');
    btn.addEventListener('click', removeHandler);
    sync();
}

function escapeHtml(s) {
    return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

function htmlEscape(s) {
    return s.replace(/"/g, '&quot;');
}

// wire existing remove buttons
Array.from(document.querySelectorAll('.pm-remove')).forEach(function (b) {
    b.addEventListener('click', removeHandler);
});

pmSelect.addEventListener('change', function () {
    if (pmSelect.value === 'other') {
        pmCustom.style.display = '';
        pmCustom.focus();
    } else {
        pmCustom.style.display = 'none';
    }
});

pmAdd.addEventListener('click', function () {
    var name = pmSelect.value === 'other' ? pmCustom.value.trim() : pmSelect.value;
    if (!name) return;
    addMethod(name);
    pmCustom.value = '';
    pmSelect.value = 'stripe';
});

// ensure initial sync
sync();