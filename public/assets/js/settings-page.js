(function () {
  'use strict';

  function fieldValue(field) {
    if (!field.name || field.disabled) return null;
    if ((field.type === 'checkbox' || field.type === 'radio') && !field.checked) return null;
    if (field.type === 'file') {
      return Array.prototype.map.call(field.files || [], function (file) {
        return [file.name, file.size, file.lastModified].join(':');
      }).join('|');
    }
    if (field.tagName === 'SELECT' && field.multiple) {
      return Array.prototype.filter.call(field.options, function (option) { return option.selected; })
        .map(function (option) { return option.value; });
    }
    return field.value;
  }

  function formSnapshot(form) {
    return Array.prototype.map.call(form.elements || [], function (field, index) {
      return [index, field.name || '', field.type || field.tagName, fieldValue(field)];
    }).filter(function (entry) {
      return entry[3] !== null;
    }).map(function (entry) {
      return JSON.stringify(entry);
    }).join('\n');
  }

  function isTrackableForm(form) {
    if (form.hasAttribute('data-settings-track-dirty')) return true;
    if ((form.method || 'get').toLowerCase() === 'get') return false;
    if (form.getAttribute('onsubmit') && /delete|purge|disconnect|revoke/i.test(form.getAttribute('onsubmit'))) return false;

    var action = form.getAttribute('action') || '';
    var isSettingsSave = action.indexOf('page=settings&') !== -1 && !!form.querySelector('input[name="tab"]');
    return isSettingsSave;
  }

  function initializeSettingsPage(context) {
    var root = context && context.root ? context.root : document;
    var shell = root.querySelector('[data-settings-page]');
    if (!shell || shell.getAttribute('data-settings-initialized') === '1') return;
    shell.setAttribute('data-settings-initialized', '1');

    var cleanupCallbacks = [];
    var search = shell.querySelector('[data-settings-search]');
    var cards = Array.prototype.slice.call(shell.querySelectorAll('[data-settings-card]'));
    var emptyState = shell.querySelector('[data-settings-empty]');
    var searchStatus = shell.querySelector('[data-settings-search-status]');
    var sidebar = shell.querySelector('.settings-sidebar');
    var sidebarScrollKey = 'project-alpha:settings-sidebar-scroll';

    function saveSidebarScroll() {
      if (!sidebar) return;
      try {
        window.sessionStorage.setItem(sidebarScrollKey, String(sidebar.scrollTop));
      } catch (error) {
        // Storage may be unavailable in hardened/private browser contexts.
      }
    }

    if (sidebar) {
      try {
        var savedSidebarScroll = parseInt(window.sessionStorage.getItem(sidebarScrollKey) || '0', 10);
        if (savedSidebarScroll > 0) {
          sidebar.scrollTop = savedSidebarScroll;
        }
      } catch (error) {
        // The sidebar still works normally when session storage is unavailable.
      }
      sidebar.addEventListener('scroll', saveSidebarScroll, { passive: true });
      cleanupCallbacks.push(function () { sidebar.removeEventListener('scroll', saveSidebarScroll); });
    }

    // Normalize visual hierarchy without changing any legacy handler or form.
    shell.querySelectorAll('button').forEach(function (button) {
      var form = button.closest('form');
      var destructiveText = [
        button.getAttribute('data-action') || '',
        button.name || '',
        button.value || '',
        button.textContent || '',
        form ? (form.getAttribute('onsubmit') || '') : ''
      ].join(' ');
      var isDestructive = /delete|purge|disconnect|revoke|disable|remove/i.test(destructiveText);
      var isPrimary = button.classList.contains('btn-primary') ||
        button.hasAttribute('data-item-library-create') ||
        (button.type === 'submit' && !isDestructive);

      if (isDestructive) {
        button.classList.add('settings-button-danger');
      } else if (isPrimary) {
        button.classList.add('settings-button-primary');
      } else {
        button.classList.add('settings-button-secondary');
      }
    });

    shell.querySelectorAll('table').forEach(function (table) {
      var headers = Array.prototype.slice.call(table.querySelectorAll('thead th'));
      if (headers.some(function (header) { return /^actions?$/i.test(header.textContent.trim()); })) {
        table.classList.add('settings-action-table');
      }
    });

    function filterCards() {
      if (!search) return;
      var terms = search.value.toLowerCase().trim().split(/\s+/).filter(Boolean);
      var visible = 0;

      cards.forEach(function (card) {
        var haystack = card.getAttribute('data-settings-search-text') || '';
        var matches = terms.length === 0 || terms.every(function (term) { return haystack.indexOf(term) !== -1; });
        card.hidden = !matches;
        if (matches) visible += 1;
      });

      if (emptyState) emptyState.hidden = visible !== 0;
      if (searchStatus) {
        searchStatus.textContent = terms.length === 0 ? '' : visible + (visible === 1 ? ' category found' : ' categories found');
      }
    }

    if (search) {
      search.addEventListener('input', filterCards);
      cleanupCallbacks.push(function () { search.removeEventListener('input', filterCards); });
    }

    var trackedForms = Array.prototype.slice.call(shell.querySelectorAll('form')).filter(isTrackableForm);
    var allowNavigation = false;

    function refreshDirtyState(form) {
      var dirty = formSnapshot(form) !== form.getAttribute('data-settings-initial-snapshot');
      form.classList.toggle('is-dirty', dirty);
      form.setAttribute('data-settings-dirty', dirty ? '1' : '0');

      var status = form.querySelector('[data-settings-save-status]');
      if (status) status.textContent = dirty ? 'Unsaved changes' : 'No unsaved changes';
    }

    function hasUnsavedChanges() {
      return trackedForms.some(function (form) { return form.getAttribute('data-settings-dirty') === '1'; });
    }

    trackedForms.forEach(function (form) {
      form.setAttribute('data-settings-initial-snapshot', formSnapshot(form));
      form.setAttribute('data-settings-dirty', '0');

      var update = function () { refreshDirtyState(form); };
      var updateAfterAction = function () { window.setTimeout(update, 0); };
      var submit = function () { allowNavigation = true; };
      var reset = function () { window.setTimeout(update, 0); };

      form.addEventListener('input', update);
      form.addEventListener('change', update);
      form.addEventListener('click', updateAfterAction);
      form.addEventListener('submit', submit);
      form.addEventListener('reset', reset);

      cleanupCallbacks.push(function () {
        form.removeEventListener('input', update);
        form.removeEventListener('change', update);
        form.removeEventListener('click', updateAfterAction);
        form.removeEventListener('submit', submit);
        form.removeEventListener('reset', reset);
      });
    });

    function beforeUnload(event) {
      if (allowNavigation || !hasUnsavedChanges()) return;
      event.preventDefault();
      event.returnValue = '';
    }

    function guardSettingsNavigation(event) {
      if (allowNavigation || !hasUnsavedChanges()) return;
      var link = event.target.closest('a[href]');
      if (!link || link.hasAttribute('download') || link.target === '_blank') return;
      if (!window.confirm('You have unsaved changes. Leave this settings section and discard them?')) {
        event.preventDefault();
        event.stopPropagation();
        event.stopImmediatePropagation();
        return;
      }
      allowNavigation = true;
    }

    function settingsSearchShortcut(event) {
      if (!search || event.defaultPrevented || event.ctrlKey || event.metaKey || event.altKey) return;
      var active = document.activeElement;
      var isEditing = active && /^(INPUT|TEXTAREA|SELECT)$/.test(active.tagName);
      if (event.key === '/' && !isEditing) {
        event.preventDefault();
        search.focus();
      }
    }

    window.addEventListener('beforeunload', beforeUnload);
    shell.addEventListener('click', guardSettingsNavigation, true);
    document.addEventListener('keydown', settingsSearchShortcut);
    cleanupCallbacks.push(function () { window.removeEventListener('beforeunload', beforeUnload); });
    cleanupCallbacks.push(function () { shell.removeEventListener('click', guardSettingsNavigation, true); });
    cleanupCallbacks.push(function () { document.removeEventListener('keydown', settingsSearchShortcut); });

    return function () {
      cleanupCallbacks.forEach(function (callback) { callback(); });
      shell.removeAttribute('data-settings-initialized');
    };
  }

  initializeSettingsPage.pageInitializerId = 'settings-page';

  if (window.ProjectAlpha && typeof window.ProjectAlpha.registerPage === 'function') {
    window.ProjectAlpha.registerPage('settings', initializeSettingsPage);
  } else if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      initializeSettingsPage({ root: document });
    }, { once: true });
  } else {
    initializeSettingsPage({ root: document });
  }
})();
