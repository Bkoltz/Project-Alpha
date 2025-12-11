<?php
// src/views/pages/invoice/on-demand-invoices-list.php
// Dedicated view for ALL on-demand invoices (ODI prefix)
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/csrf.php';

// Ensure the optional on_demand_contracts table exists before querying
$has_on_demand_table = (bool)$pdo->query("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='on_demand_contracts'")->fetchColumn();
if (!$has_on_demand_table) {
  echo '<section><h2>On-Demand Invoices</h2><div style="margin:10px 0;padding:12px;background:#fff3cd;border:1px solid #ffc107;border-radius:8px;color:#856404">On-demand invoices are not available because the database table <code>on_demand_contracts</code> is missing. Run the migrations or contact your administrator to enable this feature.</div></section>';
  return;
}

$client_id = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;
$client_name = trim($_GET['client'] ?? '');
$status = $_GET['status'] ?? '';
$project_code = trim($_GET['project_code'] ?? '');

$where=['i.on_demand_contract_id IS NOT NULL'];$p=[];
if($client_id>0){$where[]='i.client_id=?';$p[]=$client_id;}
elseif($client_name!==''){ $where[]='c.name LIKE ?'; $p[]='%'.$client_name.'%'; }
if($status!==''){ $where[]='i.status=?'; $p[] = $status; }
if($project_code!==''){ $where[]='i.project_code LIKE ?'; $p[] = $project_code.'%'; }

$per = (int)($_GET['per_page'] ?? 50); 
if(!in_array($per,[50,100],true)) $per=50;
$pageN = max(1, (int)($_GET['p'] ?? 1));
$offset = ($pageN - 1) * $per;

$sqlCount = 'SELECT COUNT(*) FROM invoices i LEFT JOIN clients c ON c.id=i.client_id'.($where?' WHERE '.implode(' AND ',$where):'');
$stc=$pdo->prepare($sqlCount);$stc->execute($p);$total=(int)$stc->fetchColumn();

$sql="SELECT i.id, i.doc_number, i.project_code, i.status, i.total, i.due_date, i.on_demand_contract_id, i.created_at, c.name client, c.id AS client_id, odc.doc_number AS contract_doc_number FROM invoices i LEFT JOIN clients c ON c.id=i.client_id LEFT JOIN on_demand_contracts odc ON odc.id=i.on_demand_contract_id";
if($where){$sql.=' WHERE '.implode(' AND ',$where);} 
$sql.=" ORDER BY i.created_at DESC LIMIT $per OFFSET $offset";
$st=$pdo->prepare($sql);$st->execute($p);$rows=$st->fetchAll();
?>
<section>
  <h2>On-Demand Invoices</h2>
  
  <?php if (!empty($_GET['error'])): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#fff1f2;color:#881337;border:1px solid #fca5a5"><?php echo htmlspecialchars((string)$_GET['error']); ?></div>
  <?php endif; ?>

  <form method="get" action="/" style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr auto auto;gap:8px;align-items:end;margin:12px 0;position:relative">
    <input type="hidden" name="page" value="invoice/on-demand-invoices-list">
    <input type="hidden" name="client_id" id="clientIdODI" value="<?php echo (int)$client_id; ?>">
    <label style="position:relative"><div>Client</div>
      <input type="text" name="client" id="clientInputODI" value="<?php echo htmlspecialchars($client_name); ?>" placeholder="Type client name..." style="padding:8px;border-radius:8px;border:1px solid #ddd">
      <div id="clientSuggestODI" style="position:absolute;z-index:60;left:0;right:0;top:100%;background:#fff;border:1px solid #eee;border-radius:8px;display:none;max-height:200px;overflow:auto"></div>
    </label>
    <label><div>Project ID</div><input type="text" name="project_code" value="<?php echo htmlspecialchars($project_code); ?>" placeholder="PA-2025" style="padding:8px;border-radius:8px;border:1px solid #ddd"></label>
    <label><div>Status</div>
      <select name="status" style="padding:8px;border-radius:8px;border:1px solid #ddd">
        <option value="">All</option>
        <option value="unpaid" <?php echo $status==='unpaid'?'selected':''; ?>>Unpaid</option>
        <option value="partial" <?php echo $status==='partial'?'selected':''; ?>>Partial</option>
        <option value="paid" <?php echo $status==='paid'?'selected':''; ?>>Paid</option>
        <option value="void" <?php echo $status==='void'?'selected':''; ?>>Void</option>
      </select>
    </label>
    <div style="display:flex;gap:8px">
      <button type="submit" style="padding:8px 12px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: small;">Filter</button>
      <a href="/?page=invoice/on-demand-invoices-list" style="padding:8px 12px;border:1px solid #ddd;border-radius:8px;background:#fff;display:inline-block; font-size: small;text-decoration:none;color:inherit">Reset</a>
    </div>
  </form>

  <script>
    (function(){
      var input = document.getElementById('clientInputODI');
      var hid = document.getElementById('clientIdODI');
      var sug = document.getElementById('clientSuggestODI');
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
          <th style="padding:10px">Contract</th>
          <th style="padding:10px">Project</th>
          <th style="padding:10px">Client</th>
          <th style="padding:10px">Total</th>
          <th style="padding:10px">Status</th>
          <th style="padding:10px">Due Date</th>
          <th style="padding:10px">Created</th>
          <th style="padding:10px">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
<?php 
  $rowStyle = ($r['status']==='paid') ? 'background:#ecfdf5;' : (($r['status']==='unpaid' || $r['status']==='partial') ? 'background:#fffbeb;' : 'background:#fef2f2;');
?>
          <tr style="border-top:1px solid #f3f4f6;<?php echo $rowStyle; ?>">
            <td style="padding:10px"><a href="/?page=invoice/invoice-details&id=<?php echo (int)$r['id']; ?>" style="text-decoration:none;color:inherit">ODI-<?php echo (int)($r['doc_number'] ?? $r['id']); ?></a></td>
            <td style="padding:10px"><a href="/?page=contract/on-demand-contracts-list" style="text-decoration:none;color:inherit">ODC-<?php echo (int)$r['contract_doc_number']; ?></a></td>
            <td style="padding:10px"><?php echo htmlspecialchars($r['project_code'] ?? ''); ?></td>
            <td style="padding:10px"><a href="/?page=client/clients-list&selected_client_id=<?php echo (int)$r['client_id']; ?>"><?php echo htmlspecialchars($r['client']); ?></a></td>
            <td style="padding:10px">$<?php echo number_format((float)$r['total'], 2); ?></td>
            <td style="padding:10px;text-transform:capitalize"><?php echo htmlspecialchars($r['status']); ?></td>
            <td style="padding:10px"><?php echo $r['due_date'] ? date('M j, Y', strtotime($r['due_date'])) : '—'; ?></td>
            <td style="padding:10px"><?php echo date('M j, Y', strtotime($r['created_at'])); ?></td>
            <td style="padding:10px;display:flex;flex-wrap:wrap;gap:8px;align-items:center">
              <a href="/?page=invoice/invoice-details&id=<?php echo (int)$r['id']; ?>" style="padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: small;">View</a>
              <?php if ($r['status'] !== 'void' && $r['status'] !== 'paid'): ?>
              <form method="post" action="/?page=email-send" style="display:inline">
                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                <input type="hidden" name="type" value="invoice">
                <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                <input type="hidden" name="redirect_to" value="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>">
                <button type="submit" style="padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: small;">Email</button>
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
    $qs=$_GET; unset($qs['p']); $base='/?'.http_build_query($qs+['page'=>'invoice/on-demand-invoices-list','per_page'=>$per]);
  ?>
  <div style="margin-top:12px;display:flex;justify-content:space-between;align-items:center">
    <div>
      <form method="get" action="/">
        <?php foreach($_GET as $k=>$v){ if($k==='per_page'||$k==='p'||$k==='page') continue; echo '<input type="hidden" name="'.htmlspecialchars($k).'" value="'.htmlspecialchars($v).'">'; }
        ?>
        <input type="hidden" name="page" value="invoice/on-demand-invoices-list">
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
