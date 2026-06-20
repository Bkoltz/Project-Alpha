<?php
// src/views/pages/quotes-list.php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/twig.php';
$client_id = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;
$client_name = trim($_GET['client'] ?? '');
$start = $_GET['start'] ?? '';
$end = $_GET['end'] ?? '';
$status = $_GET['status'] ?? 'all'; // all|approved|rejected|pending
$min_price = isset($_GET['min_price']) ? (float)$_GET['min_price'] : null;
$max_price = isset($_GET['max_price']) ? (float)$_GET['max_price'] : null;
// Detect optional columns
$hasDoc = (bool)$pdo->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='quotes' AND COLUMN_NAME='doc_number'")->fetchColumn();
$hasProj = (bool)$pdo->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='quotes' AND COLUMN_NAME='project_code'")->fetchColumn();
$project_code = trim($_GET['project_code'] ?? '');
$doc_no = isset($_GET['doc_number']) ? (int)$_GET['doc_number'] : 0;
$where=['q.quote_type = "regular"'];$p=[];
if($client_id>0){$where[]='q.client_id=?';$p[]=$client_id;}
elseif($client_name!==''){ $where[]='c.name LIKE ?'; $p[]='%'.$client_name.'%'; }
if($start!==''){$where[]='q.created_at>=?';$p[]=$start.' 00:00:00';}
if($end!==''){$where[]='q.created_at<=?';$p[]=$end.' 23:59:59';}
if(in_array($status,['approved','rejected','pending'],true)){ $where[]='q.status=?'; $p[]=$status; }
if($hasProj && $project_code!==''){ $where[]='q.project_code LIKE ?'; $p[] = $project_code.'%'; }
if($hasDoc && $doc_no>0){ $where[]='q.doc_number=?'; $p[] = $doc_no; }
if($min_price !== null){ $where[]='q.total>=?'; $p[] = $min_price; }
if($max_price !== null){ $where[]='q.total<=?'; $p[] = $max_price; }
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
  <div class="page-head">
    <h2>Quotes</h2>
    <a href="/?page=quote/quotes-create" class="btn btn-primary">Create Quote</a>
  </div>
  <?php if (!empty($_GET['emailed'])): ?>
    <div class="alert alert-success">Email sent.</div>
  <?php elseif (!empty($_GET['email_err'])): ?>
    <div class="alert alert-danger">Email failed: <?php echo htmlspecialchars($_GET['email_err']); ?></div>
  <?php endif; ?>

  <?php
  // Render the filter using Twig template instead of PHP include
  $statusOptions = [
      ['value' => 'all', 'label' => 'All'],
      ['value' => 'approved', 'label' => 'Approved'],
      ['value' => 'rejected', 'label' => 'Denied'],
      ['value' => 'pending', 'label' => 'Pending']
  ];

  $filterConfig = [
      'page' => 'quote/quotes-list',
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
              'options' => $statusOptions
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

  // Render the filter component using Twig
  echo render_template('components/document-filter.html.twig', $filterConfig);
  ?>
  <div style="overflow:auto">
    <table class="pa-table">
      <thead>
        <tr>
          <th><?php echo $hasDoc ? 'No.' : 'ID'; ?></th>
          <?php if ($hasProj): ?><th>Project</th><?php endif; ?>
          <th>Client</th>
          <th>Status</th>
          <th>Total</th>
          <th>Created</th>
          <th class="text-right">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <?php $rowStyle = $r['status']==='approved' ? 'background:#ecfdf5;' : ($r['status']==='pending' ? 'background:#fffbeb;' : ($r['status']==='rejected' ? 'background:#fef2f2;' : '')); ?>
          <tr style="<?php echo $rowStyle; ?>">
            <td>Q-<?php echo (int)$r['doc_number']; ?></td>
            <?php if ($hasProj): ?><td><?php echo htmlspecialchars($r['project_code'] ?? ''); ?></td><?php endif; ?>
            <td><a href="/?page=client/clients-list&selected_client_id=<?php echo (int)$r['client_id']; ?>"><?php echo htmlspecialchars($r['client_name']); ?></a></td>
            <td><span class="status-pill status-pill--<?php echo strtolower(htmlspecialchars($r['status'])); ?>"><?php echo htmlspecialchars($r['status']); ?></span></td>
            <td>$<?php echo number_format((float)$r['total'], 2); ?></td>
            <td><?php echo $r['created_at'] ? date('m/d/Y', strtotime($r['created_at'])) : ''; ?></td>
            <td>
              <div class="flex" style="justify-content:flex-end;gap:6px">
              <a href="/?page=quote/quote-details&id=<?php echo (int)$r['id']; ?>" class="btn btn-sm">View</a>
              <?php if ($r['status'] === 'pending'): ?>
                <a href="/?page=quote/quotes-edit&id=<?php echo (int)$r['id']; ?>" class="btn btn-sm">Edit</a>
              <?php endif; ?>
              <?php if (strtolower((string)$r['status']) !== 'rejected'): ?>
              <form method="post" action="/?page=quote/email-send" style="display:inline">
                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                <input type="hidden" name="type" value="quote">
                <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                <input type="hidden" name="redirect_to" value="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>">
                <button type="submit" class="btn btn-sm">Email</button>
              </form>
              <?php endif; ?>
              <?php if ($r['status'] === 'pending'): ?>
                <form method="post" action="/?page=quote/quote-approve" onsubmit="return confirm('Approve this quote and generate contract + invoice?')">
                  <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                  <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                  <button type="submit" class="btn btn-sm btn-success">Approve</button>
                </form>
                <form method="post" action="/?page=quote/quote-reject" onsubmit="return confirm('Deny this quote?')">
                  <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                  <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                  <button type="submit" class="btn btn-sm btn-danger">Deny</button>
              </form>
              <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php
    $last=(int)ceil(max(1,$total)/$per);
    $qs=$_GET; unset($qs['p']); $base='/?'.http_build_query($qs+['page'=>'quote/quotes-list','per_page'=>$per]);
  ?>
  <div class="flex-between" style="margin-top:12px">
    <div>
      <form method="get" action="/">
        <?php foreach($_GET as $k=>$v){ if($k==='per_page'||$k==='p'||$k==='page') continue; echo '<input type="hidden" name="'.htmlspecialchars($k).'" value="'.htmlspecialchars($v).'">'; }
        ?>
        <input type="hidden" name="page" value="quote/quotes-list">
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
      <div class="muted">Page <?php echo $pageN; ?> / <?php echo $last; ?></div>
      <?php if($pageN<$last): ?><a href="<?php echo $base.'&p='.($pageN+1); ?>" class="btn btn-sm">Next</a><?php endif; ?>
    </div>
  </div>
</section>
