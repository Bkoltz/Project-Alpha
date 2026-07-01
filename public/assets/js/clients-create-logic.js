var retryCount = 0;
var maxRetries = 10; // Stop after 10 retries (500ms total)

function initializeOrgCreate() {
    const orgInput = document.getElementById('orgInput');
    const orgId = document.getElementById('orgId');
    const orgSuggest = document.getElementById('orgSuggest');
    const createOrgBtn = document.getElementById('createOrgBtn');
    const createOrgModal = document.getElementById('createOrgModal');
    const closeCreateOrgModal = document.getElementById('closeCreateOrgModal');
    const cancelCreateOrgModal = document.getElementById('cancelCreateOrgModal');
    const createOrgForm = document.getElementById('createOrgForm');
    const createOrgCsrf = document.getElementById('createOrgCsrf');
    const createOrgNameInput = document.getElementById('createOrgNameInput');
    const clientForm = document.querySelector('form[action="/?page=clients-create"]');
    const orgValidationBanner = document.getElementById('orgValidationBanner');
    const clientNameInput = document.getElementById('clientNameInput');
    const duplicateBanner = document.getElementById('clientDuplicateBanner');
    const duplicateMessage = duplicateBanner ? duplicateBanner.querySelector('[data-duplicate-client-message]') : null;
    const orgAddressNotice = document.getElementById('orgAddressAutofillNotice');
    const addressFields = {
        address_line1: document.getElementById('clientAddressLine1'),
        address_line2: document.getElementById('clientAddressLine2'),
        city: document.getElementById('clientCity'),
        state: document.getElementById('clientState'),
        postal_code: document.getElementById('clientPostal'),
        country: document.getElementById('clientCountry')
    };

    // If elements don't exist yet, retry (with limit)
    if (!orgInput || !orgSuggest) {
        if (retryCount < maxRetries) {
            retryCount++;
            setTimeout(initializeOrgCreate, 50);
        }
        // Silently stop after max retries - we're probably not on the right page
        return;
    }

    // Get CSRF token from meta tag
    function getCsrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    function debounce(fn, ms) { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; }

    function clearSuggestions() { orgSuggest.style.display = 'none'; orgSuggest.innerHTML = ''; }

    function normalizeName(value) {
        return (value || '').trim().replace(/\s+/g, ' ').toLowerCase();
    }

    function addressIsEmpty() {
        return Object.values(addressFields).every((field) => !field || !field.value.trim());
    }

    function orgHasAddress(org) {
        return ['address_line1', 'address_line2', 'city', 'state', 'postal_code', 'country']
            .some((key) => org && String(org[key] || '').trim() !== '');
    }

    function fillAddressFromOrg(org) {
        if (!orgHasAddress(org) || !addressIsEmpty()) return;
        Object.keys(addressFields).forEach((key) => {
            if (addressFields[key] && org[key]) {
                addressFields[key].value = org[key];
            }
        });
        if (orgAddressNotice) orgAddressNotice.style.display = 'block';
    }

    function hideDuplicateWarning() {
        if (duplicateBanner) duplicateBanner.style.display = 'none';
        if (duplicateMessage) duplicateMessage.textContent = '';
    }

    async function checkDuplicateClientName(name) {
        const normalized = normalizeName(name);
        if (normalized.length < 2) {
            hideDuplicateWarning();
            return;
        }
        try {
            const res = await fetch('/?page=clients-search&term=' + encodeURIComponent(name));
            if (!res.ok) {
                hideDuplicateWarning();
                return;
            }
            const clients = await res.json();
            const match = Array.isArray(clients)
                ? clients.find((client) => normalizeName(client.name) === normalized)
                : null;
            if (!match) {
                hideDuplicateWarning();
                return;
            }
            if (duplicateMessage) {
                const orgText = match.org_name ? ` in ${match.org_name}` : '';
                duplicateMessage.textContent = `${match.name}${orgText} already exists. You can still create this client if it is intentionally separate.`;
            }
            if (duplicateBanner) duplicateBanner.style.display = 'block';
        } catch (e) {
            hideDuplicateWarning();
        }
    }

    async function fetchOrgs(term) {
        if (!term) { clearSuggestions(); orgValidationBanner.style.display = 'none'; return; }
        try {
            const res = await fetch('/?page=org-search&term=' + encodeURIComponent(term));
            if (!res.ok) { clearSuggestions(); return; }
            const items = await res.json();
            renderSuggestions(items, term);
        } catch (e) { clearSuggestions(); }
    }

    function renderSuggestions(items, term) {
        orgSuggest.innerHTML = '';
        if (!items || items.length === 0) {
            orgSuggest.style.display = 'none';
            if (term && term.trim().length > 0) {
                orgValidationBanner.style.display = 'block';
            }
            return;
        }
        orgValidationBanner.style.display = 'none';
        items.forEach(it => {
            const div = document.createElement('div');
            div.textContent = it.name;
            div.style.padding = '8px 10px';
            div.style.cursor = 'pointer';
            div.addEventListener('click', () => {
                orgInput.value = it.name;
                orgId.value = it.id;
                fillAddressFromOrg(it);
                clearSuggestions();
                orgValidationBanner.style.display = 'none';
            });
            orgSuggest.appendChild(div);
        });
        orgSuggest.style.display = 'block';
    }

    const debouncedFetch = debounce((e) => {
        orgId.value = '';
        fetchOrgs(e.target.value);
    }, 250);

    orgInput.addEventListener('input', debouncedFetch);
    if (clientNameInput) {
        clientNameInput.addEventListener('input', debounce((ev) => {
            checkDuplicateClientName(ev.target.value);
        }, 300));
    }

    document.addEventListener('click', function (ev) {
        if (!orgSuggest.contains(ev.target) && ev.target !== orgInput) clearSuggestions();
    });

    // Quick-create modal or full-page redirect when no matches
    createOrgBtn.addEventListener('click', function () {
        // Update CSRF token before showing modal
        const token = getCsrfToken();
        createOrgCsrf.value = token;
        createOrgNameInput.value = orgInput.value || '';
        createOrgModal.style.display = 'flex';
        createOrgNameInput.focus();
    });

    closeCreateOrgModal.addEventListener('click', () => { createOrgModal.style.display = 'none'; });
    cancelCreateOrgModal.addEventListener('click', () => { createOrgModal.style.display = 'none'; });

    createOrgForm.addEventListener('submit', async function (ev) {
        ev.preventDefault();
        // Update CSRF token from meta tag
        const token = getCsrfToken();
        createOrgCsrf.value = token;
        const data = new FormData(createOrgForm);
        try {
            const res = await fetch('/?page=organization/org-create', { method: 'POST', body: data });
            const j = await res.json();
            if (j && j.success) {
                // set the org on the client form
                orgInput.value = j.name || createOrgNameInput.value;
                orgId.value = j.id || '';
                createOrgModal.style.display = 'none';
                orgValidationBanner.style.display = 'none';
                // clear any saved draft (we haven't redirected)
                localStorage.removeItem('clientCreateDraft');
            } else {
                alert(j && j.error ? j.error : 'Failed to create organization');
            }
        } catch (e) { alert('Failed to create organization'); }
    });

    function saveDraft() {
        if (!clientForm) return;
        const data = {};
        Array.from(clientForm.elements).forEach(el => {
            if (!el.name) return;
            if (el.type === 'checkbox' || el.type === 'radio') return;
            data[el.name] = el.value;
        });
        try { localStorage.setItem('clientCreateDraft', JSON.stringify(data)); } catch (e) { }
    }

    function restoreDraft() {
        try {
            const raw = localStorage.getItem('clientCreateDraft');
            if (!raw) return;
            const data = JSON.parse(raw);
            if (!clientForm) return;
            Object.keys(data).forEach(k => {
                const el = clientForm.elements[k];
                if (!el) return;
                el.value = data[k];
            });
        } catch (e) { }
    }

    // Restore draft on load and handle return from full create
    function handleDraftRestore() {
        restoreDraft();
        const params = new URLSearchParams(window.location.search);
        if (params.get('org_created') && params.get('org_id')) {
            // set org fields from query params
            const id = params.get('org_id');
            const name = params.get('org_name') ? decodeURIComponent(params.get('org_name')) : '';
            if (name) orgInput.value = name;
            if (id) orgId.value = id;
            // clear draft now that it was consumed
            localStorage.removeItem('clientCreateDraft');
            // remove org_* query params from URL
            params.delete('org_created'); params.delete('org_id'); params.delete('org_name');
            const newUrl = window.location.pathname + '?' + params.toString();
            window.history.replaceState({}, document.title, newUrl);
        }
    }

    handleDraftRestore();

    // before navigating away via the client form submission, clear draft
    if (clientForm) {
        clientForm.addEventListener('submit', function () {
            localStorage.removeItem('clientCreateDraft');
        });
    }
}

// Initialize with small delay to allow DOM to settle
setTimeout(initializeOrgCreate, 10);

// Also re-initialize when pages are loaded via AJAX
document.addEventListener('pageLoaded', () => {
    retryCount = 0; // Reset retry count for new page load
    setTimeout(initializeOrgCreate, 10);
});
