(function () {
    'use strict';

    // Timer display
    const timerDisplay = document.getElementById('timerDisplay');
    const activeTimerInfo = document.getElementById('activeTimerInfo');
    const activeTimerStarted = document.getElementById('activeTimerStarted');
    const section = document.querySelector('section.finance-dashboard');
    let timerInterval = null;

    function pad(n) { return n < 10 ? '0' + n : '' + n; }
    function formatElapsed(seconds) {
        const h = Math.floor(seconds / 3600);
        const m = Math.floor((seconds % 3600) / 60);
        const s = Math.floor(seconds % 60);
        return pad(h) + ':' + pad(m) + ':' + pad(s);
    }

    function updateTimerDisplay() {
        const startedIso = section ? section.getAttribute('data-timer-started') : null;
        if (!startedIso) {
            if (timerDisplay) timerDisplay.textContent = '00:00:00';
            return;
        }
        const started = new Date(startedIso.replace(' ', 'T')).getTime();
        const now = Date.now();
        const elapsed = Math.max(0, Math.floor((now - started) / 1000));
        if (timerDisplay) timerDisplay.textContent = formatElapsed(elapsed);
    }

    function startTicking() {
        if (timerInterval) clearInterval(timerInterval);
        updateTimerDisplay();
        timerInterval = setInterval(updateTimerDisplay, 1000);
    }

    function stopTicking() {
        if (timerInterval) {
            clearInterval(timerInterval);
            timerInterval = null;
        }
        if (timerDisplay) timerDisplay.textContent = '00:00:00';
    }

    if (section && section.getAttribute('data-timer-started')) {
        if (activeTimerStarted) activeTimerStarted.textContent = section.getAttribute('data-timer-started');
        if (activeTimerInfo) activeTimerInfo.style.display = 'block';
        startTicking();
    }

    // Client autocomplete helper for both manual and edit forms
    function initClientAutocomplete(input, hiddenInput, suggestBox) {
        if (!input || !suggestBox || !hiddenInput) return;
        input.addEventListener('input', function () {
            hiddenInput.value = '';
            const term = this.value.trim();
            if (!term) {
                suggestBox.style.display = 'none';
                suggestBox.innerHTML = '';
                return;
            }
            fetch('/?page=clients-search&term=' + encodeURIComponent(term))
                .then(function (r) { return r.json(); })
                .then(function (list) {
                    if (!Array.isArray(list) || list.length === 0) {
                        suggestBox.style.display = 'none';
                        suggestBox.innerHTML = '';
                        return;
                    }
                    suggestBox.innerHTML = list.map(function (x) {
                        return '<div data-id="' + x.id + '" data-name="' + x.name.replace(/"/g, '&quot;') + '" style="padding:8px 10px;cursor:pointer">' + x.name + '</div>';
                    }).join('');
                    Array.from(suggestBox.children).forEach(function (el) {
                        el.addEventListener('click', function () {
                            input.value = this.dataset.name;
                            hiddenInput.value = this.dataset.id;
                            hiddenInput.dispatchEvent(new Event('change', { bubbles: true }));
                            suggestBox.style.display = 'none';
                        });
                    });
                    suggestBox.style.display = 'block';
                })
                .catch(function () {
                    suggestBox.style.display = 'none';
                });
        });
        document.addEventListener('click', function (e) {
            if (!suggestBox.contains(e.target) && e.target !== input) {
                suggestBox.style.display = 'none';
            }
        });
    }

    initClientAutocomplete(
        document.getElementById('clientInput'),
        document.getElementById('clientId'),
        document.getElementById('clientSuggest')
    );

    initClientAutocomplete(
        document.getElementById('editClientInput'),
        document.getElementById('editClientId'),
        document.getElementById('editClientSuggest')
    );

    document.querySelectorAll('[name="service_item_id"]').forEach(function (select) {
        select.addEventListener('change', function () {
            const option = this.options[this.selectedIndex];
            const rate = option ? option.getAttribute('data-rate') : '';
            if (!rate) return;
            const form = this.closest('form');
            const rateInput = form ? form.querySelector('[name="rate"]') : null;
            if (rateInput) rateInput.value = Number(rate).toFixed(2);
        });
    });

    function resetSelect(select, placeholder) {
        if (!select) return;
        select.innerHTML = '<option value="">' + placeholder + '</option>';
        select.disabled = true;
    }

    function optionText(parts) {
        return parts.filter(Boolean).join(' / ');
    }

    function loadTimeOptions(clientId) {
        const projectSelect = document.getElementById('timeProjectId');
        const projectCode = document.getElementById('timeProjectCode');
        const projectHelp = document.getElementById('timeProjectHelp');
        const contractSelect = document.getElementById('timeContractId');
        const invoiceSelect = document.getElementById('timeInvoiceId');

        if (projectCode) projectCode.value = '';
        if (projectHelp) {
            projectHelp.style.display = 'none';
            projectHelp.textContent = '';
        }

        if (!clientId) {
            resetSelect(projectSelect, 'Select a client first');
            resetSelect(contractSelect, 'Select a client first');
            resetSelect(invoiceSelect, 'Select a client first');
            return;
        }

        resetSelect(projectSelect, 'Loading jobs...');
        resetSelect(contractSelect, 'Loading contracts...');
        resetSelect(invoiceSelect, 'Loading invoices...');

        fetch('/?page=time-tracking/options&client_id=' + encodeURIComponent(clientId))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                const jobs = Array.isArray(data.jobs) ? data.jobs : [];
                const contracts = Array.isArray(data.contracts) ? data.contracts : [];
                const invoices = Array.isArray(data.invoices) ? data.invoices : [];

                if (projectSelect) {
                    projectSelect.innerHTML = '<option value="">No job selected</option>';
                    jobs.forEach(function (job) {
                        const opt = document.createElement('option');
                        opt.value = job.id;
                        opt.dataset.notes = job.notes || job.description || '';
                        opt.textContent = optionText([job.name, job.status ? job.status.replace('_', ' ') : '']);
                        projectSelect.appendChild(opt);
                    });
                    projectSelect.disabled = false;
                }

                if (contractSelect) {
                    contractSelect.innerHTML = '<option value="">No contract selected</option>';
                    contracts.forEach(function (contract) {
                        const opt = document.createElement('option');
                        opt.value = contract.id;
                        opt.dataset.projectId = contract.project_id || '';
                        opt.dataset.projectCode = contract.project_code || '';
                        opt.textContent = optionText(['C-' + (contract.doc_number || contract.id), contract.project_code ? 'Job ' + contract.project_code : '', contract.scope || '']);
                        contractSelect.appendChild(opt);
                    });
                    contractSelect.disabled = false;
                }

                if (invoiceSelect) {
                    invoiceSelect.innerHTML = '<option value="">No invoice selected</option>';
                    invoices.forEach(function (invoice) {
                        const opt = document.createElement('option');
                        opt.value = invoice.id;
                        opt.dataset.projectId = invoice.project_id || '';
                        opt.dataset.projectCode = invoice.project_code || '';
                        opt.textContent = optionText(['I-' + (invoice.doc_number || invoice.id), invoice.project_code ? 'Job ' + invoice.project_code : '', invoice.status]);
                        invoiceSelect.appendChild(opt);
                    });
                    invoiceSelect.disabled = false;
                }
            })
            .catch(function () {
                resetSelect(projectSelect, 'Unable to load jobs');
                resetSelect(contractSelect, 'Unable to load contracts');
                resetSelect(invoiceSelect, 'Unable to load invoices');
            });
    }

    const manualClientId = document.getElementById('clientId');
    if (manualClientId) {
        manualClientId.addEventListener('change', function () {
            loadTimeOptions(this.value);
        });
    }

    const projectSelect = document.getElementById('timeProjectId');
    if (projectSelect) {
        projectSelect.addEventListener('change', function () {
            const selected = this.options[this.selectedIndex];
            const help = document.getElementById('timeProjectHelp');
            const code = document.getElementById('timeProjectCode');
            if (code) code.value = selected ? (selected.dataset.projectCode || '') : '';
            if (help) {
                const note = selected ? (selected.dataset.notes || '') : '';
                help.textContent = note;
                help.style.display = note ? 'block' : 'none';
            }
        });
    }

    ['timeContractId', 'timeInvoiceId'].forEach(function (id) {
        const select = document.getElementById(id);
        if (!select) return;
        select.addEventListener('change', function () {
            const selected = this.options[this.selectedIndex];
            if (!selected) return;
            const projectId = selected.dataset.projectId || '';
            const projectCode = selected.dataset.projectCode || '';
            const projectSelect = document.getElementById('timeProjectId');
            const projectCodeInput = document.getElementById('timeProjectCode');
            if (projectSelect && projectId) projectSelect.value = projectId;
            if (projectCodeInput) projectCodeInput.value = projectCode;
        });
    });

    // Form validation for manual entry
    const manualForm = document.getElementById('manualEntryForm');
    if (manualForm) {
        manualForm.addEventListener('submit', function (e) {
            const hoursEl = manualForm.querySelector('[name="hours"]');
            const startEl = manualForm.querySelector('[name="start_time"]');
            const endEl = manualForm.querySelector('[name="end_time"]');
            const descEl = manualForm.querySelector('[name="description"]');
            const hours = hoursEl ? (parseFloat(hoursEl.value) || 0) : 0;
            const hasStartEnd = startEl && endEl && startEl.value && endEl.value;
            const desc = descEl ? (descEl.value || '').trim() : '';
            if (!desc) {
                e.preventDefault();
                alert('Please enter a description.');
                return false;
            }
            if (!hasStartEnd && hours <= 0) {
                e.preventDefault();
                alert('Enter start/end times or manual hours greater than 0.');
                return false;
            }
        });
    }

    // Delete confirmation
    document.querySelectorAll('.delete-entry-form').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            if (!confirm('Delete this time entry?')) {
                e.preventDefault();
                return false;
            }
        });
    });
})();
