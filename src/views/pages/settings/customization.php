<?php
// src/views/pages/settings/customization.php
require_once __DIR__ . '/../../../config/db.php';

// Fetch existing custom fields grouped by type
$fields = [];
try {
    $stmt = $pdo->query('SELECT * FROM document_custom_fields ORDER BY field_type, display_order, id');
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $fields[$row['field_type']][] = $row;
    }
} catch (Throwable $e) {
    @error_log('[customization] Error fetching custom fields: ' . $e->getMessage());
}

// Get list of document types
$docTypes = ['quote', 'contract', 'invoice'];
$currentType = $_GET['field_type'] ?? 'quote';
if (!in_array($currentType, $docTypes)) {
    $currentType = 'quote';
}
?>

<div style="max-width:1000px">
    <h2 style="margin:0 0 8px 0">Document Customization</h2>
    <p style="margin:0 0 24px 0;color:var(--muted)">Customize fields for quotes, contracts, and invoices</p>

    <!-- Document Type Tabs -->
    <div style="display:flex;gap:12px;margin-bottom:20px;border-bottom:2px solid #e5e7eb">
        <?php foreach ($docTypes as $type): ?>
            <a href="/?page=settings&tab=customization&field_type=<?php echo e($type); ?>" 
               data-skip-nav
               style="padding:10px 20px;font-weight:<?php echo $currentType === $type ? '600' : '400'; ?>;color:<?php echo $currentType === $type ? 'var(--nav-accent)' : '#6b7280'; ?>;border-bottom:<?php echo $currentType === $type ? '2px solid var(--nav-accent)' : '2px solid transparent'; ?>;margin-bottom:-2px;text-decoration:none;text-transform:capitalize">
                <?php echo e(ucfirst($type) . 's'); ?>
            </a>
        <?php endforeach; ?>
    </div>

    <!-- Info Banner -->
    <div style="margin-bottom:20px;padding:12px 16px;background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;font-size:14px">
        <strong>ℹ️ About Custom Fields:</strong> Built-in fields (like Fulfillment Date) can be renamed but not deleted. 
        Custom fields you create can be reordered, edited, or removed at any time.
    </div>

    <!-- Custom Fields List -->
    <div style="margin-bottom:24px">
        <h3 style="margin:0 0 12px 0;font-size:16px">Custom Fields for <?php echo e(ucfirst($currentType)); ?>s</h3>
        
        <div id="fieldsList" style="display:grid;gap:12px">
            <?php 
            $currentFields = $fields[$currentType] ?? [];
            if (empty($currentFields)): 
            ?>
                <div style="padding:24px;text-align:center;color:var(--muted);border:2px dashed #e5e7eb;border-radius:8px">
                    No custom fields yet. Click "Add Field" below to create one.
                </div>
            <?php else: ?>
                <?php foreach ($currentFields as $field): ?>
                    <div class="field-item" data-field-id="<?php echo e($field['id']); ?>" 
                         style="display:grid;grid-template-columns:auto 1fr auto auto auto;gap:12px;align-items:center;padding:12px 16px;border:1px solid #e5e7eb;border-radius:8px;background:#fff">
                        
                        <!-- Drag Handle -->
                        <div class="drag-handle" style="cursor:move;color:#9ca3af;font-size:18px" title="Drag to reorder">
                            ⋮⋮
                        </div>
                        
                        <!-- Field Info -->
                        <div>
                            <div style="font-weight:600;margin-bottom:2px">
                                <?php echo htmlspecialchars($field['field_label']); ?>
                                <?php if ($field['is_required']): ?>
                                    <span style="color:#dc2626;font-size:12px">*</span>
                                <?php endif; ?>
                            </div>
                            <div style="font-size:13px;color:var(--muted)">
                                Type: <?php echo e(ucfirst($field['field_data_type'])); ?>
                                <?php if ($field['is_builtin']): ?>
                                    <span style="margin-left:8px;padding:2px 6px;background:#fef3c7;color:#92400e;border-radius:4px;font-size:11px">Built-in</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Order -->
                        <div style="font-size:13px;color:var(--muted)">
                            Order: <?php echo $field['display_order']; ?>
                        </div>
                        
                        <!-- Edit Button -->
                        <button type="button" onclick="editField(<?php echo e($field['id']); ?>)" 
                                style="padding:6px 12px;border-radius:6px;border:1px solid #ddd;background:#fff;cursor:pointer">
                            Edit
                        </button>
                        
                        <!-- Delete Button (only for non-builtin) -->
                        <?php if (!$field['is_builtin']): ?>
                            <button type="button" onclick="deleteField(<?php echo e($field['id']); ?>)" 
                                    style="padding:6px 12px;border-radius:6px;border:1px solid #fca5a5;background:#fee2e2;color:#991b1b;cursor:pointer">
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
                style="margin-top:12px;padding:10px 16px;border-radius:8px;border:0;background:var(--nav-accent);color:#fff;font-weight:600;cursor:pointer">
            + Add Custom Field
        </button>
    </div>

    <!-- Preview Section -->
    <div style="padding:16px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px">
        <h4 style="margin:0 0 12px 0;font-size:14px;color:var(--muted)">Preview: How fields appear on forms</h4>
        <div style="padding:12px;background:#fff;border:1px solid #e5e7eb;border-radius:6px;font-size:13px;color:var(--muted)">
            Custom fields will appear in the order shown above when creating or editing <?php echo e($currentType); ?>s.
        </div>
    </div>
</div>

<!-- Add/Edit Field Modal -->
<div id="fieldModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center">
    <div style="background:#fff;border-radius:12px;padding:24px;max-width:500px;width:90%;max-height:90vh;overflow-y:auto">
        <h3 style="margin:0 0 16px 0" id="modalTitle">Add Custom Field</h3>
        
        <form id="fieldForm" method="post" action="/?page=settings/custom-fields-handler" style="display:grid;gap:16px">
            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
            <input type="hidden" name="action" id="fieldAction" value="create">
            <input type="hidden" name="field_id" id="fieldId" value="">
            <input type="hidden" name="field_type" value="<?php echo e($currentType); ?>">
            
            <label>
                <div style="margin-bottom:4px;font-weight:600">Field Label *</div>
                <input type="text" name="field_label" id="fieldLabel" required 
                       placeholder="e.g., Delivery Date, Project Name, PO Number"
                       style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
                <div style="margin-top:4px;font-size:12px;color:var(--muted)">This is what users will see</div>
            </label>
            
            <label>
                <div style="margin-bottom:4px;font-weight:600">Field Type *</div>
                <select name="field_data_type" id="fieldDataType" required 
                        style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
                    <option value="text">Text (short)</option>
                    <option value="textarea">Text Area (long)</option>
                    <option value="date">Date</option>
                    <option value="number">Number</option>
                </select>
            </label>
            
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

<script src="<?php echo htmlspecialchars(asset_url('/assets/js/customization-logic.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
