<?php
// src/views/pages/contract/on-demand-contracts-list.php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/csrf.php';

$client_id = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;
$client_name = trim($_GET['client'] ?? '');
$status = $_GET['status'] ?? '';
$project_code = trim($_GET['project_code'] ?? '');

$where=[];$p=[];
if($client_id>0){$where[]='odc.client_id=?';$p[]=$client_id;}
elseif($client_name!==''){ $where[]='c.name LIKE ?'; $p[]='%'.$client_name.'%'; }
if($status!==''){ $where[]='odc.status=?'; $p[] = $status; }
if($project_code!==''){ $where[]='odc.project_code LIKE ?'; $p[] = $project_code.'%'; }

$per = (int)($_GET['per_page'] ?? 50); 
if(!in_array($per,[50,100],true)) $per=50;
$pageN = max(1, (int)($_GET['p'] ?? 1));
$offset = ($pageN - 1) * $per;

$sqlCount = 'SELECT COUNT(*) FROM on_demand_contracts odc LEFT JOIN clients c ON c.id=odc.client_id'.($where?' WHERE '.implode(' AND ',$where):'');
$stc=$pdo->prepare($sqlCount);$stc->execute($p);$total=(int)$stc->fetchColumn();

$sql="SELECT odc.id, odc.doc_number, odc.project_code, odc.status, odc.start_date, odc.end_date, odc.billing_interval_count, odc.billing_interval_unit, odc.price_per_invoice, odc.total_invoiced, odc.invoice_count, odc.last_invoice_date, c.name client, c.id AS client_id FROM on_demand_contracts odc LEFT JOIN clients c ON c.id=odc.client_id";
if($where){$sql.=' WHERE '.implode(' AND ',$where);} 
$sql.=" ORDER BY odc.created_at DESC LIMIT $per OFFSET $offset";
$st=$pdo->prepare($sql);$st->execute($p);$rows=$st->fetchAll();

$hasArchived = (bool)$pdo->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='clients' AND COLUMN_NAME='archived'")->fetchColumn();
$clients=$pdo->query('SELECT id,name FROM clients '.($hasArchived?'WHERE archived=0 ':'').'ORDER BY name')->fetchAll();
?>
<section>
  <h2>On-Demand Contracts</h2>
  
  <?php if (!empty($_GET['created'])): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0">On-demand contract created successfully.</div>
  <?php endif; ?>
  <?php if (!empty($_GET['invoice_generated'])): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0">Invoice generated successfully.</div>
  <?php endif; ?>
  <?php if (!empty($_GET['activated'])): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0">Contract activated.</div>
  <?php endif; ?>
  <?php if (!empty($_GET['error'])): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#fff1f2;color:#881337;border:1px solid #fca5a5"><?php echo htmlspecialchars((string)$_GET['error']); ?></div>
  <?php endif; ?>

  <form method="get" action="/" style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr auto auto;gap:8px;align-items:end;margin:12px 0;position:relative">
    <input type="hidden" name="page" value="contract/on-demand-contracts-list">
    <input type="hidden" name="client_id" id="clientIdODC" value="<?php echo (int)$client_id; ?>">
    <label style="position:relative"><div>Client</div>
      <input type="text" name="client" id="clientInputODC" value="<?php echo htmlspecialchars($client_name); ?>" placeholder="Type client name..." style="padding:8px;border-radius:8px;border:1px solid #ddd">
      <div id="clientSuggestODC" style="position:absolute;z-index:60;left:0;right:0;top:100%;background:#fff;border:1px solid #eee;border-radius:8px;display:none;max-height:200px;overflow:auto"></div>
    </label>
    <label><div>Project ID</div><input type="text" name="project_code" value="<?php echo htmlspecialchars($project_code); ?>" placeholder="PA-2025" style="padding:8px;border-radius:8px;border:1px solid #ddd"></label>
    <label><div>Status</div>
      <select name="status" style="padding:8px;border-radius:8px;border:1px solid #ddd">
        <option value="">All</option>
        <option value="pending" <?php echo $status==='pending'?'selected':''; ?>>Pending</option>
        <option value="active" <?php echo $status==='active'?'selected':''; ?>>Active</option>
        <option value="paused" <?php echo $status==='paused'?'selected':''; ?>>Paused</option>
        <option value="completed" <?php echo $status==='completed'?'selected':''; ?>>Completed</option>
        <option value="cancelled" <?php echo $status==='cancelled'?'selected':''; ?>>Cancelled</option>
      </select>
    </label>
    <div style="display:flex;gap:8px">
      <button type="submit" style="padding:8px 12px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: small;">Filter</button>
      <a href="/?page=contract/on-demand-contracts-list" style="padding:8px 12px;border:1px solid #ddd;border-radius:8px;background:#fff;display:inline-block; font-size: small;text-decoration:none;color:inherit">Reset</a>
    </div>
  </form>

  <script>
    (function(){
      var input = document.getElementById('clientInputODC');
      var hid = document.getElementById('clientIdODC');
      var sug = document.getElementById('clientSuggestODC');
      input.addEventListener('input', function(){
        hid.value='';
        var t=this.value.trim(); if(!t){sug.style.display='none';sug.innerHTML='';return;}
        fetch('/?page=clients-search&term='+encodeURIComponent(t)).then(r=>r.json()).then(list=>{
          if(!Array.isArray(list)||list.length===0){sug.style.display='none';sug.innerHTML='';return;}
          sug.innerHTML = list.map(x=>`<div data-id="${x.id}" data-name="${x.name}" style=\"padding:8px 10px;cursor:pointer\">${x.name}</div>`).join('');
          Array.from(sug.children).forEach(el=>{ el.addEventListener('click', function(){ input.value=this.dataset.name; hid.value=this.dataset.id; sug.style.display='none'; }); });
          sug.style.display='block';
        }).catch(()=>{sug.style.display='none'});
      });
      document.addEventListener('click', function(e){ if(!sug.contains(e.target) && e.target!==input){ sug.style.display='none'; } });
    })();
  </script>

  <div style="overflow:auto">
    <table style="width:100%;border-collapse:collapse;background:#fff;border-radius:8px;box-shadow:0 6px 18px rgba(11,18,32,0.06)">
      <thead>
        <tr style="text-align:left;border-bottom:1px solid #eee">
          <th style="padding:10px">No.</th>
          <th style="padding:10px">Project</th>
          <th style="padding:10px">Client</th>
          <th style="padding:10px">Status</th>
          <th style="padding:10px">Billing</th>
          <th style="padding:10px">Price/Invoice</th>
          <th style="padding:10px">Invoices</th>
          <th style="padding:10px">Last Invoice</th>
          <th style="padding:10px">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
<?php 
  $rowStyle = ($r['status']==='active') ? 'background:#fffbeb;' : (($r['status']==='completed') ? 'background:#ecfdf5;' : ((in_array($r['status'], ['cancelled','paused'], true)) ? 'background:#fef2f2;' : ''));
  
  $billingText = $r['billing_interval_count'] . ' ' . ucfirst($r['billing_interval_unit']);
  if ($r['billing_interval_count'] > 1) $billingText .= 's';
?>
          <tr style="border-top:1px solid #f3f4f6;<?php echo $rowStyle; ?>">
            <td style="padding:10px">ODC-<?php echo (int)($r['doc_number'] ?? $r['id']); ?></td>
            <td style="padding:10px"><?php echo htmlspecialchars($r['project_code'] ?? ''); ?></td>
            <td style="padding:10px"><a href="/?page=client/clients-list&selected_client_id=<?php echo (int)$r['client_id']; ?>"><?php echo htmlspecialchars($r['client']); ?></a></td>
            <td style="padding:10px;text-transform:capitalize"><?php echo htmlspecialchars($r['status']); ?></td>
            <td style="padding:10px"><?php echo htmlspecialchars($billingText); ?></td>
            <td style="padding:10px">$<?php echo number_format((float)$r['price_per_invoice'], 2); ?></td>
            <td style="padding:10px"><?php echo (int)$r['invoice_count']; ?> ($<?php echo number_format((float)$r['total_invoiced'], 2); ?>)</td>
            <td style="padding:10px"><?php echo $r['last_invoice_date'] ? date('M j, Y', strtotime($r['last_invoice_date'])) : '—'; ?></td>
            <td style="padding:10px;display:flex;flex-wrap:wrap;gap:8px;align-items:center">
              <a href="/?page=contract/on-demand-invoices-list&contract_id=<?php echo (int)$r['id']; ?>" style="padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: small;">Invoices</a>
              <?php if ($r['status'] === 'pending'): ?>
                <form method="post" action="/?page=on-demand-contract-activate" style="display:inline">
                  <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                  <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                  <button type="submit" style="padding:6px 10px;border:0;border-radius:8px;background:#10b981;color:#fff; font-size: small;">Activate</button>
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
                  <button type="submit" style="padding:6px 10px;border:0;border-radius:8px;background:#3b82f6;color:#fff; font-size: small;">Generate Invoice</button>
                </form>
                <?php endif; ?>
                <form method="post" action="/?page=on-demand-contract-pause" style="display:inline">
                  <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                  <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                  <button type="submit" style="padding:6px 10px;border:0;border-radius:8px;background:#f59e0b;color:#fff; font-size: small;">Pause</button>
                </form>
              <?php elseif ($r['status'] === 'paused'): ?>
                <form method="post" action="/?page=on-demand-contract-resume" style="display:inline">
                  <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                  <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                  <button type="submit" style="padding:6px 10px;border:0;border-radius:8px;background:#10b981;color:#fff; font-size: small;">Resume</button>
                </form>
              <?php endif; ?>
              <?php if (in_array($r['status'], ['pending', 'active', 'paused'], true)): ?>
                <form method="post" action="/?page=on-demand-contract-terminate" style="display:inline" onsubmit="return confirm('Terminate this on-demand contract?')">
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
    $qs=$_GET; unset($qs['p']); $base='/?'.http_build_query($qs+['page'=>'contract/on-demand-contracts-list','per_page'=>$per]);
  ?>
  <div style="margin-top:12px;display:flex;justify-content:space-between;align-items:center">
    <div>
      <form method="get" action="/">
        <?php foreach($_GET as $k=>$v){ if($k==='per_page'||$k==='p'||$k==='page') continue; echo '<input type="hidden" name="'.htmlspecialchars($k).'" value="'.htmlspecialchars($v).'">'; }
        ?>
        <input type="hidden" name="page" value="contract/on-demand-contracts-list">
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
