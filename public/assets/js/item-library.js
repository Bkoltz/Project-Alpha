// Service Library modal and reusable Work Activity connections. Safe after AJAX navigation.
(function () {
    'use strict';
    function byId(id) { return document.getElementById(id); }
    function setValue(id, value) { var el = byId(id); if (el) el.value = value == null ? '' : value; }
    function setChecked(id, value) { var el = byId(id); if (el) el.checked = !!value; }
    function billingUnitLabel(unit) {
        return unit === 'each' ? 'service unit' : String(unit || '');
    }

    function openModal() {
        var modal = byId('itemModal');
        if (!modal) return;
        modal.hidden = false;
        document.body.classList.add('has-modal');
        var name = byId('itemName'); if (name) name.focus();
    }
    function closeModal() {
        var modal = byId('itemModal'); if (modal) modal.hidden = true;
        document.body.classList.remove('has-modal');
    }

    function componentField(card, name) { return card.querySelector('[data-field="' + name + '"]'); }
    function setComponentValue(card, name, value) {
        var field = componentField(card, name); if (!field) return;
        if (field.type === 'checkbox') field.checked = value == 1 || value === true;
        else field.value = value == null ? '' : value;
    }
    function syncPayFields(card) {
        var method = (componentField(card, 'compensation_method') || {}).value || 'nonpayable';
        card.querySelectorAll('[data-pay-field]').forEach(function (field) {
            var kind = field.getAttribute('data-pay-field');
            var visible = (kind === 'amount' && ['hourly','fixed','base_overage'].indexOf(method) !== -1)
                || ((kind === 'included' || kind === 'overage') && method === 'base_overage')
                || ((kind === 'percentage' || kind === 'basis') && method === 'percentage');
            field.hidden = !visible;
            field.querySelectorAll('input,select').forEach(function (input) { input.disabled = !visible; });
        });
        var basis = (componentField(card, 'percentage_basis') || {}).value;
        if (method === 'percentage' && basis === 'cash_collected') {
            componentField(card, 'eligibility_trigger').value = 'invoice_paid';
        }
    }
    function syncClientBillingFields(card) {
        var treatment = (componentField(card, 'client_billing_treatment') || {}).value || 'fixed_price_included';
        card.querySelectorAll('[data-client-billing-field]').forEach(function (field) {
            var kind = field.getAttribute('data-client-billing-field');
            var visible = (kind === 'rate' && treatment === 'hourly')
                || ((kind === 'included' || kind === 'overage') && treatment === 'base_overage')
                || (kind === 'currency' && (treatment === 'hourly' || treatment === 'base_overage'));
            field.hidden = !visible;
            field.querySelectorAll('input,select').forEach(function (input) { input.disabled = !visible; });
        });
    }
    function renumberComponents() {
        document.querySelectorAll('#workComponents [data-work-component]').forEach(function (card, index) {
            var label = card.querySelector('[data-component-number]'); if (label) label.textContent = 'Work Activity ' + (index + 1);
        });
    }
    function addComponent(data) {
        var template = byId('workComponentTemplate'), target = byId('workComponents');
        if (!template || !target) return;
        var card = template.content.firstElementChild.cloneNode(true);
        data = data || {};
        ['id','name','work_type_id','quantity_behavior','expected_duration_minutes','client_billing_treatment','client_billing_rate','client_included_minutes','client_overage_rate','client_billing_currency','compensation_method','compensation_amount','included_minutes','overage_rate','percentage','percentage_basis','eligibility_trigger'].forEach(function (name) {
            setComponentValue(card, name, data[name]);
        });
        setComponentValue(card, 'assignment_required', data.assignment_required == null ? true : data.assignment_required);
        if (!(componentField(card, 'quantity_behavior').value)) componentField(card, 'quantity_behavior').value = 'per_line';
        if (!(componentField(card, 'work_type_id').value)) componentField(card, 'work_type_id').value = 'new';
        if (!(componentField(card, 'client_billing_treatment').value)) componentField(card, 'client_billing_treatment').value = 'fixed_price_included';
        if (!(componentField(card, 'client_billing_currency').value)) componentField(card, 'client_billing_currency').value = 'USD';
        if (!(componentField(card, 'compensation_method').value)) componentField(card, 'compensation_method').value = 'nonpayable';
        if (!(componentField(card, 'percentage_basis').value)) componentField(card, 'percentage_basis').value = 'net_line';
        if (!(componentField(card, 'eligibility_trigger').value)) componentField(card, 'eligibility_trigger').value = 'completed_approved';
        if (data.auto_name) card.setAttribute('data-auto-activity-name','1');
        if (data.auto_billing) card.setAttribute('data-auto-activity-billing','1');
        target.appendChild(card); syncPayFields(card); syncClientBillingFields(card); renumberComponents();
    }
    function clearComponents() { var target = byId('workComponents'); if (target) target.innerHTML = ''; }
    function syncBundleVisibility() { var box = byId('bundleContents'); if (box) box.hidden = (byId('entryType') || {}).value !== 'bundle'; }
    var bundleSelection = {};
    var bundleCurrentId = '';
    function bundleChoices() {
        var source = byId('bundleChoicesData');
        if (!source) return [];
        try { return JSON.parse(source.textContent || '[]'); } catch (error) { return []; }
    }
    function bundleChoice(id) {
        return bundleChoices().find(function (choice) { return String(choice.id) === String(id); });
    }
    function renderBundleSelection() {
        var target = document.querySelector('[data-bundle-selected]');
        var empty = document.querySelector('[data-bundle-empty]');
        if (!target) return;
        target.innerHTML = '';
        Object.keys(bundleSelection).forEach(function (id) {
            var choice = bundleChoice(id);
            if (!choice) return;
            var row = document.createElement('article');
            row.className = 'catalog-bundle-selected-row';
            row.setAttribute('data-selected-bundle-row','');
            row.setAttribute('data-item-id',id);
            var details = document.createElement('span');
            var name = document.createElement('strong'); name.textContent = choice.name;
            var price = document.createElement('small'); price.textContent = '$' + Number(choice.unit_price || 0).toFixed(2) + ' / ' + billingUnitLabel(choice.billing_unit);
            details.appendChild(name); details.appendChild(price);
            var quantityLabel = document.createElement('label'); quantityLabel.className = 'field';
            var quantityText = document.createElement('span'); quantityText.className = 'label'; quantityText.textContent = 'Quantity';
            var quantity = document.createElement('input'); quantity.className = 'input input--small'; quantity.type = 'number'; quantity.min = '0.01'; quantity.step = '0.01'; quantity.value = bundleSelection[id]; quantity.setAttribute('data-bundle-quantity',''); quantity.setAttribute('aria-label','Package quantity for ' + choice.name);
            quantityLabel.appendChild(quantityText); quantityLabel.appendChild(quantity);
            var remove = document.createElement('button'); remove.type = 'button'; remove.className = 'btn btn-sm btn-danger-outline'; remove.textContent = 'Remove'; remove.setAttribute('data-bundle-selected-remove',id);
            row.appendChild(details); row.appendChild(quantityLabel); row.appendChild(remove); target.appendChild(row);
        });
        if (empty) empty.hidden = target.children.length > 0;
    }
    function renderBundleResults(query) {
        var target = document.querySelector('[data-bundle-results]');
        var search = document.querySelector('[data-bundle-search]');
        if (!target) return;
        query = String(query || '').trim().toLocaleLowerCase();
        target.innerHTML = '';
        if (query === '') { target.hidden = true; if (search) search.setAttribute('aria-expanded','false'); return; }
        var matches = bundleChoices().filter(function (choice) {
            return String(choice.id) !== bundleCurrentId
                && !Object.prototype.hasOwnProperty.call(bundleSelection,String(choice.id))
                && (choice.name + ' ' + (choice.description || '')).toLocaleLowerCase().includes(query);
        }).slice(0,8);
        matches.forEach(function (choice) {
            var button = document.createElement('button'); button.type = 'button'; button.className = 'catalog-bundle-result'; button.setAttribute('data-bundle-result-add',choice.id); button.setAttribute('role','option');
            var label = document.createElement('strong'); label.textContent = choice.name;
            var detail = document.createElement('small'); detail.textContent = '$' + Number(choice.unit_price || 0).toFixed(2) + ' / ' + billingUnitLabel(choice.billing_unit);
            button.appendChild(label); button.appendChild(detail); target.appendChild(button);
        });
        if (!matches.length) { var none = document.createElement('p'); none.textContent = 'No matching services or fees.'; target.appendChild(none); }
        target.hidden = false; if (search) search.setAttribute('aria-expanded','true');
    }
    function setBundleItems(items, currentId) {
        bundleSelection = {}; bundleCurrentId = String(currentId || '');
        (items || []).forEach(function (row) { bundleSelection[String(row.item_library_id)] = row.quantity || 1; });
        var search = document.querySelector('[data-bundle-search]'); if (search) search.value = '';
        renderBundleSelection(); renderBundleResults('');
        syncBundleVisibility();
    }

    function showCreateModal() {
        var title = byId('modalTitle'); if (title) title.textContent = 'Add service';
        setValue('formAction','create'); setValue('formId',''); setValue('itemName','');
        setValue('itemDescription',''); setValue('unitPrice',''); setValue('entryType','service'); setValue('billingUnit','each');
        setValue('fulfillmentNotes',''); setChecked('isActive',true); clearComponents();
        addComponent({work_type_id:'new',client_billing_treatment:'fixed_price_included',client_billing_currency:'USD',auto_name:true,auto_billing:true});
        setBundleItems([],null); openModal();
    }
    function editItem(item) {
        var title = byId('modalTitle'); if (title) title.textContent = 'Edit service';
        setValue('formAction','update'); setValue('formId',item.id); setValue('itemName',item.item_name);
        setValue('itemDescription',item.description); setValue('unitPrice',item.unit_price); setValue('entryType',item.entry_type === 'product' ? 'service' : (item.entry_type || 'service'));
        setValue('billingUnit',item.billing_unit || (item.category === 'Hourly' ? 'hour' : 'each'));
        setValue('fulfillmentNotes',item.fulfillment_notes); setChecked('isActive',item.is_active == 1); clearComponents();
        (item.work_components || []).forEach(addComponent); setBundleItems(item.bundle_items || [],item.id); openModal();
    }
    function parseItem(button) { try { return JSON.parse(button.getAttribute('data-item') || '{}'); } catch (error) { return {}; } }

    function serializeComponents(form) {
        var components = [];
        form.querySelectorAll('[data-work-component]').forEach(function (card) {
            var component = {};
            card.querySelectorAll('[data-field]').forEach(function (field) {
                component[field.getAttribute('data-field')] = field.type === 'checkbox' ? (field.checked ? 1 : 0) : field.value;
            });
            components.push(component);
        });
        byId('componentsJson').value = JSON.stringify(components);
        var bundleItems = [];
        if ((byId('entryType') || {}).value === 'bundle') document.querySelectorAll('[data-selected-bundle-row]').forEach(function (row) {
            bundleItems.push({item_library_id:row.getAttribute('data-item-id'),quantity:row.querySelector('[data-bundle-quantity]').value});
        });
        byId('bundleItemsJson').value = JSON.stringify(bundleItems);
    }

    function handleClick(event) {
        var button = event.target.closest('[data-item-library-create]');
        if (button) { event.preventDefault(); showCreateModal(); return; }
        button = event.target.closest('[data-item-library-edit]');
        if (button) { event.preventDefault(); editItem(parseItem(button)); return; }
        if (event.target.closest('[data-item-library-close]')) { event.preventDefault(); closeModal(); return; }
        if (event.target.closest('[data-add-work-component]')) { event.preventDefault(); addComponent({}); return; }
        button = event.target.closest('[data-bundle-result-add]');
        if (button) { event.preventDefault(); bundleSelection[String(button.getAttribute('data-bundle-result-add'))] = 1; renderBundleSelection(); var search=document.querySelector('[data-bundle-search]'); renderBundleResults(search ? search.value : ''); return; }
        button = event.target.closest('[data-bundle-selected-remove]');
        if (button) { event.preventDefault(); delete bundleSelection[String(button.getAttribute('data-bundle-selected-remove'))]; renderBundleSelection(); var bundleSearch=document.querySelector('[data-bundle-search]'); renderBundleResults(bundleSearch ? bundleSearch.value : ''); return; }
        button = event.target.closest('[data-remove-work-component]');
        if (button) { event.preventDefault(); button.closest('[data-work-component]').remove(); renumberComponents(); return; }
        var modal = byId('itemModal'); if (modal && event.target === modal) closeModal();
    }
    function handleChange(event) {
        if (event.target.id === 'entryType') { syncBundleVisibility(); return; }
        if (event.target.id === 'billingUnit') {
            document.querySelectorAll('[data-auto-activity-billing]').forEach(function (card) {
                var treatment = componentField(card,'client_billing_treatment');
                if (treatment) treatment.value = event.target.value === 'hour' ? 'hourly' : 'fixed_price_included';
                syncClientBillingFields(card);
            });
            return;
        }
        var card = event.target.closest('[data-work-component]');
        if (!card) return;
        if (event.target.matches('[data-field="compensation_method"],[data-field="percentage_basis"]')) syncPayFields(card);
        if (event.target.matches('[data-field="client_billing_treatment"]')) {
            card.removeAttribute('data-auto-activity-billing');
            syncClientBillingFields(card);
        }
        if (event.target.matches('[data-field="work_type_id"]') && event.target.value !== 'new') {
            card.removeAttribute('data-auto-activity-name');
            var selected = event.target.options[event.target.selectedIndex];
            var name = componentField(card,'name');
            if (name && selected) name.value = selected.textContent.replace(/^Use\s+/,'').trim();
        }
    }
    function handleSubmit(event) {
        if (!event.target.matches('[data-catalog-form]')) return;
        serializeComponents(event.target);
    }
    function handleInput(event) {
        if (event.target.matches('[data-bundle-search]')) renderBundleResults(event.target.value);
        if (event.target.id === 'itemName') {
            document.querySelectorAll('[data-auto-activity-name]').forEach(function (card) {
                var name = componentField(card,'name'); if (name) name.value = event.target.value;
            });
        }
        if (event.target.matches('[data-field="name"]')) event.target.closest('[data-work-component]').removeAttribute('data-auto-activity-name');
    }
    function initItemLibraryPage() {
        var modal = byId('itemModal'); if (!modal) return;
        modal.setAttribute('data-item-library-ready','1');
    }
    initItemLibraryPage.pageInitializerId = 'item-library';

    if (!window.__projectAlphaItemLibraryClickReady) {
        window.__projectAlphaItemLibraryClickReady = true;
        document.addEventListener('click',handleClick);
        document.addEventListener('change',handleChange);
        document.addEventListener('input',handleInput);
        document.addEventListener('submit',handleSubmit);
    }
    window.showCreateModal = showCreateModal; window.editItem = editItem; window.closeModal = closeModal;
    if (window.ProjectAlpha && typeof window.ProjectAlpha.registerPage === 'function') window.ProjectAlpha.registerPage('settings',initItemLibraryPage);
    else if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded',initItemLibraryPage,{once:true});
    else initItemLibraryPage();
})();
