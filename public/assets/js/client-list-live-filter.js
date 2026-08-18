(function () {
    'use strict';

    var projectAlpha = window.ProjectAlpha = window.ProjectAlpha || {};

    function createClientListLiveFilter() {
        var timer = null;
        var navigationActive = false;
        var pendingState = null;
        var queuedState = null;

        function fieldNames(form) {
            return (form.getAttribute('data-live-filter-fields') || '')
                .split(',')
                .map(function (name) { return name.trim(); })
                .filter(Boolean);
        }

        function buildPageString(form) {
            var data = new FormData(form);
            var page = String(data.get('page') || 'home');
            data.delete('page');
            var params = new URLSearchParams();
            data.forEach(function (value, name) {
                if (typeof value === 'string' && value !== '') params.append(name, value);
            });
            var query = params.toString();
            return query ? page + '&' + query : page;
        }

        function setStatus(form, message) {
            var status = form && form.querySelector('[data-live-filter-status]');
            if (status) status.textContent = message;
        }

        function capture(form, input) {
            var values = {};
            fieldNames(form).forEach(function (name) {
                var field = form.elements.namedItem(name);
                if (field && typeof field.value === 'string') values[name] = field.value;
            });
            return {
                filterId: form.getAttribute('data-live-filter-id') || '',
                page: buildPageString(form),
                fieldName: input.name,
                selectionStart: typeof input.selectionStart === 'number' ? input.selectionStart : null,
                values: values
            };
        }

        function findForm(state) {
            return Array.prototype.find.call(
                document.querySelectorAll('form[data-live-filter-fields]'),
                function (form) {
                    return (form.getAttribute('data-live-filter-id') || '') === state.filterId;
                }
            ) || null;
        }

        function restore(state, message) {
            var form = findForm(state);
            if (!form) return;
            Object.keys(state.values).forEach(function (name) {
                var field = form.elements.namedItem(name);
                if (field && typeof field.value === 'string') field.value = state.values[name];
            });
            var activeField = form.elements.namedItem(state.fieldName);
            if (activeField && typeof activeField.focus === 'function') {
                activeField.focus({ preventScroll: true });
                if (state.selectionStart !== null && typeof activeField.setSelectionRange === 'function') {
                    activeField.setSelectionRange(state.selectionStart, state.selectionStart);
                }
            }
            if (message) setStatus(form, message);
        }

        async function navigate(state) {
            if (navigationActive) {
                queuedState = state;
                return;
            }
            navigationActive = true;

            if (typeof window.navigateToPage !== 'function') {
                var fallbackForm = findForm(state);
                if (fallbackForm) fallbackForm.requestSubmit();
                navigationActive = false;
                return;
            }

            var url = new URL(window.location.origin + '/');
            var separator = state.page.indexOf('&');
            url.searchParams.set('page', separator >= 0 ? state.page.slice(0, separator) : state.page);
            if (separator >= 0) {
                new URLSearchParams(state.page.slice(separator + 1)).forEach(function (value, name) {
                    url.searchParams.append(name, value);
                });
            }
            history.replaceState({ page: state.page }, '', url.pathname + url.search);

            try {
                await window.navigateToPage(state.page, false);
            } finally {
                navigationActive = false;
                if (queuedState) {
                    var next = queuedState;
                    queuedState = null;
                    restore(next, 'Updating client list…');
                    await navigate(next);
                } else if (pendingState) {
                    restore(pendingState, 'Waiting for typing to pause…');
                } else {
                    restore(state, 'Client list updated.');
                }
            }
        }

        function bind(form) {
            if (!form || form.dataset.liveFilterBound === 'true') return;
            form.dataset.liveFilterBound = 'true';

            form.addEventListener('input', function (event) {
                var input = event.target;
                if (event.isComposing || !input || !fieldNames(form).includes(input.name)) return;
                pendingState = capture(form, input);
                setStatus(form, 'Waiting for typing to pause…');
                window.clearTimeout(timer);
                var configuredDelay = Number.parseInt(form.getAttribute('data-live-filter-debounce') || '300', 10);
                var delay = Math.max(150, configuredDelay || 300);
                timer = window.setTimeout(function () {
                    var state = pendingState;
                    pendingState = null;
                    if (state) void navigate(state);
                }, delay);
            });

            form.addEventListener('submit', function () {
                window.clearTimeout(timer);
                pendingState = null;
                queuedState = null;
            });
        }

        function initialize(context) {
            var root = context && context.root ? context.root : document;
            var form = root.querySelector('form[data-live-filter-id="client-list"]');
            bind(form);
        }
        initialize.pageInitializerId = 'client-list-live-filter';

        return { initialize: initialize, buildPageString: buildPageString };
    }

    var liveFilter = projectAlpha.clientListLiveFilter || createClientListLiveFilter();
    projectAlpha.clientListLiveFilter = liveFilter;
    if (typeof projectAlpha.registerPage === 'function') {
        projectAlpha.registerPage('client/clients-list', liveFilter.initialize);
    } else {
        liveFilter.initialize({ root: document });
    }
})();
