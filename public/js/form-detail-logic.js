// Upload Modal
function showUploadModal() {
    document.getElementById('uploadModal').style.display = 'flex';
}

function closeUploadModal() {
    document.getElementById('uploadModal').style.display = 'none';
    document.getElementById('uploadForm').reset();
}

document.getElementById('uploadForm').addEventListener('submit', async function(e) {
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

// Email Modal
function showEmailModal() {
    document.getElementById('emailModal').style.display = 'flex';
}

function closeEmailModal() {
    document.getElementById('emailModal').style.display = 'none';
    document.getElementById('emailForm').reset();
    document.getElementById('clientSelector').style.display = 'none';
    document.getElementById('clientsSelector').style.display = 'none';
    document.getElementById('orgSelector').style.display = 'none';
    document.getElementById('clientTypeBtn').style.background = '#fff';
    document.getElementById('clientsTypeBtn').style.background = '#fff';
    document.getElementById('orgTypeBtn').style.background = '#fff';
}

function selectRecipientType(type) {
    if (type === 'organization') {
        // Show confirmation for organization email
        if (!confirm('This will email the document to ALL clients in the selected organization. Do you want to continue?')) {
            return;
        }
    }
    
    document.getElementById('recipientType').value = type;
    
    // Reset all buttons
    document.getElementById('clientTypeBtn').style.background = '#fff';
    document.getElementById('clientTypeBtn').style.color = 'inherit';
    document.getElementById('clientsTypeBtn').style.background = '#fff';
    document.getElementById('clientsTypeBtn').style.color = 'inherit';
    document.getElementById('orgTypeBtn').style.background = '#fff';
    document.getElementById('orgTypeBtn').style.color = 'inherit';
    
    // Hide all selectors
    document.getElementById('clientSelector').style.display = 'none';
    document.getElementById('clientsSelector').style.display = 'none';
    document.getElementById('orgSelector').style.display = 'none';
    
    // Reset required attributes
    document.getElementById('clientSelect').required = false;
    document.getElementById('orgSelect').required = false;
    
    if (type === 'client') {
        document.getElementById('clientSelector').style.display = 'block';
        document.getElementById('clientSelect').required = true;
        document.getElementById('clientTypeBtn').style.background = 'var(--nav-accent)';
        document.getElementById('clientTypeBtn').style.color = '#fff';
    } else if (type === 'clients') {
        document.getElementById('clientsSelector').style.display = 'block';
        document.getElementById('clientsTypeBtn').style.background = 'var(--nav-accent)';
        document.getElementById('clientsTypeBtn').style.color = '#fff';
    } else if (type === 'organization') {
        document.getElementById('orgSelector').style.display = 'block';
        document.getElementById('orgSelect').required = true;
        document.getElementById('orgTypeBtn').style.background = 'var(--nav-accent)';
        document.getElementById('orgTypeBtn').style.color = '#fff';
    }
}

document.getElementById('emailForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const btn = document.getElementById('emailBtn');
    const msg = document.getElementById('emailMessage');
    const formData = new FormData(this);
    
    const recipientType = document.getElementById('recipientType').value;
    if (!recipientType) {
        msg.style.display = 'block';
        msg.style.background = '#fee2e2';
        msg.style.border = '1px solid #fca5a5';
        msg.style.color = '#991b1b';
        msg.textContent = 'Please select a recipient type';
        return;
    }
    
    // Validate multiple clients selection
    if (recipientType === 'clients') {
        const checkedClients = document.querySelectorAll('input[name="client_ids[]"]:checked');
        if (checkedClients.length === 0) {
            msg.style.display = 'block';
            msg.style.background = '#fee2e2';
            msg.style.border = '1px solid #fca5a5';
            msg.style.color = '#991b1b';
            msg.textContent = 'Please select at least one client';
            return;
        }
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

// Delete Document
async function confirmDelete() {
    if (!confirm('Are you sure you want to delete this document? This action cannot be undone.')) {
        return;
    }
    
    const formData = new FormData();
    formData.append('csrf', '<?php echo csrf_token(); ?>');
    formData.append('action', 'delete_document');
    formData.append('document_id', '<?php echo $category["doc_id"]; ?>');
    
    try {
        const response = await fetch('/?page=forms-handler', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            window.location.href = result.redirect;
        } else {
            alert(result.message || 'Failed to delete document');
        }
    } catch (error) {
        alert('Failed to delete document');
    }
}