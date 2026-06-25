<?php
// src/views/pages/jobs-list.php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/twig.php';
require_once __DIR__ . '/../../../utils/acl.php';
// TODo: Change the Details Button in the project list view to "Preview" and then add button called "Details" That will open up a new page with all the details of the project.
$client_id = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;
$prefix = trim($_GET['project_prefix'] ?? '');
$selected = trim($_GET['selected_project_code'] ?? '');
$where = [];
$params = [];
if ($client_id > 0) {
  $where[] = 'pc.client_id=?';
  $params[] = $client_id;
}
if ($prefix !== '') {
  $where[] = 'pc.project_code LIKE ?';
  $params[] = $prefix . '%';
}

[$scopeWhere, $scopeParams] = scope_clause($pdo, 'pc', (int)$_SESSION['user']['id']);

// Collect distinct project codes with owning client (from any table)
$orgId = get_active_org_id();
$jobScopeWhere = $scopeWhere !== '' ? " AND pc.organization_id = ? " : '';

$sql = "SELECT pc.project_code, pc.client_id, c.name AS client_name, p.id AS project_id, p.notes,
  (SELECT COUNT(*) FROM quotes q WHERE q.project_code=pc.project_code" . ($scopeWhere !== '' ? " AND q.organization_id = ?" : "") . ") AS quotes_count,
  (SELECT COUNT(*) FROM contracts co WHERE co.project_code=pc.project_code" . ($scopeWhere !== '' ? " AND co.organization_id = ?" : "") . ") AS contracts_count,
  (SELECT COUNT(*) FROM invoices i WHERE i.project_code=pc.project_code" . ($scopeWhere !== '' ? " AND i.organization_id = ?" : "") . ") AS invoices_count
FROM (
  SELECT project_code, client_id, organization_id FROM quotes WHERE project_code IS NOT NULL" . ($scopeWhere !== '' ? " AND organization_id = ?" : "") . "
  UNION SELECT project_code, client_id, organization_id FROM contracts WHERE project_code IS NOT NULL" . ($scopeWhere !== '' ? " AND organization_id = ?" : "") . "
  UNION SELECT project_code, client_id, organization_id FROM invoices WHERE project_code IS NOT NULL" . ($scopeWhere !== '' ? " AND organization_id = ?" : "") . "
) pc JOIN clients c ON c.id=pc.client_id LEFT JOIN projects p ON p.client_id=pc.client_id AND p.name=pc.project_code";
$whereParts = array_merge($where, [$jobScopeWhere]);
$whereParts = array_filter($whereParts, function ($part) { return trim($part) !== ''; });
if ($whereParts) {
  $sql .= ' WHERE ' . implode(' AND ', array_map(function ($p) { return ltrim($p, ' AND'); }, $whereParts));
}
$sql .= ' ORDER BY pc.project_code DESC';
$rows = $pdo->prepare($sql);
// Org-id placeholders in the query when scoped: 3 subquery counts (quotes/contracts/invoices)
// + 3 UNION sources + 1 outer jobScopeWhere = 7 total. Bind exactly that many.
$jobScopeParams = $scopeWhere !== '' ? array_fill(0, 7, $orgId) : [];
// $params (prefix filter) applies to pc.project_code LIKE in the outer WHERE, which is
// appended AFTER the subquery/UNION placeholders. Reorder so bound params match token order:
// subquery counts (3) + UNION (3) come first, then outer WHERE (prefix + jobScopeWhere).
$subAndUnionParams = $scopeWhere !== '' ? array_fill(0, 6, $orgId) : [];
$outerScopeParam   = $scopeWhere !== '' ? [$orgId] : [];
$rows->execute(array_merge($subAndUnionParams, $params, $outerScopeParam));
$projects = $rows->fetchAll();
$clients = $pdo->query('SELECT id,name FROM clients ORDER BY name')->fetchAll();

$selectedRow = null;
if ($selected !== '') {
  foreach ($projects as $pr) {
    if ($pr['project_code'] === $selected) {
      $selectedRow = $pr;
      break;
    }
  }
}
?>
<section>
  <h2>Jobs</h2>
  <?php
  // Prepare client options for dropdown
  $clientOptions = [['value' => '0', 'label' => 'All Clients']];
  foreach ($clients as $c) {
      $clientOptions[] = ['value' => (string)$c['id'], 'label' => $c['name']];
  }
  
  $filterConfig = [
      'page' => 'jobs/jobs-list',
      'filters' => [
          'client_id' => [
              'type' => 'select',
              'label' => 'Client',
              'value' => (string)$client_id,
              'options' => $clientOptions
          ],
          'project_prefix' => [
              'type' => 'text',
              'label' => 'Project Prefix',
              'value' => $prefix,
              'placeholder' => 'PA-2025'
          ]
      ],
      'columns' => 4
  ];
  echo render_template('components/document-filter.html.twig', $filterConfig);
  ?>

  <?php if (!$projects): ?>
    <div style="color:var(--muted)">No jobs yet.</div>
  <?php else: ?>
    <div style="display:grid;grid-template-columns:2fr 1fr;gap:12px;align-items:start">
      <div style="display:grid;gap:12px">
        <?php foreach ($projects as $p): ?>
          <div style="border:1px solid #eee;border-radius:8px;background:#fff;overflow:hidden">
            <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 12px;border-bottom:1px solid #eee">
              <div>
                <strong>Project <?php echo htmlspecialchars($p['project_code']); ?></strong>
                <span style="color:var(--muted)"> · <?php echo htmlspecialchars($p['client_name']); ?></span>
              </div>
              <div style="display:flex;gap:12px;align-items:center">
                <?php
                $notes = isset($p['notes']) ? (string)$p['notes'] : '';
                $preview = '';
                if ($notes !== null && trim($notes) !== '') {
                  $oneLine = preg_replace('/\s+/', ' ', trim($notes));
                  if (function_exists('mb_substr')) {
                    $preview = mb_substr($oneLine, 0, 80, 'UTF-8');
                    if (mb_strlen($oneLine, 'UTF-8') > 80) {
                      $preview .= '...';
                    }
                  } else {
                    $preview = substr($oneLine, 0, 80) . (strlen($oneLine) > 80 ? '...' : '');
                  }
                }
                ?>
                <div title="<?php echo htmlspecialchars($notes ?? ''); ?>" style="color:var(--muted);max-width:520px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                  <?php echo htmlspecialchars($preview); ?>
                </div>
                <a href="/?page=jobs/job-details&amp;selected_project_code=<?php echo urlencode($p['project_code']); ?>" style="padding:6px 10px;border:1px solid #ddd;border-radius:6px;background:#fff">View Details</a>
                <a href="/?page=jobs/jobs-list&amp;selected_project_code=<?php echo urlencode($p['project_code']); ?>" style="padding:6px 10px;border:1px solid #ddd;border-radius:6px;background:#fff">Quick View</a>
                <div style="color:var(--muted)">Q: <?php echo (int)$p['quotes_count']; ?> · C: <?php echo (int)$p['contracts_count']; ?> · I: <?php echo (int)$p['invoices_count']; ?></div>
              </div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;padding:12px">
              <?php
              $pc = $p['project_code'];
              $cid = (int)$p['client_id'];
              $q = $pdo->prepare('SELECT id, doc_number, total, status, created_at FROM quotes WHERE client_id=? AND project_code=? ORDER BY created_at DESC LIMIT 5');
              $q->execute([$cid, $pc]);
              $quotes = $q->fetchAll();
              // signed_pdf_path column may be absent on older databases; select it conditionally
              $has_signed = (bool)$pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='contracts' AND COLUMN_NAME='signed_pdf_path'")->fetchColumn();
              if ($has_signed) {
                $co = $pdo->prepare('SELECT id, doc_number, status, created_at, signed_pdf_path FROM contracts WHERE client_id=? AND project_code=? ORDER BY created_at DESC LIMIT 5');
              } else {
                $co = $pdo->prepare('SELECT id, doc_number, status, created_at, NULL AS signed_pdf_path FROM contracts WHERE client_id=? AND project_code=? ORDER BY created_at DESC LIMIT 5');
              }
              $co->execute([$cid, $pc]);
              $contracts = $co->fetchAll();
              $i = $pdo->prepare('SELECT id, doc_number, total, status, created_at FROM invoices WHERE client_id=? AND project_code=? ORDER BY created_at DESC LIMIT 5');
              $i->execute([$cid, $pc]);
              $invoices = $i->fetchAll();
              ?>
              <div>
                <div style="font-weight:600;margin-bottom:6px">Quotes</div>
                <?php if ($quotes): ?>
                  <ul style="list-style:none;margin:0;padding:0;display:grid;gap:6px">
                    <?php foreach ($quotes as $row): ?>
                      <li><a href="/?page=quote/quote-print&amp;id=<?php echo (int)$row['id']; ?>">Q-<?php echo (int)($row['doc_number'] ?? $row['id']); ?></a> · $<?php echo number_format((float)$row['total'], 2); ?> · <?php echo htmlspecialchars($row['status']); ?></li>
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
                      <li>
                        <div><a href="/?page=contract/contract-print&amp;id=<?php echo (int)$row['id']; ?>">C-<?php echo (int)($row['doc_number'] ?? $row['id']); ?></a> · <?php echo htmlspecialchars($row['status']); ?></div>
                        <?php if (!empty($row['signed_pdf_path'])): ?>
                          <?php $u2 = (string)$row['signed_pdf_path'];
                          $dl2 = $u2 . (strpos($u2, '?') !== false ? '&download=1' : ''); ?>
                          <div style="margin-top:4px;display:flex;gap:6px;flex-wrap:wrap">
                            <a href="<?php echo htmlspecialchars($u2); ?>" target="_blank" style="padding:2px 6px;border-radius:6px;background:#3b82f6;color:#fff;text-decoration:none">View PDF</a>
                            <a href="<?php echo htmlspecialchars($dl2); ?>" style="padding:2px 6px;border-radius:6px;background:#6366f1;color:#fff;text-decoration:none">Download</a>
                          </div>
                        <?php endif; ?>
                      </li>
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
                      <li><a href="/?page=invoice/invoice-print&amp;id=<?php echo (int)$row['id']; ?>">I-<?php echo (int)($row['doc_number'] ?? $row['id']); ?></a> · $<?php echo number_format((float)$row['total'], 2); ?> · <?php echo htmlspecialchars($row['status']); ?></li>
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
      <div>
        <?php if ($selected !== '' && $selectedRow): ?>
          <?php
          $pn2 = $pdo->prepare('SELECT notes FROM project_meta WHERE project_code=?');
          $pn2->execute([$selected]);
          $selNotes = (string)$pn2->fetchColumn();
          $signedContracts = [];
          $has_signed = (bool)$pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='contracts' AND COLUMN_NAME='signed_pdf_path'")->fetchColumn();
          if ($has_signed) {
            $signed = $pdo->prepare("SELECT id, doc_number, signed_pdf_path, status FROM contracts WHERE project_code=? AND signed_pdf_path IS NOT NULL ORDER BY created_at DESC");
            $signed->execute([$selected]);
            $signedContracts = $signed->fetchAll();
          }
          ?>
          <div style="position:sticky;top:12px;border:1px solid #eee;border-radius:8px;background:#fff;padding:12px;display:grid;gap:12px">
            <div style="font-weight:700">Project <?php echo htmlspecialchars($selected); ?> · <?php echo htmlspecialchars($selectedRow['client_name']); ?></div>
            <form method="post" action="/?page=project-notes-update" style="display:grid;gap:8px">
              <input type="hidden" name="project_code" value="<?php echo htmlspecialchars($selected); ?>">
              <input type="hidden" name="client_id" value="<?php echo (int)$selectedRow['client_id']; ?>">
              <input type="hidden" name="redirect" value="<?php echo htmlspecialchars('/?page=jobs/jobs-list&selected_project_code=' . urlencode($selected)); ?>">
              <label>
                <div>Notes</div>
                <textarea name="notes" rows="10" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd" placeholder="Project notes visible only to you"><?php echo htmlspecialchars($selNotes ?? ''); ?></textarea>
              </label>
              <div style="display:flex;gap:8px">
                <button type="submit" style="padding:8px 12px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: small;">Save</button>
                <a href="/?page=jobs/jobs-list" style="padding:8px 12px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: small;">Close</a>
              </div>
            </form>
            <div>
              <div style="font-weight:600;margin-bottom:6px">Signed Contracts</div>
              <?php if ($signedContracts): ?>
                <ul style="list-style:none;margin:0;padding:0;display:grid;gap:6px">
                  <?php foreach ($signedContracts as $sc): ?>
                    <li style="border:1px solid #eee;border-radius:8px;padding:8px">
                      <div>C-<?php echo (int)($sc['doc_number'] ?? $sc['id']); ?> · <?php echo htmlspecialchars($sc['status']); ?></div>
                      <?php if (!empty($sc['signed_pdf_path'])): ?>
                        <?php $u3 = (string)$sc['signed_pdf_path'];
                        $dl3 = $u3 . (strpos($u3, '?') !== false ? '&download=1' : ''); ?>
                        <div style="margin-top:6px;display:flex;gap:6px;flex-wrap:wrap">
                          <a href="<?php echo htmlspecialchars($u3); ?>" target="_blank" style="padding:2px 6px;border-radius:6px;background:#3b82f6;color:#fff;text-decoration:none">View PDF</a>
                          <a href="<?php echo htmlspecialchars($dl3); ?>" style="padding:2px 6px;border-radius:6px;background:#6366f1;color:#fff;text-decoration:none">Download</a>
                        </div>
                        <div style="margin-top:6px">
                          <iframe src="<?php echo htmlspecialchars($u3); ?>" style="width:100%;height:220px;border:1px solid #eee;border-radius:6px"></iframe>
                        </div>
                      <?php endif; ?>
                    </li>
                  <?php endforeach; ?>
                </ul>
              <?php else: ?>
                <div style="color:var(--muted)">No signed contracts yet.</div>
              <?php endif; ?>
            </div>
          </div>
        <?php else: ?>
          <div style="position:sticky;top:12px;color:var(--muted);border:1px dashed #e5e7eb;border-radius:8px;padding:12px;background:#fafafa">Select a project to view and edit notes.</div>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>
</section>