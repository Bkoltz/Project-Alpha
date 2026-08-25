<?php
// Expects documentTotalRows: [{label, value, tone?}]. Presentation only.
$documentTotalRows = is_array($documentTotalRows ?? null) ? $documentTotalRows : [];
$toneStyles = [
    'normal' => ['', 'font-weight:600;', ''],
    'total' => ['border-top:1px solid #e5e7eb;', 'font-weight:700;', 'font-weight:700;'],
    'paid' => ['background:#ecfdf5;', 'font-weight:600;color:#065f46;', 'color:#065f46;'],
    'due' => ['background:#fef3c7;border-top:2px solid #f59e0b;', 'font-weight:700;color:#92400e;font-size:15px;', 'font-weight:700;color:#92400e;font-size:15px;'],
    'paid_full' => ['background:#ecfdf5;border-top:2px solid #10b981;', 'font-weight:700;color:#065f46;font-size:15px;', 'font-weight:700;color:#065f46;font-size:15px;'],
];
?>
<table style="width:100%;border-collapse:collapse;margin-top:16px">
  <tr>
    <td style="width:60%"></td>
    <td style="width:40%">
      <table style="width:100%;border-collapse:collapse">
        <?php foreach ($documentTotalRows as $row): ?>
          <?php
            $tone = (string)($row['tone'] ?? 'normal');
            [$rowStyle, $labelStyle, $valueStyle] = $toneStyles[$tone] ?? $toneStyles['normal'];
          ?>
          <tr style="<?php echo $rowStyle; ?>">
            <td style="padding:8px 10px;text-align:right;<?php echo $labelStyle; ?>"><?php echo htmlspecialchars((string)($row['label'] ?? '')); ?></td>
            <td style="padding:8px 10px;text-align:right;width:120px;<?php echo $valueStyle; ?>"><?php echo htmlspecialchars((string)($row['value'] ?? '')); ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
    </td>
  </tr>
</table>
