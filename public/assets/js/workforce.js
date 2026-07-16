(function () {
    'use strict';

    function initWorkforceTime() {
        const root = document.querySelector('[data-workforce-time-page]');
        if (!root || root.dataset.workforceReady === '1') return;
        root.dataset.workforceReady = '1';

        let timer = null;
        const startedAt = root.getAttribute('data-running-start');
        const display = root.querySelector('#workforce-timer-display');
        if (startedAt && display) {
            const startedMs = Date.parse(startedAt.replace(' ', 'T') + 'Z');
            const renderTimer = function () {
                const seconds = Math.max(0, Math.floor((Date.now() - startedMs) / 1000));
                const hours = String(Math.floor(seconds / 3600)).padStart(2, '0');
                const minutes = String(Math.floor((seconds % 3600) / 60)).padStart(2, '0');
                const remainder = String(seconds % 60).padStart(2, '0');
                display.textContent = hours + ':' + minutes + ':' + remainder;
            };
            renderTimer();
            timer = window.setInterval(renderTimer, 1000);
        }

        root.querySelectorAll('[data-workforce-entry-form]').forEach(function (form) {
            const assignment = form.querySelector('[name="work_assignment_id"]');
            const job = form.querySelector('[name="job_id"]');
            const workType = form.querySelector('[name="work_type_id"]');
            if (assignment) assignment.addEventListener('change', function () {
                const option = assignment.options[assignment.selectedIndex];
                if (!option || !option.value) return;
                if (job && option.dataset.jobId) job.value = option.dataset.jobId;
                if (workType && option.dataset.workTypeId) workType.value = option.dataset.workTypeId;
            });
            const client = form.querySelector('[data-workforce-client]');
            const project = form.querySelector('[data-workforce-project]');
            const invoice = form.querySelector('[data-workforce-invoice]');
            if (!client || !project || !invoice) return;

            function filterOptions() {
                const clientId = client.value;
                Array.from(project.options).forEach(function (option) {
                    if (!option.value) return;
                    const visible = !clientId || option.dataset.clientId === clientId;
                    option.hidden = !visible;
                    option.disabled = !visible;
                    if (!visible && option.selected) project.value = '';
                });
                Array.from(invoice.options).forEach(function (option) {
                    if (!option.value) return;
                    const visible = !clientId || option.dataset.clientId === clientId;
                    option.hidden = !visible;
                    option.disabled = !visible;
                    if (!visible && option.selected) invoice.value = '';
                });
            }

            client.addEventListener('change', filterOptions);
            project.addEventListener('change', function () {
                const option = project.options[project.selectedIndex];
                if (option && option.dataset.clientId) {
                    client.value = option.dataset.clientId;
                    filterOptions();
                }
            });
            invoice.addEventListener('change', function () {
                const option = invoice.options[invoice.selectedIndex];
                if (!option) return;
                if (option.dataset.clientId) client.value = option.dataset.clientId;
                filterOptions();
                if (option.dataset.projectId) project.value = option.dataset.projectId;
            });
            filterOptions();
        });

        return function () {
            if (timer) window.clearInterval(timer);
        };
    }

    initWorkforceTime.pageInitializerId = 'workforce-time';
    if (window.ProjectAlpha && typeof window.ProjectAlpha.registerPage === 'function') {
        window.ProjectAlpha.registerPage('workforce/time', initWorkforceTime);
    } else if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initWorkforceTime, { once: true });
    } else {
        initWorkforceTime();
    }
})();
