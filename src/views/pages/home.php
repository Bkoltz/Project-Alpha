<?php
require_once __DIR__ . '/../../config/db.php';

try {
  $pending_quotes = (int)$pdo->query("SELECT COUNT(*) FROM quotes WHERE status='pending'")->fetchColumn();
  $active_contracts = (int)$pdo->query("SELECT COUNT(*) FROM contracts WHERE status IN ('draft','active')")->fetchColumn();
  $unpaid_invoices = (int)$pdo->query("SELECT COUNT(*) FROM invoices WHERE status IN ('unpaid','partial')")->fetchColumn();
  $income_30 = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE created_at >= NOW() - INTERVAL 30 DAY")->fetchColumn();
  $clients_recent = $pdo->query("SELECT id,name,created_at FROM clients ORDER BY created_at DESC LIMIT 5")->fetchAll();
  $payments_recent = $pdo->query("SELECT p.id, p.amount, p.created_at, i.id AS invoice_id FROM payments p JOIN invoices i ON i.id=p.invoice_id ORDER BY p.created_at DESC LIMIT 5")->fetchAll();
} catch (PDOException $e) {
  // Database tables don't exist yet - show setup message
  $db_error = true;
  $pending_quotes = 0;
  $active_contracts = 0;
  $unpaid_invoices = 0;
  $income_30 = 0;
  $clients_recent = [];
  $payments_recent = [];
}
?>
<section class="hero">
  <div>
    <h1 class="h1">Dashboard</h1>
    <p class="lead">Quick glance at your business: revenue, pipelines, and recent activity.</p>
  </div>
</section>

<?php if (isset($db_error) && $db_error): ?>
  <div style="margin:24px 0;padding:16px;border-radius:10px;background:#fef3c7;border:2px solid #f59e0b;color:#92400e">
    <h3 style="margin:0 0 8px">⚠️ Database Not Initialized</h3>
    <p style="margin:0">The database tables haven't been created yet. Please run the migration script located at <code>database/migrations/000_all.sql</code> to initialize your database.</p>
  </div>
<?php endif; ?>

<section style="margin-top:24px;display:grid;gap:18px;grid-template-columns:repeat(auto-fill,minmax(240px,1fr))">
  <article class="card" style="padding:16px;border-radius:10px;background:#fff;box-shadow:0 6px 18px rgba(11,18,32,0.06)">
    <div style="color:var(--muted)">Income (30d)</div>
    <div style="font-weight:700;font-size:22px">$<?php echo number_format($income_30, 2); ?></div>
  </article>
  <article class="card" style="padding:16px;border-radius:10px;background:#fff;box-shadow:0 6px 18px rgba(11,18,32,0.06)">
    <div style="color:var(--muted)">Pending Quotes</div>
    <div style="font-weight:700;font-size:22px"><?php echo $pending_quotes; ?></div>
  </article>
  <article class="card" style="padding:16px;border-radius:10px;background:#fff;box-shadow:0 6px 18px rgba(11,18,32,0.06)">
    <div style="color:var(--muted)">Active Contracts</div>
    <div style="font-weight:700;font-size:22px"><?php echo $active_contracts; ?></div>
  </article>
  <article class="card" style="padding:16px;border-radius:10px;background:#fff;box-shadow:0 6px 18px rgba(11,18,32,0.06)">
    <div style="color:var(--muted)">Unpaid/Partial Invoices</div>
    <div style="font-weight:700;font-size:22px"><?php echo $unpaid_invoices; ?></div>
  </article>
</section>

<section style="margin-top:24px;display:grid;gap:24px;grid-template-columns:1fr 1fr">
  <div>
    <h3 style="margin:0 0 8px">Recent Clients</h3>
    <ul style="margin:0;padding:0;list-style:none;display:grid;gap:8px">
      <?php foreach ($clients_recent as $c): ?>
        <li style="padding:8px 12px;border-radius:8px;background:#fff;box-shadow:0 6px 18px rgba(11,18,32,0.06)">
          <?php echo htmlspecialchars($c['name']); ?>
          <span style="color:var(--muted);font-size:12px"> · <?php echo htmlspecialchars($c['created_at']); ?></span>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
  <div>
    <h3 style="margin:0 0 8px">Recent Payments</h3>
    <ul style="margin:0;padding:0;list-style:none;display:grid;gap:8px">
      <?php foreach ($payments_recent as $p): ?>
        <li style="padding:8px 12px;border-radius:8px;background:#fff;box-shadow:0 6px 18px rgba(11,18,32,0.06)">
          $<?php echo number_format((float)$p['amount'], 2); ?> on Invoice #<?php echo (int)$p['invoice_id']; ?>
          <span style="color:var(--muted);font-size:12px"> · <?php echo htmlspecialchars($p['created_at']); ?></span>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
</section>