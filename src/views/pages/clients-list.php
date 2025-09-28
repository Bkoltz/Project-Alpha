<?php
// src/views/pages/clients-list.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/format.php';
$clients = $pdo->query("SELECT id, name, email, phone, organization, created_at FROM clients ORDER BY created_at DESC LIMIT 50")->fetchAll();
?>
<section>
  <h2>Clients</h2>
  <?php if (!empty($_GET['created'])): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#e6fffa;color:#065f46;border:1px solid #99f6e4">Client created.</div>
  <?php endif; ?>
  <div style="overflow:auto">
    <table style="width:100%;border-collapse:collapse;background:#fff;border-radius:8px;box-shadow:0 6px 18px rgba(11,18,32,0.06)">
      <thead>
        <tr style="text-align:left;border-bottom:1px solid #eee">
          <th style="padding:10px">Name</th>
          <th style="padding:10px">Email</th>
          <th style="padding:10px">Phone</th>
          <th style="padding:10px">Organization</th>
          <th style="padding:10px">Created</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($clients as $c): ?>
          <tr style="border-top:1px solid #f3f4f6">
            <td style="padding:10px"><?php echo htmlspecialchars($c['name']); ?></td>
            <td style="padding:10px"><?php echo htmlspecialchars($c['email'] ?? ''); ?></td>
            <td style="padding:10px"><?php echo htmlspecialchars(format_phone($c['phone'] ?? '')); ?></td>
            <td style="padding:10px"><?php echo htmlspecialchars($c['organization'] ?? ''); ?></td>
            <td style="padding:10px"><?php echo htmlspecialchars($c['created_at']); ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
