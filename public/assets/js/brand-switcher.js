window.BrandSwitcher = (function () {
    'use strict';

    function getSelectedBrandOrg(page) {
        var sel = document.querySelector('.brand-switcher[data-page="' + page + '"]');
        if (!sel) return '';
        var val = sel.value;
        return val === '' ? '' : parseInt(val, 10);
    }

    function getCurrentBrandOrg(page) {
        return getSelectedBrandOrg(page);
    }

    function filterClientsByBrand(list, orgId) {
        if (orgId === '' || orgId === null || orgId === undefined) return list;
        var oid = parseInt(orgId, 10);
        if (isNaN(oid)) return list;
        return (list || []).filter(function (x) {
            return parseInt(x.organization_id, 10) === oid;
        });
    }

    function clearClientSelection(inputId, hiddenId, suggestId, bannerId, page) {
        var input = document.getElementById(inputId);
        var hidden = document.getElementById(hiddenId);
        var suggest = document.getElementById(suggestId);
        var banner = document.getElementById(bannerId);
        if (input) input.value = '';
        if (hidden) hidden.value = '';
        if (suggest) { suggest.innerHTML = ''; suggest.style.display = 'none'; }
        if (banner) banner.style.display = 'none';
    }

    function listen(page, inputId, hiddenId, suggestId, bannerId) {
        var sel = document.querySelector('.brand-switcher[data-page="' + page + '"]');
        if (!sel) return;
        sel.addEventListener('change', function () {
            clearClientSelection(inputId, hiddenId, suggestId, bannerId, page);
        });
    }

    return {
        getSelectedBrandOrg: getSelectedBrandOrg,
        getCurrentBrandOrg: getCurrentBrandOrg,
        filterClientsByBrand: filterClientsByBrand,
        clearClientSelection: clearClientSelection,
        listen: listen
    };
})();
