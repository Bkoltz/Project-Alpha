<?php
// src/views/pages/settings/documents/customization.php
require_once __DIR__ . '/../../../../config/db.php';
require_once __DIR__ . '/../../../../utils/csrf.php';

$activeTab = $_GET['field_tab'] ?? 'regular';
if (!in_array($activeTab, ['regular', 'long_term', 'on_demand'])) {
    $activeTab = 'regular';
}

// Fetch fields for active tab
$stmt = $pdo->prepare('SELECT * FROM document_custom_fields WHERE document_type = ? ORDER BY display_order, id');
$stmt->execute([$activeTab]);
$fields = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div style="max-width:1100px">
    <h3 style="margin:0 0 8px 0">Document Header Fields</h3>
    <p style="margin:0 0 20px 0;color:var(--muted);font-size:14px">Customize which fields appear in the top section of your documents. Fields left empty won't appear on the document.</p>

    <!-- Document Type Tabs -->
    <div style="display:flex;gap:12px;margin-bottom:24px;border-bottom:2px solid #e5e7eb;padding-bottom:2px">
        <a href="/?page=settings&tab=documents&doc_tab=customization&field_tab=regular" data-skip-nav
           style="padding:10px 20px;font-weight:<?php echo $activeTab === 'regular' ? '600' : '400'; ?>;color:<?php echo $activeTab === 'regular' ? 'var(--nav-accent)' : '#6b7280'; ?>;border-bottom:<?php echo $activeTab === 'regular' ? '3px solid var(--nav-accent)' : '3px solid transparent'; ?>;margin-bottom:-2px;text-decoration:none">
            Regular
        </a>
        <a href="/?page=settings&tab=documents&doc_tab=customization&field_tab=long_term" data-skip-nav
           style="padding:10px 20px;font-weight:<?php echo $activeTab === 'long_term' ? '600' : '400'; ?>;color:<?php echo $activeTab === 'long_term' ? 'var(--nav-accent)' : '#6b7280'; ?>;border-bottom:<?php echo $activeTab === 'long_term' ? '3px solid var(--nav-accent)' : '3px solid transparent'; ?>;margin-bottom:-2px;text-decoration:none">
            Long Term
        </a>
        <a href="/?page=settings&tab=documents&doc_tab=customization&field_tab=on_demand" data-skip-nav
           style="padding:10px 20px;font-weight:<?php echo $activeTab === 'on_demand' ? '600' : '400'; ?>;color:<?php echo $activeTab === 'on_demand' ? 'var(--nav-accent)' : '#6b7280'; ?>;border-bottom:<?php echo $activeTab === 'on_demand' ? '3px solid var(--nav-accent)' : '3px solid transparent'; ?>;margin-bottom:-2px;text-decoration:none">
            On-Demand
        </a>
    </div>

    <!-- Info Banner -->
    <div style="margin-bottom:20px;padding:12px 16px;background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;font-size:13px">
        <strong>ℹ️ About Custom Fields:</strong> Built-in fields (Deposit, Fulfillment Date) cannot be deleted but can be reordered. Custom fields can be added, edited, reordered, or removed.
    </div>

    <!-- Fields List -->
    <div id="fieldsList" style="display:grid;gap:12px;margin-bottom:24px">
        <?php if (empty($fields)): ?>
            <div style="padding:24px;text-align:center;color:var(--muted);border:2px dashed #e5e7eb;border-radius:8px">
                No fields configured yet. Click "+ Add Custom Field" below to create one.
            </div>
        <?php else: ?>
            <?php foreach ($fields as $field): ?>
                <div class="field-item" data-field-id="<?php echo $field['id']; ?>" draggable="true"
                     style="display:grid;grid-template-columns:auto 1fr auto auto auto;gap:12px;align-items:center;padding:12px 16px;border:1px solid #e5e7eb;border-radius:8px;background:#fff;cursor:move">
                    
                    <!-- Drag Handle -->
                    <div class="drag-handle" style="color:#9ca3af;font-size:18px;cursor:move" title="Drag to reorder">⋮⋮</div>
                    
                    <!-- Field Info -->
                    <div>
                        <div style="font-weight:600;margin-bottom:4px">
                            <?php echo htmlspecialchars($field['field_label']); ?>
                            <?php if ($field['is_required']): ?>
                                <span style="color:#dc2626;font-size:12px;margin-left:4px">*</span>
                            <?php endif; ?>
                            <?php if ($field['is_builtin']): ?>
                                <span style="margin-left:8px;padding:2px 6px;background:#fef3c7;color:#92400e;border-radius:4px;font-size:11px;font-weight:600">Built-in</span>
                            <?php endif; ?>
                            <?php if (!$field['is_enabled']): ?>
                                <span style="margin-left:8px;padding:2px 6px;background:#f3f4f6;color:#6b7280;border-radius:4px;font-size:11px;font-weight:600">Disabled</span>
                            <?php endif; ?>
                        </div>
                        <div style="font-size:12px;color:var(--muted)">
                            Type: <?php echo ucfirst($field['field_type']); ?>
                            <?php if ($field['field_key']): ?> • Key: <?php echo htmlspecialchars($field['field_key']); ?><?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Toggle Switch -->
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;white-space:nowrap" title="Enable/Disable">
                        <input type="checkbox" class="field-toggle" data-field-id="<?php echo $field['id']; ?>" 
                               <?php echo $field['is_enabled'] ? 'checked' : ''; ?> 
                               <?php echo $field['is_builtin'] ? 'disabled' : ''; ?>
                               style="width:16px;height:16px">
                        <span style="font-size:13px;color:#6b7280">Enabled</span>
                    </label>
                    
                    <!-- Edit Button -->
                    <?php if (!$field['is_builtin']): ?>
                        <button type="button" onclick="editField(<?php echo $field['id']; ?>)" 
                                style="padding:6px 12px;border-radius:6px;border:1px solid #ddd;background:#fff;cursor:pointer;font-size:13px;white-space:nowrap">
                            Edit
                        </button>
                    <?php else: ?>
                        <div style="width:54px"></div>
                    <?php endif; ?>
                    
                    <!-- Delete Button -->
                    <?php if (!$field['is_builtin']): ?>
                        <button type="button" onclick="deleteField(<?php echo $field['id']; ?>)" 
                                style="padding:6px 12px;border-radius:6px;border:1px solid #fca5a5;background:#fee2e2;color:#991b1b;cursor:pointer;font-size:13px;white-space:nowrap">
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
        
        <form id="fieldForm" method="post" style="display:grid;gap:16px">
            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
            <input type="hidden" name="action" id="fieldAction" value="create">
            <input type="hidden" name="field_id" id="fieldId" value="">
            
            <label>
                <div style="margin-bottom:4px;font-weight:600">Field Label *</div>
                <input type="text" name="field_label" id="fieldLabel" required 
                       placeholder="e.g., Pick Up Date, Rental Duration"
                       style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
            </label>
            
            <label>
                <div style="margin-bottom:4px;font-weight:600">Field Type *</div>
                <select name="field_type" id="fieldType" required onchange="toggleOptionsField()"
                        style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
                    <option value="text">Text (short)</option>
                    <option value="textarea">Text Area (long)</option>
                    <option value="date">Date</option>
                    <option value="number">Number</option>
                    <option value="select">Select Dropdown</option>
                </select>
            </label>
            
            <div id="optionsField" style="display:none">
                <label>
                    <div style="margin-bottom:4px;font-weight:600">Options (one per line) *</div>
                    <textarea name="field_options" id="fieldOptions" rows="4" 
                              placeholder="Option 1&#10;Option 2&#10;Option 3"
                              style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd"></textarea>
                    <div style="margin-top:4px;font-size:12px;color:var(--muted)">Enter each option on a new line</div>
                </label>
            </div>
            
            <fieldset id="docTypesSection" style="border:1px solid #e5e7eb;border-radius:8px;padding:12px">
                <legend style="padding:0 8px;font-weight:600">Apply to Document Types:</legend>
                <div style="display:grid;gap:8px">
                    <label style="display:flex;align-items:center;gap:8px">
                        <input type="checkbox" name="document_types[]" value="regular" checked>
                        <span>Regular</span>
                    </label>
                    <label style="display:flex;align-items:center;gap:8px">
                        <input type="checkbox" name="document_types[]" value="long_term" checked>
                        <span>Long Term</span>
                    </label>
                    <label style="display:flex;align-items:center;gap:8px">
                        <input type="checkbox" name="document_types[]" value="on_demand" checked>
                        <span>On-Demand</span>
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
const activeTab = '<?php echo $activeTab; ?>';
</script>

<script src="js/document-customization-logic.js" defer></script>
