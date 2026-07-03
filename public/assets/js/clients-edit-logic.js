(function () {
    'use strict';

    function debounce(fn, ms) {
        let timer;
        return function (...args) {
            clearTimeout(timer);
            timer = setTimeout(() => fn.apply(this, args), ms);
        };
    }

    function initClientEditPage(context) {
        const root = context && context.root ? context.root : document;
        const orgInput = root.querySelector('#orgInputEdit');
        const orgId = root.querySelector('#orgIdEdit');
        const orgSuggest = root.querySelector('#orgSuggestEdit');
        const orgValidationBanner = root.querySelector('#orgValidationBannerEdit');

        if (!orgInput || !orgId || !orgSuggest) {
            return;
        }
        if (orgInput.dataset.orgEditReady === '1') {
            return;
        }
        orgInput.dataset.orgEditReady = '1';

        function getCsrfToken() {
            const meta = document.querySelector('meta[name="csrf-token"]');
            return meta ? meta.getAttribute('content') : '';
        }

        function clearSuggestions() {
            orgSuggest.style.display = 'none';
            orgSuggest.innerHTML = '';
        }

        function setValidationVisible(visible) {
            if (orgValidationBanner) {
                orgValidationBanner.style.display = visible ? 'block' : 'none';
            }
        }

        function renderSuggestions(items, term) {
            orgSuggest.innerHTML = '';
            if (!items || items.length === 0) {
                orgSuggest.style.display = 'none';
                setValidationVisible(!!(term && term.trim()));
                return;
            }

            setValidationVisible(false);
            items.forEach(item => {
                const option = document.createElement('div');
                option.textContent = item.name;
                option.style.padding = '8px 10px';
                option.style.cursor = 'pointer';
                option.addEventListener('click', () => {
                    orgInput.value = item.name;
                    orgId.value = item.id;
                    clearSuggestions();
                    setValidationVisible(false);
                });
                orgSuggest.appendChild(option);
            });
            orgSuggest.style.display = 'block';
        }

        async function fetchOrgs(term) {
            if (!term) {
                clearSuggestions();
                setValidationVisible(false);
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
            const value = event.target.value;
            if (!value || value.trim() === '') {
                orgId.value = '0';
                clearSuggestions();
                setValidationVisible(false);
            } else {
                orgId.value = '';
                fetchOrgs(value);
            }
        }, 200));

        const handleDocumentClick = event => {
            if (!orgSuggest.contains(event.target) && event.target !== orgInput) {
                clearSuggestions();
            }
        };
        document.addEventListener('click', handleDocumentClick);

        const createOrgBtn = root.querySelector('#createOrgBtnEdit');
        const createOrgModal = root.querySelector('#createOrgModalEdit');
        const closeCreateOrgModal = root.querySelector('#closeCreateOrgModalEdit');
        const cancelCreateOrgModal = root.querySelector('#cancelCreateOrgModalEdit');
        const createOrgForm = root.querySelector('#createOrgFormEdit');
        const createOrgCsrf = root.querySelector('#createOrgCsrfEdit');
        const createOrgNameInput = root.querySelector('#createOrgNameInputEdit');

        if (createOrgBtn && createOrgModal) {
            createOrgBtn.addEventListener('click', () => {
                if (createOrgCsrf) createOrgCsrf.value = getCsrfToken();
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
                        setValidationVisible(false);
                    } else {
                        alert(payload && payload.error ? payload.error : 'Failed to create organization');
                    }
                } catch (e) {
                    alert('Failed to create organization');
                }
            });
        }

        return function cleanupClientEditPage() {
            document.removeEventListener('click', handleDocumentClick);
        };
    }
    initClientEditPage.pageInitializerId = 'client-edit';

    window.initializeClientEditPage = function () {
        return initClientEditPage({ root: document });
    };

    if (window.ProjectAlpha && typeof window.ProjectAlpha.registerPage === 'function') {
        window.ProjectAlpha.registerPage(['client/clients-edit', 'clients-edit'], initClientEditPage);
    } else if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => initClientEditPage({ root: document }), { once: true });
    } else {
        initClientEditPage({ root: document });
    }
})();
