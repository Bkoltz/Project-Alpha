(function () {
    'use strict';

    function initDeleteButton(context) {
        const root = context && context.root ? context.root : document;
        const btn = root.querySelector('#deleteOrgBtn');
        const form = root.querySelector('#deleteOrgForm');

        if (!btn || !form || btn.dataset.deleteInitialized === 'true') {
            return;
        }

        btn.dataset.deleteInitialized = 'true';
        btn.addEventListener('click', function (event) {
            event.preventDefault();
            if (confirm('Delete this organization? Clients will not be deleted, but will no longer be associated with this organization.')) {
                form.submit();
            }
        });
    }
    initDeleteButton.pageInitializerId = 'organization-edit';

    if (window.ProjectAlpha && typeof window.ProjectAlpha.registerPage === 'function') {
        window.ProjectAlpha.registerPage(['organization/organizations-edit', 'organizations-edit'], initDeleteButton);
    } else if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => initDeleteButton({ root: document }), { once: true });
    } else {
        initDeleteButton({ root: document });
    }
})();
