(function () {
    function setMessage(msg, text) {
        if (!msg) return;
        msg.style.display = 'block';
        msg.style.background = '#fee2e2';
        msg.style.border = '1px solid #fca5a5';
        msg.style.color = '#991b1b';
        msg.textContent = text;
    }

    function clearSuggestions(el) {
        if (!el) return;
        el.innerHTML = '';
        el.style.display = 'none';
    }

    function renderSelectedClients(root, selected) {
        const selectedList = root.querySelector('[data-forms-email-selected-clients]');
        const hidden = root.querySelector('[data-forms-email-client-hidden]');
        if (!selectedList || !hidden) return;
        selectedList.innerHTML = '';
        hidden.innerHTML = '';

        if (selected.size === 0) {
            selectedList.innerHTML = '<div class="forms-email-empty">No clients selected.</div>';
            return;
        }

        selected.forEach((client) => {
            const row = document.createElement('div');
            row.className = 'forms-email-chip';
            row.innerHTML = `
                <div>
                    <strong></strong>
                    <small></small>
                </div>
                <button type="button" aria-label="Remove client">x</button>
            `;
            row.querySelector('strong').textContent = client.name || 'Client';
            row.querySelector('small').textContent = client.email || 'No email on file';
            row.querySelector('button').addEventListener('click', () => {
                selected.delete(String(client.id));
                renderSelectedClients(root, selected);
            });
            selectedList.appendChild(row);

            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'client_ids[]';
            input.value = client.id;
            hidden.appendChild(input);
        });
    }

    function setRecipientType(root, type) {
        const recipientType = root.querySelector('#recipientType');
        const clientsPanel = root.querySelector('[data-forms-email-panel="clients"]');
        const orgPanel = root.querySelector('[data-forms-email-panel="organization"]');
        const clientBtn = root.querySelector('[data-recipient-type="clients"]');
        const orgBtn = root.querySelector('[data-recipient-type="organization"]');

        if (recipientType) recipientType.value = type;
        if (clientsPanel) clientsPanel.style.display = type === 'clients' ? 'block' : 'none';
        if (orgPanel) orgPanel.style.display = type === 'organization' ? 'block' : 'none';

        [clientBtn, orgBtn].forEach((btn) => {
            if (!btn) return;
            const active = btn.dataset.recipientType === type;
            btn.style.background = active ? 'var(--nav-accent)' : '#fff';
            btn.style.color = active ? '#fff' : 'inherit';
        });
    }

    async function searchJson(url) {
        const response = await fetch(url, { headers: { 'Accept': 'application/json' } });
        if (!response.ok) return [];
        const data = await response.json();
        return Array.isArray(data) ? data : [];
    }

    function renderSuggestions(box, rows, onPick) {
        if (!box) return;
        box.innerHTML = '';
        if (rows.length === 0) {
            clearSuggestions(box);
            return;
        }
        rows.forEach((row) => {
            const item = document.createElement('button');
            item.type = 'button';
            item.className = 'forms-email-suggestion';
            item.innerHTML = '<strong></strong><small></small>';
            item.querySelector('strong').textContent = row.name || 'Untitled';
            item.querySelector('small').textContent = row.email || row.org_name || row.address || '';
            item.addEventListener('click', () => onPick(row));
            box.appendChild(item);
        });
        box.style.display = 'block';
    }

    function initClientSearch(root, selected) {
        const input = root.querySelector('[data-forms-email-client-search]');
        const suggestions = root.querySelector('[data-forms-email-client-suggestions]');
        if (!input || !suggestions) return;
        let timer = 0;
        input.addEventListener('input', () => {
            clearTimeout(timer);
            const term = input.value.trim();
            if (term.length < 2) {
                clearSuggestions(suggestions);
                return;
            }
            timer = window.setTimeout(async () => {
                const rows = await searchJson('/?page=clients-search&term=' + encodeURIComponent(term));
                renderSuggestions(suggestions, rows, (client) => {
                    selected.set(String(client.id), client);
                    input.value = '';
                    clearSuggestions(suggestions);
                    renderSelectedClients(root, selected);
                });
            }, 180);
        });
    }

    function renderDepartments(root, departments) {
        const list = root.querySelector('[data-forms-email-departments]');
        if (!list) return;
        list.innerHTML = '';
        if (!departments.length) {
            list.innerHTML = '<div class="forms-email-empty">No departments found for this organization.</div>';
            return;
        }
        departments.forEach((department) => {
            const label = document.createElement('label');
            label.className = 'forms-email-check';
            label.innerHTML = '<input type="checkbox" name="department_ids[]"><span></span>';
            label.querySelector('input').value = department.id;
            label.querySelector('span').textContent = department.name + (department.folder_name ? ' - ' + department.folder_name : '');
            list.appendChild(label);
        });
    }

    function initOrgSearch(root) {
        const input = root.querySelector('[data-forms-email-org-search]');
        const suggestions = root.querySelector('[data-forms-email-org-suggestions]');
        const orgName = root.querySelector('[data-forms-email-selected-org]');
        const orgHidden = root.querySelector('[data-forms-email-org-id]');
        if (!input || !suggestions || !orgHidden) return;
        let timer = 0;
        input.addEventListener('input', () => {
            clearTimeout(timer);
            const term = input.value.trim();
            if (term.length < 2) {
                clearSuggestions(suggestions);
                return;
            }
            timer = window.setTimeout(async () => {
                const rows = await searchJson('/?page=organization/org-search&term=' + encodeURIComponent(term));
                renderSuggestions(suggestions, rows, async (org) => {
                    orgHidden.value = org.id;
                    input.value = '';
                    if (orgName) orgName.textContent = org.name || 'Selected organization';
                    clearSuggestions(suggestions);
                    const departments = await searchJson('/?page=organization/organization-departments-options&organization_id=' + encodeURIComponent(org.id));
                    renderDepartments(root, departments);
                });
            }, 180);
        });
    }

    function resetPicker(root, selected) {
        selected.clear();
        renderSelectedClients(root, selected);
        const orgHidden = root.querySelector('[data-forms-email-org-id]');
        const orgName = root.querySelector('[data-forms-email-selected-org]');
        const departments = root.querySelector('[data-forms-email-departments]');
        if (orgHidden) orgHidden.value = '';
        if (orgName) orgName.textContent = 'No organization selected.';
        if (departments) departments.innerHTML = '<div class="forms-email-empty">Select an organization to choose departments.</div>';
        setRecipientType(root, 'clients');
    }

    window.FormsEmailRecipientPicker = {
        init(form) {
            if (!form || form.dataset.formsEmailReady === '1') return null;
            form.dataset.formsEmailReady = '1';
            const selected = new Map();
            renderSelectedClients(form, selected);
            initClientSearch(form, selected);
            initOrgSearch(form);
            form.querySelectorAll('[data-recipient-type]').forEach((btn) => {
                btn.addEventListener('click', () => setRecipientType(form, btn.dataset.recipientType || 'clients'));
            });
            setRecipientType(form, 'clients');
            return {
                reset() {
                    resetPicker(form, selected);
                },
                validate(msg) {
                    const type = form.querySelector('#recipientType')?.value || '';
                    if (!type) {
                        setMessage(msg, 'Please choose who should receive the email');
                        return false;
                    }
                    if (type === 'clients' && selected.size === 0) {
                        setMessage(msg, 'Please add at least one client');
                        return false;
                    }
                    if (type === 'organization') {
                        const orgId = form.querySelector('[data-forms-email-org-id]')?.value || '';
                        if (!orgId) {
                            setMessage(msg, 'Please choose an organization');
                            return false;
                        }
                    }
                    return true;
                }
            };
        }
    };
})();
