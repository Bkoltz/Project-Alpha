<?php
// src/views/pages/financial/mileage-list.php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/csrf.php';

$orgId = 1; // default organization

// Filters
$start = $_GET['start'] ?? '';
$end = $_GET['end'] ?? '';
$purpose = $_GET['purpose'] ?? '';
$clientId = isset($_GET['client_id']) && $_GET['client_id'] !== '' ? (int)$_GET['client_id'] : 0;
$billable = $_GET['billable'] ?? '';

$where = ['m.organization_id = ?'];
$params = [$orgId];

if ($start !== '') {
    $where[] = 'm.trip_date >= ?';
    $params[] = $start;
}
if ($end !== '') {
    $where[] = 'm.trip_date <= ?';
    $params[] = $end;
}
if ($purpose !== '' && in_array($purpose, ['business', 'medical', 'moving', 'charitable', 'personal'], true)) {
    $where[] = 'm.purpose = ?';
    $params[] = $purpose;
}
if ($clientId > 0) {
    $where[] = 'm.client_id = ?';
    $params[] = $clientId;
}
if ($billable === '1') {
    $where[] = 'm.is_billable = 1';
} elseif ($billable === '0') {
    $where[] = 'm.is_billable = 0';
}

$whereClause = 'WHERE ' . implode(' AND ', $where);

// Main list
$stmt = $pdo->prepare("
    SELECT m.*, c.name AS client_name
    FROM mileage_logs m
    LEFT JOIN clients c ON m.client_id = c.id
    $whereClause
    ORDER BY m.trip_date DESC
");
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Summary values over the filtered result set
$totalMiles = 0.0;
$totalDeductible = 0.0;
$businessMiles = 0.0;
$personalMiles = 0.0;

foreach ($logs as $log) {
    $miles = (float)$log['miles'];
    $rate = (float)$log['mileage_rate'];
    $deductibleMiles = !empty($log['round_trip']) ? $miles * 2 : $miles;
    $deductibleAmount = $deductibleMiles * $rate;

    $totalMiles += $miles;
    $totalDeductible += $deductibleAmount;

    if ($log['purpose'] === 'business') {
        $businessMiles += $deductibleMiles;
    } else {
        $personalMiles += $deductibleMiles;
    }
}

// Clients for filter dropdown
$clientsStmt = $pdo->query('SELECT id, name FROM clients ORDER BY name ASC');
$clients = $clientsStmt->fetchAll(PDO::FETCH_ASSOC);
?>

<section>
  <div class="page-head">
    <h2>Mileage Log</h2>
    <a href="/?page=financial/mileage-create" class="btn btn-primary">Log Mileage</a>
  </div>

  <?php if (!empty($_GET['created'])): ?>
    <div class="alert alert-success">Mileage entry created.</div>
  <?php elseif (!empty($_GET['updated'])): ?>
    <div class="alert alert-success">Mileage entry updated.</div>
  <?php elseif (!empty($_GET['deleted'])): ?>
    <div class="alert alert-success">Mileage entry deleted.</div>
  <?php elseif (!empty($_GET['error'])): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($_GET['error']); ?></div>
  <?php endif; ?>

  <!-- Summary cards -->
  <div class="grid grid-4" style="margin-bottom:20px">
    <div class="card card-tight">
      <div class="label-muted">Total Miles</div>
      <div class="font-600" style="font-size:20px"><?php echo number_format($totalMiles, 2); ?></div>
    </div>
    <div class="card card-tight">
      <div class="label-muted">Total Deductible Amount</div>
      <div class="font-600" style="font-size:20px">$\u003c?php echo number_format($totalDeductible, 2); ?></div>
    </div>
    <div class="card card-tight">
      <div class="label-muted">Business Miles</div>
      <div class="font-600" style="font-size:20px"><?php echo number_format($businessMiles, 2); ?></div>
    </div>
    <div class="card card-tight">
      <div class="label-muted">Personal/Other Miles</div>
      <div class="font-600" style="font-size:20px"><?php echo number_format($personalMiles, 2); ?></div>
    </div>
  </div>

  <!-- Filters -->
  <div class="card card-tight" style="margin-bottom:20px">
    <form method="get" action="/" class="grid grid-4">
      <input type="hidden" name="page" value="financial/mileage-list">
      <div class="field">
        <label class="label" for="filter-start">Start Date</label>
        <input type="date" id="filter-start" name="start" value="<?php echo htmlspecialchars($start); ?>" class="input input-sm">
      </div>
      <div class="field">
        <label class="label" for="filter-end">End Date</label>
        <input type="date" id="filter-end" name="end" value="<?php echo htmlspecialchars($end); ?>" class="input input-sm">
      </div>
      <div class="field">
        <label class="label" for="filter-purpose">Purpose</label>
        <select id="filter-purpose" name="purpose" class="input input-sm">
          <option value="">All</option>
          <option value="business" <?php echo $purpose === 'business' ? 'selected' : ''; ?>>Business</option>
          <option value="medical" <?php echo $purpose === 'medical' ? 'selected' : ''; ?>>Medical</option>
          <option value="moving" <?php echo $purpose === 'moving' ? 'selected' : ''; ?>>Moving</option>
          <option value="charitable" <?php echo $purpose === 'charitable' ? 'selected' : ''; ?>>Charitable</option>
          <option value="personal" <?php echo $purpose === 'personal' ? 'selected' : ''; ?>>Personal</option>
        </select>
      </div>
      <div class="field">
        <label class="label" for="filter-client">Client</label>
        <select id="filter-client" name="client_id" class="input input-sm">
          <option value="">All</option>
          <?php foreach ($clients as $c): ?>
            <option value="<?php echo (int)$c['id']; ?>" <?php echo $clientId === (int)$c['id'] ? 'selected' : ''; ?>>
              <?php echo htmlspecialchars($c['name']); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label class="label" for="filter-billable">Billable</label>
        <select id="filter-billable" name="billable" class="input input-sm">
          <option value="">All</option>
          <option value="1" <?php echo $billable === '1' ? 'selected' : ''; ?>>Billable</option>
          <option value="0" <?php echo $billable === '0' ? 'selected' : ''; ?>>Non-billable</option>
        </select>
      </div>
      <div class="field" style="display:flex;align-items:flex-end">
        <button type="submit" class="btn btn-primary">Filter</button>
        <a href="/?page=financial/mileage-list" class="btn" style="margin-left:8px">Clear</a>
      </div>
    </form>
  </div>

  <!-- Mileage table -->
  <div class="pa-table-wrap">
    <table class="pa-table">
      <thead>
        <tr>
          <th>Date</th>
          <th>Start → End</th>
          <th>Miles</th>
          <th>Round Trip</th>
          <th>Rate</th>
          <th>Deductible Amount</th>
          <th>Purpose</th>
          <th>Billable</th>
          <th>Client</th>
          <th class="text-right">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($logs)): ?>
          <tr>
            <td colspan="10" class="muted" style="text-align:center">No mileage entries found.</td>
          </tr>
        <?php else: ?>
          <?php foreach ($logs as $log):
            $miles = (float)$log['miles'];
            $rate = (float)$log['mileage_rate'];
            $deductibleMiles = !empty($log['round_trip']) ? $miles * 2 : $miles;
            $deductibleAmount = $deductibleMiles * $rate;
          ?>
            <tr>
              <td><?php echo htmlspecialchars($log['trip_date']); ?></td>
              <td>
                <?php
                $startText = $log['start_location'] ? htmlspecialchars($log['start_location']) : '—';
                $endText = $log['end_location'] ? htmlspecialchars($log['end_location']) : '—';
                echo $startText . ' → ' . $endText;
                ?>
              </td>
              <td><?php echo number_format($miles, 2); ?></td>
              <td style="text-align:center">
                <?php if (!empty($log['round_trip'])): ?>
                  <span title="Round trip: deduction uses double miles">↔️</span>
                <?php else: ?>
                  <span class="muted">—</span>
                <?php endif; ?>
              </td>
              <td>$<?php echo number_format($rate, 3); ?></td>
              <td>$<?php echo number_format($deductibleAmount, 2); ?></td>
              <td><span class="status-pill status-pill--<?php echo strtolower(htmlspecialchars($log['purpose'])); ?>"><?php echo htmlspecialchars(ucfirst($log['purpose'])); ?></span></td>
              <td>
                <?php if (!empty($log['is_billable'])): ?>
                  <span class="status-pill status-pill--paid">Yes</span>
                <?php else: ?>
                  <span class="status-pill status-pill--unpaid">No</span>
                <?php endif; ?>
              </td>
              <td><?php echo htmlspecialchars($log['client_name'] ?? '—'); ?></td>
              <td class="text-right">
                <div class="flex flex-end">
                  <a href="/?page=financial/mileage-create&id=<?php echo (int)$log['id']; ?>" class="btn btn-sm">Edit</a>
                  <form method="post" action="/?page=financial/mileage-handler" class="inline-form mileage-delete-form" style="display:inline">
                    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?php echo (int)$log['id']; ?>">
                    <button type="submit" class="btn btn-sm">Delete</button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</section>

<script>
  document.querySelectorAll('.mileage-delete-form').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      if (!confirm('Delete this mileage entry?')) {
        e.preventDefault();
        return false;
      }
      e.preventDefault();
      var data = new FormData(form);
      fetch(form.action, { method: 'POST', body: data })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          if (res.success && res.redirect) {
            window.location.href = res.redirect;
          } else {
            alert(res.message || 'Failed to delete mileage entry');
          }
        })
        .catch(function () { alert('Request failed'); });
    });
  });
</script>
