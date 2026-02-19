document.addEventListener('DOMContentLoaded', function () {
    const presetButtons = document.querySelectorAll('.preset-btn');
    const startDateInput = document.querySelector('input[name="start_date"]');
    const endDateInput = document.querySelector('input[name="end_date"]');
    const enableSchedulingCheckbox = document.getElementById('enableScheduling');
    const schedulingOptions = document.getElementById('schedulingOptions');
    const addEmailBtn = document.getElementById('addEmailBtn');
    const emailContainer = document.getElementById('emailContainer');

    // Handle preset date buttons
    presetButtons.forEach(button => {
        button.addEventListener('click', function (e) {
            e.preventDefault();
            const preset = this.dataset.preset;
            const today = new Date();
            let startDate, endDate;

            switch (preset) {
                case 'last-month':
                    startDate = new Date(today.getFullYear(), today.getMonth() - 1, 1);
                    endDate = new Date(today.getFullYear(), today.getMonth(), 0);
                    break;
                case 'last-quarter':
                    const quarter = Math.floor(today.getMonth() / 3);
                    startDate = new Date(today.getFullYear(), (quarter - 1) * 3, 1);
                    endDate = new Date(today.getFullYear(), quarter * 3, 0);
                    break;
                case 'all-time':
                    startDate = new Date(2020, 0, 1);
                    endDate = today;
                    break;
                case 'this-year':
                    startDate = new Date(today.getFullYear(), 0, 1);
                    endDate = today;
                    break;
            }

            if (startDate && endDate) {
                startDateInput.value = startDate.toISOString().split('T')[0];
                endDateInput.value = endDate.toISOString().split('T')[0];
            }
        });
    });

    // Handle email scheduling toggle
    const form = document.getElementById('auditForm');
    enableSchedulingCheckbox.addEventListener('change', function () {
        schedulingOptions.style.display = this.checked ? 'block' : 'none';

        // Change form action and button text based on scheduling
        if (this.checked) {
            form.action = '/?page=financial/audit-schedule-handler';
            document.getElementById('formAction').value = 'create';
            document.querySelector('button[type="submit"]').textContent = 'Save Schedule';
        } else {
            form.action = '/?page=financial/audit-export';
            document.getElementById('formAction').value = 'generate';
            document.querySelector('button[type="submit"]').textContent = 'Generate Audit Report';
        }
    });

    // Handle add email button
    addEmailBtn.addEventListener('click', function (e) {
        e.preventDefault();
        const emailInputs = emailContainer.querySelectorAll('input[type="email"]');
        if (emailInputs.length < 5) {
            const newInput = document.createElement('input');
            newInput.type = 'email';
            newInput.name = 'schedule_email[]';
            newInput.placeholder = 'email@example.com';
            newInput.style.cssText = 'padding: 10px; border: 1px solid #ddd; border-radius: 8px;';
            emailContainer.appendChild(newInput);

            // Update button visibility
            updateAddEmailButton();
        }
    });

    function updateAddEmailButton() {
        const emailInputs = emailContainer.querySelectorAll('input[type="email"]');
        addEmailBtn.style.display = emailInputs.length >= 5 ? 'none' : 'block';
    }

    // Allow removing empty email fields by clicking backspace on empty input
    emailContainer.addEventListener('keydown', function (e) {
        if (e.key === 'Backspace' && e.target.value === '' && e.target.tagName === 'INPUT') {
            const emailInputs = emailContainer.querySelectorAll('input[type="email"]');
            if (emailInputs.length > 1) {
                e.target.remove();
                updateAddEmailButton();
            }
        }
    });

    updateAddEmailButton();
});