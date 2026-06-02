function money(n) {
    return '$' + (Number(n) || 0).toFixed(2)
}

var itemCounter = 0;

function addItem(item = '', desc = '', qty = 1, price = 0) {
    var wrap = document.createElement('div');
    var itemId = 'item_' + (itemCounter++);
    var descId = 'desc_' + itemCounter;
    var priceId = 'price_' + itemCounter;
    wrap.style.display = 'grid'; wrap.style.gridTemplateColumns = '3fr 3fr 1fr 1fr auto'; wrap.style.gap = '8px';
    wrap.innerHTML = `
    <input id="${itemId}" required placeholder="Item name..." name="item[]" style="padding:10px;border-radius:8px;border:1px solid #ddd" value="${item}" oninput="recalc()" data-item-autocomplete data-description-field="${descId}" data-price-field="${priceId}">
    <textarea id="${descId}" placeholder="Description (optional)" name="item_desc[]" style="padding:10px;border-radius:8px;border:1px solid #ddd;resize:vertical;min-height:42px" oninput="recalc()">${desc}</textarea>
    <input required type="number" step="0.01" min="0" name="item_qty[]" style="padding:10px;border-radius:8px;border:1px solid #ddd" value="${qty}" oninput="recalc()">
    <input id="${priceId}" required type="number" step="0.01" min="0" name="item_price[]" style="padding:10px;border-radius:8px;border:1px solid #ddd" value="${price}" oninput="recalc()">
    <button type="button" onclick="this.parentElement.remove();recalc()" style="border:0;background:#fee2e2;color:#991b1b;border-radius:8px;padding:8px 10px">Remove</button>
  `;
    document.getElementById('items').appendChild(wrap);

    // Re-initialize autocomplete for the new item input
    function initAutocomplete() {
        if (window.ItemAutocomplete) {
            const input = document.getElementById(itemId);
            if (input && !input._itemAutocomplete) {
                const descField = document.getElementById(descId);
                const priceField = document.getElementById(priceId);
                const instance = new ItemAutocomplete(input, {
                    descriptionField: descField,
                    priceField: priceField
                });
                // Mark as initialized
                input._itemAutocomplete = instance;
            }
        } else {
            // Retry after a short delay if ItemAutocomplete not loaded yet
            setTimeout(initAutocomplete, 100);
        }
    }

    initAutocomplete();

    recalc();
}

// Calculate number of billing periods between two dates
function calcInvoiceCountFromDates(startDate, endDate, intervalCount, intervalUnit) {
    if (!startDate || !endDate) return 0;
    var start = new Date(startDate);
    var end = new Date(endDate);
    if (isNaN(start) || isNaN(end) || end <= start) return 0;
    var periods = 0;
    var cursor = new Date(start);
    while (cursor < end) {
        if (intervalUnit === 'day') cursor.setDate(cursor.getDate() + intervalCount);
        else if (intervalUnit === 'week') cursor.setDate(cursor.getDate() + 7 * intervalCount);
        else if (intervalUnit === 'month') cursor.setMonth(cursor.getMonth() + intervalCount);
        else if (intervalUnit === 'year') cursor.setFullYear(cursor.getFullYear() + intervalCount);
        if (cursor <= end) periods++;
    }
    return Math.max(1, periods);
}

function recalc() {
    var docType = document.querySelector('input[name="doc_type"]:checked').value;
    var isLongTerm = (docType === 'long_term');
    var isOnDemand = (docType === 'on_demand');
    // Get pricing type from radio (long-term) or hidden field (on-demand)
    var pricingTypeEl = document.querySelector('input[name="lt_pricing_type"]:checked') || document.querySelector('input[name="lt_pricing_type"][type="hidden"]');
    var pricingType = (isLongTerm || isOnDemand) ? (pricingTypeEl?.value || null) : null;
    var endDateEl = document.getElementById('endDateType');
    var isOngoing = (isLongTerm || isOnDemand) && endDateEl && endDateEl.value === 'ongoing';
    var hasFixedEnd = endDateEl && endDateEl.value === 'fixed';

    // Auto-calculate invoice count when Fixed End Date + Fixed Total
    if (isLongTerm && pricingType === 'fixed_total' && hasFixedEnd) {
        var startVal = document.getElementById('startDateField').value;
        var endVal = document.querySelector('input[name="lt_end_date"]').value;
        var intCount = parseInt(document.getElementById('billingIntervalCount').value) || 1;
        var intUnit = document.getElementById('billingIntervalUnit').value;
        var autoCount = calcInvoiceCountFromDates(startVal, endVal, intCount, intUnit);
        var invoiceInput = document.getElementById('invoiceCountInput');
        if (invoiceInput) {
            invoiceInput.value = autoCount;
            invoiceInput.readOnly = true;
            invoiceInput.style.backgroundColor = '#f3f4f6';
        }
    } else {
        var invoiceInput = document.getElementById('invoiceCountInput');
        if (invoiceInput) {
            invoiceInput.readOnly = false;
            invoiceInput.style.backgroundColor = '';
        }
    }

    var subtotal = 0;

    // Calculate subtotal based on pricing type
    if (isLongTerm && (pricingType === 'per_invoice' || pricingType === 'on_demand')) {
        subtotal = parseFloat(document.getElementById('pricePerInvoiceInput').value) || 0;
    } else {
        var qtys = Array.from(document.querySelectorAll('[name=\"item_qty[]\"]')).map(e => parseFloat(e.value) || 0);
        var prices = Array.from(document.querySelectorAll('[name=\"item_price[]\"]')).map(e => parseFloat(e.value) || 0);
        for (var i = 0; i < qtys.length; i++) { subtotal += qtys[i] * prices[i]; }
    }

    // For fixed_total pricing, calculate price per invoice
    if (isLongTerm && pricingType === 'fixed_total') {
        var invoiceCount = parseInt(document.getElementById('invoiceCountInput').value) || 1;
        var qtys = Array.from(document.querySelectorAll('[name=\"item_qty[]\"]')).map(e => parseFloat(e.value) || 0);
        var prices = Array.from(document.querySelectorAll('[name=\"item_price[]\"]')).map(e => parseFloat(e.value) || 0);
        subtotal = 0;
        for (var i = 0; i < qtys.length; i++) { subtotal += qtys[i] * prices[i]; }
    }

    var dtype = document.getElementById('discountType').value;
    var dval = parseFloat(document.getElementById('discountValue').value) || 0;
    var taxp = parseFloat(document.getElementById('taxPercent').value) || 0;
    var discount = 0;
    if (dtype === 'percent') { discount = Math.max(0, Math.min(100, dval)) * subtotal / 100; }
    else if (dtype === 'fixed') { discount = Math.max(0, dval); }
    var taxable = Math.max(0, subtotal - discount);
    var tax = Math.max(0, taxp) * taxable / 100;
    var total = Math.max(0, taxable + tax);

    // Calculate deposit
    var depositTypeEl = document.getElementById('depositType');
    var depositValueEl = document.getElementById('depositValue');
    var depType = depositTypeEl ? depositTypeEl.value : 'none';
    var depVal = depositValueEl ? parseFloat(depositValueEl.value) || 0 : 0;
    var deposit = 0;
    if (depType === 'percent') { deposit = Math.max(0, Math.min(100, depVal)) * total / 100; }
    else if (depType === 'fixed') { deposit = Math.max(0, depVal); }

    document.getElementById('subtotalVal').textContent = money(subtotal);
    document.getElementById('discountVal').textContent = money(discount);
    document.getElementById('taxVal').textContent = money(tax);

    // For ongoing quotes, don't show total (unknown)
    if (isOngoing) {
        document.getElementById('totalVal').parentElement.style.display = 'none';
    } else {
        document.getElementById('totalVal').parentElement.style.display = 'flex';
        document.getElementById('totalVal').textContent = money(total);
    }

    // Show calculated price per invoice for fixed_total
    if (isLongTerm && pricingType === 'fixed_total') {
        var invoiceCount = parseInt(document.getElementById('invoiceCountInput').value) || 1;
        var pricePerInv = total / invoiceCount;
        document.getElementById('calcPriceVal').textContent = money(pricePerInv);
        document.getElementById('invoiceAmountRow').style.display = 'none';
    } else if (isLongTerm) {
        document.getElementById('invoiceAmountRow').style.display = 'block';
        document.getElementById('invoiceAmountVal').textContent = money(total);
    } else {
        document.getElementById('invoiceAmountRow').style.display = 'none';
    }

    // Show/hide deposit row
    var depositRowEl = document.getElementById('depositRow');
    var depositValEl = document.getElementById('depositVal');
    if (depositRowEl) {
        if (depType !== 'none' && deposit > 0) {
            depositRowEl.style.display = 'block';
            if (depositValEl) depositValEl.textContent = money(deposit);
        } else {
            depositRowEl.style.display = 'none';
        }
    }

    updateDiscountWarning();
}

// Safely add event listeners only if elements exist
['discountType', 'discountValue', 'taxPercent'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('input', recalc);
});

['depositType', 'depositValue'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('input', recalc);
});

var discountTypeEl = document.getElementById('discountType');
if (discountTypeEl) discountTypeEl.addEventListener('change', updateDiscountWarning);

// No need for DOMContentLoaded start date setting - now handled in toggleDocTypeFields
function toggleDocTypeFields() {
    var docType = document.querySelector('input[name="doc_type"]:checked').value;
    var isLongTerm = (docType === 'long_term');
    var isOnDemand = (docType === 'on_demand');

    // Show/hide the appropriate settings sections
    document.getElementById('longTermFields').style.display = isLongTerm ? 'block' : 'none';
    var onDemandSection = document.getElementById('onDemandFields');
    if (onDemandSection) onDemandSection.style.display = isOnDemand ? 'block' : 'none';

    // Always show items section (all quote types use items, except long-term per_invoice)
    document.getElementById('items').parentElement.style.display = 'block';
    document.getElementById('invoiceAmountRow').style.display = 'none';

    if (isLongTerm) {
        // Set start date to today when first enabling long-term
        var startField = document.getElementById('startDateField');
        if (startField && !startField.value) {
            startField.value = new Date().toISOString().split('T')[0];
        }
        toggleEndDate();
        togglePricingFields();
        updateDiscountWarning();
    } else {
        // Regular or on-demand quote - show custom fields if they exist
        ['depositTypeLabel', 'depositValueLabel', 'fulfillmentDateLabel'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.style.display = 'block';
        });
    }
    recalc();
}

function toggleEndDate() {
    var type = document.getElementById('endDateType').value;
    var isOngoing = (type === 'ongoing');

    document.getElementById('endDateField').style.display = isOngoing ? 'none' : 'block';

    // Hide fulfillment date when ongoing
    var fulfillmentLabel = document.getElementById('fulfillmentDateLabel');
    var docType = document.querySelector('input[name="doc_type"]:checked').value;
    if (docType === 'long_term' && fulfillmentLabel) {
        fulfillmentLabel.style.display = isOngoing ? 'none' : 'block';
    }

    var fixedTotalOption = document.getElementById('fixedTotalOption');
    if (isOngoing) {
        fixedTotalOption.style.display = 'none';
        document.getElementById('recurringPerInvoice').checked = true;
    } else {
        fixedTotalOption.style.display = 'flex';
    }

    togglePricingFields();
    updateDiscountWarning();
    recalc();
}

// Recalc when date/interval inputs change
['startDateField'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('change', recalc);
});
document.querySelectorAll('input[name="lt_end_date"]').forEach(el => el.addEventListener('change', recalc));
['billingIntervalCount', 'billingIntervalUnit'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('change', recalc);
});

function togglePricingFields() {
    var docType = document.querySelector('input[name="doc_type"]:checked').value;

    if (docType !== 'long_term') {
        // Regular or on-demand quote - show custom fields, items always visible
        ['depositTypeLabel', 'depositValueLabel', 'fulfillmentDateLabel'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.style.display = 'block';
        });
        document.getElementById('items').parentElement.style.display = 'block';
        // Re-enable required attributes on items
        setItemsRequired(true);
        return;
    }

    // Long-term quote - check pricing type
    var pricingTypeEl = document.querySelector('input[name="lt_pricing_type"]:checked');
    var pricingType = pricingTypeEl ? pricingTypeEl.value : 'per_invoice';

    if (pricingType === 'per_invoice') {
        // Recurring amount - hide deposit and fulfillment, hide items
        ['depositTypeLabel', 'depositValueLabel', 'fulfillmentDateLabel'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.style.display = 'none';
        });
        document.getElementById('perInvoiceField').style.display = 'block';
        document.getElementById('fixedTotalFields').style.display = 'none';
        document.getElementById('items').parentElement.style.display = 'none';
        document.getElementById('billingIntervalFields').style.display = 'grid';
        // Disable required attributes on hidden items so form can submit
        setItemsRequired(false);
    } else {
        // Fixed total - show deposit and fulfillment, show items
        ['depositTypeLabel', 'depositValueLabel'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.style.display = 'block';
        });
        var isOngoing = document.getElementById('endDateType').value === 'ongoing';
        const fulfillmentLabel = document.getElementById('fulfillmentDateLabel');
        if (fulfillmentLabel) fulfillmentLabel.style.display = isOngoing ? 'none' : 'block';
        document.getElementById('perInvoiceField').style.display = 'none';
        document.getElementById('fixedTotalFields').style.display = 'block';
        document.getElementById('items').parentElement.style.display = 'block';
        document.getElementById('billingIntervalFields').style.display = 'grid';
        // Re-enable required attributes on items
        setItemsRequired(true);
    }
    recalc();
}

// Helper to enable/disable required attribute on item inputs
function setItemsRequired(required) {
    document.querySelectorAll('#items input[name="item[]"]').forEach(el => {
        if (required) {
            el.setAttribute('required', '');
        } else {
            el.removeAttribute('required');
        }
    });
    document.querySelectorAll('#items input[name="item_qty[]"]').forEach(el => {
        if (required) {
            el.setAttribute('required', '');
        } else {
            el.removeAttribute('required');
        }
    });
    document.querySelectorAll('#items input[name="item_price[]"]').forEach(el => {
        if (required) {
            el.setAttribute('required', '');
        } else {
            el.removeAttribute('required');
        }
    });
}

function updateDiscountWarning() {
    var docType = document.querySelector('input[name="doc_type"]:checked').value;
    var isLongTerm = (docType === 'long_term');
    var isOngoing = document.getElementById('endDateType').value === 'ongoing';
    var discountType = document.getElementById('discountType').value;

    var warning = document.getElementById('discountWarning');
    if (isLongTerm && isOngoing && discountType !== 'none') {
        warning.style.display = 'block';
    } else {
        warning.style.display = 'none';
    }
}

addItem();

function loadProjectsForClient(clientId) {
    if (!clientId) {
        document.getElementById('projectSection').style.display = 'none';
        return;
    }

    fetch('/?page=projects-search&client_id=' + encodeURIComponent(clientId))
        .then(r => r.json())
        .then(projects => {
            const projectSelect = document.getElementById('projectSelect');
            projectSelect.innerHTML = '<option value="">-- Select Project --</option>';

            if (projects && projects.length > 0) {
                projects.forEach(project => {
                    const option = document.createElement('option');
                    option.value = project.id;
                    option.textContent = project.name + ' (' + project.status.replace('_', ' ') + ')';
                    projectSelect.appendChild(option);
                });
                document.getElementById('projectSection').style.display = 'block';
            } else {
                document.getElementById('projectSection').style.display = 'none';
            }
        })
        .catch(() => {
            document.getElementById('projectSection').style.display = 'none';
        });
}

document.getElementById('createProjectBtn').addEventListener('click', function () {
    const clientId = document.getElementById('clientId').value;
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
                loadProjectsForClient(clientId);
                // Select the new project
                setTimeout(() => {
                    const projectSelect = document.getElementById('projectSelect');
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

function initQuoteClientDropdown() {
    var ci = document.getElementById('clientInput');
    var cid = document.getElementById('clientId');
    var sug = document.getElementById('clientSuggest');
    var taxBanner = document.getElementById('taxExemptBanner');
    
    if (!ci || !cid || !sug) {
        console.log('Quote client dropdown elements not found');
        return;
    }
    
    ci.addEventListener('input', function () {
        cid.value = '';
        var t = this.value.trim();
        if (!t) { sug.style.display = 'none'; sug.innerHTML = ''; if(taxBanner) taxBanner.style.display = 'none'; return; }
        fetch('/?page=clients-search&term=' + encodeURIComponent(t))
            .then(r => r.json())
            .then(list => {
                if (!Array.isArray(list) || list.length === 0) { sug.style.display = 'none'; sug.innerHTML = ''; return; }
                sug.innerHTML = list.map(x => `<div data-id="${x.id}" data-name="${x.name}" data-taxexempt="${x.tax_exempt_file || ''}" style="padding:8px 10px;cursor:pointer">${x.name}</div>`).join('');
                Array.from(sug.children).forEach(el => {
                    el.addEventListener('click', function () {
                        ci.value = this.dataset.name; cid.value = this.dataset.id;
                        if (this.dataset.taxexempt && taxBanner) { taxBanner.style.display = 'block'; } else if(taxBanner) { taxBanner.style.display = 'none'; }
                        loadProjectsForClient(this.dataset.id);
                        sug.style.display = 'none';
                    });
                });
                sug.style.display = 'block';
            }).catch(() => { sug.style.display = 'none' });
    });
    document.addEventListener('click', function (e) { if (!sug.contains(e.target) && e.target !== ci) { sug.style.display = 'none'; } });
}

// Initialize immediately (for hard refresh) and on pageLoaded (for SPA nav)
initQuoteClientDropdown();
document.addEventListener('pageLoaded', initQuoteClientDropdown);
// Fallbacks for SPA navigation timing issues
setTimeout(initQuoteClientDropdown, 100);
setTimeout(initQuoteClientDropdown, 500);

document.getElementById('quoteForm').addEventListener('submit', function (e) {
    var cid = document.getElementById('clientId');
    if (!cid || !cid.value) {
        e.preventDefault();
        alert('Please select a client from suggestions.');
    }
});