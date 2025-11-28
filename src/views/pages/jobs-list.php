<?php
// src/views/pages/jobs-list.php
require_once __DIR__ . '/../../config/db.php';
// TODo: Change the Details Button in the project list view to "Preview" and then add button called "Details" That will open up a new page with all the details of the project.
$client_id = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;
$prefix = trim($_GET['project_prefix'] ?? '');
$selected = trim($_GET['selected_project_code'] ?? '');
$where = [];
$params = [];
if ($client_id > 0) { $where[] = 'pc.client_id=?'; $params[] = $client_id; }
if ($prefix !== '') { $where[] = 'pc.project_code LIKE ?'; $params[] = $prefix.'%'; }

// Collect distinct project codes with owning client (from any table)
$sql = "SELECT pc.project_code, pc.client_id, c.name AS client_name, 
  (SELECT COUNT(*) FROM quotes q WHERE q.project_code=pc.project_code) AS quotes_count,
  (SELECT COUNT(*) FROM contracts co WHERE co.project_code=pc.project_code) AS contracts_count,
  (SELECT COUNT(*) FROM invoices i WHERE i.project_code=pc.project_code) AS invoices_count
FROM (
  SELECT project_code, client_id FROM quotes WHERE project_code IS NOT NULL
  UNION SELECT project_code, client_id FROM contracts WHERE project_code IS NOT NULL
  UNION SELECT project_code, client_id FROM invoices WHERE project_code IS NOT NULL
) pc JOIN clients c ON c.id=pc.client_id";
if ($where) { $sql .= ' WHERE '.implode(' AND ',$where); }
$sql .= ' ORDER BY pc.project_code DESC';
$rows = $pdo->prepare($sql);
$rows->execute($params);
$projects = $rows->fetchAll();
$clients = $pdo->query('SELECT id,name FROM clients ORDER BY name')->fetchAll();

$selectedRow = null;
if ($selected !== '') {
  foreach ($projects as $pr) { if ($pr['project_code'] === $selected) { $selectedRow = $pr; break; } }
}
?>
<section>
  <h2>Jobs</h2>
  <form method="get" action="/" style="display:grid;grid-template-columns:1fr 1fr auto auto;gap:8px;align-items:end;margin:12px 0">
    <input type="hidden" name="page" value="jobs-list">
    <label>
      <div>Client</div>
      <select name="client_id" style="padding:8px;border-radius:8px;border:1px solid #ddd">
        <option value="">-- All Clients --</option>
        <?php foreach ($clients as $c): ?>
          <option value="<?php echo (int)$c['id']; ?>" <?php echo $c['id'] === $client_id ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['name']); ?></option>
        <?php endforeach; ?>
      </select>
    </label>

    <label>
      <div>Project Code Prefix</div>
      <input type="text" name="project_prefix" placeholder="abc" value="<?php echo htmlspecialchars($prefix); ?>" style="padding:8px;border-radius:8px;border:1px solid #ddd">
    </label>

    <div>
      <button type="submit" style="padding:8px 12px;border-radius:8px;background:var(--nav-accent);color:#fff">Filter</button>
    </div>

    <div style="text-align:right">
      <a href="/?page=jobs-list" style="padding:8px 12px;border:1px solid #ddd;border-radius:8px;background:#fff;display:inline-block; font-size: small;">Reset</a>
    </div>
  </form>

  <?php if (!$projects): ?>
    <div style="color:var(--muted)">No jobs to display.</div>
  <?php else: ?>
    <div style="display:grid;gap:12px">
      <?php foreach ($projects as $p): ?>
        <div style="border:1px solid #eee;border-radius:8px;padding:10px;background:#fff;display:flex;justify-content:space-between;align-items:center">
          <div>
            <div style="font-weight:700"><?php echo htmlspecialchars($p['project_code']); ?><?php echo $p['client_name'] ? ' · '.htmlspecialchars($p['client_name']) : ''; ?></div>
            <div style="font-size:13px;color:var(--muted)">Quotes: <?php echo (int)$p['quotes_count']; ?> · Contracts: <?php echo (int)$p['contracts_count']; ?> · Invoices: <?php echo (int)$p['invoices_count']; ?></div>
          </div>
          <div style="display:flex;gap:8px;align-items:center">
            <a href="/?page=jobs-list&amp;selected_project_code=<?php echo urlencode($p['project_code']); ?>" style="padding:6px 10px;border:1px solid #ddd;border-radius:6px;background:#fff">Details</a>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($selectedRow): ?>
    <div style="margin-top:12px;border:1px solid #eee;border-radius:8px;padding:12px;background:#fff">
      <h3>Job <?php echo htmlspecialchars($selectedRow['project_code']); ?></h3>
      <div style="display:grid;grid-template-columns:1fr auto;gap:8px;align-items:center;">
        <div>Client: <?php echo htmlspecialchars($selectedRow['client_name']); ?></div>
        <div><a href="/?page=jobs-list" style="padding:8px 12px;border:1px solid #ddd;border-radius:8px;background:#fff">Close</a></div>
      </div>

      <div style="margin-top:8px">
        <h4>Quotes</h4>
        <?php
          $q = $pdo->prepare('SELECT id, doc_number, client_id, created_at FROM quotes WHERE project_code = ? ORDER BY created_at DESC');
          $q->execute([$selectedRow['project_code']]);
          $qrows = $q->fetchAll();
        ?>
        <?php if (!$qrows): ?>
          <div style="color:var(--muted)">No quotes in this job.</div>
        <?php else: ?>
          <ul style="list-style:none;padding:0;margin:0;display:grid;gap:6px">
            <?php foreach ($qrows as $qr): ?>
              <li style="display:flex;gap:12px;align-items:center">
                <span style="font-weight:600">Quote</span> #<?php echo (int)$qr['doc_number']; ?> - <a href="/?page=quote/quotes-edit&id=<?php echo (int)$qr['id']; ?>">View</a>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>

      <div style="margin-top:8px">
        <h4>Contracts</h4>
        <?php
          $co = $pdo->prepare('SELECT id, doc_number, client_id, created_at FROM contracts WHERE project_code = ? ORDER BY created_at DESC');
          $co->execute([$selectedRow['project_code']]);
          $corows = $co->fetchAll();
        ?>
        <?php if (!$corows): ?>
          <div style="color:var(--muted)">No contracts in this job.</div>
        <?php else: ?>
          <ul style="list-style:none;padding:0;margin:0;display:grid;gap:6px">
            <?php foreach ($corows as $cr): ?>
              <li style="display:flex;gap:12px;align-items:center">
                <span style="font-weight:600">Contract</span> #<?php echo (int)$cr['doc_number']; ?> - <a href="/?page=contract/contracts-edit&id=<?php echo (int)$cr['id']; ?>">View</a>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>

      <div style="margin-top:8px">
        <h4>Invoices</h4>
        <?php
          $iv = $pdo->prepare('SELECT id, doc_number, client_id, created_at FROM invoices WHERE project_code = ? ORDER BY created_at DESC');
          $iv->execute([$selectedRow['project_code']]);
          $ivrows = $iv->fetchAll();
        ?>
        <?php if (!$ivrows): ?>
          <div style="color:var(--muted)">No invoices in this job.</div>
        <?php else: ?>
          <ul style="list-style:none;padding:0;margin:0;display:grid;gap:6px">
            <?php foreach ($ivrows as $ir): ?>
              <li style="display:flex;gap:12px;align-items:center">
                <span style="font-weight:600">Invoice</span> #<?php echo (int)$ir['doc_number']; ?> - <a href="/?page=invoice/invoices-edit&id=<?php echo (int)$ir['id']; ?>">View</a>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>

    </div>
  <?php endif; ?>
</section>
