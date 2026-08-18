(function () {
    'use strict';

    function initPublicClientOnboarding() {
        var form = document.querySelector('[data-public-onboarding-form]');
        if (!form || form.dataset.onboardingReady === '1') return;

        var types = form.querySelectorAll('[data-onboarding-type]');
        var organizationFields = form.querySelector('[data-organization-fields]');
        var contactNameLabel = form.querySelector('[data-contact-name-label]');
        if (!organizationFields || !contactNameLabel) return;

        form.dataset.onboardingReady = '1';
        function syncOrganizationFields() {
            var selected = form.querySelector('[data-onboarding-type]:checked');
            var organizationMode = !!selected && selected.value === 'business';
            organizationFields.hidden = !organizationMode;
            contactNameLabel.textContent = organizationMode ? 'Contact name' : 'Full name';
            var organizationName = organizationFields.querySelector('[name="organization_name"]');
            if (organizationName) organizationName.required = organizationMode;
        }

        types.forEach(function (type) {
            type.addEventListener('change', syncOrganizationFields);
        });
        syncOrganizationFields();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initPublicClientOnboarding, { once: true });
    } else {
        initPublicClientOnboarding();
    }
})();
