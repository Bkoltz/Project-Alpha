<?php
// src/views/pages/calendar.php
require_once __DIR__ . '/../../config/db.php';

$start = $_GET['start'] ?? date('Y-m-01');
$end = $_GET['end'] ?? date('Y-m-t');

// Pull scheduled items from contracts and invoices within range
$st = $pdo->prepare(
  "SELECT 'contract' AS kind, id, client_id, project_code, doc_number, scheduled_date, status, created_at FROM contracts WHERE scheduled_date IS NOT NULL AND scheduled_date BETWEEN ? AND ?
   UNION ALL
   SELECT 'invoice' AS kind, id, client_id, project_code, doc_number, scheduled_date, status, created_at FROM invoices WHERE scheduled_date IS NOT NULL AND scheduled_date BETWEEN ? AND ?
   ORDER BY scheduled_date ASC, kind ASC, created_at DESC"
);
$st->execute([$start,$end,$start,$end]);
$rows = $st->fetchAll();

// Preload client names
$clients = $pdo->query('SELECT id,name FROM clients')->fetchAll(PDO::FETCH_KEY_PAIR);
?>
<section>
  <h2>Calendar</h2>
  <form method="get" action="/" style="display:grid;grid-template-columns:1fr 1fr auto auto;gap:8px;align-items:end;margin:12px 0">
    <input type="hidden" name="page" value="calendar">
    <label><div>Start</div><input type="date" name="start" value="<?php echo htmlspecialchars($start); ?>" style="padding:8px;border-radius:8px;border:1px solid #ddd"></label>
    <label><div>End</div><input type="date" name="end" value="<?php echo htmlspecialchars($end); ?>" style="padding:8px;border-radius:8px;border:1px solid #ddd"></label>
    <button type="submit" style="padding:8px 12px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: small;">Filter</button>
    <a href="/?page=calendar" style="padding:8px 12px;border:1px solid #ddd;border-radius:8px;background:#fff;display:inline-block; font-size: small;">Reset</a>
  </form>

  <?php if (!$rows): ?>
    <div style="color:var(--muted)">No scheduled items in this range.</div>
  <?php else: ?>
  <div style="overflow:auto">
    <table style="width:100%;border-collapse:collapse;background:#fff;border-radius:8px;box-shadow:0 6px 18px rgba(11,18,32,0.06)">
      <thead>
        <tr style="text-align:left;border-bottom:1px solid #eee">
          <th style="padding:10px">Date</th>
          <th style="padding:10px">Type</th>
          <th style="padding:10px">No.</th>
          <th style="padding:10px">Project</th>
          <th style="padding:10px">Client</th>
          <th style="padding:10px">Status</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <tr style="border-top:1px solid #f3f4f6">
            <td style="padding:10px"><?php echo htmlspecialchars($r['scheduled_date']); ?></td>
            <td style="padding:10px;text-transform:capitalize"><?php echo htmlspecialchars($r['kind']); ?></td>
            <td style="padding:10px">
              <?php if ($r['kind']==='contract'): ?>C-<?php echo (int)($r['doc_number'] ?? $r['id']); ?><?php else: ?>I-<?php echo (int)($r['doc_number'] ?? $r['id']); ?><?php endif; ?>
            </td>
            <td style="padding:10px"><?php echo htmlspecialchars($r['project_code'] ?? ''); ?></td>
            <td style="padding:10px"><?php echo htmlspecialchars($clients[(int)$r['client_id']] ?? ''); ?></td>
            <td style="padding:10px"><?php echo htmlspecialchars($r['status']); ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
