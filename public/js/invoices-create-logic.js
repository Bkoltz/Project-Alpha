function money(n) { return '$' + (Number(n) || 0).toFixed(2) }
var itemCounterInv = 0;
function addItemInv(item = '', desc = '', qty = 1, price = 0) {
    var wrap = document.createElement('div');
    var itemId = 'itemInv_' + (itemCounterInv++);
    var descId = 'descInv_' + itemCounterInv;
    var priceId = 'priceInv_' + itemCounterInv;
    wrap.style.display = 'grid'; wrap.style.gridTemplateColumns = '3fr 3fr 1fr 1fr auto'; wrap.style.gap = '8px';
    wrap.innerHTML = `
    <input id="${itemId}" required placeholder="Item name..." name="item[]" style="padding:10px;border-radius:8px;border:1px solid #ddd" value="${item}" oninput="recalcInv()" data-item-autocomplete data-description-field="${descId}" data-price-field="${priceId}">
    <textarea id="${descId}" placeholder="Description (optional)" name="item_desc[]" style="padding:10px;border-radius:8px;border:1px solid #ddd;resize:vertical;min-height:42px" oninput="recalcInv()">${desc}</textarea>
    <input required type="number" step="0.01" min="0" name="item_qty[]" style="padding:10px;border-radius:8px;border:1px solid #ddd" value="${qty}" oninput="recalcInv()">
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
var ciI = document.getElementById('clientInputInv');
var cidI = document.getElementById('clientIdInv');
var sugI = document.getElementById('clientSuggestInv');
var taxBannerInv = document.getElementById('taxExemptBannerInv');
ciI.addEventListener('input', function () {
    cidI.value = '';
    var t = this.value.trim();
    if (!t) { sugI.style.display = 'none'; sugI.innerHTML = ''; taxBannerInv.style.display = 'none'; return; }
    fetch('/?page=clients-search&term=' + encodeURIComponent(t))
        .then(r => r.json())
        .then(list => {
            if (!Array.isArray(list) || list.length === 0) { sugI.style.display = 'none'; sugI.innerHTML = ''; return; }
            sugI.innerHTML = list.map(x => `<div data-id="${x.id}" data-name="${x.name}" data-taxexempt="${x.tax_exempt_file || ''}" style=\"padding:8px 10px;cursor:pointer\">${x.name}</div>`).join('');
            Array.from(sugI.children).forEach(el => {
                el.addEventListener('click', function () {
                    ciI.value = this.dataset.name; cidI.value = this.dataset.id;
                    if (this.dataset.taxexempt) { taxBannerInv.style.display = 'block'; } else { taxBannerInv.style.display = 'none'; }
                    loadProjectsForClientInv(this.dataset.id);
                    sugI.style.display = 'none';
                });
            });
            sugI.style.display = 'block';
        }).catch(() => { sugI.style.display = 'none' });
});
document.addEventListener('click', function (e) { if (!sugI.contains(e.target) && e.target !== ciI) { sugI.style.display = 'none'; } });

function loadProjectsForClientInv(clientId) {
    if (!clientId) {
        document.getElementById('projectSectionInv').style.display = 'none';
        return;
    }

    fetch('/?page=projects-search&client_id=' + encodeURIComponent(clientId))
        .then(r => r.json())
        .then(projects => {
            const projectSelect = document.getElementById('projectSelectInv');
            projectSelect.innerHTML = '<option value="">-- Select Project --</option>';

            if (projects && projects.length > 0) {
                projects.forEach(project => {
                    const option = document.createElement('option');
                    option.value = project.id;
                    option.textContent = project.name + ' (' + project.status.replace('_', ' ') + ')';
                    projectSelect.appendChild(option);
                });
                document.getElementById('projectSectionInv').style.display = 'block';
            } else {
                document.getElementById('projectSectionInv').style.display = 'none';
            }
        })
        .catch(() => {
            document.getElementById('projectSectionInv').style.display = 'none';
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

document.getElementById('invoiceForm').addEventListener('submit', function (e) { if (!cidI.value) { e.preventDefault(); alert('Please select a client from suggestions.'); } });