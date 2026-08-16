// Service Library modal, package search, and exclusive Service/Work Activity link.
(function () {
    'use strict';
    function byId(id) { return document.getElementById(id); }
    function setValue(id, value) { var el = byId(id); if (el) el.value = value == null ? '' : value; }
    function setChecked(id, value) { var el = byId(id); if (el) el.checked = !!value; }
    function billingUnitLabel(unit) { return unit === 'each' ? 'service unit' : String(unit || ''); }
    function openModal() { var modal = byId('itemModal'); if (!modal) return; modal.hidden = false; document.body.classList.add('has-modal'); var name = byId('itemName'); if (name) name.focus(); }
    function closeModal() { var modal = byId('itemModal'); if (modal) modal.hidden = true; document.body.classList.remove('has-modal'); }

    function syncPricing() {
        var model = (byId('pricingModel') || {}).value || 'fixed';
        document.querySelectorAll('[data-overage-field]').forEach(function (field) { field.hidden = model !== 'base_overage'; });
        var unit = byId('billingUnit');
        if (unit) { unit.disabled = model === 'hourly'; if (model === 'hourly') unit.value = 'hour'; }
        var label = document.querySelector('[data-price-label]');
        var help = document.querySelector('[data-price-help]');
        if (label) label.textContent = model === 'hourly' ? 'Client hourly rate *' : (model === 'base_overage' ? 'Base client price *' : 'Client price *');
        if (help) help.textContent = model === 'base_overage' ? 'The base price covers the included minutes; only approved overage becomes an additional charge.' : 'The normal client price; it can still be adjusted on an individual document.';
    }
    function syncActivityLink() {
        var mode = (byId('activityLinkMode') || {}).value || 'none';
        var field = document.querySelector('[data-existing-activity]');
        if (field) field.hidden = mode !== 'existing';
        var select = byId('linkedWorkTypeId');
        if (select) select.required = mode === 'existing';
    }
    function syncBundleVisibility() {
        var isBundle = (byId('entryType') || {}).value === 'bundle';
        var box = byId('bundleContents'); if (box) box.hidden = !isBundle;
        var link = byId('activityLinkCard'); if (link) link.hidden = isBundle;
        if (isBundle) setValue('activityLinkMode', 'none');
        syncActivityLink();
    }

    var bundleSelection = {};
    var bundleCurrentId = '';
    function bundleChoices() { var source = byId('bundleChoicesData'); if (!source) return []; try { return JSON.parse(source.textContent || '[]'); } catch (error) { return []; } }
    function bundleChoice(id) { return bundleChoices().find(function (choice) { return String(choice.id) === String(id); }); }
    function renderBundleSelection() {
        var target = document.querySelector('[data-bundle-selected]');
        var empty = document.querySelector('[data-bundle-empty]');
        if (!target) return;
        target.innerHTML = '';
        Object.keys(bundleSelection).forEach(function (id) {
            var choice = bundleChoice(id); if (!choice) return;
            var row = document.createElement('article'); row.className = 'catalog-bundle-selected-row'; row.setAttribute('data-selected-bundle-row',''); row.setAttribute('data-item-id',id);
            var details = document.createElement('span'); var name = document.createElement('strong'); name.textContent = choice.name; var price = document.createElement('small'); price.textContent = '$' + Number(choice.unit_price || 0).toFixed(2) + ' / ' + billingUnitLabel(choice.billing_unit); details.appendChild(name); details.appendChild(price);
            var quantityLabel = document.createElement('label'); quantityLabel.className = 'field'; var quantityText = document.createElement('span'); quantityText.className = 'label'; quantityText.textContent = 'Quantity'; var quantity = document.createElement('input'); quantity.className = 'input input--small'; quantity.type = 'number'; quantity.min = '0.01'; quantity.step = '0.01'; quantity.value = bundleSelection[id]; quantity.setAttribute('data-bundle-quantity',''); quantity.setAttribute('aria-label','Package quantity for ' + choice.name); quantityLabel.appendChild(quantityText); quantityLabel.appendChild(quantity);
            var remove = document.createElement('button'); remove.type = 'button'; remove.className = 'btn btn-sm btn-danger-outline'; remove.textContent = 'Remove'; remove.setAttribute('data-bundle-selected-remove',id);
            row.appendChild(details); row.appendChild(quantityLabel); row.appendChild(remove); target.appendChild(row);
        });
        if (empty) empty.hidden = target.children.length > 0;
    }
    function renderBundleResults(query) {
        var target = document.querySelector('[data-bundle-results]'); var search = document.querySelector('[data-bundle-search]'); if (!target) return;
        query = String(query || '').trim().toLocaleLowerCase(); target.innerHTML = '';
        if (!query) { target.hidden = true; if (search) search.setAttribute('aria-expanded','false'); return; }
        var matches = bundleChoices().filter(function (choice) { return String(choice.id) !== bundleCurrentId && !Object.prototype.hasOwnProperty.call(bundleSelection,String(choice.id)) && (choice.name + ' ' + (choice.description || '')).toLocaleLowerCase().includes(query); }).slice(0,8);
        matches.forEach(function (choice) { var button = document.createElement('button'); button.type = 'button'; button.className = 'catalog-bundle-result'; button.setAttribute('data-bundle-result-add',choice.id); button.setAttribute('role','option'); var label = document.createElement('strong'); label.textContent = choice.name; var detail = document.createElement('small'); detail.textContent = '$' + Number(choice.unit_price || 0).toFixed(2) + ' / ' + billingUnitLabel(choice.billing_unit); button.appendChild(label); button.appendChild(detail); target.appendChild(button); });
        if (!matches.length) { var none = document.createElement('p'); none.textContent = 'No matching services or fees.'; target.appendChild(none); }
        target.hidden = false; if (search) search.setAttribute('aria-expanded','true');
    }
    function setBundleItems(items, currentId) { bundleSelection = {}; bundleCurrentId = String(currentId || ''); (items || []).forEach(function (row) { bundleSelection[String(row.item_library_id)] = row.quantity || 1; }); var search = document.querySelector('[data-bundle-search]'); if (search) search.value = ''; renderBundleSelection(); renderBundleResults(''); syncBundleVisibility(); }

    function resetActivityOptions(currentServiceId) {
        var select = byId('linkedWorkTypeId'); if (!select) return;
        Array.prototype.forEach.call(select.options, function (option) {
            var linkedServiceId = option.getAttribute('data-linked-service-id');
            option.disabled = !!linkedServiceId && linkedServiceId !== String(currentServiceId || '');
        });
    }
    function showCreateModal() {
        var title = byId('modalTitle'); if (title) title.textContent = 'Add service';
        setValue('formAction','create'); setValue('formId',''); setValue('itemName',''); setValue('itemDescription',''); setValue('unitPrice',''); setValue('pricingModel','fixed'); setValue('clientIncludedMinutes',''); setValue('clientOverageRate',''); setValue('pricingCurrency','USD'); setValue('entryType','service'); setValue('billingUnit','each'); setValue('fulfillmentNotes',''); setChecked('isActive',true); setChecked('portalRequestable',false); setValue('portalCategory',''); setValue('portalDisplayOrder','0'); setValue('portalGeometry','optional'); setValue('portalSummary',''); setValue('portalQuestions','[]'); setValue('linkedComponentId',''); setValue('activityLinkMode','new'); setValue('linkedWorkTypeId',''); resetActivityOptions(null); setBundleItems([],null); syncPricing(); syncActivityLink(); openModal();
    }
    function editItem(item) {
        var title = byId('modalTitle'); if (title) title.textContent = 'Edit service';
        setValue('formAction','update'); setValue('formId',item.id); setValue('itemName',item.item_name); setValue('itemDescription',item.description); setValue('unitPrice',item.unit_price); setValue('pricingModel',item.client_pricing_model || (item.billing_unit === 'hour' ? 'hourly' : 'fixed')); setValue('clientIncludedMinutes',item.client_included_minutes); setValue('clientOverageRate',item.client_overage_rate); setValue('pricingCurrency',item.pricing_currency || 'USD'); setValue('entryType',item.entry_type === 'product' ? 'service' : (item.entry_type || 'service')); setValue('billingUnit',item.billing_unit || 'each'); setValue('fulfillmentNotes',item.fulfillment_notes); setChecked('isActive',item.is_active == 1); setChecked('portalRequestable',item.portal_requestable == 1); setValue('portalCategory',item.portal_category || ''); setValue('portalDisplayOrder',item.portal_display_order || '0'); setValue('portalGeometry',item.portal_geometry_requirement || 'optional'); setValue('portalSummary',item.portal_summary || ''); setValue('portalQuestions',item.portal_questions_json || '[]'); setValue('linkedComponentId',item.linked_component_id || ''); resetActivityOptions(item.id);
        if (item.linked_work_type_id) { setValue('activityLinkMode','existing'); setValue('linkedWorkTypeId',item.linked_work_type_id); } else { setValue('activityLinkMode','none'); setValue('linkedWorkTypeId',''); }
        setBundleItems(item.bundle_items || [],item.id); syncPricing(); syncActivityLink(); openModal();
    }
    function parseItem(button) { try { return JSON.parse(button.getAttribute('data-item') || '{}'); } catch (error) { return {}; } }
    function serializeBundles() { var items = []; if ((byId('entryType') || {}).value === 'bundle') document.querySelectorAll('[data-selected-bundle-row]').forEach(function (row) { items.push({item_library_id:row.getAttribute('data-item-id'),quantity:row.querySelector('[data-bundle-quantity]').value}); }); setValue('bundleItemsJson',JSON.stringify(items)); }

    function handleClick(event) {
        var button = event.target.closest('[data-item-library-create]'); if (button) { event.preventDefault(); showCreateModal(); return; }
        button = event.target.closest('[data-item-library-edit]'); if (button) { event.preventDefault(); editItem(parseItem(button)); return; }
        if (event.target.closest('[data-item-library-close]')) { event.preventDefault(); closeModal(); return; }
        button = event.target.closest('[data-bundle-result-add]'); if (button) { event.preventDefault(); bundleSelection[String(button.getAttribute('data-bundle-result-add'))] = 1; renderBundleSelection(); var search=document.querySelector('[data-bundle-search]'); renderBundleResults(search ? search.value : ''); return; }
        button = event.target.closest('[data-bundle-selected-remove]'); if (button) { event.preventDefault(); delete bundleSelection[String(button.getAttribute('data-bundle-selected-remove'))]; renderBundleSelection(); var bundleSearch=document.querySelector('[data-bundle-search]'); renderBundleResults(bundleSearch ? bundleSearch.value : ''); return; }
        var modal = byId('itemModal'); if (modal && event.target === modal) closeModal();
    }
    function handleChange(event) { if (event.target.id === 'entryType') syncBundleVisibility(); if (event.target.id === 'pricingModel') syncPricing(); if (event.target.id === 'activityLinkMode') syncActivityLink(); }
    function handleInput(event) { if (event.target.matches('[data-bundle-search]')) renderBundleResults(event.target.value); }
    function handleSubmit(event) { if (!event.target.matches('[data-catalog-form]')) return; var unit = byId('billingUnit'); if (unit) unit.disabled = false; serializeBundles(); }
    function initItemLibraryPage() {
        var modal = byId('itemModal'); if (!modal) return; modal.setAttribute('data-item-library-ready','1');
        var requestedId = new URLSearchParams(window.location.search).get('edit_service');
        if (requestedId) { var button = document.querySelector('[data-item-library-edit][data-item]'); document.querySelectorAll('[data-item-library-edit][data-item]').forEach(function (candidate) { var item=parseItem(candidate); if (String(item.id)===String(requestedId)) button=candidate; }); if (button) editItem(parseItem(button)); }
    }
    initItemLibraryPage.pageInitializerId = 'item-library';
    if (!window.__projectAlphaItemLibraryClickReady) { window.__projectAlphaItemLibraryClickReady = true; document.addEventListener('click',handleClick); document.addEventListener('change',handleChange); document.addEventListener('input',handleInput); document.addEventListener('submit',handleSubmit); }
    window.showCreateModal = showCreateModal; window.editItem = editItem; window.closeModal = closeModal;
    if (window.ProjectAlpha && typeof window.ProjectAlpha.registerPage === 'function') window.ProjectAlpha.registerPage('settings',initItemLibraryPage); else if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded',initItemLibraryPage,{once:true}); else initItemLibraryPage();
})();
