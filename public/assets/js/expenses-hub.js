(function () {
    'use strict';

    function initExpensesHub(context) {
        const root = context && context.root ? context.root : document;
        const tabs = root.querySelectorAll('.expenses-hub__tab');
        const panels = root.querySelectorAll('.expenses-hub__panel');
        if (!tabs.length || !panels.length) return;

        function switchTab(target) {
            tabs.forEach(tab => {
                const selected = tab.dataset.tab === target;
                tab.classList.toggle('active', selected);
                tab.setAttribute('aria-selected', selected ? 'true' : 'false');
            });
            panels.forEach(panel => panel.classList.toggle('active', panel.id === 'tab-' + target));
            const url = new URL(window.location.href);
            url.searchParams.set('tab', target);
            window.history.replaceState({}, '', url.toString());
        }

        tabs.forEach(tab => {
            if (tab.dataset.expensesHubReady === '1') return;
            tab.dataset.expensesHubReady = '1';
            tab.addEventListener('click', function (event) {
                event.preventDefault();
                switchTab(this.dataset.tab);
            });
        });

        const params = new URLSearchParams(window.location.search);
        const active = params.get('tab') || 'overview';
        const tabEl = root.querySelector('.expenses-hub__tab[data-tab="' + active + '"]');
        if (tabEl) switchTab(active);
    }
    initExpensesHub.pageInitializerId = 'expenses-hub';

    if (window.ProjectAlpha && typeof window.ProjectAlpha.registerPage === 'function') {
        window.ProjectAlpha.registerPage('financial/expenses-list', initExpensesHub);
    } else if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => initExpensesHub({ root: document }), { once: true });
    } else {
        initExpensesHub({ root: document });
    }
})();
