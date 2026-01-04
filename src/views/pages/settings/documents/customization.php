<?php
// src/views/pages/settings/documents/customization.php
require_once __DIR__ . '/../../../../config/db.php';

// Fetch existing custom fields
$fields = [];
try {
    $stmt = $pdo->query('SELECT * FROM document_custom_fields ORDER BY display_order, id');
    $fields = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    @error_log('[customization] Error fetching custom fields: ' . $e->getMessage());
}
?>

<div style="max-width:900px">
    <h3 style="margin:0 0 8px 0">Custom Fields</h3>
    <p style="margin:0 0 20px 0;color:var(--muted);font-size:14px">Add custom fields to your documents. Choose which document types should include each field.</p>

    <!-- Info Banner -->
    <div style="margin-bottom:20px;padding:12px 16px;background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;font-size:13px">
        <strong>ℹ️ About Custom Fields:</strong> Built-in fields (like Fulfillment Date) can be renamed but not deleted. 
        Custom fields you create can be reordered, edited, or removed at any time.
    </div>
    <!-- Documents Validity Setting -->
    <fieldset style="border:1px solid #eee;border-radius:8px;padding:12px;margin-bottom:16px">
        <legend style="padding:0 6px;color:var(--muted)">Document Validity</legend>
        <label style="margin-bottom:8px;display:block">
            <div style="margin-bottom:6px">Documents Valid for (days)</div>
            <input type="number" min="0" name="documents_valid_days" value="<?php echo htmlspecialchars((string)($appConfig['documents_valid_days'] ?? 14)); ?>" style="width:120px;padding:10px;border-radius:8px;border:1px solid #ddd">
            <div style="margin-top:6px;color:var(--muted);font-size:13px">Number of days public document links remain valid. Previously located in Terms &amp; Conditions.</div>
        </label>
    </fieldset>
    <!-- Custom Fields List -->
    <div id="fieldsList" style="display:grid;gap:12px;margin-bottom:24px">
        <?php if (empty($fields)): ?>
            <div style="padding:24px;text-align:center;color:var(--muted);border:2px dashed #e5e7eb;border-radius:8px">
                No custom fields yet. Click "Add Field" below to create one.
            </div>
        <?php else: ?>
            <?php foreach ($fields as $field): 
                // Parse field_type to check which doc types this field applies to
                $docTypes = explode(',', $field['field_type']);
                $hasQuote = in_array('quote', $docTypes);
                $hasContract = in_array('contract', $docTypes);
                $hasInvoice = in_array('invoice', $docTypes);
            ?>
                <div class="field-item" data-field-id="<?php echo $field['id']; ?>" 
                     style="display:grid;grid-template-columns:auto 1fr auto auto;gap:12px;align-items:center;padding:12px 16px;border:1px solid #e5e7eb;border-radius:8px;background:#fff">
                    
                    <!-- Drag Handle -->
                    <div class="drag-handle" style="cursor:move;color:#9ca3af;font-size:18px" title="Drag to reorder">⋮⋮</div>
                    
                    <!-- Field Info -->
                    <div>
                        <div style="font-weight:600;margin-bottom:4px">
                            <?php echo htmlspecialchars($field['field_label']); ?>
                            <?php if ($field['is_required']): ?>
                                <span style="color:#dc2626;font-size:12px">*</span>
                            <?php endif; ?>
                            <?php if ($field['is_builtin']): ?>
                                <span style="margin-left:8px;padding:2px 6px;background:#fef3c7;color:#92400e;border-radius:4px;font-size:11px">Built-in</span>
                            <?php endif; ?>
                        </div>
                        <div style="font-size:12px;color:var(--muted)">
                            Type: <?php echo ucfirst($field['field_data_type']); ?> • 
                            Include on: 
                            <?php 
                            $included = [];
                            if ($hasQuote) $included[] = 'Quotes';
                            if ($hasContract) $included[] = 'Contracts';
                            if ($hasInvoice) $included[] = 'Invoices';
                            echo implode(', ', $included);
                            ?>
                        </div>
                    </div>
                    
                    <!-- Edit Button -->
                    <button type="button" onclick="editField(<?php echo $field['id']; ?>)" 
                            style="padding:6px 12px;border-radius:6px;border:1px solid #ddd;background:#fff;cursor:pointer;font-size:13px">
                        Edit
                    </button>
                    
                    <!-- Delete Button (only for non-builtin) -->
                    <?php if (!$field['is_builtin']): ?>
                        <button type="button" onclick="deleteField(<?php echo $field['id']; ?>)" 
                                style="padding:6px 12px;border-radius:6px;border:1px solid #fca5a5;background:#fee2e2;color:#991b1b;cursor:pointer;font-size:13px">
                            Delete
                        </button>
                    <?php else: ?>
                        <div style="width:73px"></div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Add Field Button -->
    <button type="button" onclick="showAddFieldModal()" 
            style="padding:10px 16px;border-radius:8px;border:0;background:var(--nav-accent);color:#fff;font-weight:600;cursor:pointer">
        + Add Custom Field
    </button>
</div>

<!-- Add/Edit Field Modal -->
<div id="fieldModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center">
    <div style="background:#fff;border-radius:12px;padding:24px;max-width:500px;width:90%;max-height:90vh;overflow-y:auto">
        <h3 style="margin:0 0 16px 0" id="modalTitle">Add Custom Field</h3>
        
        <form id="fieldForm" method="post" action="/?page=settings/custom-fields-handler" style="display:grid;gap:16px">
            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
            <input type="hidden" name="action" id="fieldAction" value="create">
            <input type="hidden" name="field_id" id="fieldId" value="">
            
            <label>
                <div style="margin-bottom:4px;font-weight:600">Field Label *</div>
                <input type="text" name="field_label" id="fieldLabel" required 
                       placeholder="e.g., Delivery Date, Project Name, PO Number"
                       style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
            </label>
            
            <label>
                <div style="margin-bottom:4px;font-weight:600">Field Type *</div>
                <select name="field_data_type" id="fieldDataType" required 
                        onchange="toggleNumberFields()"
                        style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
                    <option value="text">Text (short)</option>
                    <option value="textarea">Text Area (long)</option>
                    <option value="date">Date</option>
                    <option value="number">Number</option>
                </select>
            </label>
            
            <label>
                <div style="margin-bottom:4px;font-weight:600">Default Value</div>
                <input type="text" name="default_value" id="fieldDefaultValue" 
                       placeholder="Optional default value"
                       style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
                <div style="margin-top:4px;font-size:12px;color:var(--muted)">Pre-fill this value when creating new documents</div>
            </label>
            
            <div id="numberFieldsSection" style="display:none">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                    <label>
                        <div style="margin-bottom:4px;font-weight:600">Min Value</div>
                        <input type="number" step="0.01" name="min_value" id="fieldMinValue" 
                               placeholder="Minimum"
                               style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
                    </label>
                    <label>
                        <div style="margin-bottom:4px;font-weight:600">Max Value</div>
                        <input type="number" step="0.01" name="max_value" id="fieldMaxValue" 
                               placeholder="Maximum"
                               style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
                    </label>
                </div>
                <div style="font-size:12px;color:var(--muted);margin-top:4px">Optional constraints for number fields</div>
            </div>
            
            <fieldset style="border:1px solid #e5e7eb;border-radius:8px;padding:12px">
                <legend style="padding:0 8px;font-weight:600">Include on:</legend>
                <div style="display:grid;gap:8px">
                    <label style="display:flex;align-items:center;gap:8px">
                        <input type="checkbox" name="include_quote" id="includeQuote" value="1" checked>
                        <span>Quotes</span>
                    </label>
                    <label style="display:flex;align-items:center;gap:8px">
                        <input type="checkbox" name="include_contract" id="includeContract" value="1" checked>
                        <span>Contracts</span>
                    </label>
                    <label style="display:flex;align-items:center;gap:8px">
                        <input type="checkbox" name="include_invoice" id="includeInvoice" value="1" checked>
                        <span>Invoices</span>
                    </label>
                </div>
            </fieldset>
            
            <label style="display:flex;align-items:center;gap:10px">
                <input type="checkbox" name="is_required" id="fieldRequired" value="1">
                <span style="font-weight:600">Required field</span>
            </label>
            
            <div style="display:flex;gap:12px;margin-top:8px">
                <button type="submit" 
                        style="flex:1;padding:10px;border-radius:8px;border:0;background:var(--nav-accent);color:#fff;font-weight:600;cursor:pointer">
                    Save Field
                </button>
                <button type="button" onclick="closeFieldModal()" 
                        style="flex:1;padding:10px;border-radius:8px;border:1px solid #ddd;background:#fff;cursor:pointer">
                    Cancel
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function toggleNumberFields() {
    var dataType = document.getElementById('fieldDataType').value;
    var numberSection = document.getElementById('numberFieldsSection');
    if (numberSection) {
        numberSection.style.display = dataType === 'number' ? 'block' : 'none';
    }
}

function showAddFieldModal() {
    document.getElementById('modalTitle').textContent = 'Add Custom Field';
    document.getElementById('fieldAction').value = 'create';
    document.getElementById('fieldId').value = '';
    document.getElementById('fieldLabel').value = '';
    document.getElementById('fieldDataType').value = 'text';
    document.getElementById('fieldDefaultValue').value = '';
    document.getElementById('fieldMinValue').value = '';
    document.getElementById('fieldMaxValue').value = '';
    document.getElementById('includeQuote').checked = true;
    document.getElementById('includeContract').checked = true;
    document.getElementById('includeInvoice').checked = true;
    document.getElementById('fieldRequired').checked = false;
    toggleNumberFields();
    document.getElementById('fieldModal').style.display = 'flex';
}

function editField(fieldId) {
    fetch('/?page=settings/custom-fields-handler&action=get&id=' + fieldId)
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                const docTypes = data.field.field_type.split(',');
                document.getElementById('modalTitle').textContent = 'Edit Field';
                document.getElementById('fieldAction').value = 'update';
                document.getElementById('fieldId').value = fieldId;
                document.getElementById('fieldLabel').value = data.field.field_label;
                document.getElementById('fieldDataType').value = data.field.field_data_type;
                document.getElementById('fieldDefaultValue').value = data.field.default_value || '';
                document.getElementById('fieldMinValue').value = data.field.min_value || '';
                document.getElementById('fieldMaxValue').value = data.field.max_value || '';
                document.getElementById('includeQuote').checked = docTypes.includes('quote');
                document.getElementById('includeContract').checked = docTypes.includes('contract');
                document.getElementById('includeInvoice').checked = docTypes.includes('invoice');
                document.getElementById('fieldRequired').checked = data.field.is_required == 1;
                toggleNumberFields();
                document.getElementById('fieldModal').style.display = 'flex';
            } else {
                alert('Error loading field data');
            }
        })
        .catch(err => alert('Error: ' + err.message));
}

function deleteField(fieldId) {
    if (!confirm('Are you sure you want to delete this field?')) return;
    
    const formData = new FormData();
    formData.append('csrf', '<?php echo csrf_token(); ?>');
    formData.append('action', 'delete');
    formData.append('field_id', fieldId);
    
    fetch('/?page=settings/custom-fields-handler', {
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

// Enable drag and drop reordering (simplified)
document.addEventListener('DOMContentLoaded', function() {
    const fieldsList = document.getElementById('fieldsList');
    if (!fieldsList) return;
    
    let draggedElement = null;
    
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
    });
    
    fieldsList.addEventListener('drop', function(e) {
        e.preventDefault();
        if (!draggedElement) return;
        
        const target = e.target.closest('.field-item');
        if (target && target !== draggedElement) {
            const rect = target.getBoundingClientRect();
            const next = (e.clientY - rect.top) / rect.height > 0.5;
            fieldsList.insertBefore(draggedElement, next ? target.nextSibling : target);
            
            // Save new order
            saveFieldOrder();
        }
    });
    
    // Make items draggable
    document.querySelectorAll('.field-item').forEach(item => {
        item.setAttribute('draggable', 'true');
    });
});

function saveFieldOrder() {
    const items = document.querySelectorAll('.field-item');
    const order = Array.from(items).map((item, index) => ({
        id: item.dataset.fieldId,
        order: index + 1
    }));
    
    const formData = new FormData();
    formData.append('csrf', '<?php echo csrf_token(); ?>');
    formData.append('action', 'reorder');
    formData.append('order', JSON.stringify(order));
    
    fetch('/?page=settings/custom-fields-handler', {
        method: 'POST',
        body: formData
    });
}
</script>
