// Make this function global so navigation.js can call it
window.initializeClientEditPage = function () {
    const orgInput = document.getElementById('orgInputEdit');
    const orgId = document.getElementById('orgIdEdit');
    const orgSuggest = document.getElementById('orgSuggestEdit');
    const orgValidationBanner = document.getElementById('orgValidationBannerEdit');

    if (!orgInput || !orgSuggest) {
        console.warn('Org edit elements not found, retrying in 50ms...');
        setTimeout(window.initializeClientEditPage, 50);
        return;
    }

    console.log('✓ Org edit script initialized');

    // Get CSRF token from meta tag
    function getCsrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    function debounce(fn, ms) { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; }
    function clearSuggestions() { orgSuggest.style.display = 'none'; orgSuggest.innerHTML = ''; }

    async function fetchOrgs(term) {
        console.log('fetchOrgs called with term:', term);
        if (!term) { clearSuggestions(); orgValidationBanner.style.display = 'none'; return; }
        try {
            const url = '/?page=org-search&term=' + encodeURIComponent(term);
            const res = await fetch(url);
            if (!res.ok) { clearSuggestions(); return; }
            const items = await res.json();
            console.log('Items received:', items);
            renderSuggestions(items, term);
        } catch (e) { console.error('Fetch error:', e); clearSuggestions(); }
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
                clearSuggestions();
                orgValidationBanner.style.display = 'none';
            });
            orgSuggest.appendChild(div);
        });
        orgSuggest.style.display = 'block';
    }

    const debouncedFetch = debounce((e) => {
        const val = e.target.value;
        if (!val || val.trim() === '') {
            orgId.value = '0'; // Set to 0 to remove org
            clearSuggestions();
            orgValidationBanner.style.display = 'none';
        } else {
            orgId.value = ''; // Clear during search
            fetchOrgs(val);
        }
    }, 200);

    orgInput.addEventListener('input', debouncedFetch);

    document.addEventListener('click', function (ev) {
        if (!orgSuggest.contains(ev.target) && ev.target !== orgInput) {
            clearSuggestions();
        }
    });

    // Create organization modal
    const createOrgBtn = document.getElementById('createOrgBtnEdit');
    const createOrgModal = document.getElementById('createOrgModalEdit');
    const closeCreateOrgModal = document.getElementById('closeCreateOrgModalEdit');
    const cancelCreateOrgModal = document.getElementById('cancelCreateOrgModalEdit');
    const createOrgForm = document.getElementById('createOrgFormEdit');
    const createOrgCsrf = document.getElementById('createOrgCsrfEdit');
    const createOrgNameInput = document.getElementById('createOrgNameInputEdit');

    if (createOrgBtn && createOrgModal) {
        createOrgBtn.addEventListener('click', function () {
            console.log('Create org button clicked');
            const token = getCsrfToken();
            if (createOrgCsrf) createOrgCsrf.value = token;
            if (createOrgNameInput) createOrgNameInput.value = orgInput.value || '';
            createOrgModal.style.display = 'flex';
            if (createOrgNameInput) createOrgNameInput.focus();
        });
    }

    if (closeCreateOrgModal && createOrgModal) {
        closeCreateOrgModal.addEventListener('click', () => { createOrgModal.style.display = 'none'; });
    }
    if (cancelCreateOrgModal && createOrgModal) {
        cancelCreateOrgModal.addEventListener('click', () => { createOrgModal.style.display = 'none'; });
    }

    if (createOrgForm && createOrgCsrf && createOrgNameInput) {
        createOrgForm.addEventListener('submit', async function (ev) {
            ev.preventDefault();
            const token = getCsrfToken();
            createOrgCsrf.value = token;
            console.log('Create org form submitted');
            const data = new FormData(createOrgForm);
            try {
                const url = '/?page=organization/org-create';
                const res = await fetch(url, { method: 'POST', body: data });
                const j = await res.json();
                if (j && j.success) {
                    orgInput.value = j.name || createOrgNameInput.value;
                    orgId.value = j.id || '';
                    createOrgModal.style.display = 'none';
                    orgValidationBanner.style.display = 'none';
                } else {
                    alert(j && j.error ? j.error : 'Failed to create organization');
                }
            } catch (e) { console.error('Form submit error:', e); alert('Failed to create organization'); }
        });
    }
};

// Try immediate initialization first (works when script loads after DOM)
console.log('Attempting immediate initialization...');
setTimeout(function () {
    if (document.getElementById('orgInputEdit')) {
        console.log('Elements found immediately, initializing now');
        window.initializeClientEditPage();
    } else {
        console.log('Elements not found immediately, waiting for DOM...');
    }
}, 0);

// Initialize on first load (check if DOM is ready)
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
        console.log('DOMContentLoaded fired, initializing...');
        window.initializeClientEditPage();
    });
} else {
    // DOM already ready, initialize immediately
    console.log('DOM already ready, initializing immediately...');
    window.initializeClientEditPage();
}

// Also re-initialize when pages are loaded via AJAX
document.addEventListener('pageLoaded', function () {
    console.log('pageLoaded event fired, re-initializing...');
    setTimeout(window.initializeClientEditPage, 10);
});