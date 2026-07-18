var sel = document.getElementById('invoiceSelect');
var amt = document.getElementById('amountInput');
var recordPaymentForm = document.getElementById('recordPaymentForm');
var invoiceFields = document.getElementById('invoicePaymentFields');
var manualFields = document.getElementById('manualPaymentFields');
var manualClientId = document.getElementById('manualClientId');
var manualClientSearch = document.getElementById('manualClientSearch');
var manualClientSuggest = document.getElementById('manualClientSuggest');
var manualJobSearch = document.getElementById('manualJobSearch');
var manualJobSelect = document.getElementById('manualJobSelect');
var manualJobExpected = document.getElementById('manualJobExpected');
var manualJobExpectedAmount = document.getElementById('manualJobExpectedAmount');
var manualJobVariance = document.getElementById('manualJobVariance');
var scopeInputs = Array.from(document.querySelectorAll('input[name="payment_scope"]'));
var sendReceiptInput = document.getElementById('sendReceiptInput');
var sendReceiptHelp = document.getElementById('sendReceiptHelp');
var selectedManualClientEmail = '';

function selectedScope() {
    var checked = scopeInputs.find(function (input) { return input.checked; });
    return checked ? checked.value : 'invoice';
}

function escapeHtml(text) {
    var div = document.createElement('div');
    div.textContent = text == null ? '' : String(text);
    return div.innerHTML;
}

function escapeAttribute(text) {
    return escapeHtml(text).replace(/"/g, '&quot;').replace(/'/g, '&#39;');
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
    if (manualClientSearch) manualClientSearch.required = false;
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
    updateReceiptAvailability();
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
        return '<button type="button" data-client-id="' + escapeAttribute(client.id) + '" data-client-name="' + escapeAttribute(client.name) + '" data-client-email="' + escapeAttribute(client.email || '') + '" style="display:block;width:100%;text-align:left;padding:9px 10px;border:0;border-bottom:1px solid #eef2f7;background:#fff;cursor:pointer">' +
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
        selectedManualClientEmail = '';
        updateReceiptAvailability();
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

    manualClientSuggest.addEventListener('click', function (event) {
        var option = event.target.closest('[data-client-id]');
        if (!option) return;
        manualClientId.value = option.getAttribute('data-client-id') || '';
        manualClientSearch.value = option.getAttribute('data-client-name') || '';
        selectedManualClientEmail = option.getAttribute('data-client-email') || '';
        hideManualClientSuggestions();
        updateReceiptAvailability();
    });

    document.addEventListener('click', function (event) {
        if (!manualClientSuggest.contains(event.target) && event.target !== manualClientSearch) {
            hideManualClientSuggestions();
        }
    });
}

function selectedJobOption() {
    if (!manualJobSelect || !manualJobSelect.value) return null;
    return manualJobSelect.options[manualJobSelect.selectedIndex] || null;
}

function effectiveManualClientEmail() {
    if (selectedManualClientEmail) return selectedManualClientEmail;
    var jobOption = selectedJobOption();
    return jobOption ? (jobOption.getAttribute('data-client-email') || '') : '';
}

function updateReceiptAvailability() {
    if (!sendReceiptInput) return;
    if (selectedScope() !== 'manual') {
        sendReceiptInput.disabled = false;
        if (sendReceiptHelp) sendReceiptHelp.textContent = 'A receipt can be emailed to the invoice client.';
        return;
    }

    var hasEmail = effectiveManualClientEmail().trim() !== '';
    sendReceiptInput.disabled = !hasEmail;
    if (!hasEmail) sendReceiptInput.checked = false;
    if (sendReceiptHelp) {
        sendReceiptHelp.textContent = hasEmail
            ? 'A receipt can be emailed to the selected client.'
            : 'Email receipt is unavailable until a client with an email address is selected.';
    }
}

function formatMoney(amount, currency) {
    try {
        return new Intl.NumberFormat(undefined, { style: 'currency', currency: currency || 'USD' }).format(amount);
    } catch (error) {
        return '$' + Number(amount || 0).toFixed(2);
    }
}

function updateJobVariance() {
    var jobOption = selectedJobOption();
    if (!jobOption || !manualJobExpected || !manualJobExpectedAmount || !manualJobVariance) {
        if (manualJobExpected) manualJobExpected.hidden = true;
        return;
    }

    manualJobExpected.hidden = false;
    var known = jobOption.getAttribute('data-expected-known') === '1';
    var expected = Number(jobOption.getAttribute('data-expected') || 0);
    var currency = jobOption.getAttribute('data-currency') || 'USD';
    if (!known) {
        manualJobExpectedAmount.textContent = 'Not configured';
        manualJobVariance.textContent = 'The payment can still be recorded. Review this service job’s client-billing setup when convenient.';
        return;
    }

    manualJobExpectedAmount.textContent = formatMoney(expected, currency);
    var actual = Number(amt && amt.value !== '' ? amt.value : NaN);
    if (!Number.isFinite(actual)) {
        manualJobVariance.textContent = 'Enter the amount actually received to compare it with the expected charge.';
        return;
    }
    var variance = Math.round((actual - expected) * 100) / 100;
    if (Math.abs(variance) < 0.005) {
        manualJobVariance.textContent = 'The received amount matches the expected charge.';
        return;
    }
    manualJobVariance.textContent = 'Variance: ' + formatMoney(variance, currency) + '. This is a warning only; the amount actually received will be saved.';
}

function syncJobClient() {
    var jobOption = selectedJobOption();
    if (jobOption && manualClientId && manualClientSearch) {
        var jobClientId = jobOption.getAttribute('data-client-id') || '';
        var jobClientName = jobOption.getAttribute('data-client-name') || '';
        if (jobClientId && jobClientId !== '0') {
            manualClientId.value = jobClientId;
            manualClientSearch.value = jobClientName;
            selectedManualClientEmail = jobOption.getAttribute('data-client-email') || '';
        }
    }
    updateJobVariance();
    updateReceiptAvailability();
}

if (manualJobSearch && manualJobSelect) {
    manualJobSearch.addEventListener('input', function () {
        var term = manualJobSearch.value.trim().toLowerCase();
        Array.from(manualJobSelect.options).forEach(function (option, index) {
            option.hidden = index > 0 && term !== '' && (option.getAttribute('data-search') || '').indexOf(term) === -1;
        });
        var selected = selectedJobOption();
        if (selected && selected.hidden) {
            manualJobSelect.value = '';
            syncJobClient();
        }
    });
    manualJobSelect.addEventListener('change', syncJobClient);
}

if (amt) amt.addEventListener('input', updateJobVariance);

togglePaymentScope();
