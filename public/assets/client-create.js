// Client create page - organization typeahead & creation
// Includes AJAX search for organizations and inline creation

function initClientCreateOrg() {
  var orgInput = document.getElementById('orgInput');
  if (!orgInput) return; // Not on client create page

  var orgSuggest = document.getElementById('orgSuggest');
  var orgIdHidden = document.getElementById('orgId');
  var createOrgBtn = document.getElementById('createOrgBtn');
  var createOrgModal = document.getElementById('createOrgModal');
  var createOrgForm = document.getElementById('createOrgForm');
  var createOrgNameInput = document.getElementById('createOrgNameInput');
  var closeCreateOrgModal = document.getElementById('closeCreateOrgModal');

  // Typeahead search
  orgInput.addEventListener('input', function() {
    var term = this.value.trim();
    if (term === '') {
      orgSuggest.style.display = 'none';
      orgSuggest.innerHTML = '';
      orgIdHidden.value = '';
      return;
    }

    fetch('/?page=org-search&term=' + encodeURIComponent(term))
      .then(r => r.json())
      .then(list => {
        if (!Array.isArray(list) || list.length === 0) {
          orgSuggest.style.display = 'none';
          orgSuggest.innerHTML = '';
          return;
        }
        orgSuggest.innerHTML = list.map(x => `
          <div data-id="${x.id}" data-name="${x.name}" style="padding:8px 10px;cursor:pointer;border-bottom:1px solid #eee">
            ${x.name}
          </div>
        `).join('');
        Array.from(orgSuggest.children).forEach(el => {
          el.addEventListener('click', function() {
            orgInput.value = this.dataset.name;
            orgIdHidden.value = this.dataset.id;
            orgSuggest.style.display = 'none';
          });
          el.addEventListener('mouseenter', function() {
            this.style.backgroundColor = '#f0f0f0';
          });
          el.addEventListener('mouseleave', function() {
            this.style.backgroundColor = 'transparent';
          });
        });
        orgSuggest.style.display = 'block';
      })
      .catch(err => {
        console.error('Fetch error for org-search:', err);
        orgSuggest.style.display = 'none';
      });
  });

  // Close suggestions on blur
  document.addEventListener('click', function(e) {
    if (!orgSuggest.contains(e.target) && e.target !== orgInput) {
      orgSuggest.style.display = 'none';
    }
  });

  // Open create org modal
  if (createOrgBtn) {
    createOrgBtn.addEventListener('click', function() {
      createOrgNameInput.value = orgInput.value;
      createOrgModal.style.display = 'flex';
      createOrgNameInput.focus();
    });
  }

  // Close modal
  if (closeCreateOrgModal) {
    closeCreateOrgModal.addEventListener('click', function() {
      createOrgModal.style.display = 'none';
    });
  }
  if (createOrgModal) {
    createOrgModal.addEventListener('click', function(e) {
      if (e.target === this) {
        this.style.display = 'none';
      }
    });
  }
  if (document.getElementById('cancelCreateOrgModal')) {
    document.getElementById('cancelCreateOrgModal').addEventListener('click', function() {
      createOrgModal.style.display = 'none';
    });
  }

  // Handle create org form submission
  if (createOrgForm) {
    createOrgForm.addEventListener('submit', function(e) {
      e.preventDefault();
      var orgName = createOrgNameInput.value.trim();
      if (!orgName) {
        alert('Organization name required');
        return;
      }

      var fd = new FormData();
      fd.append('name', orgName);
      fd.append('csrf', document.querySelector('input[name="csrf"]').value);

      fetch('/?page=organization/org-create', {
        method: 'POST',
        body: fd
      })
      .then(r => r.json())
      .then(result => {
        if (result.success) {
          orgInput.value = result.name;
          orgIdHidden.value = result.id;
          createOrgModal.style.display = 'none';
          orgSuggest.style.display = 'none';
        } else {
          alert('Error: ' + (result.error || 'Failed to create organization'));
        }
      })
      .catch(err => {
        console.error('Error:', err);
        alert('Failed to create organization');
      });
    });
  }
}

// Initialize on page load
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initClientCreateOrg);
} else {
  initClientCreateOrg();
}

// Re-initialize after AJAX page load
document.addEventListener('pageLoaded', function() {
  initClientCreateOrg();
});
