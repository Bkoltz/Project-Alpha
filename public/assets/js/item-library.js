// Item Library modal and repeatable internal work components. Safe after AJAX navigation.
(function () {
    'use strict';
    function byId(id) { return document.getElementById(id); }
    function setValue(id, value) { var el = byId(id); if (el) el.value = value == null ? '' : value; }
    function setChecked(id, value) { var el = byId(id); if (el) el.checked = !!value; }

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
    function renumberComponents() {
        document.querySelectorAll('#workComponents [data-work-component]').forEach(function (card, index) {
            var label = card.querySelector('[data-component-number]'); if (label) label.textContent = 'Work component ' + (index + 1);
        });
    }
    function addComponent(data) {
        var template = byId('workComponentTemplate'), target = byId('workComponents');
        if (!template || !target) return;
        var card = template.content.firstElementChild.cloneNode(true);
        data = data || {};
        ['id','name','work_type_id','quantity_behavior','expected_duration_minutes','compensation_method','compensation_amount','included_minutes','overage_rate','percentage','percentage_basis','eligibility_trigger'].forEach(function (name) {
            setComponentValue(card, name, data[name]);
        });
        setComponentValue(card, 'assignment_required', data.assignment_required == null ? true : data.assignment_required);
        if (!(componentField(card, 'quantity_behavior').value)) componentField(card, 'quantity_behavior').value = 'per_line';
        if (!(componentField(card, 'compensation_method').value)) componentField(card, 'compensation_method').value = 'nonpayable';
        if (!(componentField(card, 'percentage_basis').value)) componentField(card, 'percentage_basis').value = 'net_line';
        if (!(componentField(card, 'eligibility_trigger').value)) componentField(card, 'eligibility_trigger').value = 'completed_approved';
        target.appendChild(card); syncPayFields(card); renumberComponents();
    }
    function clearComponents() { var target = byId('workComponents'); if (target) target.innerHTML = ''; }
    function syncBundleVisibility() { var box = byId('bundleContents'); if (box) box.hidden = (byId('entryType') || {}).value !== 'bundle'; }
    function setBundleItems(items, currentId) {
        var selected = {}; (items || []).forEach(function (row) { selected[String(row.item_library_id)] = row.quantity; });
        document.querySelectorAll('[data-bundle-row]').forEach(function (row) {
            var id = row.getAttribute('data-item-id'), checkbox = row.querySelector('[data-bundle-child]'), quantity = row.querySelector('[data-bundle-quantity]');
            row.hidden = String(currentId || '') === id; checkbox.checked = Object.prototype.hasOwnProperty.call(selected,id); quantity.value = selected[id] || 1;
        });
        syncBundleVisibility();
    }

    function showCreateModal() {
        var title = byId('modalTitle'); if (title) title.textContent = 'Add catalog item';
        setValue('formAction','create'); setValue('formId',''); setValue('itemName',''); setValue('itemSku','');
        setValue('itemDescription',''); setValue('unitPrice',''); setValue('entryType','product'); setValue('billingUnit','each');
        setValue('taxBehavior','inherit'); setValue('fulfillmentNotes',''); setChecked('isActive',true); clearComponents(); setBundleItems([],null); openModal();
    }
    function editItem(item) {
        var title = byId('modalTitle'); if (title) title.textContent = 'Edit catalog item';
        setValue('formAction','update'); setValue('formId',item.id); setValue('itemName',item.item_name); setValue('itemSku',item.sku);
        setValue('itemDescription',item.description); setValue('unitPrice',item.unit_price); setValue('entryType',item.entry_type || 'product');
        setValue('billingUnit',item.billing_unit || (item.category === 'Hourly' ? 'hour' : 'each')); setValue('taxBehavior',item.tax_behavior || 'inherit');
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
        if ((byId('entryType') || {}).value === 'bundle') document.querySelectorAll('[data-bundle-row]').forEach(function (row) {
            var checkbox=row.querySelector('[data-bundle-child]'); if(checkbox.checked) bundleItems.push({item_library_id:checkbox.value,quantity:row.querySelector('[data-bundle-quantity]').value});
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
        button = event.target.closest('[data-remove-work-component]');
        if (button) { event.preventDefault(); button.closest('[data-work-component]').remove(); renumberComponents(); return; }
        var modal = byId('itemModal'); if (modal && event.target === modal) closeModal();
    }
    function handleChange(event) {
        if (event.target.id === 'entryType') { syncBundleVisibility(); return; }
        if (!event.target.matches('[data-field="compensation_method"],[data-field="percentage_basis"]')) return;
        syncPayFields(event.target.closest('[data-work-component]'));
    }
    function handleSubmit(event) {
        if (!event.target.matches('[data-catalog-form]')) return;
        serializeComponents(event.target);
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
        document.addEventListener('submit',handleSubmit);
    }
    window.showCreateModal = showCreateModal; window.editItem = editItem; window.closeModal = closeModal;
    if (window.ProjectAlpha && typeof window.ProjectAlpha.registerPage === 'function') window.ProjectAlpha.registerPage('settings/item-library',initItemLibraryPage);
    else if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded',initItemLibraryPage,{once:true});
    else initItemLibraryPage();
})();
