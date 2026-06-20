<?php
// src/views/pages/clients-list.php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/format.php';
require_once __DIR__ . '/../../../utils/twig.php';
$per = null; // show all clients
$pageN = 1;
$offset = 0;
$q = trim($_GET['q'] ?? '');
$org = trim($_GET['org'] ?? '');
$where = [];
$params = [];
if ($q !== '') { $where[] = 'c.name LIKE ?'; $params[] = '%'.$q.'%'; }
if ($org !== '') { $where[] = 'o.name LIKE ?'; $params[] = '%'.$org.'%'; }

// Guard for older DBs without 'archived' column
$hasArchived = (bool)$pdo->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='clients' AND COLUMN_NAME='archived'")->fetchColumn();
$activeFilter = $hasArchived ? 'archived=0' : '1=1';

// Build WHERE clause
$whereClause = 'WHERE '.$activeFilter;
if (!empty($where)) {
  $whereClause .= ' AND ('.implode(' AND ', $where).')';
}

// fetch all clients without pagination
$sql = "SELECT c.id, c.name, c.email, c.phone, c.created_at, o.name as organization_name 
        FROM clients c 
        LEFT JOIN organizations o ON c.organization_id = o.id 
        $whereClause
        ORDER BY c.name ASC";
$st = $pdo->prepare($sql);
$st->execute($params);
$clients = $st->fetchAll();
?>
<section>
  <h2>Clients</h2>
  <div class="mb-1">
    <a href="/?page=client/archived-clients" class="btn btn-sm">View Archived</a>
  </div>
  <?php if (!empty($_GET['archived'])): ?>
    <div class="alert alert-success">Client archived.</div>
  <?php elseif (!empty($_GET['restored'])): ?>
    <div class="alert alert-success">Client restored.</div>
  <?php elseif (!empty($_GET['deleted'])): ?>
    <div class="alert alert-danger">Client permanently deleted.</div>
  <?php endif; ?>
  <?php $selected = isset($_GET['selected_client_id']) ? (int)$_GET['selected_client_id'] : 0; ?>
  <div class="grid-2 align-start">
  <div>
  <?php
  $filterConfig = [
      'page' => 'client/clients-list',
      'filters' => [
          'q' => [
              'type' => 'text',
              'label' => 'Search by Name',
              'value' => $q,
              'placeholder' => 'Type to search...'
          ],
          'org' => [
              'type' => 'text',
              'label' => 'Search by Organization',
              'value' => $org,
              'placeholder' => 'Type to search...'
          ]
      ],
      'columns' => 4
  ];
  echo render_template('components/document-filter.html.twig', $filterConfig);
  ?>
  <?php if (!empty($_GET['created'])): ?>
    <div class="alert alert-success">Client created.</div>
  <?php endif; ?>
  <div class="pa-table-wrap">
    <table class="pa-table">
      <thead>
        <tr>
          <th>Name</th>
          <th>Email</th>
          <th>Phone</th>
          <th>Organization</th>
          <!-- <th>Created</th> -->
          <th>Edit</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($clients as $c): ?>
          <tr>
            <td><a href="/?page=client/clients-list&selected_client_id=<?php echo (int)$c['id']; ?>" class="font-600"><?php echo htmlspecialchars($c['name']); ?></a></td>
            <td><?php echo htmlspecialchars($c['email'] ?? ''); ?></td>
            <td><?php echo htmlspecialchars(format_phone($c['phone'] ?? '')); ?></td>
            <td><?php echo htmlspecialchars($c['organization_name'] ?? ''); ?></td>
            <!-- <td><?php echo htmlspecialchars($c['created_at']); ?></td> -->
            <td><a href="/?page=client/clients-edit&id=<?php echo (int)$c['id']; ?>" class="btn btn-sm">Edit</a></td>
            <td>
              <!-- TODO: archive button does not work on the edit view of a client -->
              <form method="post" action="/?page=client/clients-delete" onsubmit="return confirm('Archive client <?php echo addslashes($c['name']); ?>? This moves the client to Archived Clients.');" class="inline-form">
                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                <input type="hidden" name="id" value="<?php echo (int)$c['id']; ?>">
                <button type="submit" class="btn btn-sm">Archive</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <!-- Pagination removed: showing all clients -->
  </div>
  <div>
    <h3 class="card-title">Related Projects</h3>
    <?php if ($selected>0): ?>
      <?php
      // Gather distinct project codes for this client
      $proj = $pdo->prepare("SELECT project_code FROM (
        SELECT project_code FROM quotes WHERE client_id=? AND project_code IS NOT NULL
        UNION DISTINCT SELECT project_code FROM contracts WHERE client_id=? AND project_code IS NOT NULL
        UNION DISTINCT SELECT project_code FROM invoices WHERE client_id=? AND project_code IS NOT NULL
      ) t ORDER BY project_code DESC");
      $proj->execute([$selected,$selected,$selected]);
      $projects = $proj->fetchAll(PDO::FETCH_COLUMN);
      ?>
      <?php if ($projects): ?>
        <div class="grid">
          <?php foreach ($projects as $pc): ?>
            <div class="card card-tight">
              <div class="card-head"><span class="card-title">Project <?php echo htmlspecialchars($pc); ?></span></div>
              <div class="grid-3 card-body">
                <?php
                  $q = $pdo->prepare('SELECT id, doc_number, total, status, created_at FROM quotes WHERE client_id=? AND project_code=? ORDER BY created_at DESC');
                  $q->execute([$selected, $pc]); $quotes = $q->fetchAll();
                  $co = $pdo->prepare('SELECT id, doc_number, status, created_at FROM contracts WHERE client_id=? AND project_code=? ORDER BY created_at DESC');
                  $co->execute([$selected, $pc]); $contracts = $co->fetchAll();
                  $i = $pdo->prepare('SELECT id, doc_number, total, status, created_at FROM invoices WHERE client_id=? AND project_code=? ORDER BY created_at DESC');
                  $i->execute([$selected, $pc]); $invoices = $i->fetchAll();
                ?>
                <div>
                  <div class="font-600 mb-1">Quotes</div>
                  <?php if ($quotes): ?>
                    <ul class="list-plain grid">
                      <?php foreach ($quotes as $row): ?>
                        <li><a href="/?page=quote-print&id=<?php echo (int)$row['id']; ?>">Q-<?php echo (int)($row['doc_number'] ?? $row['id']); ?></a> · $<?php echo number_format((float)$row['total'],2); ?> · <?php echo htmlspecialchars($row['status']); ?></li>
                      <?php endforeach; ?>
                    </ul>
                  <?php else: ?>
                    <div class="muted">None</div>
                  <?php endif; ?>
                </div>
                <div>
                  <div class="font-600 mb-1">Contracts</div>
                  <?php if ($contracts): ?>
                    <ul class="list-plain grid">
                      <?php foreach ($contracts as $row): ?>
                        <li><a href="/?page=contract-print&id=<?php echo (int)$row['id']; ?>">C-<?php echo (int)($row['doc_number'] ?? $row['id']); ?></a> · <?php echo htmlspecialchars($row['status']); ?></li>
                      <?php endforeach; ?>
                    </ul>
                  <?php else: ?>
                    <div class="muted">None</div>
                  <?php endif; ?>
                </div>
                <div>
                  <div class="font-600 mb-1">Invoices</div>
                  <?php if ($invoices): ?>
                    <ul class="list-plain grid">
                      <?php foreach ($invoices as $row): ?>
                        <li><a href="/?page=invoice-print&id=<?php echo (int)$row['id']; ?>">I-<?php echo (int)($row['doc_number'] ?? $row['id']); ?></a> · $<?php echo number_format((float)$row['total'],2); ?> · <?php echo htmlspecialchars($row['status']); ?></li>
                      <?php endforeach; ?>
                    </ul>
                  <?php else: ?>
                    <div class="muted">None</div>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <div class="muted">No projects for this client yet.</div>
      <?php endif; ?>
    <?php else: ?>
      <div class="muted">Select a client on the left to view related projects and documents.</div>
    <?php endif; ?>
  </div>
</section>
