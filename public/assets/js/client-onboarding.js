function initClientOnboardingPage() {
    const clientSelect = document.querySelector('[data-onboarding-client-select]');
    if (clientSelect && clientSelect.dataset.initialized !== '1') {
        clientSelect.dataset.initialized = '1';
        clientSelect.addEventListener('change', function () {
            const email = this.selectedOptions[0]?.dataset.email || '';
            const emailInput = this.form?.querySelector('input[name="email"]');
            if (emailInput && email) emailInput.value = email;
        });
    }

    document.querySelectorAll('[data-copy-onboarding-link]').forEach(button => {
        if (button.dataset.initialized === '1') return;
        button.dataset.initialized = '1';
        button.addEventListener('click', async function () {
            const input = document.getElementById(this.dataset.copyOnboardingLink || '');
            if (!input) return;
            try {
                await navigator.clipboard.writeText(input.value);
                this.textContent = 'Copied';
            } catch (error) {
                input.select();
                document.execCommand('copy');
            }
        });
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initClientOnboardingPage);
} else {
    initClientOnboardingPage();
}
document.addEventListener('pageLoaded', initClientOnboardingPage);
