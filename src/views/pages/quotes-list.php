<?php
// src/views/pages/quotes-list.php
require_once __DIR__ . '/../../config/db.php';
$client_id = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;
$client_name = trim($_GET['client'] ?? '');
$start = $_GET['start'] ?? '';
$end = $_GET['end'] ?? '';
$status = $_GET['status'] ?? 'all'; // all|approved|rejected|pending
// Detect optional columns
$hasDoc = (bool)$pdo->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='quotes' AND COLUMN_NAME='doc_number'")->fetchColumn();
$hasProj = (bool)$pdo->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='quotes' AND COLUMN_NAME='project_code'")->fetchColumn();
$project_code = trim($_GET['project_code'] ?? '');
$doc_no = isset($_GET['doc_number']) ? (int)$_GET['doc_number'] : 0;
$where=[];$p=[];
if($client_id>0){$where[]='q.client_id=?';$p[]=$client_id;}
elseif($client_name!==''){ $where[]='c.name LIKE ?'; $p[]='%'.$client_name.'%'; }
if($start!==''){$where[]='q.created_at>=?';$p[]=$start.' 00:00:00';}
if($end!==''){$where[]='q.created_at<=?';$p[]=$end.' 23:59:59';}
if(in_array($status,['approved','rejected','pending'],true)){ $where[]='q.status=?'; $p[]=$status; }
if($hasProj && $project_code!==''){ $where[]='q.project_code LIKE ?'; $p[] = $project_code.'%'; }
if($hasDoc && $doc_no>0){ $where[]='q.doc_number=?'; $p[] = $doc_no; }
$per = (int)($_GET['per_page'] ?? 50); if(!in_array($per,[50,100],true)) $per=50;
$pageN = max(1, (int)($_GET['p'] ?? 1));
$offset = ($pageN - 1) * $per;

$sqlCount = 'SELECT COUNT(*) FROM quotes q'.($where? ' WHERE '.implode(' AND ', $where):'');
$stc=$pdo->prepare($sqlCount);$stc->execute($p);$total=(int)$stc->fetchColumn();

$select = 'q.id, q.status, q.total, q.created_at, c.name AS client_name, c.id AS client_id';
$select = ($hasDoc ? 'q.doc_number, ' : 'q.id AS doc_number, ') . $select;
$select = ($hasProj ? 'q.project_code, ' : "'' AS project_code, ") . $select;
$sql = "SELECT $select FROM quotes q JOIN clients c ON c.id=q.client_id";
if($where){$sql.=' WHERE '.implode(' AND ',$where);} $sql.=" ORDER BY q.created_at DESC LIMIT $per OFFSET $offset";
$st=$pdo->prepare($sql);$st->execute($p);$rows=$st->fetchAll();
$hasArchived = (bool)$pdo->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='clients' AND COLUMN_NAME='archived'")->fetchColumn();
$clients=$pdo->query('SELECT id,name FROM clients '.($hasArchived?'WHERE archived=0 ':'').'ORDER BY name')->fetchAll();
?>
<section>
  <h2>Quotes</h2>
  <form method="get" action="/" style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr 1fr 1fr auto auto;gap:8px;align-items:end;margin:12px 0;position:relative">
    <input type="hidden" name="page" value="quotes-list">
    <input type="hidden" name="client_id" id="clientIdQL" value="<?php echo (int)$client_id; ?>">
    <label style="position:relative"><div>Client</div>
      <input type="text" name="client" id="clientInputQL" value="<?php echo htmlspecialchars($client_name); ?>" placeholder="Type client name..." style="padding:8px;border-radius:8px;border:1px solid #ddd">
      <div id="clientSuggestQL" style="position:absolute;z-index:60;left:0;right:0;top:100%;background:#fff;border:1px solid #eee;border-radius:8px;display:none;max-height:200px;overflow:auto"></div>
    </label>
    <label><div>Status</div>
      <select name="status" style="padding:8px;border-radius:8px;border:1px solid #ddd">
        <?php $sf=htmlspecialchars($status); ?>
        <option value="all" <?php echo $sf==='all'?'selected':''; ?>>All</option>
        <option value="approved" <?php echo $sf==='approved'?'selected':''; ?>>Approved</option>
        <option value="rejected" <?php echo $sf==='rejected'?'selected':''; ?>>Denied</option>
        <option value="pending" <?php echo $sf==='pending'?'selected':''; ?>>Pending</option>
      </select>
    </label>
    <label><div>Start</div><input type="date" name="start" value="<?php echo htmlspecialchars($start); ?>" style="padding:8px;border-radius:8px;border:1px solid #ddd"></label>
    <label><div>End</div><input type="date" name="end" value="<?php echo htmlspecialchars($end); ?>" style="padding:8px;border-radius:8px;border:1px solid #ddd"></label>
    <label><div>Project ID</div><input type="text" name="project_code" value="<?php echo htmlspecialchars($project_code); ?>" placeholder="PA-2025" style="padding:8px;border-radius:8px;border:1px solid #ddd"></label>
    <label><div>Doc #</div><input type="number" name="doc_number" value="<?php echo htmlspecialchars($_GET['doc_number'] ?? ''); ?>" style="padding:8px;border-radius:8px;border:1px solid #ddd"></label>
    <button type="submit" style="padding:8px 12px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: small;">Filter</button>
    <a href="/?page=quotes-list" style="padding:8px 12px;border:1px solid #ddd;border-radius:8px;background:#fff;display:inline-block; font-size: small;">Reset</a>
  </form>
  <script>
    (function(){
      var input = document.getElementById('clientInputQL');
      var hid = document.getElementById('clientIdQL');
      var sug = document.getElementById('clientSuggestQL');
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
          <th style="padding:10px"><?php echo $hasDoc ? 'No.' : 'ID'; ?></th>
          <?php if ($hasProj): ?><th style="padding:10px">Project</th><?php endif; ?>
          <th style="padding:10px">Client</th>
          <th style="padding:10px">Status</th>
          <th style="padding:10px">Total</th>
          <th style="padding:10px">Created</th>
          <th style="padding:10px">Actions</th>
          <th style="padding:10px">Edit</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <?php $rowStyle = $r['status']==='approved' ? 'background:#ecfdf5;' : ($r['status']==='pending' ? 'background:#fffbeb;' : ($r['status']==='rejected' ? 'background:#fef2f2;' : '')); ?>
          <tr style="border-top:1px solid #f3f4f6;<?php echo $rowStyle; ?>">
            <td style="padding:10px">Q-<?php echo (int)$r['doc_number']; ?></td>
            <?php if ($hasProj): ?><td style="padding:10px"><?php echo htmlspecialchars($r['project_code'] ?? ''); ?></td><?php endif; ?>
            <td style="padding:10px"><a href="/?page=clients-list&selected_client_id=<?php echo (int)$r['client_id']; ?>"><?php echo htmlspecialchars($r['client_name']); ?></a></td>
            <td style="padding:10px;text-transform:capitalize"><?php echo htmlspecialchars($r['status']); ?></td>
            <td style="padding:10px">$<?php echo number_format((float)$r['total'], 2); ?></td>
            <td style=\"padding:10px\"><?php echo $r['created_at'] ? date('m/d/Y', strtotime($r['created_at'])) : ''; ?></td>
            <td style="padding:10px;display:flex;gap:8px">
              <a href="/?page=quote-print&id=<?php echo (int)$r['id']; ?>" style="padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: medium;">PDF</a>
              <form method="post" action="/?page=email-send" style="display:inline">
                <input type="hidden" name="type" value="quote">
                <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                <button type="submit" style="padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: medium;">Email</button>
              </form>
              <?php if ($r['status'] === 'pending'): ?>
                <form method="post" action="/?page=quote-approve" onsubmit="return confirm('Approve this quote and generate contract + invoice?')">
                  <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                  <button type="submit" style="padding:6px 10px;border:0;border-radius:8px;background:#16a34a;color:#fff">Approve</button>
                </form>
                <form method="post" action="/?page=quote-reject" onsubmit="return confirm('Deny this quote?')">
                  <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                  <button type="submit" style="padding:6px 10px;border:0;border-radius:8px;background:#ef4444;color:#fff">Deny</button>
                </form>
              <?php endif; ?>
            </td>
            <td style="padding:10px"><a href="/?page=quotes-edit&id=<?php echo (int)$r['id']; ?>" style="padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: medium;">Edit</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php
    $last=(int)ceil(max(1,$total)/$per);
    $qs=$_GET; unset($qs['p']); $base='/?'.http_build_query($qs+['page'=>'quotes-list','per_page'=>$per]);
  ?>
  <div style="margin-top:12px;display:flex;justify-content:space-between;align-items:center">
    <div>
      <form method="get" action="/">
        <?php foreach($_GET as $k=>$v){ if($k==='per_page'||$k==='p'||$k==='page') continue; echo '<input type="hidden" name="'.htmlspecialchars($k).'" value="'.htmlspecialchars($v).'">'; }
        ?>
        <input type="hidden" name="page" value="quotes-list">
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
