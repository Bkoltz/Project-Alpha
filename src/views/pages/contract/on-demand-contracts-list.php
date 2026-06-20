<?php
// src/views/pages/contract/on-demand-contracts-list.php
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

$where=['odc.contract_type="on_demand"'];$p=[];
if($client_id>0){$where[]='odc.client_id=?';$p[]=$client_id;}
elseif($client_name!==''){ $where[]='c.name LIKE ?'; $p[]='%'.$client_name.'%'; }
if($status!==''){ $where[]='odc.status=?';$p[] = $status; }
if($start!==''){$where[]='odc.created_at>=?';$p[]=$start.' 00:00:00';}
if($end!==''){$where[]='odc.created_at<=?';$p[]=$end.' 23:59:59';}
if($project_code!==''){ $where[]='odc.project_code LIKE ?'; $p[] = $project_code.'%'; }
if($doc_no>0){ $where[]='odc.doc_number=?'; $p[] = $doc_no; }
if($min_price !== null){ $where[]='odc.price_per_invoice>=?'; $p[] = $min_price; }
if($max_price !== null){ $where[]='odc.price_per_invoice<=?'; $p[] = $max_price; }

$per = (int)($_GET['per_page'] ?? 50); 
if(!in_array($per,[50,100],true)) $per=50;
$pageN = max(1, (int)($_GET['p'] ?? 1));
$offset = ($pageN - 1) * $per;

$sqlCount = 'SELECT COUNT(*) FROM contracts odc LEFT JOIN clients c ON c.id=odc.client_id'.($where?' WHERE '.implode(' AND ',$where):'');
$stc=$pdo->prepare($sqlCount);$stc->execute($p);$total=(int)$stc->fetchColumn();

$sql="SELECT odc.id, odc.doc_number, odc.project_code, odc.status, odc.start_date, odc.end_date, odc.billing_interval_count, odc.billing_interval_unit, odc.price_per_invoice, odc.total_invoiced, odc.invoice_count, odc.last_invoice_date, c.name client, c.id AS client_id FROM contracts odc LEFT JOIN clients c ON c.id=odc.client_id";
if($where){$sql.=' WHERE '.implode(' AND ',$where);} 
$sql.=" ORDER BY odc.created_at DESC LIMIT $per OFFSET $offset";
$st=$pdo->prepare($sql);$st->execute($p);$rows=$st->fetchAll();

$hasArchived = (bool)$pdo->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='clients' AND COLUMN_NAME='archived'")->fetchColumn();
$clients=$pdo->query('SELECT id,name FROM clients '.($hasArchived?'WHERE archived=0 ':'').'ORDER BY name')->fetchAll();
?>
<section>
  <h2>On-Demand Contracts</h2>
  
  <?php if (!empty($_GET['created'])): ?>
    <div class="alert alert-success">On-demand contract created successfully.</div>
  <?php endif; ?>
  <?php if (!empty($_GET['invoice_generated'])): ?>
    <div class="alert alert-success">Invoice generated successfully.</div>
  <?php endif; ?>
  <?php if (!empty($_GET['activated'])): ?>
    <div class="alert alert-success">Contract activated.</div>
  <?php endif; ?>
  <?php if (!empty($_GET['error'])): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars((string)$_GET['error']); ?></div>
  <?php endif; ?>

  <?php
  $filterConfig = [
      'page' => 'contract/on-demand-contracts-list',
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
          <th>Price/Invoice</th>
          <th>Invoices</th>
          <th>Last Invoice</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
<?php 
  $billingText = $r['billing_interval_count'] . ' ' . ucfirst($r['billing_interval_unit']);
  if ($r['billing_interval_count'] > 1) $billingText .= 's';
?>
          <tr>
            <td>ODC-<?php echo (int)($r['doc_number'] ?? $r['id']); ?></td>
            <td><?php echo htmlspecialchars($r['project_code'] ?? ''); ?></td>
            <td><a href="/?page=client/clients-list&selected_client_id=<?php echo (int)$r['client_id']; ?>"><?php echo htmlspecialchars($r['client']); ?></a></td>
            <td><span class="status-pill status-pill--<?php echo htmlspecialchars($r['status']); ?>"><?php echo htmlspecialchars($r['status']); ?></span></td>
            <td><?php echo htmlspecialchars($billingText); ?></td>
            <td>$<?php echo number_format((float)$r['price_per_invoice'], 2); ?></td>
            <td><?php echo (int)$r['invoice_count']; ?> ($<?php echo number_format((float)$r['total_invoiced'], 2); ?>)</td>
            <td><?php echo $r['last_invoice_date'] ? date('M j, Y', strtotime($r['last_invoice_date'])) : '—'; ?></td>
            <td class="flex flex-wrap" style="align-items:center">
              <a href="/?page=contract/on-demand-invoices-list&contract_id=<?php echo (int)$r['id']; ?>" class="btn btn-sm">Invoices</a>
              <?php if ($r['status'] === 'pending'): ?>
                <form method="post" action="/?page=on-demand-contract-activate" style="display:inline">
                  <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                  <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                  <button type="submit" class="btn btn-sm" style="background:#10b981;color:#fff">Activate</button>
                </form>
              <?php endif; ?>
              <?php if ($r['status'] === 'active'): ?>
                <?php 
                  // Check if contract has ended
                  $canGenerate = true;
                  if (!empty($r['end_date']) && $r['end_date'] < date('Y-m-d')) {
                    $canGenerate = false;
                  }
                ?>
                <?php if ($canGenerate): ?>
                <form method="post" action="/?page=on-demand-invoice-generate" style="display:inline" onsubmit="return confirm('Generate invoice for this contract?')">
                  <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                  <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                  <button type="submit" class="btn btn-sm" style="background:#3b82f6;color:#fff">Generate Invoice</button>
                </form>
                <?php endif; ?>
                <form method="post" action="/?page=on-demand-contract-pause" style="display:inline">
                  <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                  <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                  <button type="submit" class="btn btn-sm" style="background:#f59e0b;color:#fff">Pause</button>
                </form>
              <?php elseif ($r['status'] === 'paused'): ?>
                <form method="post" action="/?page=on-demand-contract-resume" style="display:inline">
                  <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                  <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                  <button type="submit" class="btn btn-sm" style="background:#10b981;color:#fff">Resume</button>
                </form>
              <?php endif; ?>
              <?php if (in_array($r['status'], ['pending', 'active', 'paused'], true)): ?>
                <form method="post" action="/?page=on-demand-contract-terminate" style="display:inline" onsubmit="return confirm('Terminate this on-demand contract?')">
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
    $qs=$_GET; unset($qs['p']); $base='/?'.http_build_query($qs+['page'=>'contract/on-demand-contracts-list','per_page'=>$per]);
  ?>
  <div class="flex-between" style="margin-top:12px">
    <div>
      <form method="get" action="/">
        <?php foreach($_GET as $k=>$v){ if($k==='per_page'||$k==='p'||$k==='page') continue; echo '<input type="hidden" name="'.htmlspecialchars($k).'" value="'.htmlspecialchars($v).'">'; }
        ?>
        <input type="hidden" name="page" value="contract/on-demand-contracts-list">
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
