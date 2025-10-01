<?php
// src/views/pages/clients-list.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/format.php';
$per = (int)($_GET['per_page'] ?? 50); if(!in_array($per, [50,100], true)) $per = 50;
$pageN = max(1, (int)($_GET['p'] ?? 1));
$offset = ($pageN - 1) * $per;
$total = (int)$pdo->query('SELECT COUNT(*) FROM clients')->fetchColumn();
$clients = $pdo->query("SELECT id, name, email, phone, organization, created_at FROM clients ORDER BY created_at DESC LIMIT $per OFFSET $offset")->fetchAll();
?>
<section>
  <h2>Clients</h2>
  <?php if (!empty($_GET['created'])): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#e6fffa;color:#065f46;border:1px solid #99f6e4">Client created.</div>
  <?php endif; ?>
  <div style="overflow:auto">
    <table style="width:100%;border-collapse:collapse;background:#fff;border-radius:8px;box-shadow:0 6px 18px rgba(11,18,32,0.06)">
      <thead>
        <tr style="text-align:left;border-bottom:1px solid #eee">
          <th style="padding:10px">Name</th>
          <th style="padding:10px">Email</th>
          <th style="padding:10px">Phone</th>
          <th style="padding:10px">Organization</th>
          <th style="padding:10px">Created</th>
          <th style="padding:10px">Edit</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($clients as $c): ?>
          <tr style="border-top:1px solid #f3f4f6">
            <td style="padding:10px"><?php echo htmlspecialchars($c['name']); ?></td>
            <td style="padding:10px"><?php echo htmlspecialchars($c['email'] ?? ''); ?></td>
            <td style="padding:10px"><?php echo htmlspecialchars(format_phone($c['phone'] ?? '')); ?></td>
            <td style="padding:10px"><?php echo htmlspecialchars($c['organization'] ?? ''); ?></td>
            <td style="padding:10px"><?php echo htmlspecialchars($c['created_at']); ?></td>
            <td style="padding:10px"><a href="/?page=clients-edit&id=<?php echo (int)$c['id']; ?>" style="padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff">Edit</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php
    $last = (int)ceil(max(1,$total)/$per);
    $qs = $_GET; unset($qs['p']); $base='/?'.http_build_query($qs+['page'=>'clients-list','per_page'=>$per]);
  ?>
  <div style="margin-top:12px;display:flex;justify-content:space-between;align-items:center">
    <div>
      <form method="get" action="/">
        <?php foreach ($_GET as $k=>$v){ if($k==='per_page'||$k==='p'||$k==='page') continue; echo '<input type="hidden" name="'.htmlspecialchars($k).'" value="'.htmlspecialchars($v).'">'; }
        ?>
        <input type="hidden" name="page" value="clients-list">
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
