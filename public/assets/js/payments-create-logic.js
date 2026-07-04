var sel = document.getElementById('invoiceSelect');
var amt = document.getElementById('amountInput');
var invoiceFields = document.getElementById('invoicePaymentFields');
var manualFields = document.getElementById('manualPaymentFields');
var manualClientSelect = document.getElementById('manualClientSelect');
var scopeInputs = Array.from(document.querySelectorAll('input[name="payment_scope"]'));
var sendReceiptInput = document.getElementById('sendReceiptInput');

function selectedScope() {
    var checked = scopeInputs.find(function (input) { return input.checked; });
    return checked ? checked.value : 'invoice';
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
    if (manualClientSelect) manualClientSelect.required = isManual;
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
togglePaymentScope();
