<?php
// src/views/pages/settings/item-library.php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/csrf.php';

$successMsg = '';
$errorMsg = '';

if (!empty($_GET['created'])) {
    $successMsg = 'Item created successfully!';
} elseif (!empty($_GET['updated'])) {
    $successMsg = 'Item updated successfully!';
} elseif (!empty($_GET['deleted'])) {
    $successMsg = 'Item deleted successfully!';
} elseif (!empty($_GET['error'])) {
    $errorMsg = $_GET['error'];
}

// Fetch all items
$stmt = $pdo->prepare('SELECT * FROM item_library ORDER BY is_active DESC, item_name ASC');
$stmt->execute();
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div>
  <div class="page-head">
    <h3 style="margin:0">Item Library</h3>
    <button type="button" data-item-library-create style="padding:10px 20px;background:#3b82f6;color:#fff;border:0;border-radius:8px;cursor:pointer;font-size:14px">
      + Add New Item
    </button>
  </div>

  <?php if ($successMsg): ?>
    <div style="padding:12px;background:#d1fae5;color:#065f46;border-radius:6px;margin-bottom:16px">
      ✓ <?php echo htmlspecialchars($successMsg); ?>
    </div>
  <?php endif; ?>

  <?php if ($errorMsg): ?>
    <div style="padding:12px;background:#fee2e2;color:#991b1b;border-radius:6px;margin-bottom:16px">
      ⚠ <?php echo htmlspecialchars($errorMsg); ?>
    </div>
  <?php endif; ?>

  <div style="background:#fff;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,0.1);overflow:hidden">
    <table class="pa-table">
      <thead>
        <tr style="background:#f9fafb;border-bottom:2px solid #e5e7eb">
          <th style="padding:12px;text-align:left;font-weight:600">Item Name</th>
          <th style="padding:12px;text-align:left;font-weight:600">Description</th>
          <th style="padding:12px;text-align:right;font-weight:600">Unit Price</th>
          <th style="padding:12px;text-align:center;font-weight:600">Status</th>
          <th style="padding:12px;text-align:center;font-weight:600">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($items)): ?>
          <tr>
            <td colspan="5" style="padding:40px;text-align:center;color:#9ca3af">
              No items yet. Click "Add New Item" to create your first predefined item.
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($items as $item): ?>
            <tr style="border-bottom:1px solid #f3f4f6;<?php echo !$item['is_active'] ? 'opacity:0.5;' : ''; ?>">
              <td style="padding:12px;font-weight:500"><?php echo htmlspecialchars($item['item_name']); ?></td>
              <td style="padding:12px;color:#6b7280;max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                <?php echo htmlspecialchars($item['description'] ?? '—'); ?>
              </td>
              <td style="padding:12px;text-align:right;font-weight:500">$<?php echo number_format($item['unit_price'], 2); ?></td>
              <td style="padding:12px;text-align:center">
                <span style="padding:4px 12px;border-radius:12px;font-size:12px;font-weight:600;<?php echo $item['is_active'] ? 'background:#d1fae5;color:#065f46' : 'background:#f3f4f6;color:#6b7280'; ?>">
                  <?php echo $item['is_active'] ? 'Active' : 'Inactive'; ?>
                </span>
              </td>
              <td style="padding:12px;text-align:center">
                <button type="button" data-item-library-edit data-item='<?php echo htmlspecialchars(json_encode($item), ENT_QUOTES, 'UTF-8'); ?>' style="padding:6px 12px;background:#fff;border:1px solid #d1d5db;border-radius:6px;cursor:pointer;margin-right:8px">
                  Edit
                </button>
                <form method="post" action="/?page=settings/item-library-handler" style="display:inline" onsubmit="return confirm('Delete this item?')">
                  <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?php echo $item['id']; ?>">
                  <button type="submit" style="padding:6px 12px;background:#fff;border:1px solid #fca5a5;color:#dc2626;border-radius:6px;cursor:pointer">
                    Delete
                  </button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

<!-- Create/Edit Modal -->
<div id="itemModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:12px;padding:24px;width:90%;max-width:600px;max-height:90vh;overflow-y:auto">
    <h2 id="modalTitle" style="margin:0 0 20px 0">Add New Item</h2>
    
    <form method="post" action="/?page=settings/item-library-handler">
      <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
      <input type="hidden" id="formAction" name="action" value="create">
      <input type="hidden" id="formId" name="id" value="">
      
      <div style="margin-bottom:16px">
        <label style="display:block;margin-bottom:6px;font-weight:500">Item Name *</label>
        <input type="text" name="item_name" id="itemName" required maxlength="255" 
          style="width:100%;padding:10px;border:1px solid #d1d5db;border-radius:6px;font-size:14px"
          placeholder="e.g., Roofing Shingles, Labor Hour, Paint Gallon">
      </div>

      <div style="margin-bottom:16px">
        <label style="display:block;margin-bottom:6px;font-weight:500">Description</label>
        <textarea name="description" id="itemDescription" rows="3"
          style="width:100%;padding:10px;border:1px solid #d1d5db;border-radius:6px;font-size:14px;resize:vertical"
          placeholder="Optional detailed description"></textarea>
      </div>

      <div style="margin-bottom:16px">
        <label style="display:block;margin-bottom:6px;font-weight:500">Unit Price *</label>
        <input type="number" name="unit_price" id="unitPrice" required step="0.01" min="0"
          style="width:100%;padding:10px;border:1px solid #d1d5db;border-radius:6px;font-size:14px"
          placeholder="0.00">
      </div>

      <div style="margin-bottom:20px">
        <label style="display:flex;align-items:center;cursor:pointer">
          <input type="checkbox" name="is_hourly" id="isHourly" value="1"
            style="width:18px;height:18px;margin-right:8px">
          <span>This is an hourly service</span>
        </label>
      </div>

      <div style="margin-bottom:20px">
        <label style="display:flex;align-items:center;cursor:pointer">
          <input type="checkbox" name="is_active" id="isActive" value="1" checked
            style="width:18px;height:18px;margin-right:8px">
          <span>Active (show in autocomplete)</span>
        </label>
      </div>

      <div style="display:flex;gap:12px;justify-content:flex-end">
        <button type="button" data-item-library-close style="padding:10px 20px;background:#fff;border:1px solid #d1d5db;border-radius:6px;cursor:pointer">
          Cancel
        </button>
        <button type="submit" style="padding:10px 20px;background:#3b82f6;color:#fff;border:0;border-radius:6px;cursor:pointer">
          Save Item
        </button>
      </div>
    </form>
  </div>
</div>

<script src="<?php echo htmlspecialchars(asset_url('/assets/js/item-library.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
</div>
