var itemCounterCo = 0;

function money(n) { return '$' + (Number(n) || 0).toFixed(2) }

function addItemCo(item = '', desc = '', qty = 1, price = 0) {
    var wrap = document.createElement('div');
    var itemId = 'itemCo_' + (itemCounterCo++);
    var descId = 'descCo_' + itemCounterCo;
    var priceId = 'priceCo_' + itemCounterCo;
    wrap.style.display = 'grid'; wrap.style.gridTemplateColumns = '3fr 3fr 1fr 1fr auto'; wrap.style.gap = '8px';
    wrap.innerHTML = `
    <input id="${itemId}" required placeholder="Item name..." name="item[]" style="padding:10px;border-radius:8px;border:1px solid #ddd" value="${item}" oninput="recalcCo()" data-item-autocomplete data-description-field="${descId}" data-price-field="${priceId}">
    <textarea id="${descId}" placeholder="Description (optional)" name="item_desc[]" style="padding:10px;border-radius:8px;border:1px solid #ddd;resize:vertical;min-height:42px" oninput="recalcCo()">${desc}</textarea>
    <input required type="number" step="0.01" min="0" name="item_qty[]" style="padding:10px;border-radius:8px;border:1px solid #ddd" value="${qty}" oninput="recalcCo()">
    <input id="${priceId}" required type="number" step="0.01" min="0" name="item_price[]" style="padding:10px;border-radius:8px;border:1px solid #ddd" value="${price}" oninput="recalcCo()">
    <button type="button" onclick="this.parentElement.remove();recalcCo()" style="border:0;background:#fee2e2;color:#991b1b;border-radius:8px;padding:8px 10px">Remove</button>
  `;
    document.getElementById('itemsCo').appendChild(wrap);

    // Re-initialize autocomplete for the new item input
    if (window.ItemAutocomplete) {
        const input = document.getElementById(itemId);
        const descField = document.getElementById(descId);
        const priceField = document.getElementById(priceId);
        new ItemAutocomplete(input, {
            descriptionField: descField,
            priceField: priceField
        });
    }

    recalcCo();
}
function recalcCo() {
    var docType = document.querySelector('input[name="doc_type"]:checked').value;
    var isLongTerm = (docType === 'long_term');
    var isOnDemand = (docType === 'on_demand');
    var pricingType = (isLongTerm || isOnDemand) ? document.querySelector('input[name="pricing_type"]:checked')?.value : null;
    var isOngoing = (isLongTerm || isOnDemand) && document.getElementById('endDateTypeCo').value === 'ongoing';

    var subtotal = 0;

    // Calculate subtotal based on pricing type
    if (isLongTerm && (pricingType === 'per_invoice' || pricingType === 'on_demand')) {
        // Use price per invoice
        subtotal = parseFloat(document.getElementById('pricePerInvoiceInput').value) || 0;
    } else {
        // Use line items
        var qtys = Array.from(document.querySelectorAll('[name=\"item_qty[]\"]')).map(e => parseFloat(e.value) || 0);
        var prices = Array.from(document.querySelectorAll('[name=\"item_price[]\"]')).map(e => parseFloat(e.value) || 0);
        for (var i = 0; i < qtys.length; i++) { subtotal += qtys[i] * prices[i]; }
    }

    // For fixed_total pricing, recalculate based on items
    if (isLongTerm && pricingType === 'fixed_total') {
        var qtys = Array.from(document.querySelectorAll('[name=\"item_qty[]\"]')).map(e => parseFloat(e.value) || 0);
        var prices = Array.from(document.querySelectorAll('[name=\"item_price[]\"]')).map(e => parseFloat(e.value) || 0);
        subtotal = 0;
        for (var i = 0; i < qtys.length; i++) { subtotal += qtys[i] * prices[i]; }
    }

    var dtype = document.getElementById('discountTypeCo').value;
    var dval = parseFloat(document.getElementById('discountValueCo').value) || 0;
    var taxp = parseFloat(document.getElementById('taxPercentCo').value) || 0;
    var discount = 0;
    if (dtype === 'percent') { discount = Math.max(0, Math.min(100, dval)) * subtotal / 100; }
    else if (dtype === 'fixed') { discount = Math.max(0, dval); }
    var taxable = Math.max(0, subtotal - discount);
    var tax = Math.max(0, taxp) * taxable / 100;
    var total = Math.max(0, taxable + tax);

    // Calculate deposit
    var depType = document.getElementById('depositTypeCo').value;
    var depVal = parseFloat(document.getElementById('depositValueCo').value) || 0;
    var deposit = 0;
    if (depType === 'percent') { deposit = Math.max(0, Math.min(100, depVal)) * total / 100; }
    else if (depType === 'fixed') { deposit = Math.max(0, depVal); }

    document.getElementById('subtotalValCo').textContent = money(subtotal);
    document.getElementById('discountValCo').textContent = money(discount);
    document.getElementById('taxValCo').textContent = money(tax);

    // For ongoing contracts, don't show total (unknown)
    if (isOngoing) {
        document.getElementById('totalValCo').parentElement.style.display = 'none';
    } else {
        document.getElementById('totalValCo').parentElement.style.display = 'flex';
        document.getElementById('totalValCo').textContent = money(total);
    }

    // Show calculated price per invoice for fixed_total
    if (isLongTerm && pricingType === 'fixed_total') {
        var invoiceCount = parseInt(document.getElementById('invoiceCountInputCo').value) || 1;
        var pricePerInv = total / invoiceCount;
        document.getElementById('calcPriceValCo').textContent = money(pricePerInv);
        document.getElementById('invoiceAmountRow').style.display = 'none';
    } else if (isLongTerm) {
        document.getElementById('invoiceAmountRow').style.display = 'block';
        document.getElementById('invoiceAmountVal').textContent = money(total);
    } else {
        document.getElementById('invoiceAmountRow').style.display = 'none';
    }

    // Show/hide deposit row
    if (depType !== 'none' && deposit > 0) {
        document.getElementById('depositRowCo').style.display = 'block';
        document.getElementById('depositValCo').textContent = money(deposit);
    } else {
        document.getElementById('depositRowCo').style.display = 'none';
    }

    // Update discount warning
    updateDiscountWarning();
}
// Safely add event listeners only if elements exist
['discountTypeCo', 'discountValueCo', 'taxPercentCo'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('input', recalcCo);
});
['depositTypeCo', 'depositValueCo'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('input', recalcCo);
});
const discountTypeElCo = document.getElementById('discountTypeCo');
if (discountTypeElCo) discountTypeElCo.addEventListener('change', updateDiscountWarning);

// No need for DOMContentLoaded start date setting - now handled in toggleDocTypeFields

function toggleDocTypeFields() {
    var docType = document.querySelector('input[name="doc_type"]:checked').value;
    var isLongTerm = (docType === 'long_term');
    var isOnDemand = (docType === 'on_demand');

    document.getElementById('longTermFields').style.display = (isLongTerm || isOnDemand) ? 'block' : 'none';

    if (isLongTerm || isOnDemand) {
        // Set start date to today when first enabling LT or On-Demand
        var startField = document.getElementById('startDateFieldCo');
        if (!startField.value) {
            startField.value = new Date().toISOString().split('T')[0];
        }

        // Hide/show billing intervals based on type
        var billingFields = document.querySelector('#longTermFields > div:nth-of-type(3)');
        if (billingFields) {
            billingFields.style.display = isOnDemand ? 'none' : 'grid';
        }

        // Trigger toggleEndDate to set initial state correctly
        toggleEndDate();
        togglePricingFields();
        updateDiscountWarning();
    } else {
        // Regular contract - always show items
        document.getElementById('itemsCo').parentElement.style.display = 'block';
        document.getElementById('invoiceAmountRow').style.display = 'none';
        // Show custom fields for regular contracts (if they exist)
        ['depositTypeLabelCo', 'depositValueLabelCo', 'fulfillmentDateLabelCo'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.style.display = 'block';
        });
    }
    recalcCo();
}

function toggleEndDate() {
    var type = document.getElementById('endDateTypeCo').value;
    var isOngoing = (type === 'ongoing');

    document.getElementById('endDateFieldCo').style.display = isOngoing ? 'none' : 'block';

    // Hide fulfillment date when ongoing
    var fulfillmentLabel = document.getElementById('fulfillmentDateLabelCo');
    var docType = document.querySelector('input[name="doc_type"]:checked').value;
    if (docType === 'long_term' && fulfillmentLabel) {
        fulfillmentLabel.style.display = isOngoing ? 'none' : 'block';
    }

    // Show/hide fixed total option based on contract duration
    var fixedTotalOption = document.getElementById('fixedTotalOption');
    if (isOngoing) {
        fixedTotalOption.style.display = 'none';
        // Force per_invoice if ongoing
        document.getElementById('recurringPerInvoice').checked = true;
    } else {
        fixedTotalOption.style.display = 'flex';
    }

    togglePricingFields();
    updateDiscountWarning();
    recalcCo();
}

function togglePricingFields() {
    var docType = document.querySelector('input[name="doc_type"]:checked').value;
    var isLongTerm = (docType === 'long_term');
    var isOnDemand = (docType === 'on_demand');

    if (docType === 'regular') {
        // Regular contract - show custom fields
        ['depositTypeLabelCo', 'depositValueLabelCo', 'fulfillmentDateLabelCo'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.style.display = 'block';
        });
        return;
    }

    var pricingType = document.querySelector('input[name="pricing_type"]:checked').value;

    if (pricingType === 'per_invoice') {
        // Recurring amount - hide custom fields
        ['depositTypeLabelCo', 'depositValueLabelCo', 'fulfillmentDateLabelCo'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.style.display = 'none';
        });
    } else if (pricingType === 'on_demand') {
        // On-demand - show deposits, hide fulfillment
        ['depositTypeLabelCo', 'depositValueLabelCo'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.style.display = 'block';
        });
        const fulfillmentLabel = document.getElementById('fulfillmentDateLabelCo');
        if (fulfillmentLabel) fulfillmentLabel.style.display = 'none';
    } else {
        // Fixed total - show deposit and fulfillment
        ['depositTypeLabelCo', 'depositValueLabelCo'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.style.display = 'block';
        });
        var isOngoing = document.getElementById('endDateTypeCo').value === 'ongoing';
        const fulfillmentLabel = document.getElementById('fulfillmentDateLabelCo');
        if (fulfillmentLabel) fulfillmentLabel.style.display = isOngoing ? 'none' : 'block';
        document.getElementById('depositValueLabelCo').style.display = 'block';
        document.getElementById('fulfillmentDateLabelCo').style.display = 'block';
        return;
    }

    var pricingType = document.querySelector('input[name="pricing_type"]:checked').value;

    if (pricingType === 'per_invoice') {
        // Recurring amount - hide deposit and fulfillment
        document.getElementById('depositTypeLabelCo').style.display = 'none';
        document.getElementById('depositValueLabelCo').style.display = 'none';
        document.getElementById('fulfillmentDateLabelCo').style.display = 'none';
        document.getElementById('perInvoiceField').style.display = 'block';
        document.getElementById('fixedTotalFieldsCo').style.display = 'none';
        document.getElementById('itemsCo').parentElement.style.display = 'none';
    } else if (pricingType === 'on_demand') {
        // On-demand - show deposits, hide fulfillment, show price per invoice
        document.getElementById('depositTypeLabelCo').style.display = 'block';
        document.getElementById('depositValueLabelCo').style.display = 'block';
        document.getElementById('fulfillmentDateLabelCo').style.display = 'none';
        document.getElementById('perInvoiceField').style.display = 'block';
        document.getElementById('fixedTotalFieldsCo').style.display = 'none';
        document.getElementById('itemsCo').parentElement.style.display = 'none';
    } else {
        // Fixed total - show deposit and fulfillment
        document.getElementById('depositTypeLabelCo').style.display = 'block';
        document.getElementById('depositValueLabelCo').style.display = 'block';
        var isOngoing = document.getElementById('endDateTypeCo').value === 'ongoing';
        document.getElementById('fulfillmentDateLabelCo').style.display = isOngoing ? 'none' : 'block';
        document.getElementById('perInvoiceField').style.display = 'none';
        document.getElementById('fixedTotalFieldsCo').style.display = 'block';
        document.getElementById('itemsCo').parentElement.style.display = 'block';
    }
    recalcCo();
}

function updateDiscountWarning() {
    var docType = document.querySelector('input[name="doc_type"]:checked').value;
    var isLongTerm = (docType === 'long_term');
    var isOngoing = document.getElementById('endDateTypeCo').value === 'ongoing';
    var discountType = document.getElementById('discountTypeCo').value;

    var warning = document.getElementById('discountWarning');
    if (isLongTerm && isOngoing && discountType !== 'none') {
        warning.style.display = 'block';
    } else {
        warning.style.display = 'none';
    }
}

addItemCo();

// Client typeahead
var ci = document.getElementById('clientInputCo');
var cid = document.getElementById('clientIdCo');
var sug = document.getElementById('clientSuggestCo');
var taxBanner = document.getElementById('taxExemptBannerCo');
ci.addEventListener('input', function () {
    cid.value = '';
    var t = this.value.trim();
    if (!t) { sug.style.display = 'none'; sug.innerHTML = ''; taxBanner.style.display = 'none'; return; }
    fetch('/?page=clients-search&term=' + encodeURIComponent(t))
        .then(r => r.json())
        .then(list => {
            if (!Array.isArray(list) || list.length === 0) { sug.style.display = 'none'; sug.innerHTML = ''; return; }
            sug.innerHTML = list.map(x => `<div data-id="${x.id}" data-name="${x.name}" data-taxexempt="${x.tax_exempt_file || ''}" style="padding:8px 10px;cursor:pointer">${x.name}</div>`).join('');
            Array.from(sug.children).forEach(el => {
                el.addEventListener('click', function () {
                    ci.value = this.dataset.name; cid.value = this.dataset.id;
                    if (this.dataset.taxexempt) { taxBanner.style.display = 'block'; } else { taxBanner.style.display = 'none'; }
                    loadProjectsForClientCo(this.dataset.id);
                    sug.style.display = 'none';
                });
            });
            sug.style.display = 'block';
        }).catch(() => { sug.style.display = 'none' });
});
document.addEventListener('click', function (e) { if (!sug.contains(e.target) && e.target !== ci) { sug.style.display = 'none'; } });

function loadProjectsForClientCo(clientId) {
    if (!clientId) {
        document.getElementById('projectSectionCo').style.display = 'none';
        return;
    }

    fetch('/?page=projects-search&client_id=' + encodeURIComponent(clientId))
        .then(r => r.json())
        .then(projects => {
            const projectSelect = document.getElementById('projectSelectCo');
            projectSelect.innerHTML = '<option value="">-- Select Project --</option>';

            if (projects && projects.length > 0) {
                projects.forEach(project => {
                    const option = document.createElement('option');
                    option.value = project.id;
                    option.textContent = project.name + ' (' + project.status.replace('_', ' ') + ')';
                    projectSelect.appendChild(option);
                });
                document.getElementById('projectSectionCo').style.display = 'block';
            } else {
                document.getElementById('projectSectionCo').style.display = 'none';
            }
        })
        .catch(() => {
            document.getElementById('projectSectionCo').style.display = 'none';
        });
}

document.getElementById('createProjectBtnCo').addEventListener('click', function () {
    const clientId = document.getElementById('clientIdCo').value;
    if (!clientId) {
        alert('Please select a client first.');
        return;
    }

    const projectName = prompt('Enter project name:');
    if (!projectName || !projectName.trim()) return;

    fetch('/?page=project/projects-create-quick', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'csrf=' + encodeURIComponent(document.querySelector('input[name="csrf"]').value) +
            '&name=' + encodeURIComponent(projectName.trim()) +
            '&client_id=' + encodeURIComponent(clientId)
    })
        .then(r => r.json())
        .then(result => {
            if (result.success) {
                // Reload projects for this client
                loadProjectsForClientCo(clientId);
                // Select the new project
                setTimeout(() => {
                    const projectSelect = document.getElementById('projectSelectCo');
                    for (let i = 0; i < projectSelect.options.length; i++) {
                        if (projectSelect.options[i].value == result.project_id) {
                            projectSelect.selectedIndex = i;
                            break;
                        }
                    }
                }, 100);
            } else {
                alert('Failed to create project: ' + (result.error || 'Unknown error'));
            }
        })
        .catch(() => {
            alert('Failed to create project.');
        });
});

document.getElementById('coCreateForm').addEventListener('submit', function (e) {
    if (!cid.value) {
        e.preventDefault();
        alert('Please select a client from suggestions.');
        return;
    }
    // Set form action based on contract type
    var docType = document.querySelector('input[name="doc_type"]:checked').value;
    if (docType === 'long_term') {
        this.action = '/?page=long-term-contracts-create';
    } else if (docType === 'on_demand') {
        this.action = '/?page=on-demand-contracts-create';
    } else {
        this.action = '/?page=contracts-create';
    }
});

// Signature Management
let signatureCount = 0;
const MAX_SIGNATURES = 5;

function addSignature(title = 'Client Signature', isRequired = true) {
    if (signatureCount >= MAX_SIGNATURES) {
        alert('Maximum of ' + MAX_SIGNATURES + ' signatures allowed');
        return;
    }

    signatureCount++;
    const sigId = 'sig_' + Date.now() + '_' + signatureCount;

    const wrap = document.createElement('div');
    wrap.className = 'signature-item';
    wrap.dataset.sigId = sigId;
    wrap.style.cssText = 'display:grid;grid-template-columns:1fr auto auto;gap:12px;align-items:center;padding:12px;border:1px solid #e5e7eb;border-radius:6px;background:#fff';

    wrap.innerHTML = `
    <div style="display:grid;gap:8px">
      <input type="text" name="signature_titles[]" value="${title}" required placeholder="Signature Title (e.g., Project Manager, Owner)"
             style="padding:8px;border-radius:6px;border:1px solid #ddd;font-weight:600">
      <div style="font-size:12px;color:var(--muted)">Order: ${signatureCount}</div>
    </div>
    <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer">
      <input type="checkbox" name="signature_required[]" value="${sigId}" ${isRequired ? 'checked' : ''}>
      Required
    </label>
    <button type="button" onclick="removeSignature('${sigId}')" ${signatureCount === 1 ? 'disabled style="opacity:0.5;cursor:not-allowed"' : ''}
            style="padding:6px 12px;border-radius:6px;border:1px solid #fca5a5;background:#fee2e2;color:#991b1b;font-size:13px">
      Remove
    </button>
    <input type="hidden" name="signature_orders[]" value="${signatureCount}">
  `;

    document.getElementById('signaturesList').appendChild(wrap);

    if (signatureCount >= MAX_SIGNATURES) {
        document.getElementById('addSigBtn').disabled = true;
        document.getElementById('addSigBtn').style.opacity = '0.5';
        document.getElementById('addSigBtn').style.cursor = 'not-allowed';
    }
}

function removeSignature(sigId) {
    const item = document.querySelector(`[data-sig-id="${sigId}"]`);
    if (item) {
        item.remove();
        signatureCount--;

        // Re-enable add button if under max
        if (signatureCount < MAX_SIGNATURES) {
            document.getElementById('addSigBtn').disabled = false;
            document.getElementById('addSigBtn').style.opacity = '1';
            document.getElementById('addSigBtn').style.cursor = 'pointer';
        }

        // Update order numbers
        const items = document.querySelectorAll('.signature-item');
        items.forEach((el, idx) => {
            const orderDiv = el.querySelector('[style*="Order:"]');
            if (orderDiv) orderDiv.textContent = 'Order: ' + (idx + 1);
            const orderInput = el.querySelector('input[name="signature_orders[]"]');
            if (orderInput) orderInput.value = idx + 1;
        });

        // Disable remove button on first signature if it's the only one
        if (signatureCount === 1) {
            const firstRemoveBtn = items[0]?.querySelector('button[onclick^="removeSignature"]');
            if (firstRemoveBtn) {
                firstRemoveBtn.disabled = true;
                firstRemoveBtn.style.opacity = '0.5';
                firstRemoveBtn.style.cursor = 'not-allowed';
            }
        }
    }
}

// Add default signature on page load
addSignature();