function money(n) { return '$' + (Number(n) || 0).toFixed(2) }
var itemCounterInv = 0;
function addItemInv(item = '', desc = '', qty = 1, price = 0, timeEntryId = null, billingUnit = 'each') {
    var wrap = document.createElement('div');
    var rowIndex = document.querySelectorAll('#itemsInv > div').length;
    var itemId = 'itemInv_' + (itemCounterInv++);
    var descId = 'descInv_' + itemCounterInv;
    var priceId = 'priceInv_' + itemCounterInv;
    wrap.style.display = 'grid'; wrap.style.gridTemplateColumns = '3fr 3fr 1fr 1fr auto'; wrap.style.gap = '8px';
    var teIds = Array.isArray(timeEntryId) ? timeEntryId : (timeEntryId ? [timeEntryId] : []);
    var teInput = teIds.map(id => `<input type="hidden" name="time_entry_ids[${rowIndex}][]" value="${id}">`).join('');
    wrap.innerHTML = teInput + `
    <input type="hidden" name="item_billing_unit[]" value="${billingUnit === 'hour' ? 'hour' : 'each'}">
    <input id="${itemId}" required placeholder="Item name..." name="item[]" style="padding:10px;border-radius:8px;border:1px solid #ddd" value="${item}" oninput="recalcInv()" data-item-autocomplete data-description-field="${descId}" data-price-field="${priceId}">
    <textarea id="${descId}" placeholder="Description (optional)" name="item_desc[]" style="padding:10px;border-radius:8px;border:1px solid #ddd;resize:vertical;min-height:42px" oninput="recalcInv()">${desc}</textarea>
    <input required type="number" step="0.01" ${teIds.length === 0 ? 'min="0"' : ''} name="item_qty[]" class="qty-input" style="padding:10px;border-radius:8px;border:1px solid #ddd" value="${qty}" oninput="recalcInv()">
    <input id="${priceId}" required type="number" step="0.01" min="0" name="item_price[]" style="padding:10px;border-radius:8px;border:1px solid #ddd" value="${price}" oninput="recalcInv()">
    <button type="button" onclick="this.parentElement.remove();recalcInv()" style="border:0;background:#fee2e2;color:#991b1b;border-radius:8px;padding:8px 10px">Remove</button>
  `;
    document.getElementById('itemsInv').appendChild(wrap);

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

    recalcInv();
}

function recalcInv() {
    var qtys = Array.from(document.querySelectorAll('[name=\"item_qty[]\"]')).map(e => parseFloat(e.value) || 0);
    var prices = Array.from(document.querySelectorAll('[name=\"item_price[]\"]')).map(e => parseFloat(e.value) || 0);
    var subtotal = 0; for (var i = 0; i < qtys.length; i++) { subtotal += qtys[i] * prices[i]; }
    var dtype = document.getElementById('discountTypeInv').value;
    var dval = parseFloat(document.getElementById('discountValueInv').value) || 0;
    var taxp = parseFloat(document.getElementById('taxPercentInv').value) || 0;
    var discount = 0; if (dtype === 'percent') { discount = Math.max(0, Math.min(100, dval)) * subtotal / 100; } else if (dtype === 'fixed') { discount = Math.max(0, dval); }
    var taxable = Math.max(0, subtotal - discount);
    var tax = Math.max(0, taxp) * taxable / 100;
    var total = Math.max(0, taxable + tax);
    document.getElementById('subtotalValInv').textContent = money(subtotal);
    document.getElementById('discountValInv').textContent = money(discount);
    document.getElementById('taxValInv').textContent = money(tax);
    document.getElementById('totalValInv').textContent = money(total);
}

['discountTypeInv', 'discountValueInv', 'taxPercentInv'].forEach(id => document.getElementById(id).addEventListener('input', recalcInv));
addItemInv();

// Client typeahead for invoice
function initInvoiceClientDropdown() {
    var ciI = document.getElementById('clientInputInv');
    var cidI = document.getElementById('clientIdInv');
    var sugI = document.getElementById('clientSuggestInv');
    var taxBannerInv = document.getElementById('taxExemptBannerInv');
    
    if (!ciI || !cidI || !sugI) return;
    if (ciI._clientDropdownInitialized) return;
    ciI._clientDropdownInitialized = true;
    
    ciI.addEventListener('input', function () {
        cidI.value = '';
        var t = this.value.trim();
        if (!t) { sugI.style.display = 'none'; sugI.innerHTML = ''; if(taxBannerInv) taxBannerInv.style.display = 'none'; return; }
        fetch('/?page=clients-search&term=' + encodeURIComponent(t))
            .then(r => r.json())
            .then(list => {
                if (!Array.isArray(list) || list.length === 0) { sugI.style.display = 'none'; sugI.innerHTML = ''; return; }
                sugI.innerHTML = list.map(x => `<div data-id="${x.id}" data-name="${x.name}" data-taxexempt="${x.tax_exempt_file || ''}" style=\"padding:8px 10px;cursor:pointer\">${x.name}</div>`).join('');
                Array.from(sugI.children).forEach(el => {
                    el.addEventListener('click', function (e) {
                        e.stopPropagation();
                        ciI.value = this.dataset.name; cidI.value = this.dataset.id;
                        if (this.dataset.taxexempt && taxBannerInv) { taxBannerInv.style.display = 'block'; } else if(taxBannerInv) { taxBannerInv.style.display = 'none'; }
                        loadProjectsForClientInv(this.dataset.id);
                        sugI.style.display = 'none';
                    });
                });
                sugI.style.display = 'block';
            }).catch(() => { sugI.style.display = 'none' });
    });
}
initInvoiceClientDropdown.pageInitializerId = 'invoice-create-client-dropdown';

if (window.ProjectAlpha && typeof window.ProjectAlpha.registerPage === 'function') {
    window.ProjectAlpha.registerPage('invoice/invoices-create', initInvoiceClientDropdown);
} else {
    initInvoiceClientDropdown();
}

function loadProjectsForClientInv(clientId) {
    const projectSection = document.getElementById('projectSectionInv');
    const projectSelect = document.getElementById('projectSelectInv');
    if (!clientId) {
        if (projectSelect) projectSelect.innerHTML = '<option value="">-- Select Project --</option>';
        if (projectSection) projectSection.style.display = 'none';
        return;
    }

    fetch('/?page=projects-search&client_id=' + encodeURIComponent(clientId))
        .then(r => r.json())
        .then(projects => {
            projectSelect.innerHTML = '<option value="">-- Select Project --</option>';

            if (projects && projects.length > 0) {
                projects.forEach(project => {
                    const option = document.createElement('option');
                    option.value = project.id;
                    option.textContent = project.name + ' (' + project.status.replace('_', ' ') + ')';
                    projectSelect.appendChild(option);
                });
                projectSection.style.display = 'block';
            } else {
                projectSelect.value = '';
                projectSection.style.display = 'none';
            }
        })
        .catch(() => {
            projectSection.style.display = 'none';
        });
}

document.getElementById('createProjectBtnInv').addEventListener('click', function () {
    const clientId = document.getElementById('clientIdInv').value;
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
                loadProjectsForClientInv(clientId);
                // Select the new project
                setTimeout(() => {
                    const projectSelect = document.getElementById('projectSelectInv');
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

document.getElementById('invoiceForm').addEventListener('submit', function (e) {
    const clientId = document.getElementById('clientIdInv');
    if (!clientId || !clientId.value) {
        e.preventDefault();
        alert('Please select a client from suggestions.');
    }
});

// Tracked time integration
(function () {
    const modal = document.getElementById('trackedTimeModal');
    const openBtn = document.getElementById('btnAddFromTrackedTime');
    const closeBtn = document.getElementById('closeTrackedTimeModal');
    const loading = document.getElementById('trackedTimeLoading');
    const empty = document.getElementById('trackedTimeEmpty');
    const form = document.getElementById('trackedTimeForm');
    const tbody = document.getElementById('trackedTimeTbody');
    const selectAll = document.getElementById('selectAllTrackedTime');
    const addSelected = document.getElementById('btnAddSelectedTrackedTime');
    const clientIdInput = document.getElementById('clientIdInv');

    if (!openBtn || !modal) return;

    function renderTrackedTime(entries) {
        tbody.innerHTML = '';
        if (!Array.isArray(entries) || entries.length === 0) {
            loading.style.display = 'none';
            form.style.display = 'none';
            empty.style.display = 'block';
            return;
        }
        entries.forEach(function (entry) {
            const tr = document.createElement('tr');
            const serviceName = entry.service_name || 'Tracked Time';
            const detail = entry.detail || [entry.started_at || '', entry.ended_at || '', entry.description || ''].filter(Boolean).join(' ');
            tr.innerHTML = `
                <td><input type="checkbox" class="tt-checkbox" data-id="${entry.id}" data-group="${escapeAttr(entry.adjustment_group_id || '')}" data-service="${escapeAttr(serviceName)}" data-desc="${escapeAttr(entry.description || '')}" data-detail="${escapeAttr(detail)}" data-hours="${entry.hours}" data-rate="${entry.rate}"></td>
                <td>${(entry.started_at || '').substr(0, 10)}</td>
                <td>${escapeHtml(serviceName)}${entry.description ? '<div style="font-size:12px;color:var(--muted)">' + escapeHtml(entry.description) + '</div>' : ''}</td>
                <td>${Number(entry.hours).toFixed(2)}</td>
                <td>$${Number(entry.rate).toFixed(2)}</td>
                <td>$${(Number(entry.hours) * Number(entry.rate)).toFixed(2)}</td>
            `;
            tbody.appendChild(tr);
        });
        document.querySelectorAll('.tt-checkbox').forEach(function (checkbox) {
            checkbox.addEventListener('change', function () {
                if (!this.dataset.group) return;
                document.querySelectorAll('.tt-checkbox').forEach(function (candidate) {
                    if (candidate.dataset.group === checkbox.dataset.group) candidate.checked = checkbox.checked;
                });
            });
        });
        loading.style.display = 'none';
        empty.style.display = 'none';
        form.style.display = 'block';
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function escapeAttr(text) {
        return escapeHtml(text).replace(/"/g, '&quot;');
    }

    function loadTrackedTime() {
        const clientId = clientIdInput ? clientIdInput.value : '';
        loading.style.display = 'block';
        form.style.display = 'none';
        empty.style.display = 'none';
        modal.style.display = 'flex';
        fetch('/?page=time-tracking/unbilled' + (clientId ? '&client_id=' + encodeURIComponent(clientId) : ''))
            .then(function (r) { return r.json(); })
            .then(renderTrackedTime)
            .catch(function () {
                loading.style.display = 'none';
                empty.style.display = 'block';
            });
    }

    openBtn.addEventListener('click', function () {
        if (!clientIdInput || !clientIdInput.value) {
            alert('Please select a client first.');
            return;
        }
        loadTrackedTime();
    });

    if (closeBtn) {
        closeBtn.addEventListener('click', function () { modal.style.display = 'none'; });
    }

    if (selectAll) {
        selectAll.addEventListener('change', function () {
            const checked = this.checked;
            document.querySelectorAll('.tt-checkbox').forEach(function (cb) { cb.checked = checked; });
        });
    }

    if (addSelected) {
        addSelected.addEventListener('click', function () {
            const checked = Array.from(document.querySelectorAll('.tt-checkbox:checked'));
            if (checked.length === 0) {
                alert('Please select at least one time entry.');
                return;
            }
            const itemWrap = document.getElementById('itemsInv');
            if (itemWrap && itemWrap.children.length === 1) {
                const firstItem = itemWrap.querySelector('[name="item[]"]');
                if (firstItem && !firstItem.value.trim()) {
                    itemWrap.innerHTML = '';
                }
            }
            const groups = new Map();
            checked.forEach(function (cb) {
                const rate = parseFloat(cb.dataset.rate) || 0;
                const service = cb.dataset.service || 'Tracked Time';
                const key = service + '|' + rate.toFixed(2);
                if (!groups.has(key)) {
                    groups.set(key, { item: service, rate, hours: 0, ids: [], descriptions: [] });
                }
                const group = groups.get(key);
                const hours = parseFloat(cb.dataset.hours) || 0;
                group.hours += hours;
                group.ids.push(cb.dataset.id);
                group.descriptions.push(cb.dataset.detail || cb.dataset.desc || '');
            });
            groups.forEach(function (group) {
                const detail = group.descriptions.filter(Boolean).join('\n');
                addItemInv(group.item, detail, group.hours.toFixed(2), group.rate.toFixed(2), group.ids, 'hour');
            });
            modal.style.display = 'none';
            recalcInv();
        });
    }
})();
