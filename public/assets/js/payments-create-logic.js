var sel = document.getElementById('invoiceSelect');
var amt = document.getElementById('amountInput');
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
        stripeNotice.style.display = method === 'stripe' ? 'block' : 'none';
    }
}

if (methodSelect) {
    methodSelect.addEventListener('change', togglePaymentFields);
    togglePaymentFields();
}
