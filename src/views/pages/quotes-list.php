<?php
// src/views/pages/quotes-list.php
require_once __DIR__ . '/../../config/db.php';
$client_id = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;
$start = $_GET['start'] ?? '';
$end = $_GET['end'] ?? '';
$where=[];$p=[];
if($client_id>0){$where[]='q.client_id=?';$p[]=$client_id;}
if($start!==''){$where[]='q.created_at>=?';$p[]=$start.' 00:00:00';}
if($end!==''){$where[]='q.created_at<=?';$p[]=$end.' 23:59:59';}
$sql="SELECT q.id, q.status, q.total, q.created_at, c.name AS client_name FROM quotes q JOIN clients c ON c.id=q.client_id";
if($where){$sql.=' WHERE '.implode(' AND ',$where);} $sql.=' ORDER BY q.created_at DESC LIMIT 100';
$st=$pdo->prepare($sql);$st->execute($p);$rows=$st->fetchAll();
$clients=$pdo->query('SELECT id,name FROM clients ORDER BY name')->fetchAll();
?>
<section>
  <h2>Quotes</h2>
  <form method="get" action="/" style="display:grid;grid-template-columns:1fr 1fr 1fr auto auto;gap:8px;align-items:end;margin:12px 0">
    <input type="hidden" name="page" value="quotes-list">
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
    <a href="/?page=quotes-list" style="padding:8px 12px;border:1px solid #ddd;border-radius:8px;background:#fff;display:inline-block">Reset</a>
  </form>
  <div style="overflow:auto">
    <table style="width:100%;border-collapse:collapse;background:#fff;border-radius:8px;box-shadow:0 6px 18px rgba(11,18,32,0.06)">
      <thead>
        <tr style="text-align:left;border-bottom:1px solid #eee">
          <th style="padding:10px">ID</th>
          <th style="padding:10px">Client</th>
          <th style="padding:10px">Status</th>
          <th style="padding:10px">Total</th>
          <th style="padding:10px">Created</th>
          <th style="padding:10px">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <tr style="border-top:1px solid #f3f4f6">
            <td style="padding:10px">#<?php echo (int)$r['id']; ?></td>
            <td style="padding:10px"><?php echo htmlspecialchars($r['client_name']); ?></td>
            <td style="padding:10px;text-transform:capitalize"><?php echo htmlspecialchars($r['status']); ?></td>
            <td style="padding:10px">$<?php echo number_format((float)$r['total'], 2); ?></td>
            <td style="padding:10px"><?php echo htmlspecialchars($r['created_at']); ?></td>
            <td style="padding:10px;display:flex;gap:8px">
              <a href="/?page=quote-print&id=<?php echo (int)$r['id']; ?>" style="padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff">PDF</a>
              <?php if ($r['status'] === 'pending'): ?>
                <form method="post" action="/?page=quote-approve" onsubmit="return confirm('Approve this quote and generate contract + invoice?')">
                  <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                  <button type="submit" style="padding:6px 10px;border:0;border-radius:8px;background:#d1fae5;color:#065f46">Approve</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
