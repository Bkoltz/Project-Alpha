var sel = document.getElementById('invoiceSelect');
var amt = document.getElementById('amountInput');
function update() {
    var opt = sel.options[sel.selectedIndex];
    if (!opt) return;
    var r = opt.getAttribute('data-remaining');
    if (r) { amt.value = r; }
}
sel.addEventListener('change', update);
if (!amt.value) { update(); }

// Toggle check number field based on payment method
var methodSelect = document.getElementById('paymentMethod');
var checkField = document.getElementById('checkNumberField');
var checkInput = document.getElementById('checkNumberInput');
var stripeNotice = document.getElementById('stripeNotice');

function togglePaymentFields() {
    var method = methodSelect.value.toLowerCase();
    // Check field
    if (method === 'check' || method === 'bank_transfer') {
        checkField.style.display = 'block';
        checkInput.required = true;
        checkInput.placeholder = method === 'check' ? 'Enter check number' : 'Enter reference / transaction number';
    } else {
        checkField.style.display = 'none';
        checkInput.required = false;
    }
    // Stripe notice
    if (stripeNotice) {
        stripeNotice.style.display = method === 'stripe' ? 'block' : 'none';
    }
}

methodSelect.addEventListener('change', togglePaymentFields);
togglePaymentFields(); // Run on page load
