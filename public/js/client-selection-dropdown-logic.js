// Client typeahead
function initClientDropdown() {
    var ci = document.getElementById('clientInput');
    var cid = document.getElementById('clientId');
    var sug = document.getElementById('clientSuggest');

    // Only initialize if elements exist
    if (!ci || !cid || !sug) return;
    // Remove any existing input listener to avoid duplicates
    ci.removeEventListener('input', ci._clientDropdownHandler);
    ci._clientDropdownHandler = function () {
        cid.value = '';
        var t = this.value.trim();

        if (!t) {
            if (sug) { sug.style.display = 'none'; sug.innerHTML = ''; }
            return;
        }

        fetch('/?page=clients-search&term=' + encodeURIComponent(t))
            .then(r => r.json())
            .then(list => {
                if (!sug) return;

                if (!Array.isArray(list) || list.length === 0) {
                    sug.style.display = 'none';
                    sug.innerHTML = '';
                    return;
                }

                sug.innerHTML = list.map(x => `<div data-id="${x.id}" data-name="${x.name}" data-taxexempt="${x.tax_exempt_file || ''}" style="padding:8px 10px;cursor:pointer">${x.name}</div>`).join('');

                Array.from(sug.children).forEach(el => {
                    el.addEventListener('click', function () {
                        ci.value = this.dataset.name;
                        cid.value = this.dataset.id;

                        // Dispatch change event for other listeners
                        ci.dispatchEvent(new Event('change', { bubbles: true }));

                        if (sug) sug.style.display = 'none';
                    });
                });
                sug.style.display = 'block';
            }).catch(() => {
                if (sug) sug.style.display = 'none';
            });
    };
    ci.addEventListener('input', ci._clientDropdownHandler);

    // Close dropdown on outside click
    document.addEventListener('click', function (e) {
        if (sug && !sug.contains(e.target) && e.target !== ci) {
            sug.style.display = 'none';
        }
    });
}

// Auto-initialize on full page loads
document.addEventListener('DOMContentLoaded', initClientDropdown);

// Re-initialize when pages are loaded via AJAX navigation
document.addEventListener('pageLoaded', initClientDropdown);
