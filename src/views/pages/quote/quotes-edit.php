<?php
// src/views/pages/quotes-edit.php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../utils/document_fields.php';
$id = (int)($_GET['id'] ?? 0);
$q = $pdo->prepare('SELECT * FROM quotes WHERE id=?');
$q->execute([$id]);
$quote = $q->fetch(PDO::FETCH_ASSOC);
if (!$quote) {
  echo '<p>Quote not found</p>';
  return;
}
$items = $pdo->prepare('SELECT * FROM quote_items WHERE quote_id=?');
$items->execute([$id]);
$items = $items->fetchAll(PDO::FETCH_ASSOC);
$clients = $pdo->query("SELECT id, name FROM clients ORDER BY name ASC")->fetchAll();
?>
<main class="main-content" role="main">
  <section>
    <h2>Edit Quote Q-<?php echo htmlspecialchars($quote['doc_number'] ?? $quote['id']); ?><?php if (!empty($quote['project_code'])) echo ' (Job ' . htmlspecialchars($quote['project_code']) . ')'; ?></h2>
    <form id="quoteEditForm" method="post" action="/?page=quote/quotes-update" style="display:grid;gap:16px;max-width:900px">
      <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">
      <input type="hidden" name="id" value="<?php echo (int)$quote['id']; ?>">
      <div style="display:grid;gap:12px;grid-template-columns:1fr 1fr">
        <label>
          <div>Client</div>
          <select required name="client_id" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
            <?php foreach ($clients as $c): ?>
              <option value="<?php echo (int)$c['id']; ?>" <?php echo (int)$quote['client_id'] === (int)$c['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['name']); ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>
          <div>Tax (%)</div>
          <input id="taxPercent" type="number" step="0.01" name="tax_percent" value="<?php echo htmlspecialchars($quote['tax_percent']); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
        </label>
        <label>
          <div>Discount Type</div>
          <select id="discountType" name="discount_type" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
            <option value="none" <?php echo $quote['discount_type'] === 'none' ? 'selected' : ''; ?>>None</option>
            <option value="percent" <?php echo $quote['discount_type'] === 'percent' ? 'selected' : ''; ?>>Percent</option>
            <option value="fixed" <?php echo $quote['discount_type'] === 'fixed' ? 'selected' : ''; ?>>Fixed $</option>
          </select>
        </label>
        <label>
          <div>Discount Value</div>
          <input id="discountValue" type="number" step="0.01" name="discount_value" value="<?php echo htmlspecialchars($quote['discount_value']); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
        </label>
        <label>
          <div>Deposit Type</div>
          <select id="depositType" name="deposit_type" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
            <option value="none" <?php echo ($quote['deposit_type'] ?? 'none') === 'none' ? 'selected' : ''; ?>>None</option>
            <option value="percent" <?php echo ($quote['deposit_type'] ?? '') === 'percent' ? 'selected' : ''; ?>>Percent</option>
            <option value="fixed" <?php echo ($quote['deposit_type'] ?? '') === 'fixed' ? 'selected' : ''; ?>>Fixed $</option>
          </select>
        </label>
        <label>
          <div>Deposit Value</div>
          <input id="depositValue" type="number" step="0.01" name="deposit_value" value="<?php echo htmlspecialchars($quote['deposit_amount'] ?? 0); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
        </label>
        <label>
          <div>Fulfillment Date</div>
          <input type="date" name="fulfillment_date" value="<?php echo htmlspecialchars($quote['fulfillment_date'] ?? ''); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
        </label>
      </div>

    <?php
    // Determine document type and render custom fields
    $documentType = 'regular';
    if (!empty($quote['is_long_term'])) $documentType = 'long_term';
    elseif (!empty($quote['is_on_demand'])) $documentType = 'on_demand';
    
    // Get existing custom field values
    $existingCustomFields = !empty($quote['custom_fields']) ? json_decode($quote['custom_fields'], true) : [];
    if (!is_array($existingCustomFields)) $existingCustomFields = [];
    
    // Fetch non-builtin custom fields for this document type
    $customFieldsStmt = $pdo->prepare('
        SELECT * FROM document_custom_fields 
        WHERE document_type = ? AND is_enabled = 1 AND is_builtin = 0
        ORDER BY display_order, id
    ');
    $customFieldsStmt->execute([$documentType]);
    $customFields = $customFieldsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($customFields)):
    ?>
    <div style="border:1px solid #e5e7eb;border-radius:8px;padding:16px;background:#f9fafb">
      <div style="font-weight:600;margin-bottom:12px;color:#374151">Custom Fields</div>
      <div style="display:grid;gap:12px;grid-template-columns:repeat(auto-fit, minmax(200px, 1fr))">
        <?php foreach ($customFields as $field): 
          $fieldKey = $field['field_key'];
          $fieldValue = $existingCustomFields[$fieldKey] ?? '';
        ?>
        <label>
          <div><?php echo htmlspecialchars($field['field_label']); ?><?php if ($field['is_required']): ?> <span style="color:#dc2626">*</span><?php endif; ?></div>
          <?php if ($field['field_type'] === 'date'): ?>
            <input type="date" name="custom_field_<?php echo htmlspecialchars($fieldKey); ?>" value="<?php echo htmlspecialchars($fieldValue); ?>" <?php if ($field['is_required']) echo 'required'; ?> style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
          <?php elseif ($field['field_type'] === 'number'): ?>
            <input type="number" step="0.01" name="custom_field_<?php echo htmlspecialchars($fieldKey); ?>" value="<?php echo htmlspecialchars($fieldValue); ?>" <?php if ($field['is_required']) echo 'required'; ?> style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
          <?php elseif ($field['field_type'] === 'textarea'): ?>
            <textarea name="custom_field_<?php echo htmlspecialchars($fieldKey); ?>" rows="3" <?php if ($field['is_required']) echo 'required'; ?> style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd"><?php echo htmlspecialchars($fieldValue); ?></textarea>
          <?php elseif ($field['field_type'] === 'select'): ?>
            <?php $options = json_decode($field['field_options'] ?? '[]', true); ?>
            <select name="custom_field_<?php echo htmlspecialchars($fieldKey); ?>" <?php if ($field['is_required']) echo 'required'; ?> style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
              <option value="">-- Select --</option>
              <?php foreach ($options as $opt): ?>
                <option value="<?php echo htmlspecialchars($opt); ?>" <?php echo $fieldValue === $opt ? 'selected' : ''; ?>><?php echo htmlspecialchars($opt); ?></option>
              <?php endforeach; ?>
            </select>
          <?php else: ?>
            <input type="text" name="custom_field_<?php echo htmlspecialchars($fieldKey); ?>" value="<?php echo htmlspecialchars($fieldValue); ?>" <?php if ($field['is_required']) echo 'required'; ?> style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
          <?php endif; ?>
        </label>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <div>
      <div style="font-weight:600;margin-bottom:8px">Items</div>
      <div id="items" style="display:grid;gap:8px"></div>
      <button type="button" onclick="addItem()" style="margin-top:6px;padding:8px 12px;border-radius:8px;border:1px solid #ddd;background:#fff">+ Add Item</button>
    </div>
      <div>
        <div style="font-weight:600;margin-bottom:8px">Items</div>
        <div id="items" style="display:grid;gap:8px"></div>
        <button type="button" onclick="addItem()" style="margin-top:6px;padding:8px 12px;border-radius:8px;border:1px solid #ddd;background:#fff">+ Add Item</button>
      </div>

      <?php
      $pn = null;
      $pt = null;
      if (!empty($quote['project_code'])) {
        try {
          $pm = $pdo->prepare('SELECT notes, terms FROM project_meta WHERE project_code=?');
          $pm->execute([$quote['project_code']]);
          $row = $pm->fetch(PDO::FETCH_ASSOC);
          if ($row) {
            $pn = (string)($row['notes'] ?? '');
            $pt = (string)($row['terms'] ?? '');
          }
        } catch (Throwable $e) {
          try {
            $pm = $pdo->prepare('SELECT notes FROM project_meta WHERE project_code=?');
            $pm->execute([$quote['project_code']]);
            $row = $pm->fetch(PDO::FETCH_ASSOC);
            if ($row) {
              $pn = (string)($row['notes'] ?? '');
            }
          } catch (Throwable $e2) { /* ignore */
          }
        }
      }
      ?>
      <?php if (!isset($appConfig['quote_scope_enabled']) || !empty($appConfig['quote_scope_enabled'])): ?>
        <label>
          <div>Scope of Work</div>
          <textarea name="scope" rows="4" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd" placeholder="Optional: Describe the scope of work and deliverables..."><?php echo htmlspecialchars($quote['scope'] ?? ''); ?></textarea>
        </label>
      <?php endif; ?>
      <label>
        <div>Job Notes</div>
        <textarea name="project_notes" rows="3" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd" placeholder="Shared across related docs"><?php echo htmlspecialchars($pn ?? ''); ?></textarea>
      </label>
      <label>
        <div>Job Terms (override default terms for this job)</div>
        <textarea name="project_terms" rows="6" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd" placeholder="If set, used for all quotes/contracts under this project"><?php echo htmlspecialchars($pt ?? ''); ?></textarea>
      </label>

      <div id="totals" style="margin-top:8px;display:grid;gap:6px;justify-content:end">
        <div style="display:flex;gap:16px;justify-content:flex-end">
          <div style="min-width:140px;text-align:right;color:var(--muted)">Subtotal</div>
          <div id="subtotalVal" style="min-width:120px;text-align:right">$0.00</div>
        </div>
        <div style="display:flex;gap:16px;justify-content:flex-end">
          <div style="min-width:140px;text-align:right;color:var(--muted)">Discount</div>
          <div id="discountVal" style="min-width:120px;text-align:right">$0.00</div>
        </div>
        <div style="display:flex;gap:16px;justify-content:flex-end">
          <div style="min-width:140px;text-align:right;color:var(--muted)">Tax</div>
          <div id="taxVal" style="min-width:120px;text-align:right">$0.00</div>
        </div>
        <div style="display:flex;gap:16px;justify-content:flex-end;font-weight:700">
          <div style="min-width:140px;text-align:right">Total</div>
          <div id="totalVal" style="min-width:120px;text-align:right">$0.00</div>
        </div>
      </div>

      <div>
        <button type="submit" style="padding:10px 14px;border-radius:8px;border:0;background:var(--nav-accent);color:#fff;font-weight:600">Update Quote</button>
      </div>
    </form>
  </section>
      
  <!-- TODO this needs to change to a script but the way the we are handling scripts in navigation.js is fucking with it in a weird way and i cant be bothered to fix it rn  -->
  <!-- Serverside items stored for retreival from quotes-edit-logic.js -->
  <div id="quote-items-data" type="application/json" style ="display:none">
    <?php echo json_encode($items) ?>
  </div>

  <!-- Client logic -->
  <script src="js/quotes-edit-logic.js" defer></script>
</main>