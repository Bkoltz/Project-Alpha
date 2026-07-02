(function () {
    'use strict';

    // Timer display
    const timerDisplay = document.getElementById('timerDisplay');
    const activeTimerInfo = document.getElementById('activeTimerInfo');
    const activeTimerStarted = document.getElementById('activeTimerStarted');
    const section = document.querySelector('section.finance-dashboard');
    let timerInterval = null;

    function pad(n) { return n < 10 ? '0' + n : '' + n; }
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text == null ? '' : String(text);
        return div.innerHTML;
    }

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
            hiddenInput.dispatchEvent(new Event('change', { bubbles: true }));
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
                        return '<div data-id="' + x.id + '" data-name="' + escapeHtml(x.name) + '" style="padding:8px 10px;cursor:pointer">' + escapeHtml(x.name) + '</div>';
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

    function clearSelect(select, label) {
        if (!select) return;
        select.innerHTML = '<option value="">' + escapeHtml(label) + '</option>';
        select.disabled = true;
    }

    function setHelp(help, message) {
        if (!help) return;
        help.textContent = message || '';
        help.style.display = message ? 'block' : 'none';
    }

    function updateProjectCode(projectSelect, projectCodeInput) {
        if (!projectSelect || !projectCodeInput) return;
        const option = projectSelect.options[projectSelect.selectedIndex];
        projectCodeInput.value = option ? (option.getAttribute('data-project-code') || '') : '';
    }

    function selectOptionByValue(select, selectedValue) {
        if (!select || !selectedValue) return false;
        const value = String(selectedValue);
        for (let i = 0; i < select.options.length; i += 1) {
            if (select.options[i].value === value) {
                select.selectedIndex = i;
                return true;
            }
        }
        return false;
    }

    function selectJobOption(projectSelect, selectedId, selectedCode) {
        if (!projectSelect) return;
        const id = selectedId ? String(selectedId) : '';
        const code = selectedCode ? String(selectedCode) : '';
        for (let i = 0; i < projectSelect.options.length; i += 1) {
            const option = projectSelect.options[i];
            if ((id && option.value === id) || (code && option.getAttribute('data-project-code') === code)) {
                projectSelect.selectedIndex = i;
                return;
            }
        }
    }

    function selectedOption(select) {
        return select && select.selectedIndex >= 0 ? select.options[select.selectedIndex] : null;
    }

    function optionMatchesJob(option, projectId, projectCode) {
        if (!option) return false;
        const optionProjectId = option.getAttribute('data-project-id') || '';
        const optionProjectCode = option.getAttribute('data-project-code') || '';
        return !!((projectId && optionProjectId === String(projectId)) || (projectCode && optionProjectCode === String(projectCode)));
    }

    function selectFirstMatchingJobDocument(select, projectId, projectCode) {
        if (!select) return false;
        for (let i = 0; i < select.options.length; i += 1) {
            if (optionMatchesJob(select.options[i], projectId, projectCode)) {
                select.selectedIndex = i;
                return true;
            }
        }
        return false;
    }

    function selectInvoiceForContract(invoiceSelect, contractId) {
        if (!invoiceSelect || !contractId) return false;
        const value = String(contractId);
        for (let i = 0; i < invoiceSelect.options.length; i += 1) {
            if ((invoiceSelect.options[i].getAttribute('data-contract-id') || '') === value) {
                invoiceSelect.selectedIndex = i;
                return true;
            }
        }
        return false;
    }

    function syncFromJob(formConfig) {
        const option = selectedOption(formConfig.projectSelect);
        const projectId = option ? option.value : '';
        const code = option ? (option.getAttribute('data-project-code') || '') : '';
        updateProjectCode(formConfig.projectSelect, formConfig.projectCodeInput);
        const contractMatched = selectFirstMatchingJobDocument(formConfig.contractSelect, projectId, code);
        if (contractMatched && formConfig.contractSelect) {
            const contractOption = selectedOption(formConfig.contractSelect);
            if (contractOption && selectInvoiceForContract(formConfig.invoiceSelect, contractOption.value)) {
                return;
            }
        }
        selectFirstMatchingJobDocument(formConfig.invoiceSelect, projectId, code);
    }

    function syncFromContract(formConfig) {
        const option = selectedOption(formConfig.contractSelect);
        const code = option ? option.getAttribute('data-project-code') : '';
        const projectId = option ? option.getAttribute('data-project-id') : '';
        if ((code || projectId) && formConfig.projectSelect) {
            selectJobOption(formConfig.projectSelect, projectId, code);
            updateProjectCode(formConfig.projectSelect, formConfig.projectCodeInput);
        }
        if (option && selectInvoiceForContract(formConfig.invoiceSelect, option.value)) {
            return;
        }
        selectFirstMatchingJobDocument(formConfig.invoiceSelect, projectId, code);
    }

    function syncFromInvoice(formConfig) {
        const option = selectedOption(formConfig.invoiceSelect);
        const code = option ? option.getAttribute('data-project-code') : '';
        const projectId = option ? option.getAttribute('data-project-id') : '';
        const contractId = option ? option.getAttribute('data-contract-id') : '';
        if ((code || projectId) && formConfig.projectSelect) {
            selectJobOption(formConfig.projectSelect, projectId, code);
            updateProjectCode(formConfig.projectSelect, formConfig.projectCodeInput);
        }
        if (contractId && formConfig.contractSelect) {
            selectOptionByValue(formConfig.contractSelect, contractId);
        }
    }

    function populateTimeOptions(formConfig, data) {
        const projectSelect = formConfig.projectSelect;
        const contractSelect = formConfig.contractSelect;
        const invoiceSelect = formConfig.invoiceSelect;
        const projectCodeInput = formConfig.projectCodeInput;

        if (projectSelect) {
            projectSelect.innerHTML = '<option value="">No job selected</option>';
            (data.jobs || []).forEach(function (job) {
                const option = document.createElement('option');
                option.value = job.id ? String(job.id) : '';
                option.textContent = job.project_code || job.name || 'Job';
                option.setAttribute('data-project-code', job.project_code || '');
                projectSelect.appendChild(option);
            });
            projectSelect.disabled = false;
            selectJobOption(projectSelect, projectSelect.getAttribute('data-selected-id'), projectSelect.getAttribute('data-selected-code'));
            updateProjectCode(projectSelect, projectCodeInput);
            setHelp(formConfig.projectHelp, (data.jobs || []).length ? '' : 'No active jobs for this client.');
            projectSelect.removeAttribute('data-selected-id');
            projectSelect.removeAttribute('data-selected-code');
        }

        if (contractSelect) {
            contractSelect.innerHTML = '<option value="">No contract selected</option>';
            (data.contracts || []).forEach(function (contract) {
                const option = document.createElement('option');
                option.value = String(contract.id || '');
                option.textContent = 'C-' + (contract.doc_number || contract.id) + (contract.project_code ? ' / Job ' + contract.project_code : '');
                option.setAttribute('data-project-code', contract.project_code || '');
                option.setAttribute('data-project-id', contract.project_id || '');
                contractSelect.appendChild(option);
            });
            contractSelect.disabled = false;
            selectOptionByValue(contractSelect, contractSelect.getAttribute('data-selected-id'));
            contractSelect.removeAttribute('data-selected-id');
        }

        if (invoiceSelect) {
            invoiceSelect.innerHTML = '<option value="">No invoice selected</option>';
            (data.invoices || []).forEach(function (invoice) {
                const option = document.createElement('option');
                option.value = String(invoice.id || '');
                option.textContent = 'I-' + (invoice.doc_number || invoice.id) + (invoice.project_code ? ' / Job ' + invoice.project_code : '');
                option.setAttribute('data-project-code', invoice.project_code || '');
                option.setAttribute('data-project-id', invoice.project_id || '');
                option.setAttribute('data-contract-id', invoice.contract_id || '');
                invoiceSelect.appendChild(option);
            });
            invoiceSelect.disabled = false;
            selectOptionByValue(invoiceSelect, invoiceSelect.getAttribute('data-selected-id'));
            invoiceSelect.removeAttribute('data-selected-id');
        }
    }

    function loadTimeOptions(formConfig) {
        const clientId = formConfig.clientInput ? formConfig.clientInput.value : '';
        clearSelect(formConfig.projectSelect, clientId ? 'Loading jobs...' : 'Select a client first');
        clearSelect(formConfig.contractSelect, clientId ? 'Loading contracts...' : 'Select a client first');
        clearSelect(formConfig.invoiceSelect, clientId ? 'Loading invoices...' : 'Select a client first');
        if (formConfig.projectCodeInput) formConfig.projectCodeInput.value = '';
        setHelp(formConfig.projectHelp, '');
        if (!clientId) return;

        fetch('/?page=time-tracking/options&client_id=' + encodeURIComponent(clientId), {
            credentials: 'same-origin'
        })
            .then(function (r) { return r.ok ? r.json() : Promise.reject(); })
            .then(function (data) { populateTimeOptions(formConfig, data || {}); })
            .catch(function () {
                clearSelect(formConfig.projectSelect, 'Unable to load jobs');
                clearSelect(formConfig.contractSelect, 'Unable to load contracts');
                clearSelect(formConfig.invoiceSelect, 'Unable to load invoices');
            });
    }

    function initTimeOptions(formConfig) {
        if (!formConfig.form || !formConfig.clientInput) return;
        formConfig.clientInput.addEventListener('change', function () { loadTimeOptions(formConfig); });
        if (formConfig.projectSelect) {
            formConfig.projectSelect.addEventListener('change', function () {
                syncFromJob(formConfig);
            });
        }
        if (formConfig.contractSelect) {
            formConfig.contractSelect.addEventListener('change', function () {
                syncFromContract(formConfig);
            });
        }
        if (formConfig.invoiceSelect) {
            formConfig.invoiceSelect.addEventListener('change', function () {
                syncFromInvoice(formConfig);
            });
        }
        if (formConfig.clientInput.value) {
            loadTimeOptions(formConfig);
        }
    }

    initTimeOptions({
        form: document.getElementById('manualEntryForm'),
        clientInput: document.getElementById('clientId'),
        projectSelect: document.getElementById('timeProjectId'),
        projectCodeInput: document.getElementById('timeProjectCode'),
        projectHelp: document.getElementById('timeProjectHelp'),
        contractSelect: document.getElementById('timeContractId'),
        invoiceSelect: document.getElementById('timeInvoiceId')
    });

    initTimeOptions({
        form: document.getElementById('editEntryForm'),
        clientInput: document.getElementById('editClientId'),
        projectSelect: document.getElementById('editTimeProjectId'),
        projectCodeInput: document.getElementById('editTimeProjectCode'),
        projectHelp: document.getElementById('editTimeProjectHelp'),
        contractSelect: document.getElementById('editTimeContractId'),
        invoiceSelect: document.getElementById('editTimeInvoiceId')
    });

    function validateTimeEntryForm(form) {
        if (!form) return;
        form.addEventListener('submit', function (e) {
            const hoursEl = form.querySelector('[name="hours"]');
            const startEl = form.querySelector('[name="start_time"]');
            const endEl = form.querySelector('[name="end_time"]');
            const descEl = form.querySelector('[name="description"]');
            const hoursRaw = hoursEl ? (hoursEl.value || '').trim() : '';
            const hours = hoursRaw ? (parseFloat(hoursRaw) || 0) : 0;
            const hasManualHours = hoursRaw !== '' && hours > 0;
            const hasStart = !!(startEl && startEl.value);
            const hasEnd = !!(endEl && endEl.value);
            const desc = descEl ? (descEl.value || '').trim() : '';
            if (!desc) {
                e.preventDefault();
                alert('Please enter a description.');
                return false;
            }
            if (hasStart !== hasEnd) {
                e.preventDefault();
                alert('Enter both start and end time, or use manual hours.');
                return false;
            }
            if (hasStart && hasEnd && hasManualHours) {
                e.preventDefault();
                alert('Use either start/end times or manual hours, not both.');
                return false;
            }
            if (!hasStart && !hasEnd && !hasManualHours) {
                e.preventDefault();
                alert('Enter start/end times or manual hours greater than 0.');
                return false;
            }
        });
    }

    validateTimeEntryForm(document.getElementById('manualEntryForm'));
    validateTimeEntryForm(document.getElementById('editEntryForm'));

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
