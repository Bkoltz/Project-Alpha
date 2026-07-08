// Upload Modal
function showUploadModal() {
    document.getElementById('uploadModal').style.display = 'flex';
}

function closeUploadModal() {
    document.getElementById('uploadModal').style.display = 'none';
    document.getElementById('uploadForm').reset();
}

document.getElementById('uploadForm').addEventListener('submit', async function (e) {
    e.preventDefault();

    const btn = document.getElementById('uploadBtn');
    const msg = document.getElementById('uploadMessage');
    const formData = new FormData(this);

    btn.disabled = true;
    btn.textContent = 'Uploading...';
    msg.style.display = 'none';

    try {
        const response = await fetch('/?page=forms-handler', {
            method: 'POST',
            body: formData
        });

        const result = await response.json();

        if (result.success) {
            window.location.reload();
        } else {
            throw new Error(result.message);
        }
    } catch (error) {
        msg.style.display = 'block';
        msg.style.background = '#fee2e2';
        msg.style.border = '1px solid #fca5a5';
        msg.style.color = '#991b1b';
        msg.textContent = error.message || 'Failed to upload document';

        btn.disabled = false;
        btn.textContent = 'Upload';
    }
});

// Edit Folder Modal
function editFolder(id, title) {
    document.getElementById('editFolderId').value = id;
    document.getElementById('editTitle').value = title;
    document.getElementById('editModal').style.display = 'flex';
}

function closeEditModal() {
    document.getElementById('editModal').style.display = 'none';
}

document.getElementById('editForm').addEventListener('submit', async function (e) {
    e.preventDefault();

    const btn = document.getElementById('updateBtn');
    const msg = document.getElementById('editMessage');
    const formData = new FormData(this);

    btn.disabled = true;
    btn.textContent = 'Updating...';
    msg.style.display = 'none';

    try {
        const response = await fetch('/?page=forms-handler', {
            method: 'POST',
            body: formData
        });

        const result = await response.json();

        if (result.success) {
            window.location.reload();
        } else {
            throw new Error(result.message);
        }
    } catch (error) {
        msg.style.display = 'block';
        msg.style.background = '#fee2e2';
        msg.style.border = '1px solid #fca5a5';
        msg.style.color = '#991b1b';
        msg.textContent = error.message || 'Failed to update folder';

        btn.disabled = false;
        btn.textContent = 'Update';
    }
});

// Email Modal
let formsEmailPicker = null;

function getFormsEmailPicker() {
    if (!formsEmailPicker && window.FormsEmailRecipientPicker) {
        formsEmailPicker = window.FormsEmailRecipientPicker.init(document.getElementById('emailForm'));
    }
    return formsEmailPicker;
}

function updateSelectedCount() {
    const checkboxes = document.querySelectorAll('.doc-checkbox:checked');
    const count = checkboxes.length;
    document.getElementById('selectedCount').textContent = count;
}

function showEmailModal() {
    const checkboxes = document.querySelectorAll('.doc-checkbox:checked');
    if (checkboxes.length === 0) {
        alert('Please select at least one document to email');
        return;
    }

    const docIds = Array.from(checkboxes).map(cb => cb.dataset.docId);
    document.getElementById('documentIds').value = JSON.stringify(docIds);
    document.getElementById('emailModal').style.display = 'flex';
    getFormsEmailPicker();
}

function closeEmailModal() {
    document.getElementById('emailModal').style.display = 'none';
    document.getElementById('emailForm').reset();
    getFormsEmailPicker()?.reset();
}

document.getElementById('emailForm').addEventListener('submit', async function (e) {
    e.preventDefault();

    const btn = document.getElementById('emailBtn');
    const msg = document.getElementById('emailMessage');
    const formData = new FormData(this);

    if (!getFormsEmailPicker()?.validate(msg)) {
        return;
    }

    btn.disabled = true;
    btn.textContent = 'Sending...';
    msg.style.display = 'none';

    try {
        const response = await fetch('/?page=forms-handler', {
            method: 'POST',
            body: formData
        });

        const result = await response.json();

        if (result.success) {
            msg.style.display = 'block';
            msg.style.background = '#ecfdf5';
            msg.style.border = '1px solid #a7f3d0';
            msg.style.color = '#065f46';
            msg.textContent = result.message;

            setTimeout(() => {
                closeEmailModal();
                // Uncheck all checkboxes
                document.querySelectorAll('.doc-checkbox:checked').forEach(cb => cb.checked = false);
                updateSelectedCount();
            }, 2000);
        } else {
            throw new Error(result.message);
        }
    } catch (error) {
        msg.style.display = 'block';
        msg.style.background = '#fee2e2';
        msg.style.border = '1px solid #fca5a5';
        msg.style.color = '#991b1b';
        msg.textContent = error.message || 'Failed to send email';

        btn.disabled = false;
        btn.textContent = 'Send Email';
    }
});

// Delete Functions
async function deleteDocument(docId) {
    if (!confirm('Are you sure you want to delete this document? This action cannot be undone.')) {
        return;
    }

    const formData = new FormData();
    formData.append('csrf', window.formCsrfToken || '');
    formData.append('action', 'delete_document');
    formData.append('document_id', docId);

    try {
        const response = await fetch('/?page=forms-handler', {
            method: 'POST',
            body: formData
        });

        const result = await response.json();

        if (result.success) {
            window.location.reload();
        } else {
            alert(result.message || 'Failed to delete document');
        }
    } catch (error) {
        alert('Failed to delete document');
    }
}

async function deleteFolder() {
    if (!confirm('Are you sure you want to delete this folder and ALL its documents? This action cannot be undone.')) {
        return;
    }

    const formData = new FormData();
    formData.append('csrf', window.formCsrfToken || '');
    formData.append('action', 'delete_category');
    formData.append('category_id', String(window.formFolderId || ''));

    try {
        const response = await fetch('/?page=forms-handler', {
            method: 'POST',
            body: formData
        });

        const result = await response.json();

        if (result.success) {
            window.location.href = '/?page=financial/forms-list';
        } else {
            alert(result.message || 'Failed to delete folder');
        }
    } catch (error) {
        alert('Failed to delete folder');
    }
}
