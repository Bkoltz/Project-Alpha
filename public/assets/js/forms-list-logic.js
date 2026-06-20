// Upload File Modal
function showUploadFileModal() {
    document.getElementById('uploadFileModal').style.display = 'flex';
}

function closeUploadFileModal() {
    document.getElementById('uploadFileModal').style.display = 'none';
    document.getElementById('uploadFileForm').reset();
}

document.getElementById('uploadFileForm').addEventListener('submit', async function (e) {
    e.preventDefault();

    const btn = document.getElementById('uploadFileBtn');
    const msg = document.getElementById('uploadFileMessage');
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
            window.location.href = result.redirect;
        } else {
            throw new Error(result.message);
        }
    } catch (error) {
        msg.style.display = 'block';
        msg.style.background = '#fee2e2';
        msg.style.border = '1px solid #fca5a5';
        msg.style.color = '#991b1b';
        msg.textContent = error.message || 'Failed to upload file';

        btn.disabled = false;
        btn.textContent = 'Upload';
    }
});

// Create Folder Modal
function showCreateCategoryModal() {
    document.getElementById('createModal').style.display = 'flex';
}

function closeCreateModal() {
    document.getElementById('createModal').style.display = 'none';
    document.getElementById('createForm').reset();
}

document.getElementById('createForm').addEventListener('submit', async function (e) {
    e.preventDefault();

    const btn = document.getElementById('createBtn');
    const msg = document.getElementById('createMessage');
    const formData = new FormData(this);

    btn.disabled = true;
    btn.textContent = 'Creating...';
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
        msg.textContent = error.message || 'Failed to create category';

        btn.disabled = false;
        btn.textContent = 'Create';
    }
});

// Edit Category Modal
function editCategory(id, title) {
    document.getElementById('editCategoryId').value = id;
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
        msg.textContent = error.message || 'Failed to update category';

        btn.disabled = false;
        btn.textContent = 'Update';
    }
});

// Upload Document Modal
function uploadDocument(categoryId) {
    document.getElementById('uploadCategoryId').value = categoryId;
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
            window.location.href = result.redirect;
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

// Delete Category
async function deleteCategory(id) {
    if (!confirm('Are you sure you want to delete this category and all its documents? This action cannot be undone.')) {
        return;
    }

    const formData = new FormData();
    formData.append('csrf', window.formCsrfToken || '');
    formData.append('action', 'delete_category');
    formData.append('category_id', id);

    try {
        const response = await fetch('/?page=forms-handler', {
            method: 'POST',
            body: formData
        });

        const result = await response.json();

        if (result.success) {
            window.location.reload();
        } else {
            alert(result.message || 'Failed to delete category');
        }
    } catch (error) {
        alert('Failed to delete category');
    }
}
