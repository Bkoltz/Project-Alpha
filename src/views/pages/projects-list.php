<?php
// src/views/pages/projects-list.php
require_once __DIR__ . '/../../config/db.php';

$client_id = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;
$prefix = trim($_GET['project_prefix'] ?? '');
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
  UNION DISTINCT SELECT project_code, client_id FROM contracts WHERE project_code IS NOT NULL
  UNION DISTINCT SELECT project_code, client_id FROM invoices WHERE project_code IS NOT NULL
) pc JOIN clients c ON c.id=pc.client_id";
if ($where) { $sql .= ' WHERE '.implode(' AND ',$where); }
$sql .= ' ORDER BY pc.project_code DESC';
$rows = $pdo->prepare($sql);
$rows->execute($params);
$projects = $rows->fetchAll();
$clients = $pdo->query('SELECT id,name FROM clients ORDER BY name')->fetchAll();
?>
<section>
  <h2>Projects</h2>
  <form method="get" action="/" style="display:grid;grid-template-columns:1fr 1fr auto auto;gap:8px;align-items:end;margin:12px 0">
    <input type="hidden" name="page" value="projects-list">
    <label>
      <div>Client</div>
      <select name="client_id" style="padding:8px;border-radius:8px;border:1px solid #ddd">
        <option value="0">All</option>
        <?php foreach ($clients as $c): ?>
          <option value="<?php echo (int)$c['id']; ?>" <?php echo $client_id===(int)$c['id']?'selected':''; ?>><?php echo htmlspecialchars($c['name']); ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>
      <div>Project prefix</div>
      <input type="text" name="project_prefix" value="<?php echo htmlspecialchars($prefix); ?>" placeholder="PA-2025" style="padding:8px;border-radius:8px;border:1px solid #ddd">
    </label>
    <button type="submit" style="padding:8px 12px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: small;">Filter</button>
    <a href="/?page=projects-list" style="padding:8px 12px;border:1px solid #ddd;border-radius:8px;background:#fff;display:inline-block; font-size: small;">Reset</a>
  </form>

  <?php if (!$projects): ?>
    <div style="color:var(--muted)">No projects yet.</div>
  <?php else: ?>
  <div style="display:grid;gap:12px">
    <?php foreach ($projects as $p): ?>
      <div style="border:1px solid #eee;border-radius:8px;background:#fff;overflow:hidden">
        <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 12px;border-bottom:1px solid #eee">
          <div>
            <strong>Project <?php echo htmlspecialchars($p['project_code']); ?></strong>
            <span style="color:var(--muted)"> · <?php echo htmlspecialchars($p['client_name']); ?></span>
          </div>
          <div style="color:var(--muted)">Q: <?php echo (int)$p['quotes_count']; ?> · C: <?php echo (int)$p['contracts_count']; ?> · I: <?php echo (int)$p['invoices_count']; ?></div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;padding:12px">
          <?php
            $pc = $p['project_code']; $cid = (int)$p['client_id'];
            $q = $pdo->prepare('SELECT id, doc_number, total, status, created_at FROM quotes WHERE client_id=? AND project_code=? ORDER BY created_at DESC LIMIT 5');
            $q->execute([$cid, $pc]); $quotes = $q->fetchAll();
            $co = $pdo->prepare('SELECT id, doc_number, status, created_at FROM contracts WHERE client_id=? AND project_code=? ORDER BY created_at DESC LIMIT 5');
            $co->execute([$cid, $pc]); $contracts = $co->fetchAll();
            $i = $pdo->prepare('SELECT id, doc_number, total, status, created_at FROM invoices WHERE client_id=? AND project_code=? ORDER BY created_at DESC LIMIT 5');
            $i->execute([$cid, $pc]); $invoices = $i->fetchAll();
          ?>
          <div>
            <div style="font-weight:600;margin-bottom:6px">Quotes</div>
            <?php if ($quotes): ?>
              <ul style="list-style:none;margin:0;padding:0;display:grid;gap:6px">
                <?php foreach ($quotes as $row): ?>
                  <li><a href="/?page=quote-print&id=<?php echo (int)$row['id']; ?>">Q-<?php echo (int)($row['doc_number'] ?? $row['id']); ?></a> · $<?php echo number_format((float)$row['total'],2); ?> · <?php echo htmlspecialchars($row['status']); ?></li>
                <?php endforeach; ?>
              </ul>
            <?php else: ?>
              <div style="color:var(--muted)">None</div>
            <?php endif; ?>
          </div>
          <div>
            <div style="font-weight:600;margin-bottom:6px">Contracts</div>
            <?php if ($contracts): ?>
              <ul style="list-style:none;margin:0;padding:0;display:grid;gap:6px">
                <?php foreach ($contracts as $row): ?>
                  <li><a href="/?page=contract-print&id=<?php echo (int)$row['id']; ?>">C-<?php echo (int)($row['doc_number'] ?? $row['id']); ?></a> · <?php echo htmlspecialchars($row['status']); ?></li>
                <?php endforeach; ?>
              </ul>
            <?php else: ?>
              <div style="color:var(--muted)">None</div>
            <?php endif; ?>
          </div>
          <div>
            <div style="font-weight:600;margin-bottom:6px">Invoices</div>
            <?php if ($invoices): ?>
              <ul style="list-style:none;margin:0;padding:0;display:grid;gap:6px">
                <?php foreach ($invoices as $row): ?>
                  <li><a href="/?page=invoice-print&id=<?php echo (int)$row['id']; ?>">I-<?php echo (int)($row['doc_number'] ?? $row['id']); ?></a> · $<?php echo number_format((float)$row['total'],2); ?> · <?php echo htmlspecialchars($row['status']); ?></li>
                <?php endforeach; ?>
              </ul>
            <?php else: ?>
              <div style="color:var(--muted)">None</div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</section>
