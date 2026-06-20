<?php
// src/views/pages/contract/long-term-contracts-list.php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/twig.php';
require_once __DIR__ . '/../../../utils/csrf.php';

$client_id = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;
$client_name = trim($_GET['client'] ?? '');
$status = $_GET['status'] ?? '';
$start = $_GET['start'] ?? '';
$end = $_GET['end'] ?? '';
$project_code = trim($_GET['project_code'] ?? '');
$doc_no = isset($_GET['doc_number']) ? (int)$_GET['doc_number'] : 0;
$min_price = isset($_GET['min_price']) ? (float)$_GET['min_price'] : null;
$max_price = isset($_GET['max_price']) ? (float)$_GET['max_price'] : null;

$where=['ltc.contract_type="long_term"'];$p=[];
if($client_id>0){$where[]='ltc.client_id=?';$p[]=$client_id;}
elseif($client_name!==''){ $where[]='c.name LIKE ?'; $p[]='%'.$client_name.'%'; }
if($status!==''){ $where[]='ltc.status=?';$p[] = $status; }
if($start!==''){$where[]='ltc.created_at>=?';$p[]=$start.' 00:00:00';}
if($end!==''){$where[]='ltc.created_at<=?';$p[]=$end.' 23:59:59';}
if($project_code!==''){ $where[]='ltc.project_code LIKE ?'; $p[] = $project_code.'%'; }
if($doc_no>0){ $where[]='ltc.doc_number=?'; $p[] = $doc_no; }
if($min_price !== null){ $where[]='ltc.total>=?'; $p[] = $min_price; }
if($max_price !== null){ $where[]='ltc.total<=?'; $p[] = $max_price; }

$per = (int)($_GET['per_page'] ?? 50); 
if(!in_array($per,[50,100],true)) $per=50;
$pageN = max(1, (int)($_GET['p'] ?? 1));
$offset = ($pageN - 1) * $per;

$sqlCount = 'SELECT COUNT(*) FROM contracts ltc LEFT JOIN clients c ON c.id=ltc.client_id'.($where?' WHERE '.implode(' AND ',$where):'');
$stc=$pdo->prepare($sqlCount);$stc->execute($p);$total=(int)$stc->fetchColumn();

$sql="SELECT ltc.id, ltc.doc_number, ltc.project_code, ltc.status, ltc.total, ltc.deposit_type, ltc.deposit_amount, ltc.deposit_paid, ltc.start_date, ltc.end_date, ltc.billing_interval_count, ltc.billing_interval_unit, ltc.pricing_type, ltc.price_per_invoice, ltc.total_invoiced, ltc.next_invoice_date, c.name client, c.id AS client_id FROM contracts ltc LEFT JOIN clients c ON c.id=ltc.client_id";
if($where){$sql.=' WHERE '.implode(' AND ',$where);} 
$sql.=" ORDER BY ltc.created_at DESC LIMIT $per OFFSET $offset";
$st=$pdo->prepare($sql);$st->execute($p);$rows=$st->fetchAll();

$hasArchived = (bool)$pdo->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='clients' AND COLUMN_NAME='archived'")->fetchColumn();
$clients=$pdo->query('SELECT id,name FROM clients '.($hasArchived?'WHERE archived=0 ':'').'ORDER BY name')->fetchAll();
?>
<section>
  <h2>Long-term Contracts</h2>
  
  <?php if (!empty($_GET['created'])): ?>
    <div class="alert alert-success">Long-term contract created successfully.</div>
  <?php endif; ?>
  <?php if (!empty($_GET['error'])): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars((string)$_GET['error']); ?></div>
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
                  ['value' => '', 'label' => 'All'],
                  ['value' => 'pending', 'label' => 'Pending'],
                  ['value' => 'active', 'label' => 'Active'],
                  ['value' => 'paused', 'label' => 'Paused'],
                  ['value' => 'completed', 'label' => 'Completed'],
                  ['value' => 'cancelled', 'label' => 'Cancelled']
              ]
          ],
                    'start' => [
              'type' => 'date',
              'label' => 'Start',
              'value' => $start
          ],
          'end' => [
              'type' => 'date',
              'label' => 'End',
              'value' => $end
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
          ],
          'doc_number' => [
              'type' => 'number',
              'label' => 'Doc #',
              'value' => $doc_no
          ]
      ]
  ];
  
  // Render the filter using Twig template
  echo render_template('components/document-filter.html.twig', $filterConfig);
  ?>

  <div class="pa-table-wrap">
    <table class="pa-table">
      <thead>
        <tr>
          <th>No.</th>
          <th>Project</th>
          <th>Client</th>
          <th>Status</th>
          <th>Billing</th>
          <th>Amount</th>
          <th>Next Invoice</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
<?php 
  $billingText = $r['billing_interval_count'] . ' ' . ucfirst($r['billing_interval_unit']);
  if ($r['billing_interval_count'] > 1) $billingText .= 's';
  
  $amountText = '';
  if ($r['pricing_type'] === 'per_invoice') {
    $amountText = '$' . number_format((float)$r['price_per_invoice'], 2) . '/inv';
  } else {
    $amountText = '$' . number_format((float)$r['total'], 2) . ' total';
  }
?>
          <tr>
            <td><a href="/?page=contract/long-term-contract-details&id=<?php echo (int)$r['id']; ?>" style="text-decoration:none;color:inherit">LTC-<?php echo (int)($r['doc_number'] ?? $r['id']); ?></a></td>
            <td><?php echo htmlspecialchars($r['project_code'] ?? ''); ?></td>
            <td><a href="/?page=client/clients-list&selected_client_id=<?php echo (int)$r['client_id']; ?>"><?php echo htmlspecialchars($r['client']); ?></a></td>
            <td><span class="status-pill status-pill--<?php echo htmlspecialchars($r['status']); ?>"><?php echo htmlspecialchars($r['status']); ?></span></td>
            <td><?php echo htmlspecialchars($billingText); ?></td>
            <td><?php echo htmlspecialchars($amountText); ?></td>
            <td><?php echo $r['next_invoice_date'] ? date('M j, Y', strtotime($r['next_invoice_date'])) : '—'; ?></td>
            <td class="flex flex-wrap" style="align-items:center">
              <a href="/?page=contract/long-term-contract-details&id=<?php echo (int)$r['id']; ?>" class="btn btn-sm">View</a>
              <?php if ($r['status'] === 'pending'): ?>
                <form method="post" action="/?page=long-term-contract-activate" style="display:inline">
                  <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                  <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                  <button type="submit" class="btn btn-sm" style="background:#10b981;color:#fff">Activate</button>
                </form>
              <?php endif; ?>
              <?php if ($r['status'] === 'active'): ?>
                <form method="post" action="/?page=long-term-contract-pause" style="display:inline">
                  <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                  <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                  <button type="submit" class="btn btn-sm" style="background:#f59e0b;color:#fff">Pause</button>
                </form>
              <?php elseif ($r['status'] === 'paused'): ?>
                <form method="post" action="/?page=long-term-contract-resume" style="display:inline">
                  <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                  <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                  <button type="submit" class="btn btn-sm" style="background:#10b981;color:#fff">Resume</button>
                </form>
              <?php endif; ?>
              <?php if (in_array($r['status'], ['pending', 'active', 'paused'], true)): ?>
                <form method="post" action="/?page=long-term-contract-terminate" style="display:inline" onsubmit="return confirm('Terminate this long-term contract? This will stop future invoicing.')">
                  <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                  <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                  <button type="submit" class="btn btn-sm" style="background:#dc2626;color:#fff">Terminate</button>
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
  <div class="flex-between" style="margin-top:12px">
    <div>
      <form method="get" action="/">
        <?php foreach($_GET as $k=>$v){ if($k==='per_page'||$k==='p'||$k==='page') continue; echo '<input type="hidden" name="'.htmlspecialchars($k).'\" value="'.htmlspecialchars($v).'">'; }
        ?>
        <input type="hidden" name="page" value="contract/long-term-contracts-list">
        <label>Per page
          <select name="per_page" onchange="this.form.submit()" class="input-sm">
            <option value="50" <?php echo $per===50?'selected':''; ?>>50</option>
            <option value="100" <?php echo $per===100?'selected':''; ?>>100</option>
          </select>
        </label>
      </form>
    </div>
    <div class="flex">
      <?php if($pageN>1): ?><a href="<?php echo $base.'&p='.($pageN-1); ?>" class="btn btn-sm">Prev</a><?php endif; ?>
      <div class="btn btn-sm muted">Page <?php echo $pageN; ?> / <?php echo $last; ?></div>
      <?php if($pageN<$last): ?><a href="<?php echo $base.'&p='.($pageN+1); ?>" class="btn btn-sm">Next</a><?php endif; ?>
    </div>
  </div>
</section>
