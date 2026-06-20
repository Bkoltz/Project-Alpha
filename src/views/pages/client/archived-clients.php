<?php
// src/views/pages/archived-clients.php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/csrf.php';

$per = (int)($_GET['per_page'] ?? 50);
if (!in_array($per, [50, 100], true)) $per = 50;
$pageN = max(1, (int)($_GET['p'] ?? 1));
$offset = ($pageN - 1) * $per;
$q = trim($_GET['q'] ?? '');
$params=[]; $where='';
if ($q !== '') { $where = 'WHERE ac.name LIKE ?'; $params[] = '%'.$q.'%'; }

$stc = $pdo->prepare('SELECT COUNT(*) FROM archived_clients ac '.($where));
$stc->execute($params);
$total = (int)$stc->fetchColumn();

$sql = "SELECT ac.id, ac.client_id, ac.name, ac.email, ac.phone, org.name AS organization, ac.archived_at FROM archived_clients ac LEFT JOIN organizations org ON ac.organization_id = org.id ".($where)." ORDER BY ac.archived_at DESC LIMIT $per OFFSET $offset";
$params = [];
$where = '';

if ($q !== '') {
  $where = 'WHERE name LIKE ?';
  $params[] = '%' . $q . '%';
}

$stc = $pdo->prepare('SELECT COUNT(*) FROM archived_clients ' . ($where));
$stc->execute($params);
$total = (int)$stc->fetchColumn();

$sql = "SELECT id, client_id, name, email, phone, organization_id, archived_at FROM archived_clients " . ($where) . " ORDER BY archived_at DESC LIMIT $per OFFSET $offset";

$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);
?>
<section>
  <h2>Archived Clients</h2>
  <form method="get" action="/" class="flex form-row">
    <input type="hidden" name="page" value="client/archived-clients">
    <label class="field">
      <div class="label">Search by name</div>
      <input type="text" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="e.g., Acme" class="input">
    </label>
    <button type="submit" class="btn btn-primary">Filter</button>
    <a href="/?page=client/archived-clients" class="btn">Reset</a>
  </form>
  <div class="pa-table-wrap">
    <table class="pa-table">
      <thead>
        <tr>
          <th>Client ID</th>
          <th>Name</th>
          <th>Email</th>
          <th>Phone</th>
          <th>Organization</th>
          <th>Archived</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td>#<?php echo (int)$r['client_id']; ?></td>
            <td><?php echo htmlspecialchars($r['name']); ?></td>
            <td><?php echo htmlspecialchars($r['email'] ?? ''); ?></td>
            <td><?php echo htmlspecialchars($r['phone'] ?? ''); ?></td>
            <td><?php echo htmlspecialchars($r['organization'] ?? ''); ?></td>
            <td><?php echo htmlspecialchars($r['archived_at']); ?></td>
          </tr>
          <tr>
            <td colspan="6">
              <form method="post" action="/?page=client/clients-restore" onsubmit="return confirm('Restore client <?php echo addslashes($r['name']); ?> to active list?');" class="inline-form">
                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                <button type="submit" class="btn btn-sm">Restore</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php $last = (int)ceil(max(1, $total) / $per);
  $qs = $_GET;
  unset($qs['p']);
  $base = '/?' . http_build_query($qs + ['page' => 'client/archived-clients', 'per_page' => $per]); ?>
  <div class="flex-between align-center mt-1">
    <div>
      <form method="get" action="/">
        <?php foreach ($_GET as $k => $v) {
          if ($k === 'per_page' || $k === 'p' || $k === 'page') continue;
          echo '<input type="hidden" name="' . htmlspecialchars($k) . '" value="' . htmlspecialchars($v) . '"';
        }
        ?>
        <input type="hidden" name="page" value="client/archived-clients">
        <label class="label-muted">Per page
          <select name="per_page" onchange="this.form.submit()" class="input-sm">
            <option value="50" <?php echo $per === 50 ? 'selected' : ''; ?>>50</option>
            <option value="100" <?php echo $per === 100 ? 'selected' : ''; ?>>100</option>
          </select>
        </label>
      </form>
    </div>
    <div class="flex">
      <?php if ($pageN > 1): ?><a href="<?php echo $base . '&p=' . ($pageN - 1); ?>" class="btn btn-sm">Prev</a><?php endif; ?>
      <div class="btn btn-sm muted">Page <?php echo $pageN; ?> / <?php echo $last; ?></div>
      <?php if ($pageN < $last): ?><a href="<?php echo $base . '&p=' . ($pageN + 1); ?>" class="btn btn-sm">Next</a><?php endif; ?>
    </div>
  </div>
</section>