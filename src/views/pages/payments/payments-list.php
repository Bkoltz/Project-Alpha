<?php
// src/views/pages/payments-list.php
require_once __DIR__ . '/../../../config/db.php';
$client_id = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;
$client_name = trim($_GET['client'] ?? '');
$start = $_GET['start'] ?? '';
$end = $_GET['end'] ?? '';
$where=[];$p=[];
if($client_id>0){$where[]='c.id=?';$p[]=$client_id;}
elseif($client_name!==''){ $where[]='c.name LIKE ?'; $p[]='%'.$client_name.'%'; }
if($start!==''){$where[]='p.created_at>=?';$p[]=$start.' 00:00:00';}
if($end!==''){$where[]='p.created_at<=?';$p[]=$end.' 23:59:59';}
$per = (int)($_GET['per_page'] ?? 50); if(!in_array($per,[50,100],true)) $per=50;
$pageN = max(1, (int)($_GET['p'] ?? 1));
$offset = ($pageN - 1) * $per;

$sqlCount = 'SELECT COUNT(*) FROM payments p JOIN invoices i ON i.id=p.invoice_id JOIN clients c ON c.id=i.client_id';
if($where){$sqlCount.=' WHERE '.implode(' AND ',$where);} $stc=$pdo->prepare($sqlCount);$stc->execute($p);$total=(int)$stc->fetchColumn();

$sql = 'SELECT p.id, p.amount, p.status, p.created_at, i.id AS invoice_id, c.name AS client FROM payments p JOIN invoices i ON i.id=p.invoice_id JOIN clients c ON c.id=i.client_id';
if($where){$sql.=' WHERE '.implode(' AND ',$where);} $sql.=" ORDER BY p.created_at DESC LIMIT $per OFFSET $offset";
$rows = $pdo->prepare($sql); $rows->execute($p); $rows = $rows->fetchAll();
$clients=$pdo->query('SELECT id,name FROM clients ORDER BY name')->fetchAll();
?>
<section>
  <h2>Payments</h2>
  <form method="get" action="/" style="display:grid;grid-template-columns:1fr 1fr 1fr auto auto;gap:8px;align-items:end;margin:12px 0;position:relative">
    <input type="hidden" name="page" value="payments-list">
    <input type="hidden" name="client_id" id="clientIdPL" value="<?php echo (int)$client_id; ?>">
    <label style="position:relative"><div>Client</div>
      <input type="text" name="client" id="clientInputPL" value="<?php echo htmlspecialchars($client_name); ?>" placeholder="Type client name..." style="padding:8px;border-radius:8px;border:1px solid #ddd">
      <div id="clientSuggestPL" style="position:absolute;z-index:60;left:0;right:0;top:100%;background:#fff;border:1px solid #eee;border-radius:8px;display:none;max-height:200px;overflow:auto"></div>
    </label>
    <label><div>Start</div><input type="date" name="start" value="<?php echo htmlspecialchars($start); ?>" style="padding:8px;border-radius:8px;border:1px solid #ddd"></label>
    <label><div>End</div><input type="date" name="end" value="<?php echo htmlspecialchars($end); ?>" style="padding:8px;border-radius:8px;border:1px solid #ddd"></label>
    <button type="submit" style="padding:8px 12px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: small;">Filter</button>
    <a href="/?page=payments-list" style="padding:8px 12px;border:1px solid #ddd;border-radius:8px;background:#fff;display:inline-block; font-size: small;">Reset</a>
  </form>
  <script>
    (function(){
      var input = document.getElementById('clientInputPL');
      var hid = document.getElementById('clientIdPL');
      var sug = document.getElementById('clientSuggestPL');
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
            <td style="padding:10px"><?php echo $r['created_at'] ? date('m/d/Y', strtotime($r['created_at'])) : ''; ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php
    $last=(int)ceil(max(1,$total)/$per);
    $qs=$_GET; unset($qs['p']); $base='/?'.http_build_query($qs+['page'=>'payments-list','per_page'=>$per]);
  ?>
  <div style="margin-top:12px;display:flex;justify-content:space-between;align-items:center">
    <div>
      <form method="get" action="/">
        <?php foreach($_GET as $k=>$v){ if($k==='per_page'||$k==='p'||$k==='page') continue; echo '<input type="hidden" name="'.htmlspecialchars($k).'" value="'.htmlspecialchars($v).'">'; }
        ?>
        <input type="hidden" name="page" value="payments-list">
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
