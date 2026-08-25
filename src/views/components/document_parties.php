<?php
// Expects normalized sender and recipient arrays. It performs no data access.
$documentPartySender = is_array($documentPartySender ?? null) ? $documentPartySender : [];
$documentPartyRecipient = is_array($documentPartyRecipient ?? null) ? $documentPartyRecipient : [];
$senderLines = document_sender_lines($documentPartySender);
$senderPhone = trim((string)($documentPartySender['phone'] ?? ''));
$senderEmail = trim((string)($documentPartySender['email'] ?? ''));
$recipientLines = is_array($documentPartyRecipient['lines'] ?? null) ? $documentPartyRecipient['lines'] : [];
$recipientPhone = $documentPartyRecipient['phone'] ?? null;
$recipientEmail = $documentPartyRecipient['email'] ?? null;
?>
<table style="width:100%;table-layout:fixed;margin:12px 0 16px;border-collapse:collapse">
  <tr>
    <td style="vertical-align:top;width:50%;padding-right:12px">
      <div class="font-600">From</div>
      <div><?php foreach ($senderLines as $line) { echo '<div>' . htmlspecialchars($line) . '</div>'; } ?></div>
      <?php if ($senderPhone !== '' || $senderEmail !== ''): ?>
        <div style="margin-top:6px;color:#4b5563;font-size:13px">
          <?php if ($senderPhone !== ''): ?><div><?php echo htmlspecialchars(format_phone($senderPhone), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div><?php endif; ?>
          <?php if ($senderEmail !== ''): ?><div><?php echo htmlspecialchars($senderEmail); ?></div><?php endif; ?>
        </div>
      <?php endif; ?>
    </td>
    <td style="vertical-align:top;width:50%;padding-left:12px">
      <div class="font-600">To</div>
      <div><?php foreach ($recipientLines as $line) { echo '<div>' . htmlspecialchars((string)$line) . '</div>'; } ?></div>
      <?php if ($recipientPhone !== null || $recipientEmail !== null): ?>
        <div style="margin-top:6px;color:#4b5563;font-size:13px">
          <?php if ($recipientPhone !== null): ?><div><?php echo htmlspecialchars(format_phone((string)$recipientPhone), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div><?php endif; ?>
          <?php if ($recipientEmail !== null): ?><div><?php echo htmlspecialchars((string)$recipientEmail); ?></div><?php endif; ?>
        </div>
      <?php endif; ?>
    </td>
  </tr>
</table>
