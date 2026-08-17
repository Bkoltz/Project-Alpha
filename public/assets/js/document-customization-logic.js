function documentCustomizationCsrfToken() {
    const input = document.querySelector('#fieldForm input[name="csrf"]');
    return input ? input.value : '';
}

function documentCustomizationActiveTab() {
    const root = document.querySelector('[data-document-customization]');
    return root ? (root.getAttribute('data-active-field-tab') || 'regular') : 'regular';
}

function toggleOptionsField() {
    const fieldType = document.getElementById('fieldType').value;
    const optionsField = document.getElementById('optionsField');
    optionsField.style.display = fieldType === 'select' ? 'block' : 'none';
}

function showAddFieldModal() {
    document.getElementById('modalTitle').textContent = 'Add Custom Field';
    document.getElementById('fieldAction').value = 'create';
    document.getElementById('fieldId').value = '';
    document.getElementById('fieldLabel').value = '';
    document.getElementById('fieldType').value = 'text';
    document.getElementById('fieldOptions').value = '';
    document.getElementById('fieldRequired').checked = false;
    
    // Show document type checkboxes for create, check current tab
    document.getElementById('docTypesSection').style.display = 'block';
    document.querySelectorAll('[name="document_types[]"]').forEach(cb => {
        cb.checked = cb.value === documentCustomizationActiveTab();
    });
    
    toggleOptionsField();
    document.getElementById('fieldModal').style.display = 'flex';
}

function editField(fieldId) {
    fetch('/?page=settings/document-custom-fields-handler&action=get&id=' + fieldId)
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                document.getElementById('modalTitle').textContent = 'Edit Field';
                document.getElementById('fieldAction').value = 'update';
                document.getElementById('fieldId').value = fieldId;
                document.getElementById('fieldLabel').value = data.field.field_label;
                document.getElementById('fieldType').value = data.field.field_type;
                document.getElementById('fieldRequired').checked = data.field.is_required == 1;
                
                // Populate options for select fields
                if (data.field.field_type === 'select' && data.field.field_options) {
                    document.getElementById('fieldOptions').value = data.field.field_options.join('\n');
                } else {
                    document.getElementById('fieldOptions').value = '';
                }
                
                // Hide document type checkboxes when editing (can't change)
                document.getElementById('docTypesSection').style.display = 'none';
                
                toggleOptionsField();
                document.getElementById('fieldModal').style.display = 'flex';
            } else {
                alert('Error loading field data');
            }
        })
        .catch(err => alert('Error: ' + err.message));
}

function deleteField(fieldId) {
    if (!confirm('Are you sure you want to delete this field? This cannot be undone.')) return;
    
    const formData = new FormData();
    formData.append('csrf', documentCustomizationCsrfToken());
    formData.append('action', 'delete');
    formData.append('field_id', fieldId);
    
    fetch('/?page=settings/document-custom-fields-handler', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert('Error deleting field: ' + (data.message || 'Unknown error'));
        }
    })
    .catch(err => alert('Error: ' + err.message));
}

function closeFieldModal() {
    document.getElementById('fieldModal').style.display = 'none';
}

// Handle form submission
document.getElementById('fieldForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    
    fetch('/?page=settings/document-custom-fields-handler', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert('Error: ' + (data.message || 'Unknown error'));
        }
    })
    .catch(err => alert('Error: ' + err.message));
});

// Handle toggle switches
document.querySelectorAll('.field-toggle').forEach(toggle => {
    toggle.addEventListener('change', function() {
        const fieldId = this.dataset.fieldId;
        const isEnabled = this.checked ? 1 : 0;
        
        const formData = new FormData();
        formData.append('csrf', documentCustomizationCsrfToken());
        formData.append('action', 'toggle');
        formData.append('field_id', fieldId);
        formData.append('is_enabled', isEnabled);
        
        fetch('/?page=settings/document-custom-fields-handler', {
            method: 'POST',
            body: formData
        })
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                alert('Error toggling field');
                this.checked = !this.checked; // Revert
            }
        })
        .catch(err => {
            alert('Error: ' + err.message);
            this.checked = !this.checked; // Revert
        });
    });
});

// Drag and drop reordering
// This classic script can be loaded again after a settings soft navigation.
// Top-level lexical bindings would throw on the second visit.
var fieldsList = document.getElementById('fieldsList');
var draggedElement = null;

fieldsList.addEventListener('dragstart', function(e) {
    if (e.target.classList.contains('field-item')) {
        draggedElement = e.target;
        e.target.style.opacity = '0.5';
    }
});

fieldsList.addEventListener('dragend', function(e) {
    if (e.target.classList.contains('field-item')) {
        e.target.style.opacity = '1';
    }
});

fieldsList.addEventListener('dragover', function(e) {
    e.preventDefault();
    if (!draggedElement) return;
    
    const afterElement = getDragAfterElement(fieldsList, e.clientY);
    if (afterElement == null) {
        fieldsList.appendChild(draggedElement);
    } else {
        fieldsList.insertBefore(draggedElement, afterElement);
    }
});

fieldsList.addEventListener('drop', function(e) {
    e.preventDefault();
    if (draggedElement) {
        saveFieldOrder();
    }
});

function getDragAfterElement(container, y) {
    const draggableElements = [...container.querySelectorAll('.field-item:not(.dragging)')];
    
    return draggableElements.reduce((closest, child) => {
        const box = child.getBoundingClientRect();
        const offset = y - box.top - box.height / 2;
        
        if (offset < 0 && offset > closest.offset) {
            return { offset: offset, element: child };
        } else {
            return closest;
        }
    }, { offset: Number.NEGATIVE_INFINITY }).element;
}

function saveFieldOrder() {
    const items = document.querySelectorAll('.field-item');
    const order = Array.from(items).map((item, index) => ({
        id: item.dataset.fieldId,
        order: index + 1
    }));
    
    const formData = new FormData();
    formData.append('csrf', documentCustomizationCsrfToken());
    formData.append('action', 'reorder');
    formData.append('order', JSON.stringify(order));
    
    fetch('/?page=settings/document-custom-fields-handler', {
        method: 'POST',
        body: formData
    });
}
