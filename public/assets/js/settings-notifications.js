(function () {
    'use strict';

    function initSettingsNotifications(context) {
        var root = context && context.root ? context.root : document;
        var section = root.querySelector('[data-settings-notifications]');
        if (!section || section.getAttribute('data-notifications-ready') === '1') return;
        section.setAttribute('data-notifications-ready', '1');

        var cronEnabled = section.querySelector('input[name="cron_enabled"]');
        var scheduleSection = section.querySelector('#cronScheduleSection');
        var scheduleSelect = section.querySelector('select[name="cron_schedule"]');
        var customSection = section.querySelector('#customCronSection');

        function showSchedule() {
            if (scheduleSection && cronEnabled) {
                scheduleSection.style.display = cronEnabled.checked ? '' : 'none';
            }
        }

        function showCustomSchedule() {
            if (customSection && scheduleSelect) {
                customSection.style.display = scheduleSelect.value === 'custom' ? '' : 'none';
            }
        }

        if (cronEnabled) cronEnabled.addEventListener('change', showSchedule);
        if (scheduleSelect) scheduleSelect.addEventListener('change', showCustomSchedule);
        showSchedule();
        showCustomSchedule();
    }

    initSettingsNotifications.pageInitializerId = 'settings-notifications';
    if (window.ProjectAlpha && typeof window.ProjectAlpha.registerPage === 'function') {
        window.ProjectAlpha.registerPage('settings', initSettingsNotifications);
    } else if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initSettingsNotifications, { once: true });
    } else {
        initSettingsNotifications();
    }
})();
