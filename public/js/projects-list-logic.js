// Client autocomplete


const clientInput = document.getElementById('clientSearchInput');
const clientIdInput = document.getElementById('clientIdInput');
const clientSuggestions = document.getElementById('clientSuggestions');

const orgInput = document.getElementById('orgSearchInput');
const orgIdInput = document.getElementById('orgIdInput');
const orgSuggestions = document.getElementById('orgSuggestions');

// Set initial display values
if (clientIdInput.value) {
    const client = clientData.find(c => c.id == clientIdInput.value);
    if (client) clientInput.value = client.name;
}

if (orgIdInput.value) {
    const org = orgData.find(o => o.id == orgIdInput.value);
    if (org) orgInput.value = org.name;
}

function setupAutocomplete(input, hiddenInput, suggestions, data) {
    input.addEventListener('input', function () {
        const query = this.value.toLowerCase();

        if (query.length === 0) {
            suggestions.style.display = 'none';
            hiddenInput.value = '';
            return;
        }

        const filtered = data.filter(item =>
            item.name.toLowerCase().includes(query)
        ).slice(0, 10);

        if (filtered.length === 0) {
            suggestions.style.display = 'none';
            return;
        }

        suggestions.innerHTML = filtered.map(item =>
            `<div class="suggestion-item" data-id="${item.id}" style="padding:8px 12px;cursor:pointer;border-bottom:1px solid #f3f4f6">${item.name}</div>`
        ).join('');

        suggestions.style.display = 'block';

        // Add click handlers
        suggestions.querySelectorAll('.suggestion-item').forEach(item => {
            item.addEventListener('mouseenter', function () {
                this.style.background = '#f9fafb';
            });
            item.addEventListener('mouseleave', function () {
                this.style.background = '#fff';
            });
            item.addEventListener('click', function () {
                input.value = this.textContent;
                hiddenInput.value = this.dataset.id;
                suggestions.style.display = 'none';
            });
        });
    });

    // Hide suggestions when clicking outside
    document.addEventListener('click', function (e) {
        if (!input.contains(e.target) && !suggestions.contains(e.target)) {
            suggestions.style.display = 'none';
        }
    });

    // Clear hidden value if input is manually cleared
    input.addEventListener('blur', function () {
        if (this.value === '') {
            hiddenInput.value = '';
        }
    });
}

setupAutocomplete(clientInput, clientIdInput, clientSuggestions, clientData);
setupAutocomplete(orgInput, orgIdInput, orgSuggestions, orgData);