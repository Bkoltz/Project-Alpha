<?php
// src/views/pages/contract/long-term-contracts-list.php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/csrf.php';

// Ensure the optional long_term_contracts table exists before querying
$has_long_term_table = (bool)$pdo->query("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='long_term_contracts'")->fetchColumn();
if (!$has_long_term_table) {
  echo '<section><h2>Long-term Contracts</h2><div style="margin:10px 0;padding:12px;background:#fff3cd;border:1px solid #ffc107;border-radius:8px;color:#856404">Long-term contracts are not available because the database table <code>long_term_contracts</code> is missing. Run the migrations or contact your administrator to enable this feature.</div></section>';
  return;
}

$client_id = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;
$client_name = trim($_GET['client'] ?? '');
$status = $_GET['status'] ?? '';
$project_code = trim($_GET['project_code'] ?? '');
$min_price = isset($_GET['min_price']) ? (float)$_GET['min_price'] : null;
$max_price = isset($_GET['max_price']) ? (float)$_GET['max_price'] : null;

$where=[];$p=[];
if($client_id>0){$where[]='ltc.client_id=?';$p[]=$client_id;}
elseif($client_name!==''){ $where[]='c.name LIKE ?'; $p[]='%'.$client_name.'%'; }
if($status!==''){ $where[]='ltc.status=?'; $p[] = $status; }
if($project_code!==''){ $where[]='ltc.project_code LIKE ?'; $p[] = $project_code.'%'; }
if($min_price !== null){ $where[]='ltc.total>=?'; $p[] = $min_price; }
if($max_price !== null){ $where[]='ltc.total<=?'; $p[] = $max_price; }

$per = (int)($_GET['per_page'] ?? 50); 
if(!in_array($per,[50,100],true)) $per=50;
$pageN = max(1, (int)($_GET['p'] ?? 1));
$offset = ($pageN - 1) * $per;

$sqlCount = 'SELECT COUNT(*) FROM long_term_contracts ltc LEFT JOIN clients c ON c.id=ltc.client_id'.($where?' WHERE '.implode(' AND ',$where):'');
$stc=$pdo->prepare($sqlCount);$stc->execute($p);$total=(int)$stc->fetchColumn();

$sql="SELECT ltc.id, ltc.doc_number, ltc.project_code, ltc.status, ltc.total, ltc.deposit_type, ltc.deposit_amount, ltc.deposit_paid, ltc.start_date, ltc.end_date, ltc.billing_interval_count, ltc.billing_interval_unit, ltc.pricing_type, ltc.price_per_invoice, ltc.total_invoiced, ltc.next_invoice_date, c.name client, c.id AS client_id FROM long_term_contracts ltc LEFT JOIN clients c ON c.id=ltc.client_id";
if($where){$sql.=' WHERE '.implode(' AND ',$where);} 
$sql.=" ORDER BY ltc.created_at DESC LIMIT $per OFFSET $offset";
$st=$pdo->prepare($sql);$st->execute($p);$rows=$st->fetchAll();

$hasArchived = (bool)$pdo->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='clients' AND COLUMN_NAME='archived'")->fetchColumn();
$clients=$pdo->query('SELECT id,name FROM clients '.($hasArchived?'WHERE archived=0 ':'').'ORDER BY name')->fetchAll();
?>
<section>
  <h2>Long-term Contracts</h2>
  
  <?php if (!empty($_GET['created'])): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0">Long-term contract created successfully.</div>
  <?php endif; ?>
  <?php if (!empty($_GET['error'])): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#fff1f2;color:#881337;border:1px solid #fca5a5"><?php echo htmlspecialchars((string)$_GET['error']); ?></div>
  <?php endif; ?>

  <!-- <div style="display:flex;gap:12px;margin:16px 0">
    <a href="/?page=contract/contracts-list" style="padding:8px 14px;border:1px solid #ddd;border-radius:8px;background:#fff;text-decoration:none;color:inherit">Regular Contracts</a>
    <a href="/?page=contract/long-term-contracts-list" style="padding:8px 14px;border:0;border-radius:8px;background:var(--nav-accent);color:#fff;text-decoration:none;font-weight:600">Long-term Contracts</a>
  </div> -->

  <?php
  $filterConfig = [
      'page' => 'contract/long-term-contracts-list',
      'filters' => [
          'client' => [
              'type' => 'client_autocomplete',
              'label' => 'Client',
              'value' => $client_name,
              'id_value' => $client_id
          ],
          'status' => [
              'type' => 'select',
              'label' => 'Status',
              'value' => $status,
              'options' => [
                  '' => 'All',
                  'pending' => 'Pending',
                  'active' => 'Active',
                  'paused' => 'Paused',
                  'completed' => 'Completed',
                  'cancelled' => 'Cancelled'
              ]
          ],
          'min_price' => [
              'type' => 'number',
              'label' => 'Min ($)',
              'value' => $min_price ?? '',
              'step' => '0.01'
          ],
          'max_price' => [
              'type' => 'number',
              'label' => 'Max ($)',
              'value' => $max_price ?? '',
              'step' => '0.01'
          ],
          'project_code' => [
              'type' => 'text',
              'label' => 'Project ID',
              'value' => $project_code,
              'placeholder' => 'PA-2025'
          ]
      ]
  ];
  require __DIR__ . '/../../../components/document_list_filter.php';
  ?>

  <div style="overflow:auto">
    <table style="width:100%;border-collapse:collapse;background:#fff;border-radius:8px;box-shadow:0 6px 18px rgba(11,18,32,0.06)">
      <thead>
        <tr style="text-align:left;border-bottom:1px solid #eee">
          <th style="padding:10px">No.</th>
          <th style="padding:10px">Project</th>
          <th style="padding:10px">Client</th>
          <th style="padding:10px">Status</th>
          <th style="padding:10px">Billing</th>
          <th style="padding:10px">Amount</th>
          <th style="padding:10px">Next Invoice</th>
          <th style="padding:10px">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
<?php 
  $rowStyle = ($r['status']==='active') ? 'background:#fffbeb;' : (($r['status']==='completed') ? 'background:#ecfdf5;' : ((in_array($r['status'], ['cancelled','paused'], true)) ? 'background:#fef2f2;' : ''));
  
  $billingText = $r['billing_interval_count'] . ' ' . ucfirst($r['billing_interval_unit']);
  if ($r['billing_interval_count'] > 1) $billingText .= 's';
  
  $amountText = '';
  if ($r['pricing_type'] === 'per_invoice') {
    $amountText = '$' . number_format((float)$r['price_per_invoice'], 2) . '/inv';
  } else {
    $amountText = '$' . number_format((float)$r['total'], 2) . ' total';
  }
?>
          <tr style="border-top:1px solid #f3f4f6;<?php echo $rowStyle; ?>">
            <td style="padding:10px"><a href="/?page=contract/long-term-contract-details&id=<?php echo (int)$r['id']; ?>" style="text-decoration:none;color:inherit">LTC-<?php echo (int)($r['doc_number'] ?? $r['id']); ?></a></td>
            <td style="padding:10px"><?php echo htmlspecialchars($r['project_code'] ?? ''); ?></td>
            <td style="padding:10px"><a href="/?page=client/clients-list&selected_client_id=<?php echo (int)$r['client_id']; ?>"><?php echo htmlspecialchars($r['client']); ?></a></td>
            <td style="padding:10px;text-transform:capitalize"><?php echo htmlspecialchars($r['status']); ?></td>
            <td style="padding:10px"><?php echo htmlspecialchars($billingText); ?></td>
            <td style="padding:10px"><?php echo htmlspecialchars($amountText); ?></td>
            <td style="padding:10px"><?php echo $r['next_invoice_date'] ? date('M j, Y', strtotime($r['next_invoice_date'])) : '—'; ?></td>
            <td style="padding:10px;display:flex;flex-wrap:wrap;gap:8px;align-items:center">
              <a href="/?page=contract/long-term-contract-details&id=<?php echo (int)$r['id']; ?>" style="padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: small;">View</a>
              <?php if ($r['status'] === 'pending'): ?>
                <form method="post" action="/?page=long-term-contract-activate" style="display:inline">
                  <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                  <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                  <button type="submit" style="padding:6px 10px;border:0;border-radius:8px;background:#10b981;color:#fff; font-size: small;">Activate</button>
                </form>
              <?php endif; ?>
              <?php if ($r['status'] === 'active'): ?>
                <form method="post" action="/?page=long-term-contract-pause" style="display:inline">
                  <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                  <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                  <button type="submit" style="padding:6px 10px;border:0;border-radius:8px;background:#f59e0b;color:#fff; font-size: small;">Pause</button>
                </form>
              <?php elseif ($r['status'] === 'paused'): ?>
                <form method="post" action="/?page=long-term-contract-resume" style="display:inline">
                  <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                  <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                  <button type="submit" style="padding:6px 10px;border:0;border-radius:8px;background:#10b981;color:#fff; font-size: small;">Resume</button>
                </form>
              <?php endif; ?>
              <?php if (in_array($r['status'], ['pending', 'active', 'paused'], true)): ?>
                <form method="post" action="/?page=long-term-contract-terminate" style="display:inline" onsubmit="return confirm('Terminate this long-term contract? This will stop future invoicing.')">
                  <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                  <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                  <button type="submit" style="padding:6px 10px;border:0;border-radius:8px;background:#dc2626;color:#fff; font-size: small;">Terminate</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php
    $last=(int)ceil(max(1,$total)/$per);
    $qs=$_GET; unset($qs['p']); $base='/?'.http_build_query($qs+['page'=>'contract/long-term-contracts-list','per_page'=>$per]);
  ?>
  <div style="margin-top:12px;display:flex;justify-content:space-between;align-items:center">
    <div>
      <form method="get" action="/">
        <?php foreach($_GET as $k=>$v){ if($k==='per_page'||$k==='p'||$k==='page') continue; echo '<input type="hidden" name="'.htmlspecialchars($k).'\" value="'.htmlspecialchars($v).'">'; }
        ?>
        <input type="hidden" name="page" value="contract/long-term-contracts-list">
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
