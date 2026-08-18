(function () {
    'use strict';

    var clients = [];
    var invoiceClients = [];
    var selected = {
        project: new Set(),
        invoice: new Set()
    };
    var lastOrgId = '';

    function byId(id) {
        return document.getElementById(id);
    }

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.textContent = text == null ? '' : String(text);
        return div.innerHTML;
    }

    function clientLabel(client) {
        return client.name + (client.email ? ' - ' + client.email : '');
    }

    function clientById(id, type) {
        id = String(id);
        var source = type === 'invoice' ? invoiceClients : clients;
        return source.find(function (client) { return String(client.id) === id; }) || null;
    }

    function pickerName(type) {
        return type === 'invoice' ? 'project_invoice_email_client_ids[]' : 'project_client_ids[]';
    }

    function pickerEmpty(type) {
        return type === 'invoice' ? 'No invoice email recipients selected.' : 'No additional clients selected.';
    }

    function getPicker(type) {
        var root = document.querySelector('[data-project-client-picker="' + type + '"]');
        if (!root) return null;
        return {
            root: root,
            selected: root.querySelector('[data-picker-selected]'),
            search: root.querySelector('[data-picker-search]'),
            suggestions: root.querySelector('[data-picker-suggestions]'),
            hidden: root.querySelector('[data-picker-hidden]')
        };
    }

    function renderPicker(type) {
        var picker = getPicker(type);
        if (!picker) return;
        var ids = Array.from(selected[type]).filter(function (id) { return clientById(id, type); });
        selected[type] = new Set(ids);

        picker.selected.innerHTML = '';
        picker.hidden.innerHTML = '';
        if (ids.length === 0) {
            picker.selected.innerHTML = '<div class="project-client-picker__empty">' + escapeHtml(picker.root.dataset.emptyText || pickerEmpty(type)) + '</div>';
        }
        ids.forEach(function (id) {
            var client = clientById(id, type);
            var row = document.createElement('div');
            row.className = 'project-client-picker__item';
            row.innerHTML =
                '<span><span class="project-client-picker__name">' + escapeHtml(client.name) + '</span>' +
                (client.is_primary_department_contact ? '<span class="project-client-picker__badge">Primary</span>' : '') +
                (client.email ? '<span class="project-client-picker__meta">' + escapeHtml(client.email) + '</span>' : '<span class="project-client-picker__meta">No email</span>') +
                '</span>' +
                '<button type="button" class="project-client-picker__remove" aria-label="Remove ' + escapeHtml(client.name) + '" data-remove-id="' + escapeHtml(id) + '">x</button>';
            picker.selected.appendChild(row);

            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = pickerName(type);
            input.value = id;
            picker.hidden.appendChild(input);
        });

        picker.selected.querySelectorAll('[data-remove-id]').forEach(function (button) {
            button.addEventListener('click', function () {
                var id = String(button.getAttribute('data-remove-id') || '');
                selected[type].delete(id);
                renderPicker(type);
            });
        });
    }

    function renderSuggestions(type, query) {
        var picker = getPicker(type);
        if (!picker) return;
        query = (query || '').trim().toLowerCase();
        picker.suggestions.innerHTML = '';
        if (!clients.length || query.length === 0) {
            picker.suggestions.style.display = 'none';
            return;
        }
        var source = type === 'invoice' ? invoiceClients : clients;
        var list = source.filter(function (client) {
            if (selected[type].has(String(client.id))) return false;
            var haystack = (client.name + ' ' + (client.email || '')).toLowerCase();
            return haystack.indexOf(query) !== -1;
        }).slice(0, 12);
        if (!list.length) {
            picker.suggestions.innerHTML = '<div class="project-client-picker__suggestion" style="color:var(--muted)">No matching clients</div>';
            picker.suggestions.style.display = 'block';
            return;
        }
        list.forEach(function (client) {
            var option = document.createElement('div');
            option.className = 'project-client-picker__suggestion';
            option.setAttribute('data-client-id', String(client.id));
            option.innerHTML =
                '<strong>' + escapeHtml(client.name) + '</strong>' +
                (client.is_primary_department_contact ? '<span class="project-client-picker__badge">Primary</span>' : '') +
                (client.is_department_contact && !client.is_primary_department_contact ? '<span class="project-client-picker__badge">Department</span>' : '') +
                '<span class="project-client-picker__meta">' + escapeHtml(client.email || 'No email') + '</span>';
            picker.suggestions.appendChild(option);
        });
        picker.suggestions.style.display = 'block';
    }

    function addClient(type, id) {
        id = String(id);
        if (!clientById(id, type)) return;
        selected[type].add(id);
        renderPicker(type);
    }

    function setPickerEnabled(enabled) {
        ['project', 'invoice'].forEach(function (type) {
            var picker = getPicker(type);
            if (!picker || !picker.search) return;
            var pickerEnabled = type === 'invoice' ? true : enabled;
            picker.search.disabled = !pickerEnabled;
            picker.search.placeholder = pickerEnabled ? 'Type a client name or email...' : 'Select an organization first';
            if (!pickerEnabled) {
                picker.suggestions.style.display = 'none';
            }
        });
    }

    function pruneSelections() {
        selected.project = new Set(Array.from(selected.project).filter(function (id) { return clientById(id, 'project'); }));
        selected.invoice = new Set(Array.from(selected.invoice).filter(function (id) { return clientById(id, 'invoice'); }));
    }

    function applyDepartmentDefaults() {
        clients.forEach(function (client) {
            if (!invoiceClients.some(function (candidate) { return candidate.id === client.id; })) {
                invoiceClients.push(client);
            }
            if (Number(client.is_primary_department_contact || 0) === 1) {
                selected.invoice.add(String(client.id));
                selected.project.add(String(client.id));
            }
        });
    }

    function renderAll() {
        renderPicker('project');
        renderPicker('invoice');
    }

    function renderOrganizationRecipient(organization) {
        var checkbox = byId('projectOrganizationInvoiceEmail');
        var help = byId('projectOrganizationInvoiceEmailHelp');
        if (!checkbox || !help) return;
        var email = organization && organization.email ? String(organization.email) : '';
        var name = organization && organization.name ? String(organization.name) : 'Organization';
        checkbox.disabled = !email;
        if (!email) checkbox.checked = false;
        help.textContent = email
            ? name + ' company email: ' + email + '. Select explicitly to include it.'
            : 'This organization has no company email saved.';
    }

    function loadClientOptions() {
        var orgId = byId('organization_id_create') ? byId('organization_id_create').value : '';
        var departmentId = byId('projectDepartmentSelect') ? byId('projectDepartmentSelect').value : '';
        if (!orgId) {
            clients = [];
            selected.project.clear();
            setPickerEnabled(false);
            renderOrganizationRecipient(null);
            renderAll();
            return;
        }
        var orgChanged = orgId !== lastOrgId;
        lastOrgId = orgId;
        if (orgChanged) {
            selected.project.clear();
            selected.invoice.clear();
            invoiceClients = [];
        }
        fetch('/?page=project/client-options&organization_id=' + encodeURIComponent(orgId) + '&department_id=' + encodeURIComponent(departmentId), {
            credentials: 'same-origin'
        })
            .then(function (response) { return response.ok ? response.json() : Promise.reject(); })
            .then(function (data) {
                clients = Array.isArray(data.clients) ? data.clients.map(function (client) {
                    client.id = String(client.id);
                    client.is_department_contact = Number(client.is_department_contact || 0);
                    client.is_primary_department_contact = Number(client.is_primary_department_contact || 0);
                    return client;
                }) : [];
                invoiceClients = clients.slice();
                renderOrganizationRecipient(data.organization_recipient || null);
                pruneSelections();
                applyDepartmentDefaults();
                setPickerEnabled(true);
                renderAll();
            })
            .catch(function () {
                clients = [];
                renderOrganizationRecipient(null);
                setPickerEnabled(false);
                renderAll();
            });
    }

    function initPickers() {
        var initializedNewPicker = false;
        ['project', 'invoice'].forEach(function (type) {
            var picker = getPicker(type);
            if (!picker || picker.root.dataset.ready === '1') return;
            picker.root.dataset.ready = '1';
            initializedNewPicker = true;
            picker.search.addEventListener('input', function () {
                renderSuggestions(type, picker.search.value);
                if (type === 'invoice' && picker.search.value.trim().length >= 2) {
                    fetch('/?page=clients-search&term=' + encodeURIComponent(picker.search.value.trim()), { credentials: 'same-origin' })
                        .then(function (response) { return response.ok ? response.json() : []; })
                        .then(function (rows) {
                            (Array.isArray(rows) ? rows : []).forEach(function (client) {
                                client.id = String(client.id);
                                if (!invoiceClients.some(function (candidate) { return candidate.id === client.id; })) {
                                    invoiceClients.push(client);
                                }
                            });
                            renderSuggestions(type, picker.search.value);
                        })
                        .catch(function () { /* keep local organization matches */ });
                }
            });
            picker.search.addEventListener('focus', function () {
                renderSuggestions(type, picker.search.value);
            });
            picker.suggestions.addEventListener('click', function (event) {
                var option = event.target.closest('[data-client-id]');
                if (!option) return;
                addClient(type, option.getAttribute('data-client-id'));
                picker.search.value = '';
                picker.suggestions.style.display = 'none';
            });
        });
        if (initializedNewPicker) {
            clients = [];
            invoiceClients = [];
            selected.project.clear();
            selected.invoice.clear();
            lastOrgId = '';
        }
        if (!window.__projectAlphaProjectPickerOutsideClickReady) {
            window.__projectAlphaProjectPickerOutsideClickReady = true;
            document.addEventListener('click', function (event) {
                ['project', 'invoice'].forEach(function (type) {
                    var picker = getPicker(type);
                    if (picker && !picker.root.contains(event.target)) {
                        picker.suggestions.style.display = 'none';
                    }
                });
            });
        }
        setPickerEnabled(false);
        renderAll();
    }

    function initOrgSearch() {
        var input = byId('orgInputProject');
        var hidden = byId('organization_id_create');
        var suggest = byId('orgSuggestProject');
        if (!input || !hidden || !suggest || input._orgSearchReady) return;
        input._orgSearchReady = true;

        input.addEventListener('input', function () {
            hidden.value = '';
            loadDepartments('');
            loadClientOptions();
            var term = input.value.trim();
            if (!term) {
                suggest.style.display = 'none';
                suggest.innerHTML = '';
                return;
            }
            fetch('/?page=organization/org-search&term=' + encodeURIComponent(term))
                .then(function (r) { return r.json(); })
                .then(function (list) {
                    if (!Array.isArray(list) || list.length === 0) {
                        suggest.style.display = 'none';
                        suggest.innerHTML = '';
                        return;
                    }
                    suggest.innerHTML = list.map(function (org) {
                        return '<div data-id="' + org.id + '" data-name="' + escapeHtml(org.name) + '" style="padding:8px 10px;cursor:pointer">' + escapeHtml(org.name) + '</div>';
                    }).join('');
                    Array.from(suggest.children).forEach(function (el) {
                        el.addEventListener('click', function () {
                            input.value = this.dataset.name;
                            hidden.value = this.dataset.id;
                            loadDepartments(this.dataset.id);
                            loadClientOptions();
                            suggest.style.display = 'none';
                        });
                    });
                    suggest.style.display = 'block';
                })
                .catch(function () {
                    suggest.style.display = 'none';
                });
        });

        document.addEventListener('click', function (e) {
            if (!suggest.contains(e.target) && e.target !== input) {
                suggest.style.display = 'none';
            }
        });
    }

    function loadDepartments(orgId) {
        var select = byId('projectDepartmentSelect');
        if (!select) return;
        var emptyLabel = select.dataset.emptyLabel || 'No department / org-level project';
        select.innerHTML = '<option value="">' + escapeHtml(emptyLabel) + '</option>';
        if (!orgId) return;
        fetch('/?page=organization/organization-departments-options&organization_id=' + encodeURIComponent(orgId), {
            credentials: 'same-origin'
        })
            .then(function (r) { return r.ok ? r.json() : []; })
            .then(function (list) {
                if (!Array.isArray(list)) return;
                list.forEach(function (dept) {
                    var option = document.createElement('option');
                    option.value = String(dept.id || '');
                    option.textContent = (dept.name || 'Department') + (dept.folder_name ? ' - ' + dept.folder_name : '');
                    select.appendChild(option);
                });
            })
            .catch(function () { /* leave only the org-level option */ });
    }

    function initProjectManagerDefaults() {
        var manager = byId('projectManagerSelect');
        var businessUnit = byId('projectBusinessUnitSelect');
        var touched = byId('projectBusinessUnitTouched');
        var suggestion = byId('projectManagerUnitSuggestion');
        if (!manager || !businessUnit || !touched || manager.dataset.unitDefaultReady === '1') return;
        manager.dataset.unitDefaultReady = '1';
        businessUnit.addEventListener('change', function () {
            touched.value = '1';
        });
        manager.addEventListener('change', function () {
            var option = manager.options[manager.selectedIndex];
            var suggestedUnit = option ? String(option.dataset.primaryBusinessUnit || '') : '';
            var suggestedOption = Array.from(businessUnit.options).find(function (unit) { return unit.value === suggestedUnit; });
            if (suggestion) {
                suggestion.textContent = suggestedOption ? 'Manager primary unit: ' + suggestedOption.textContent + '.' : 'This manager has no primary Business Unit.';
            }
            if (touched.value !== '1' && suggestedOption) {
                businessUnit.value = suggestedUnit;
            }
        });
    }

    function initProjectForm() {
        initPickers();
        initOrgSearch();
        initProjectManagerDefaults();
        var departmentSelect = byId('projectDepartmentSelect');
        if (departmentSelect && departmentSelect.dataset.clientOptionsReady !== '1') {
            departmentSelect.dataset.clientOptionsReady = '1';
            departmentSelect.addEventListener('change', loadClientOptions);
        }
        loadClientOptions();
    }
    initProjectForm.pageInitializerId = 'project-form';

    if (window.ProjectAlpha && typeof window.ProjectAlpha.registerPage === 'function') {
        window.ProjectAlpha.registerPage('project/projects-create', initProjectForm);
    } else if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initProjectForm, { once: true });
    } else {
        initProjectForm();
    }
})();
