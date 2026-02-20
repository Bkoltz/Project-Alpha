function initDeleteButton() {
    const btn = document.getElementById('deleteOrgBtn');
    const form = document.getElementById('deleteOrgForm');

    if (!btn || !form) {
        console.warn('Delete button or form not found, retrying...');
        setTimeout(initDeleteButton, 50);
        return;
    }

    // Check if already initialized
    if (btn.dataset.deleteInitialized === 'true') {
        console.log('✓ Delete button already initialized');
        return;
    }

    // Mark as initialized FIRST before attaching listener
    btn.dataset.deleteInitialized = 'true';

    // Attach listener without cloning
    btn.addEventListener('click', function (e) {
        e.preventDefault();
        if (confirm('Delete this organization? Clients will not be deleted, but will no longer be associated with this organization.')) {
            console.log('Submitting delete form');
            form.submit();
        }
    });

    console.log('✓ Delete button initialized');
}

// Initialize when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initDeleteButton);
} else {
    initDeleteButton();
}

// Re-initialize on AJAX navigation - just call initDeleteButton without resetting flag
// The guard check in initDeleteButton will handle whether we need to re-initialize
document.addEventListener('pageLoaded', function () {
    console.log('pageLoaded: checking delete button');
    setTimeout(initDeleteButton, 50);
});