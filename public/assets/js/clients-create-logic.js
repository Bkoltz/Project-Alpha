(function () {
    'use strict';

    function debounce(fn, ms) {
        let timer;
        return function (...args) {
            clearTimeout(timer);
            timer = setTimeout(() => fn.apply(this, args), ms);
        };
    }

    function initClientCreatePage(context) {
        const root = context && context.root ? context.root : document;
        const orgInput = root.querySelector('#orgInput');
        const orgId = root.querySelector('#orgId');
        const orgSuggest = root.querySelector('#orgSuggest');
        const createOrgBtn = root.querySelector('#createOrgBtn');
        const createOrgModal = root.querySelector('#createOrgModal');
        const closeCreateOrgModal = root.querySelector('#closeCreateOrgModal');
        const cancelCreateOrgModal = root.querySelector('#cancelCreateOrgModal');
        const createOrgForm = root.querySelector('#createOrgForm');
        const createOrgCsrf = root.querySelector('#createOrgCsrf');
        const createOrgNameInput = root.querySelector('#createOrgNameInput');
        const clientForm = root.querySelector('form[action="/?page=clients-create"], form[action="/?page=client/clients-create"]');
        const orgValidationBanner = root.querySelector('#orgValidationBanner');
        const clientNameInput = root.querySelector('#clientNameInput');
        const duplicateBanner = root.querySelector('#clientDuplicateBanner');
        const duplicateMessage = duplicateBanner ? duplicateBanner.querySelector('[data-duplicate-client-message]') : null;
        const orgAddressNotice = root.querySelector('#orgAddressAutofillNotice');
        const addressFields = {
            address_line1: root.querySelector('#clientAddressLine1'),
            address_line2: root.querySelector('#clientAddressLine2'),
            city: root.querySelector('#clientCity'),
            state: root.querySelector('#clientState'),
            postal_code: root.querySelector('#clientPostal'),
            country: root.querySelector('#clientCountry')
        };

        if (!orgInput || !orgId || !orgSuggest) {
            return;
        }
        if (orgInput.dataset.orgCreateReady === '1') {
            return;
        }
        orgInput.dataset.orgCreateReady = '1';

        function getCsrfToken() {
            const meta = document.querySelector('meta[name="csrf-token"]');
            return meta ? meta.getAttribute('content') : '';
        }

        function clearSuggestions() {
            orgSuggest.style.display = 'none';
            orgSuggest.innerHTML = '';
        }

        function normalizeName(value) {
            return (value || '').trim().replace(/\s+/g, ' ').toLowerCase();
        }

        function addressIsEmpty() {
            return Object.values(addressFields).every(field => !field || !field.value.trim());
        }

        function orgHasAddress(org) {
            return ['address_line1', 'address_line2', 'city', 'state', 'postal_code', 'country']
                .some(key => org && String(org[key] || '').trim() !== '');
        }

        function fillAddressFromOrg(org) {
            if (!orgHasAddress(org) || !addressIsEmpty()) return;
            Object.keys(addressFields).forEach(key => {
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
                    ? clients.find(client => normalizeName(client.name) === normalized)
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

        function renderSuggestions(items, term) {
            orgSuggest.innerHTML = '';
            if (!items || items.length === 0) {
                orgSuggest.style.display = 'none';
                if (orgValidationBanner && term && term.trim().length > 0) {
                    orgValidationBanner.style.display = 'block';
                }
                return;
            }
            if (orgValidationBanner) orgValidationBanner.style.display = 'none';
            items.forEach(item => {
                const option = document.createElement('div');
                option.textContent = item.name;
                option.style.padding = '8px 10px';
                option.style.cursor = 'pointer';
                option.addEventListener('click', () => {
                    orgInput.value = item.name;
                    orgId.value = item.id;
                    fillAddressFromOrg(item);
                    clearSuggestions();
                    if (orgValidationBanner) orgValidationBanner.style.display = 'none';
                });
                orgSuggest.appendChild(option);
            });
            orgSuggest.style.display = 'block';
        }

        async function fetchOrgs(term) {
            if (!term) {
                clearSuggestions();
                if (orgValidationBanner) orgValidationBanner.style.display = 'none';
                return;
            }
            try {
                const res = await fetch('/?page=org-search&term=' + encodeURIComponent(term));
                if (!res.ok) {
                    clearSuggestions();
                    return;
                }
                renderSuggestions(await res.json(), term);
            } catch (e) {
                clearSuggestions();
            }
        }

        orgInput.addEventListener('input', debounce(event => {
            orgId.value = '';
            fetchOrgs(event.target.value);
        }, 250));

        if (clientNameInput) {
            clientNameInput.addEventListener('input', debounce(event => {
                checkDuplicateClientName(event.target.value);
            }, 300));
        }

        const handleDocumentClick = event => {
            if (!orgSuggest.contains(event.target) && event.target !== orgInput) {
                clearSuggestions();
            }
        };
        document.addEventListener('click', handleDocumentClick);

        if (createOrgBtn && createOrgModal && createOrgNameInput) {
            createOrgBtn.addEventListener('click', () => {
                if (createOrgCsrf) createOrgCsrf.value = getCsrfToken();
                createOrgNameInput.value = orgInput.value || '';
                createOrgModal.style.display = 'flex';
                createOrgNameInput.focus();
            });
        }

        if (closeCreateOrgModal && createOrgModal) {
            closeCreateOrgModal.addEventListener('click', () => { createOrgModal.style.display = 'none'; });
        }
        if (cancelCreateOrgModal && createOrgModal) {
            cancelCreateOrgModal.addEventListener('click', () => { createOrgModal.style.display = 'none'; });
        }

        if (createOrgForm && createOrgModal && createOrgNameInput) {
            createOrgForm.addEventListener('submit', async event => {
                event.preventDefault();
                if (createOrgCsrf) createOrgCsrf.value = getCsrfToken();
                const data = new FormData(createOrgForm);
                try {
                    const res = await fetch('/?page=organization/org-create', { method: 'POST', body: data });
                    const payload = await res.json();
                    if (payload && payload.success) {
                        orgInput.value = payload.name || createOrgNameInput.value;
                        orgId.value = payload.id || '';
                        createOrgModal.style.display = 'none';
                        if (orgValidationBanner) orgValidationBanner.style.display = 'none';
                        localStorage.removeItem('clientCreateDraft');
                    } else {
                        alert(payload && payload.error ? payload.error : 'Failed to create organization');
                    }
                } catch (e) {
                    alert('Failed to create organization');
                }
            });
        }

        function restoreDraft() {
            try {
                const raw = localStorage.getItem('clientCreateDraft');
                if (!raw || !clientForm) return;
                const data = JSON.parse(raw);
                Object.keys(data).forEach(key => {
                    const field = clientForm.elements[key];
                    if (field) field.value = data[key];
                });
            } catch (e) { /* ignore invalid saved drafts */ }
        }

        restoreDraft();

        const params = new URLSearchParams(window.location.search);
        if (params.get('org_created') && params.get('org_id')) {
            const id = params.get('org_id');
            const name = params.get('org_name') || '';
            if (name) orgInput.value = name;
            if (id) orgId.value = id;
            localStorage.removeItem('clientCreateDraft');
            params.delete('org_created');
            params.delete('org_id');
            params.delete('org_name');
            window.history.replaceState({}, document.title, window.location.pathname + '?' + params.toString());
        }

        if (clientForm) {
            clientForm.addEventListener('submit', () => {
                localStorage.removeItem('clientCreateDraft');
            });
        }

        return function cleanupClientCreatePage() {
            document.removeEventListener('click', handleDocumentClick);
        };
    }
    initClientCreatePage.pageInitializerId = 'client-create';

    if (window.ProjectAlpha && typeof window.ProjectAlpha.registerPage === 'function') {
        window.ProjectAlpha.registerPage(['client/clients-create', 'clients-create'], initClientCreatePage);
    } else if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => initClientCreatePage({ root: document }), { once: true });
    } else {
        initClientCreatePage({ root: document });
    }
})();
