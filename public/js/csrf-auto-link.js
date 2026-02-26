// CSRF auto-inject for all POST forms
function autoInject() {
    try {
        var meta = document.querySelector('meta[name="csrf-token"]');
        var token = meta ? meta.getAttribute('content') : '';
        if (!token) return;
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
