<?php

declare(strict_types=1);

function render_tax_lookup_control(string $inputId, string $inputName = 'tax_percent', float $value = 0.0): string
{
    $id = preg_replace('/[^a-zA-Z0-9_\-]/', '', $inputId);
    $name = htmlspecialchars($inputName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $displayValue = htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    ob_start();
    ?>
    <div class="pa-tax-lookup" data-tax-input-id="<?php echo htmlspecialchars($id, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" style="display:grid;gap:8px">
      <div style="display:flex;align-items:center;justify-content:space-between;gap:10px">
        <div style="font-weight:600">Tax</div>
        <div class="pa-tax-mode" role="group" aria-label="Tax entry mode" style="display:inline-flex;border:1px solid #d1d5db;border-radius:8px;overflow:hidden;background:#fff">
          <button type="button" data-tax-mode="manual" aria-pressed="true" style="padding:7px 10px;border:0;border-right:1px solid #d1d5db;background:#111827;color:#fff;font-size:12px;font-weight:600;cursor:pointer">Manual %</button>
          <button type="button" data-tax-mode="zip" aria-pressed="false" style="padding:7px 10px;border:0;border-right:1px solid #d1d5db;background:#fff;color:#374151;font-size:12px;font-weight:600;cursor:pointer">ZIP</button>
          <button type="button" data-tax-mode="county" aria-pressed="false" style="padding:7px 10px;border:0;background:#fff;color:#374151;font-size:12px;font-weight:600;cursor:pointer">County</button>
        </div>
      </div>

      <div data-tax-panel="manual">
        <input id="<?php echo htmlspecialchars($id, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" type="number" step="0.01" name="<?php echo $name; ?>" value="<?php echo $displayValue; ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
      </div>

      <div data-tax-panel="zip" style="display:none;gap:6px">
        <input type="text" inputmode="numeric" autocomplete="postal-code" maxlength="10" placeholder="Enter ZIP code" data-tax-zip style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
      </div>

      <div data-tax-panel="county" style="display:none;gap:6px;position:relative">
        <input type="text" autocomplete="off" placeholder="Search county name" data-tax-county style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
      </div>

      <div data-tax-status style="min-height:18px;font-size:12px;color:#6b7280"></div>
      <div data-tax-choices style="display:none;border:1px solid #e5e7eb;border-radius:8px;background:#fff;overflow:hidden"></div>
    </div>
    <?php
    return (string)ob_get_clean();
}
