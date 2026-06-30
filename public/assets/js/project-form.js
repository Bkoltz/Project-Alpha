(function () {
    'use strict';

    function initOrgSearch() {
        var input = document.getElementById('orgInputProject');
        var hidden = document.getElementById('organization_id_create');
        var suggest = document.getElementById('orgSuggestProject');
        if (!input || !hidden || !suggest || input._orgSearchReady) return;
        input._orgSearchReady = true;

        input.addEventListener('input', function () {
            hidden.value = '';
            loadDepartments('');
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
        var select = document.getElementById('projectDepartmentSelect');
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
                    option.textContent = (dept.name || 'Department') + (dept.folder_name ? ' — ' + dept.folder_name : '');
                    select.appendChild(option);
                });
            })
            .catch(function () { /* leave only the org-level option */ });
    }

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.textContent = text || '';
        return div.innerHTML;
    }

    initOrgSearch();
    document.addEventListener('DOMContentLoaded', initOrgSearch);
    document.addEventListener('pageLoaded', initOrgSearch);
})();
