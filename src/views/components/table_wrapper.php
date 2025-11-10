<?php
/**
 * Reusable table wrapper component
 * 
 * Usage:
 * <?php 
 * $tableConfig = [
 *   'headers' => ['Name', 'Email', 'Actions'],
 *   'rows' => $data,
 *   'empty_message' => 'No records found'
 * ];
 * include __DIR__ . '/components/table_wrapper.php';
 * ?>
 */

$headers = $tableConfig['headers'] ?? [];
$rows = $tableConfig['rows'] ?? [];
$emptyMessage = $tableConfig['empty_message'] ?? 'No data available';
?>

<div style="overflow:auto">
  <table style="width:100%;border-collapse:collapse;background:#fff;border-radius:8px;box-shadow:0 6px 18px rgba(11,18,32,0.06)">
    <?php if ($headers): ?>
    <thead>
      <tr style="text-align:left;border-bottom:1px solid #eee">
        <?php foreach ($headers as $header): ?>
          <th style="padding:10px"><?php echo htmlspecialchars($header); ?></th>
        <?php endforeach; ?>
      </tr>
    </thead>
    <?php endif; ?>
    <tbody>
      <?php if (empty($rows)): ?>
        <tr>
          <td colspan="<?php echo count($headers); ?>" style="padding:20px;text-align:center;color:#6b7280">
            <?php echo htmlspecialchars($emptyMessage); ?>
          </td>
        </tr>
      <?php else: ?>
        <?php echo $tableConfig['body_html'] ?? ''; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>
