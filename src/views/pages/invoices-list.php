<?php
// src/views/pages/invoices-list.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
$netDays = (int)($appConfig['net_terms_days'] ?? 30);
if ($netDays < 0) $netDays = 0;

$client_id = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;
$min = isset($_GET['min']) ? (float)$_GET['min'] : null;
$max = isset($_GET['max']) ? (float)$_GET['max'] : null;
$statusFilter = $_GET['status'] ?? 'all'; // all|paid|unpaid|overdue
$project_code = trim($_GET['project_code'] ?? '');
$doc_no = isset($_GET['doc_number']) ? (int)$_GET['doc_number'] : 0;

$where = [];
$params = [];
if ($client_id > 0) {
  $where[] = 'i.client_id=?';
  $params[] = $client_id;
}
if ($min !== null) {
  $where[] = 'i.total>=?';
  $params[] = $min;
}
if ($max !== null) {
  $where[] = 'i.total<=?';
  $params[] = $max;
}
if ($statusFilter === 'paid') {
  $where[] = "i.status='paid'";
} elseif ($statusFilter === 'unpaid') {
  $where[] = "i.status IN ('unpaid','partial')";
} elseif ($statusFilter === 'overdue') {
  // overdue = not paid AND (due_date < today OR (due_date IS NULL AND created_at < (today - netDays)))
  $where[] = "i.status IN ('unpaid','partial') AND (
    (i.due_date IS NOT NULL AND i.due_date < CURDATE()) OR
    (i.due_date IS NULL AND i.created_at < ?)
  )";
  $params[] = date('Y-m-d', strtotime('-'.$netDays.' days'));
}
if ($project_code !== '') {
  $where[] = 'i.project_code LIKE ?';
  $params[] = $project_code.'%';
}
if ($doc_no > 0) {
  $where[] = 'i.doc_number=?';
  $params[] = $doc_no;
}

$per = (int)($_GET['per_page'] ?? 50); if(!in_array($per,[50,100],true)) $per=50;
$pageN = max(1, (int)($_GET['p'] ?? 1));
$offset = ($pageN - 1) * $per;

$sqlCount = 'SELECT COUNT(*) FROM invoices i'.($where ? ' JOIN clients c ON c.id=i.client_id WHERE '.implode(' AND ', $where) : '');
$stc = $pdo->prepare($sqlCount);
$stc->execute($params);
$total = (int)$stc->fetchColumn();

$sql = 'SELECT i.id,i.doc_number,i.project_code,i.total,i.status,i.created_at,i.due_date,c.name client,c.id AS client_id FROM invoices i JOIN clients c ON c.id=i.client_id';
if ($where) {
  $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= " ORDER BY i.created_at DESC LIMIT $per OFFSET $offset";

$rows = $pdo->prepare($sql);
$rows->execute($params);
$rows = $rows->fetchAll();
$hasArchived = (bool)$pdo->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='clients' AND COLUMN_NAME='archived'")->fetchColumn();
$clients = $pdo->query('SELECT id,name FROM clients '.($hasArchived?'WHERE archived=0 ':'').'ORDER BY name')->fetchAll();
?>
<section>
  <h2>Invoices</h2>

  <form method="get" action="/" style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr 1fr 1fr auto auto;gap:8px;align-items:end;margin:12px 0">
    <input type="hidden" name="page" value="invoices-list">
    <label>
      <div>Client</div>
      <select name="client_id" style="padding:8px;border-radius:8px;border:1px solid #ddd">
        <option value="0">All</option>
        <?php foreach ($clients as $c): ?>
          <option value="<?php echo (int)$c['id']; ?>" <?php echo $client_id == (int)$c['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['name']); ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>
      <div>Status</div>
      <select name="status" style="padding:8px;border-radius:8px;border:1px solid #ddd">
        <?php $sf = htmlspecialchars($statusFilter); ?>
        <option value="all" <?php echo $sf==='all'?'selected':''; ?>>All</option>
        <option value="paid" <?php echo $sf==='paid'?'selected':''; ?>>Paid</option>
        <option value="unpaid" <?php echo $sf==='unpaid'?'selected':''; ?>>Unpaid/Partial</option>
        <option value="overdue" <?php echo $sf==='overdue'?'selected':''; ?>>Overdue</option>
      </select>
    </label>
    <label>
      <div>Min Total ($)</div>
      <input type="number" step="0.01" name="min" value="<?php echo htmlspecialchars($_GET['min'] ?? ''); ?>" style="padding:8px;border-radius:8px;border:1px solid #ddd">
    </label>
    <label>
      <div>Max Total ($)</div>
      <input type="number" step="0.01" name="max" value="<?php echo htmlspecialchars($_GET['max'] ?? ''); ?>" style="padding:8px;border-radius:8px;border:1px solid #ddd">
    </label>
    <label>
      <div>Project</div>
      <input type="text" name="project_code" value="<?php echo htmlspecialchars($project_code); ?>" placeholder="PA-2025" style="padding:8px;border-radius:8px;border:1px solid #ddd">
    </label>
    <label>
      <div>Doc #</div>
      <input type="number" name="doc_number" value="<?php echo htmlspecialchars($_GET['doc_number'] ?? ''); ?>" style="padding:8px;border-radius:8px;border:1px solid #ddd">
    </label>
    <button type="submit" style="padding:8px 12px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: small;">Filter</button>
    <a href="/?page=invoices-list" style="padding:8px 12px;border:1px solid #ddd;border-radius:8px;background:#fff;display:inline-block; font-size: small;">Reset</a>
  </form>

  <div style="overflow:auto">
    <table style="width:100%;border-collapse:collapse;background:#fff;border-radius:8px;box-shadow:0 6px 18px rgba(11,18,32,0.06)">
      <thead>
        <tr style="text-align:left;border-bottom:1px solid #eee">
          <th style="padding:10px">No.</th>
          <th style="padding:10px">Project</th>
          <th style="padding:10px">Client</th>
          <th style="padding:10px">Total</th>
          <th style="padding:10px">Status</th>
          <th style="padding:10px">Created</th>
          <th style="padding:10px">Due</th>
          <th style="padding:10px">Actions</th>
          <th style="padding:10px">Edit</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <?php
          $rowStyle = '';
          $status = $r['status'];
          if ($status === 'paid') {
            $rowStyle = 'background:#ecfdf5;';
          } else {
            $today = strtotime('today');
            $due = isset($r['due_date']) && $r['due_date'] ? strtotime($r['due_date']) : null;
            if ($due === null) {
              // NET terms from settings
              $due = strtotime('+'.$netDays.' days', strtotime($r['created_at']));
            }
            if ($due < $today) {
              $rowStyle = 'background:#fef2f2;'; // red overdue
            } else {
              $rowStyle = 'background:#fffbeb;'; // yellow within net
            }
          }
          ?>
          <tr style="border-top:1px solid #f3f4f6;<?php echo $rowStyle; ?>">
            <td style="padding:10px">I-<?php echo (int)($r['doc_number'] ?? $r['id']); ?></td>
            <td style="padding:10px"><?php echo htmlspecialchars($r['project_code'] ?? ''); ?></td>
            <td style="padding:10px"><a href="/?page=clients-list&selected_client_id=<?php echo (int)$r['client_id']; ?>"><?php echo htmlspecialchars($r['client']); ?></a></td>
            <td style="padding:10px">$<?php echo number_format((float)$r['total'], 2); ?></td>
            <td style="padding:10px;text-transform:capitalize"><?php echo htmlspecialchars($r['status']); ?></td>
            <td style="padding:10px"><?php echo htmlspecialchars($r['created_at']); ?></td>
            <td style="padding:10px"><?php echo htmlspecialchars($r['due_date'] ?? ''); ?></td>
            <td style="padding:10px">
              <a href="/?page=invoice-print&id=<?php echo (int)$r['id']; ?>" style="padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff;margin-right:6px; font-size: medium;">PDF</a>
              <form method="post" action="/?page=email-send" style="display:inline;margin-right:6px">
                <input type="hidden" name="type" value="invoice">
                <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                <button type="submit" style="padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: medium;">Email</button>
              </form>
              <?php if ($r['status'] !== 'paid'): ?>
                <form method="post" action="/?page=invoices-mark-paid" onsubmit="return confirm('Mark invoice paid?')" style="display:inline">
                  <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                  <button type="submit" style="padding:6px 10px;border:0;border-radius:8px;background:#d1fae5;color:#065f46">Paid</button>
                </form>
              <?php endif; ?>
            </td>
            <td style="padding:10px"><a href="/?page=invoices-edit&id=<?php echo (int)$r['id']; ?>" style="padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: medium;">Edit</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php
    $last = (int)ceil(max(1,$total)/$per);
    $qs = $_GET; unset($qs['p']); $base='/?'.http_build_query($qs+['page'=>'invoices-list','per_page'=>$per]);
  ?>
  <div style="margin-top:12px;display:flex;justify-content:space-between;align-items:center">
    <div>
      <form method="get" action="/">
        <?php foreach ($_GET as $k=>$v){ if($k==='per_page'||$k==='p'||$k==='page') continue; echo '<input type="hidden" name="'.htmlspecialchars($k).'" value="'.htmlspecialchars($v).'">'; }
        ?>
        <input type="hidden" name="page" value="invoices-list">
        <label>Per page
          <select name="per_page" onchange="this.form.submit()" style="padding:6px;border-radius:8px;border:1px solid #ddd">
            <option value="50" <?php echo $per===50?'selected':''; ?>>50</option>
            <option value="100" <?php echo $per===100?'selected':''; ?>>100</option>
          </select>
        </label>
      </form>
    </div>
    <div style="display:flex;gap:8px">
      <?php if($pageN>1): ?><a href="<?php echo $base.'&p='.($pageN-1); ?>" style="padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff">Prev</a><?php endif; ?>
      <div style="padding:6px 10px;color:var(--muted)">Page <?php echo $pageN; ?> / <?php echo $last; ?></div>
      <?php if($pageN<$last): ?><a href="<?php echo $base.'&p='.($pageN+1); ?>" style="padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff">Next</a><?php endif; ?>
    </div>
  </div>
</section>
