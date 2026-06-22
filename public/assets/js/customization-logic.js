// Show add field modal
function showAddFieldModal() {
    document.getElementById('modalTitle').textContent = 'Add Custom Field';
    document.getElementById('fieldAction').value = 'create';
    document.getElementById('fieldId').value = '';
    document.getElementById('fieldLabel').value = '';
    document.getElementById('fieldDataType').value = 'text';
    document.getElementById('fieldRequired').checked = false;
    document.getElementById('fieldModal').style.display = 'flex';
}

// Edit field
function editField(fieldId) {
    // Fetch field data and populate modal
    fetch('/?page=settings/custom-fields-handler&action=get&id=' + fieldId)
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                document.getElementById('modalTitle').textContent = 'Edit Field';
                document.getElementById('fieldAction').value = 'update';
                document.getElementById('fieldId').value = fieldId;
                document.getElementById('fieldLabel').value = data.field.field_label;
                document.getElementById('fieldDataType').value = data.field.field_data_type;
                document.getElementById('fieldRequired').checked = data.field.is_required == 1;
                document.getElementById('fieldModal').style.display = 'flex';
            } else {
                alert('Error loading field data');
            }
        })
        .catch(() => alert('Error loading field data'));
}

// Delete field
function deleteField(fieldId) {
    if (!confirm('Are you sure you want to delete this custom field? This cannot be undone.')) {
        return;
    }

    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '/?page=settings/custom-fields-handler';
    form.innerHTML = `
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="field_id" value="${fieldId}">
    `;
    document.body.appendChild(form);
    form.submit();
}

// Close modal
function closeFieldModal() {
    document.getElementById('fieldModal').style.display = 'none';
}

// Close modal on outside click
document.getElementById('fieldModal')?.addEventListener('click', function (e) {
    if (e.target === this) {
        closeFieldModal();
    }
});

// Drag and drop reordering
let draggedElement = null;

document.querySelectorAll('.field-item').forEach(item => {
    item.draggable = true;

    item.addEventListener('dragstart', function (e) {
        draggedElement = this;
        this.style.opacity = '0.5';
    });

    item.addEventListener('dragend', function (e) {
        this.style.opacity = '1';
        saveNewOrder();
    });

    item.addEventListener('dragover', function (e) {
        e.preventDefault();
        const afterElement = getDragAfterElement(document.getElementById('fieldsList'), e.clientY);
        const container = document.getElementById('fieldsList');
        if (afterElement == null) {
            container.appendChild(draggedElement);
        } else {
            container.insertBefore(draggedElement, afterElement);
        }
    });
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

function saveNewOrder() {
    const items = document.querySelectorAll('.field-item');
    const order = [];
    items.forEach((item, index) => {
        order.push({
            id: item.dataset.fieldId,
            order: index + 1
        });
    });

    // Send to server
    fetch('/?page=settings/custom-fields-handler', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'reorder',
            order: order,
            csrf: '<?php echo htmlspecialchars(csrf_token()); ?>'
        })
    });
}