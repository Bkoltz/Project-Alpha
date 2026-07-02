// CSRF auto-inject for all POST forms
function autoInject() {
    try {
        if (window.__projectAlphaCsrfAutoInjectReady) return;

        var meta = document.querySelector('meta[name="csrf-token"]');
        var token = meta ? meta.getAttribute('content') : '';
        if (!token) return;
        window.__projectAlphaCsrfAutoInjectReady = true;

        document.addEventListener('submit', function (e) {
            var f = e.target;
            if (f && f.tagName === 'FORM' && (f.method || '').toLowerCase() === 'post') {
                if (!f.querySelector('input[name="csrf"]')) {
                    var i = document.createElement('input');
                    i.type = 'hidden';
                    i.name = 'csrf';
                    i.value = token;
                    f.appendChild(i);
                }
            }
        }, true);
    } catch (e) {
        console.warn("Failed to inject CSRF");
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', autoInject);
} else {
    autoInject();
}
