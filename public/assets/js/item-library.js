// public/assets/js/item-library.js
// Idempotent modal wiring for Settings > Item Library.

(function () {
    function byId(id) {
        return document.getElementById(id);
    }

    function setValue(id, value) {
        var el = byId(id);
        if (el) el.value = value == null ? '' : value;
    }

    function setChecked(id, checked) {
        var el = byId(id);
        if (el) el.checked = !!checked;
    }

    function openModal() {
        var modal = byId('itemModal');
        if (!modal) return;
        modal.style.display = 'flex';

        var name = byId('itemName');
        if (name) name.focus();
    }

    function showCreateModal() {
        var title = byId('modalTitle');
        if (title) title.textContent = 'Add New Item';

        setValue('formAction', 'create');
        setValue('formId', '');
        setValue('itemName', '');
        setValue('itemDescription', '');
        setValue('unitPrice', '');
        setChecked('isHourly', false);
        setChecked('isActive', true);
        openModal();
    }

    function editItem(item) {
        var title = byId('modalTitle');
        if (title) title.textContent = 'Edit Item';

        setValue('formAction', 'update');
        setValue('formId', item.id);
        setValue('itemName', item.item_name);
        setValue('itemDescription', item.description || '');
        setValue('unitPrice', item.unit_price);
        setChecked('isHourly', item.is_hourly == 1 || item.category === 'Hourly');
        setChecked('isActive', item.is_active == 1);
        openModal();
    }

    function closeModal() {
        var modal = byId('itemModal');
        if (modal) modal.style.display = 'none';
    }

    function parseItem(button) {
        try {
            return JSON.parse(button.getAttribute('data-item') || '{}');
        } catch (err) {
            console.warn('Failed to parse item library row data');
            return {};
        }
    }

    function handleClick(e) {
        var createButton = e.target.closest('[data-item-library-create]');
        if (createButton) {
            e.preventDefault();
            showCreateModal();
            return;
        }

        var editButton = e.target.closest('[data-item-library-edit]');
        if (editButton) {
            e.preventDefault();
            editItem(parseItem(editButton));
            return;
        }

        if (e.target.closest('[data-item-library-close]')) {
            e.preventDefault();
            closeModal();
            return;
        }

        var modal = byId('itemModal');
        if (modal && e.target === modal) {
            closeModal();
        }
    }

    function initItemLibraryPage() {
        var modal = byId('itemModal');
        if (!modal) return;
        modal.setAttribute('data-item-library-ready', '1');
    }

    window.showCreateModal = showCreateModal;
    window.editItem = editItem;
    window.closeModal = closeModal;
    window.initItemLibraryPage = initItemLibraryPage;

    if (!window.__projectAlphaItemLibraryClickReady) {
        window.__projectAlphaItemLibraryClickReady = true;
        document.addEventListener('click', handleClick);
    }

    if (!window.__projectAlphaItemLibraryPageLoadedReady) {
        window.__projectAlphaItemLibraryPageLoadedReady = true;
        document.addEventListener('pageLoaded', initItemLibraryPage);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initItemLibraryPage);
    } else {
        initItemLibraryPage();
    }
})();
