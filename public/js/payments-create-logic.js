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

function toggleCheckField() {
    var method = methodSelect.value.toLowerCase();
    if (method === 'check') {
        checkField.style.display = 'block';
        checkInput.required = true;
    } else {
        checkField.style.display = 'none';
        checkInput.required = false;
    }
}

methodSelect.addEventListener('change', toggleCheckField);
toggleCheckField(); // Run on page load