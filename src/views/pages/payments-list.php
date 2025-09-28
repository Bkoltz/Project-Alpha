<?php
// src/views/pages/payments-list.php
require_once __DIR__ . '/../../config/db.php';
$client_id = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;
$start = $_GET['start'] ?? '';
$end = $_GET['end'] ?? '';
$where=[];$p=[];
if($client_id>0){$where[]='c.id=?';$p[]=$client_id;}
if($start!==''){$where[]='p.created_at>=?';$p[]=$start.' 00:00:00';}
if($end!==''){$where[]='p.created_at<=?';$p[]=$end.' 23:59:59';}
$sql = 'SELECT p.id, p.amount, p.status, p.created_at, i.id AS invoice_id, c.name AS client FROM payments p JOIN invoices i ON i.id=p.invoice_id JOIN clients c ON c.id=i.client_id';
if($where){$sql.=' WHERE '.implode(' AND ',$where);} $sql.=' ORDER BY p.created_at DESC LIMIT 100';
$rows = $pdo->prepare($sql); $rows->execute($p); $rows = $rows->fetchAll();
$clients=$pdo->query('SELECT id,name FROM clients ORDER BY name')->fetchAll();
?>
<section>
  <h2>Payments</h2>
  <form method="get" action="/" style="display:grid;grid-template-columns:1fr 1fr 1fr auto auto;gap:8px;align-items:end;margin:12px 0">
    <input type="hidden" name="page" value="payments-list">
    <label><div>Client</div>
      <select name="client_id" style="padding:8px;border-radius:8px;border:1px solid #ddd">
        <option value="0">All</option>
        <?php foreach ($clients as $c): ?>
          <option value="<?php echo (int)$c['id']; ?>" <?php echo $client_id==(int)$c['id']?'selected':''; ?>><?php echo htmlspecialchars($c['name']); ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label><div>Start</div><input type="date" name="start" value="<?php echo htmlspecialchars($start); ?>" style="padding:8px;border-radius:8px;border:1px solid #ddd"></label>
    <label><div>End</div><input type="date" name="end" value="<?php echo htmlspecialchars($end); ?>" style="padding:8px;border-radius:8px;border:1px solid #ddd"></label>
    <button type="submit" style="padding:8px 12px;border:1px solid #ddd;border-radius:8px;background:#fff">Filter</button>
    <a href="/?page=payments-list" style="padding:8px 12px;border:1px solid #ddd;border-radius:8px;background:#fff;display:inline-block">Reset</a>
  </form>
  <div style="overflow:auto">
    <table style="width:100%;border-collapse:collapse;background:#fff;border-radius:8px;box-shadow:0 6px 18px rgba(11,18,32,0.06)">
      <thead>
        <tr style="text-align:left;border-bottom:1px solid #eee">
          <th style="padding:10px">ID</th>
          <th style="padding:10px">Invoice</th>
          <th style="padding:10px">Client</th>
          <th style="padding:10px">Amount</th>
          <th style="padding:10px">Status</th>
          <th style="padding:10px">Created</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <tr style="border-top:1px solid #f3f4f6">
            <td style="padding:10px">#<?php echo (int)$r['id']; ?></td>
            <td style="padding:10px">Invoice #<?php echo (int)$r['invoice_id']; ?></td>
            <td style="padding:10px"><?php echo htmlspecialchars($r['client']); ?></td>
            <td style="padding:10px">$<?php echo number_format((float)$r['amount'], 2); ?></td>
            <td style="padding:10px;text-transform:capitalize"><?php echo htmlspecialchars($r['status']); ?></td>
            <td style="padding:10px"><?php echo htmlspecialchars($r['created_at']); ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
