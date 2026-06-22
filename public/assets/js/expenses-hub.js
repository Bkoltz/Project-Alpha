document.addEventListener('DOMContentLoaded', function () {
    const tabs = document.querySelectorAll('.expenses-hub__tab');
    const panels = document.querySelectorAll('.expenses-hub__panel');

    function switchTab(target) {
        tabs.forEach(t => t.classList.toggle('active', t.dataset.tab === target));
        panels.forEach(p => p.classList.toggle('active', p.id === 'tab-' + target));
        const url = new URL(window.location.href);
        url.searchParams.set('tab', target);
        window.history.replaceState({}, '', url.toString());
    }

    tabs.forEach(tab => {
        tab.addEventListener('click', function () {
            switchTab(this.dataset.tab);
        });
    });

    // Initialize from URL
    const params = new URLSearchParams(window.location.search);
    const active = params.get('tab') || 'expenses';
    const tabEl = document.querySelector('.expenses-hub__tab[data-tab="' + active + '"]');
    if (tabEl) switchTab(active);
});
