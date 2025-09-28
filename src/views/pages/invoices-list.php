<?php
// src/views/pages/invoices-list.php
require_once __DIR__ . '/../../config/db.php';

$client_id = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;
$min = isset($_GET['min']) ? (float)$_GET['min'] : null;
$max = isset($_GET['max']) ? (float)$_GET['max'] : null;

$where = [];$params = [];
if ($client_id > 0) { $where[] = 'i.client_id=?'; $params[] = $client_id; }
if ($min !== null) { $where[] = 'i.total>=?'; $params[] = $min; }
if ($max !== null) { $where[] = 'i.total<=?'; $params[] = $max; }
$sql = 'SELECT i.id,i.total,i.status,i.created_at,c.name client FROM invoices i JOIN clients c ON c.id=i.client_id';
if ($where) { $sql .= ' WHERE ' . implode(' AND ', $where); }
$sql .= ' ORDER BY i.created_at DESC LIMIT 100';

$rows = $pdo->prepare($sql); $rows->execute($params); $rows = $rows->fetchAll();
$clients = $pdo->query('SELECT id,name FROM clients ORDER BY name')->fetchAll();
?>
<section>
  <h2>Invoices</h2>

  <form method="get" action="/" style="display:grid;grid-template-columns:1fr 1fr 1fr auto;gap:8px;align-items:end;margin:12px 0">
    <input type="hidden" name="page" value="invoices-list">
    <label><div>Client</div>
      <select name="client_id" style="padding:8px;border-radius:8px;border:1px solid #ddd">
        <option value="0">All</option>
        <?php foreach ($clients as $c): ?>
          <option value="<?php echo (int)$c['id']; ?>" <?php echo $client_id==(int)$c['id']?'selected':''; ?>><?php echo htmlspecialchars($c['name']); ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label><div>Min Total ($)</div>
      <input type="number" step="0.01" name="min" value="<?php echo htmlspecialchars($_GET['min'] ?? ''); ?>" style="padding:8px;border-radius:8px;border:1px solid #ddd">
    </label>
    <label><div>Max Total ($)</div>
      <input type="number" step="0.01" name="max" value="<?php echo htmlspecialchars($_GET['max'] ?? ''); ?>" style="padding:8px;border-radius:8px;border:1px solid #ddd">
    </label>
    <button type="submit" style="padding:8px 12px;border:1px solid #ddd;border-radius:8px;background:#fff">Filter</button>
  </form>

  <div style="overflow:auto">
    <table style="width:100%;border-collapse:collapse;background:#fff;border-radius:8px;box-shadow:0 6px 18px rgba(11,18,32,0.06)">
      <thead>
        <tr style="text-align:left;border-bottom:1px solid #eee">
          <th style="padding:10px">ID</th>
          <th style="padding:10px">Client</th>
          <th style="padding:10px">Total</th>
          <th style="padding:10px">Status</th>
          <th style="padding:10px">Created</th>
          <th style="padding:10px">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <?php
            $rowStyle = '';
            // color coding: paid (green), unpaid but within 30 days (yellow), unpaid older than 30 days (red)
            $status = $r['status'];
            if ($status === 'paid') $rowStyle = 'background:#ecfdf5;';
          ?>
          <tr style="border-top:1px solid #f3f4f6;<?php echo $rowStyle; ?>">
            <td style="padding:10px">#<?php echo (int)$r['id']; ?></td>
            <td style="padding:10px"><?php echo htmlspecialchars($r['client']); ?></td>
            <td style="padding:10px">$<?php echo number_format((float)$r['total'], 2); ?></td>
            <td style="padding:10px;text-transform:capitalize"><?php echo htmlspecialchars($r['status']); ?></td>
            <td style="padding:10px"><?php echo htmlspecialchars($r['created_at']); ?></td>
            <td style="padding:10px">
              <?php if ($r['status'] !== 'paid'): ?>
              <form method="post" action="/?page=invoices-mark-paid" onsubmit="return confirm('Mark invoice paid?')" style="display:inline">
                <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                <button type="submit" style="padding:6px 10px;border:0;border-radius:8px;background:#d1fae5;color:#065f46">Paid</button>
              </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
