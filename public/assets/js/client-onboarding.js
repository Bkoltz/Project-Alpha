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

    document.querySelectorAll('[data-open-onboarding-review]').forEach(button => {
        if (button.dataset.reviewInitialized === '1') return;
        button.dataset.reviewInitialized = '1';
        button.addEventListener('click', function () {
            const modal = document.getElementById(this.dataset.openOnboardingReview || '');
            if (!modal) return;
            modal.classList.add('is-open');
            modal.setAttribute('aria-hidden', 'false');
        });
    });

    document.querySelectorAll('[data-close-onboarding-review]').forEach(button => {
        if (button.dataset.reviewInitialized === '1') return;
        button.dataset.reviewInitialized = '1';
        button.addEventListener('click', function () {
            const modal = document.getElementById(this.dataset.closeOnboardingReview || '');
            if (!modal) return;
            modal.classList.remove('is-open');
            modal.setAttribute('aria-hidden', 'true');
        });
    });

    document.querySelectorAll('.onboarding-review-modal').forEach(modal => {
        if (modal.dataset.backdropInitialized === '1') return;
        modal.dataset.backdropInitialized = '1';
        modal.addEventListener('click', function (event) {
            if (event.target !== this) return;
            this.classList.remove('is-open');
            this.setAttribute('aria-hidden', 'true');
        });
    });
}
initClientOnboardingPage.pageInitializerId = 'client-onboarding';

if (window.ProjectAlpha && typeof window.ProjectAlpha.registerPage === 'function') {
    window.ProjectAlpha.registerPage('client/onboarding', initClientOnboardingPage);
} else if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initClientOnboardingPage, { once: true });
} else {
    initClientOnboardingPage();
}
