var sel = document.getElementById('invoiceSelect');
var amt = document.getElementById('amountInput');
var recordPaymentForm = document.getElementById('recordPaymentForm');
var invoiceFields = document.getElementById('invoicePaymentFields');
var manualFields = document.getElementById('manualPaymentFields');
var manualClientId = document.getElementById('manualClientId');
var manualClientSearch = document.getElementById('manualClientSearch');
var manualClientSuggest = document.getElementById('manualClientSuggest');
var scopeInputs = Array.from(document.querySelectorAll('input[name="payment_scope"]'));
var sendReceiptInput = document.getElementById('sendReceiptInput');

function selectedScope() {
    var checked = scopeInputs.find(function (input) { return input.checked; });
    return checked ? checked.value : 'invoice';
}

function escapeHtml(text) {
    var div = document.createElement('div');
    div.textContent = text == null ? '' : String(text);
    return div.innerHTML;
}

function update() {
    if (!sel || !amt) return;
    var opt = sel.options[sel.selectedIndex];
    if (!opt) return;
    var r = opt.getAttribute('data-remaining');
    if (r) { amt.value = r; }
}
if (sel && amt) {
    sel.addEventListener('change', update);
    if (!amt.value) { update(); }
}

// Toggle check number field based on payment method
var methodSelect = document.getElementById('paymentMethod');
var checkField = document.getElementById('checkNumberField');
var checkInput = document.getElementById('checkNumberInput');
var stripeNotice = document.getElementById('stripeNotice');
var referenceLabel = document.getElementById('referenceLabel');

function togglePaymentFields() {
    if (!methodSelect || !checkField || !checkInput) return;
    var method = methodSelect.value.toLowerCase();
    var scope = selectedScope();
    // Check field
    if (method === 'check' || method === 'bank_transfer') {
        checkField.style.display = 'block';
        checkInput.required = true;
        var isCheck = method === 'check';
        if (referenceLabel) {
            referenceLabel.textContent = isCheck ? 'Check Number' : 'Reference / Transaction Number';
        }
        checkInput.placeholder = isCheck ? 'Enter check number' : 'Enter reference / transaction number';
    } else {
        checkField.style.display = 'none';
        checkInput.required = false;
        checkInput.value = '';
    }
    // Stripe notice
    if (stripeNotice) {
        stripeNotice.style.display = method === 'stripe' && scope === 'invoice' ? 'block' : 'none';
    }
}

function togglePaymentScope() {
    var scope = selectedScope();
    var isManual = scope === 'manual';
    if (invoiceFields) invoiceFields.style.display = isManual ? 'none' : 'grid';
    if (manualFields) manualFields.style.display = isManual ? 'grid' : 'none';
    if (sel) sel.required = !isManual;
    if (manualClientSearch) {
        manualClientSearch.required = isManual;
        if (!isManual) {
            manualClientSearch.setCustomValidity('');
        }
    }
    if (sendReceiptInput) sendReceiptInput.checked = !isManual;

    if (methodSelect) {
        Array.from(methodSelect.options).forEach(function (option) {
            if (option.value.toLowerCase() === 'stripe') {
                option.disabled = isManual;
                if (isManual && option.selected) {
                    methodSelect.value = methodSelect.options[0] ? methodSelect.options[0].value : '';
                }
            }
        });
    }

    togglePaymentFields();
}

if (methodSelect) {
    methodSelect.addEventListener('change', togglePaymentFields);
    togglePaymentFields();
}

scopeInputs.forEach(function (input) {
    input.addEventListener('change', togglePaymentScope);
});

function hideManualClientSuggestions() {
    if (!manualClientSuggest) return;
    manualClientSuggest.style.display = 'none';
    manualClientSuggest.innerHTML = '';
}

function setManualClientValidity() {
    if (!manualClientSearch) return;
    if (selectedScope() !== 'manual' || manualClientId.value) {
        manualClientSearch.setCustomValidity('');
        return;
    }
    manualClientSearch.setCustomValidity('Choose a client from the search results.');
}

function renderManualClientSuggestions(list) {
    if (!manualClientSuggest) return;
    if (!Array.isArray(list) || list.length === 0) {
        hideManualClientSuggestions();
        return;
    }

    manualClientSuggest.innerHTML = list.map(function (client) {
        var meta = [];
        if (client.email) meta.push(client.email);
        if (client.org_name) meta.push(client.org_name);
        return '<button type="button" data-client-id="' + escapeHtml(client.id) + '" data-client-name="' + escapeHtml(client.name) + '" style="display:block;width:100%;text-align:left;padding:9px 10px;border:0;border-bottom:1px solid #eef2f7;background:#fff;cursor:pointer">' +
            '<strong>' + escapeHtml(client.name) + '</strong>' +
            (meta.length ? '<span style="display:block;margin-top:2px;color:#6b7280;font-size:12px">' + escapeHtml(meta.join(' - ')) + '</span>' : '') +
            '</button>';
    }).join('');
    manualClientSuggest.style.display = 'block';
}

if (manualClientSearch && manualClientId && manualClientSuggest) {
    var manualClientSearchTimer = null;

    manualClientSearch.addEventListener('input', function () {
        manualClientId.value = '';
        setManualClientValidity();
        var term = manualClientSearch.value.trim();
        clearTimeout(manualClientSearchTimer);
        if (!term) {
            hideManualClientSuggestions();
            return;
        }
        manualClientSearchTimer = setTimeout(function () {
            fetch('/?page=clients-search&term=' + encodeURIComponent(term))
                .then(function (response) { return response.json(); })
                .then(renderManualClientSuggestions)
                .catch(hideManualClientSuggestions);
        }, 160);
    });

    manualClientSearch.addEventListener('blur', function () {
        window.setTimeout(setManualClientValidity, 120);
    });

    manualClientSuggest.addEventListener('click', function (event) {
        var option = event.target.closest('[data-client-id]');
        if (!option) return;
        manualClientId.value = option.getAttribute('data-client-id') || '';
        manualClientSearch.value = option.getAttribute('data-client-name') || '';
        manualClientSearch.setCustomValidity('');
        hideManualClientSuggestions();
    });

    document.addEventListener('click', function (event) {
        if (!manualClientSuggest.contains(event.target) && event.target !== manualClientSearch) {
            hideManualClientSuggestions();
        }
    });
}

if (recordPaymentForm) {
    recordPaymentForm.addEventListener('submit', function (event) {
        setManualClientValidity();
        if (selectedScope() === 'manual' && manualClientSearch && !manualClientSearch.checkValidity()) {
            event.preventDefault();
            manualClientSearch.reportValidity();
        }
    });
}

togglePaymentScope();
