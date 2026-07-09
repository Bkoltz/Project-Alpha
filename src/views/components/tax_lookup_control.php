<?php

declare(strict_types=1);

function render_tax_lookup_control(string $inputId, string $inputName = 'tax_percent', float $value = 0.0): string
{
    $id = preg_replace('/[^a-zA-Z0-9_\-]/', '', $inputId);
    $name = htmlspecialchars($inputName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $displayValue = htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    ob_start();
    ?>
    <div class="pa-tax-lookup" data-tax-input-id="<?php echo htmlspecialchars($id, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
      <label class="pa-tax-lookup__field">
        <div>Tax (%)</div>
        <div data-tax-panel="manual">
          <input class="pa-tax-lookup__input" id="<?php echo htmlspecialchars($id, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" type="number" step="0.01" name="<?php echo $name; ?>" value="<?php echo $displayValue; ?>">
        </div>

        <div data-tax-panel="zip" style="display:none">
          <input class="pa-tax-lookup__input" type="text" inputmode="numeric" autocomplete="postal-code" maxlength="10" placeholder="Enter ZIP code" data-tax-zip>
        </div>

        <div data-tax-panel="county" style="display:none">
          <input class="pa-tax-lookup__input" type="text" autocomplete="off" placeholder="Search county name" data-tax-county>
        </div>
      </label>

      <div class="pa-tax-mode" role="group" aria-label="Tax entry mode">
        <button type="button" data-tax-mode="manual" aria-pressed="true">Manual %</button>
        <button type="button" data-tax-mode="zip" aria-pressed="false">ZIP</button>
        <button type="button" data-tax-mode="county" aria-pressed="false">County</button>
      </div>

      <div data-tax-status class="pa-tax-lookup__status"></div>
      <div data-tax-choices class="pa-tax-lookup__choices"></div>
    </div>
    <style>
      .pa-tax-lookup{position:relative;display:grid;gap:0;min-width:0;padding-bottom:14px}
      .pa-tax-lookup__field{display:block}
      .pa-tax-mode{position:absolute;right:0;top:-4px;display:inline-flex;border:1px solid #d1d5db;border-radius:7px;overflow:hidden;background:#fff}
      .pa-tax-mode button{height:22px;padding:0 9px;border:0;border-right:1px solid #d1d5db;background:#fff;color:#374151;font-size:12px;font-weight:600;line-height:22px;cursor:pointer}
      .pa-tax-mode button:last-child{border-right:0}
      .pa-tax-lookup__input{width:100%;padding:10px;border-radius:8px;border:1px solid #ddd}
      .pa-tax-lookup__status{min-height:16px;font-size:12px;line-height:16px;color:#6b7280}
      .pa-tax-lookup__choices{display:none;border:1px solid #e5e7eb;border-radius:8px;background:#fff;overflow:hidden}
      .pa-tax-lookup__choice{display:flex;width:100%;justify-content:space-between;align-items:center;gap:12px;text-align:left;padding:10px 12px;border:0;border-bottom:1px solid #f3f4f6;background:#fff;cursor:pointer;font:inherit}
      .pa-tax-lookup__choice:last-child{border-bottom:0}
      .pa-tax-lookup__choice strong{white-space:nowrap}
    </style>
    <?php
    return (string)ob_get_clean();
}
