(function () {
    'use strict';

    function initClientCombobox(root, combobox) {
        if (!combobox || combobox.dataset.ready === '1') return;
        combobox.dataset.ready = '1';
        const input = combobox.querySelector('[data-workforce-client-search]');
        const hidden = combobox.querySelector('[data-workforce-client]');
        const results = combobox.querySelector('[data-workforce-client-results]');
        if (!input || !hidden || !results) return;

        let timer = null;
        let controller = null;
        let activeIndex = -1;

        function closeResults() {
            results.hidden = true;
            results.innerHTML = '';
            activeIndex = -1;
            input.setAttribute('aria-expanded', 'false');
        }

        function choose(row) {
            hidden.value = String(row.id || '');
            input.value = row.name || '';
            input.dataset.selectedName = row.name || '';
            closeResults();
            hidden.dispatchEvent(new Event('change', { bubbles: true }));
        }

        function setActive(index) {
            const options = Array.from(results.querySelectorAll('[role="option"]'));
            if (!options.length) return;
            activeIndex = Math.max(0, Math.min(index, options.length - 1));
            options.forEach(function (option, optionIndex) {
                const selected = optionIndex === activeIndex;
                option.classList.toggle('is-active', selected);
                option.setAttribute('aria-selected', selected ? 'true' : 'false');
            });
            options[activeIndex].scrollIntoView({ block: 'nearest' });
        }

        function render(rows) {
            results.innerHTML = '';
            activeIndex = -1;
            if (!Array.isArray(rows) || rows.length === 0) {
                const empty = document.createElement('div');
                empty.className = 'workforce-combobox__empty';
                empty.textContent = 'No matching clients';
                results.appendChild(empty);
            } else {
                rows.forEach(function (row) {
                    const option = document.createElement('button');
                    option.type = 'button';
                    option.className = 'workforce-combobox__option';
                    option.setAttribute('role', 'option');
                    option.setAttribute('aria-selected', 'false');
                    option.textContent = row.name || '';
                    option.addEventListener('mousedown', function (event) {
                        event.preventDefault();
                        choose(row);
                    });
                    results.appendChild(option);
                });
            }
            results.hidden = false;
            input.setAttribute('aria-expanded', 'true');
        }

        function search() {
            const query = input.value.trim();
            if (query.length < 1) {
                closeResults();
                return;
            }
            if (controller) controller.abort();
            controller = new AbortController();
            fetch('/?page=api/workforce-v1&resource=clients&q=' + encodeURIComponent(query) + '&limit=25', {
                signal: controller.signal,
                headers: { 'Accept': 'application/json' }
            })
                .then(function (response) { return response.ok ? response.json() : Promise.reject(new Error('Search failed')); })
                .then(function (payload) { render(payload && Array.isArray(payload.data) ? payload.data : []); })
                .catch(function (error) {
                    if (error.name !== 'AbortError') closeResults();
                });
        }

        input.addEventListener('input', function () {
            if (input.value !== input.dataset.selectedName) {
                hidden.value = '';
                hidden.dispatchEvent(new Event('change', { bubbles: true }));
            }
            window.clearTimeout(timer);
            timer = window.setTimeout(search, 180);
        });
        input.addEventListener('focus', function () {
            if (input.value.trim() && !hidden.value) search();
        });
        input.addEventListener('keydown', function (event) {
            const options = results.querySelectorAll('[role="option"]');
            if (event.key === 'ArrowDown' && options.length) {
                event.preventDefault();
                setActive(activeIndex + 1);
            } else if (event.key === 'ArrowUp' && options.length) {
                event.preventDefault();
                setActive(activeIndex <= 0 ? options.length - 1 : activeIndex - 1);
            } else if (event.key === 'Enter' && activeIndex >= 0 && options[activeIndex]) {
                event.preventDefault();
                options[activeIndex].dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));
            } else if (event.key === 'Escape') {
                closeResults();
            }
        });
        input.addEventListener('blur', function () {
            window.setTimeout(closeResults, 120);
        });
        hidden.addEventListener('workforce:set-client', function (event) {
            const detail = event.detail || {};
            hidden.value = detail.id ? String(detail.id) : '';
            input.value = detail.name || '';
            input.dataset.selectedName = detail.name || '';
            hidden.dispatchEvent(new Event('change', { bubbles: true }));
        });
    }

    function initEntryForm(root, form) {
        const assignment = form.querySelector('[name="work_assignment_id"]');
        const assignmentSelect = assignment && assignment.tagName === 'SELECT' ? assignment : null;
        const job = form.querySelector('[name="job_id"]');
        const workType = form.querySelector('[name="work_type_id"]');
        const client = form.querySelector('[data-workforce-client]');
        const clientSearch = form.querySelector('[data-workforce-client-search]');
        const project = form.querySelector('[data-workforce-project]');
        const invoice = form.querySelector('[data-workforce-invoice]');

        function setClient(id, name) {
            if (!client) return;
            client.dispatchEvent(new CustomEvent('workforce:set-client', {
                detail: { id: id || '', name: name || '' }
            }));
        }

        function filterOptions() {
            const clientId = client ? client.value : '';
            [project, invoice, job].forEach(function (select) {
                if (!select) return;
                Array.from(select.options).forEach(function (option) {
                    if (!option.value || !option.dataset.clientId) return;
                    const visible = !clientId || option.dataset.clientId === clientId;
                    option.hidden = !visible;
                    option.disabled = !visible;
                    if (!visible && option.selected) select.value = '';
                });
            });
            if (assignment && assignment.value) {
                const assignmentJobId = assignmentSelect
                    ? assignmentSelect.options[assignmentSelect.selectedIndex].dataset.jobId
                    : assignment.dataset.jobId;
                if (!job || !job.value || assignmentJobId !== job.value) {
                    assignment.value = '';
                }
            }
        }

        if (assignmentSelect) assignmentSelect.addEventListener('change', function () {
            const option = assignmentSelect.options[assignmentSelect.selectedIndex];
            if (!option || !option.value) return;
            if (job && option.dataset.jobId) job.value = option.dataset.jobId;
            if (workType && option.dataset.workTypeId) workType.value = option.dataset.workTypeId;
            if (job) job.dispatchEvent(new Event('change', { bubbles: true }));
        });
        if (client) client.addEventListener('change', filterOptions);
        if (project) project.addEventListener('change', function () {
            const option = project.options[project.selectedIndex];
            if (option && option.dataset.clientId) {
                setClient(option.dataset.clientId, option.dataset.clientName || '');
            }
            filterOptions();
        });
        if (invoice) invoice.addEventListener('change', function () {
            const option = invoice.options[invoice.selectedIndex];
            if (!option) return;
            if (option.dataset.clientId) setClient(option.dataset.clientId, option.dataset.clientName || '');
            if (option.dataset.projectId && project) project.value = option.dataset.projectId;
            if (option.dataset.jobId && job) job.value = option.dataset.jobId;
            filterOptions();
        });
        if (job) job.addEventListener('change', function () {
            const option = job.options[job.selectedIndex];
            if (!option) return;
            if (option.dataset.clientId) setClient(option.dataset.clientId, option.dataset.clientName || '');
            if (option.dataset.projectId && project) project.value = option.dataset.projectId;
            filterOptions();
        });
        if (clientSearch && client && client.value) clientSearch.dataset.selectedName = clientSearch.value;
        filterOptions();
    }

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

        root.querySelectorAll('[data-workforce-client-combobox]').forEach(function (combobox) {
            initClientCombobox(root, combobox);
        });
        root.querySelectorAll('[data-workforce-entry-form]').forEach(function (form) {
            initEntryForm(root, form);
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
