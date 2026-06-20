<?php
// src/views/pages/financial/mileage-create.php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/csrf.php';

$editMode = false;
$log = [
    'id' => null,
    'trip_date' => date('Y-m-d'),
    'start_location' => '',
    'end_location' => '',
    'miles' => '',
    'purpose' => 'business',
    'description' => '',
    'round_trip' => 0,
    'mileage_rate' => 0.670,
    'is_billable' => 0,
    'client_id' => null,
    'project_id' => null,
];

$editId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($editId > 0) {
    $stmt = $pdo->prepare('
        SELECT m.*, c.name AS client_name
        FROM mileage_logs m
        LEFT JOIN clients c ON m.client_id = c.id
        WHERE m.id = ? AND m.organization_id = ?
    ');
    $stmt->execute([$editId, 1]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($existing) {
        $editMode = true;
        $log = array_merge($log, $existing);
    }
}

// Clients for billable dropdown
$clientsStmt = $pdo->query('SELECT id, name FROM clients ORDER BY name ASC');
$clients = $clientsStmt->fetchAll(PDO::FETCH_ASSOC);
?>

<section>
  <div class="page-head">
    <h2><?php echo $editMode ? 'Edit Mileage Entry' : 'Log Mileage'; ?></h2>
    <a href="/?page=financial/mileage-list" class="btn">← Back to List</a>
  </div>

  <?php if (!empty($_GET['error'])): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($_GET['error']); ?></div>
  <?php endif; ?>

  <div class="card" style="max-width:720px">
    <form id="mileageForm" method="post" action="/?page=financial/mileage-handler">
      <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
      <input type="hidden" name="action" value="<?php echo $editMode ? 'update' : 'create'; ?>">
      <?php if ($editMode): ?>
        <input type="hidden" name="id" value="<?php echo (int)$log['id']; ?>">
      <?php endif; ?>

      <div class="grid grid-2">
        <div class="field">
          <label class="label" for="trip_date">Trip Date *</label>
          <input type="date" id="trip_date" name="trip_date" required
                 value="<?php echo htmlspecialchars($log['trip_date']); ?>" class="input">
        </div>

        <div class="field">
          <label class="label" for="miles">Miles *</label>
          <input type="number" id="miles" name="miles" required step="0.01" min="0.01"
                 value="<?php echo $log['miles'] !== '' ? htmlspecialchars(number_format((float)$log['miles'], 2, '.', '')) : ''; ?>" class="input">
        </div>
      </div>

      <div class="grid grid-2">
        <div class="field">
          <label class="label" for="start_location">Start Location</label>
          <input type="text" id="start_location" name="start_location"
                 value="<?php echo htmlspecialchars($log['start_location'] ?? ''); ?>" class="input">
        </div>

        <div class="field">
          <label class="label" for="end_location">End Location</label>
          <input type="text" id="end_location" name="end_location"
                 value="<?php echo htmlspecialchars($log['end_location'] ?? ''); ?>" class="input">
        </div>
      </div>

      <div class="field">
        <label class="label" style="display:flex;align-items:center;gap:8px;cursor:pointer">
          <input type="checkbox" id="round_trip" name="round_trip" value="1"
                 <?php echo !empty($log['round_trip']) ? 'checked' : ''; ?> style="width:18px;height:18px">
          Round Trip (doubles miles for deduction)
        </label>
        <div id="roundTripNote" class="muted-note" style="display:<?php echo !empty($log['round_trip']) ? 'block' : 'none'; ?>">
          Total miles for deduction: <span id="deductMilesBase">0</span> × 2 = <strong id="deductMilesTotal">0</strong>
        </div>
      </div>

      <div class="grid grid-2">
        <div class="field">
          <label class="label" for="purpose">Purpose</label>
          <select id="purpose" name="purpose" class="input">
            <option value="business" <?php echo ($log['purpose'] ?? '') === 'business' ? 'selected' : ''; ?>>Business</option>
            <option value="medical" <?php echo ($log['purpose'] ?? '') === 'medical' ? 'selected' : ''; ?>>Medical</option>
            <option value="moving" <?php echo ($log['purpose'] ?? '') === 'moving' ? 'selected' : ''; ?>>Moving</option>
            <option value="charitable" <?php echo ($log['purpose'] ?? '') === 'charitable' ? 'selected' : ''; ?>>Charitable</option>
            <option value="personal" <?php echo ($log['purpose'] ?? '') === 'personal' ? 'selected' : ''; ?>>Personal</option>
          </select>
        </div>

        <div class="field">
          <label class="label" for="mileage_rate">Mileage Rate</label>
          <input type="number" id="mileage_rate" name="mileage_rate" step="0.001" min="0"
                 value="<?php echo htmlspecialchars(number_format((float)($log['mileage_rate'] ?? 0.670), 3, '.', '')); ?>" class="input">
        </div>
      </div>

      <div class="field">
        <label class="label" for="description">Description</label>
        <textarea id="description" name="description" rows="3" class="input"><?php echo htmlspecialchars($log['description'] ?? ''); ?></textarea>
      </div>

      <div class="field">
        <label class="label" style="display:flex;align-items:center;gap:8px;cursor:pointer">
          <input type="checkbox" id="is_billable" name="is_billable" value="1"
                 <?php echo !empty($log['is_billable']) ? 'checked' : ''; ?> style="width:18px;height:18px">
          Billable to client
        </label>
      </div>

      <div class="field" id="clientField" style="display:<?php echo !empty($log['is_billable']) ? 'block' : 'none'; ?>">
        <label class="label" for="client_id">Client</label>
        <select id="client_id" name="client_id" class="input">
          <option value="">-- Select Client --</option>
          <?php foreach ($clients as $c): ?>
            <option value="<?php echo (int)$c['id']; ?>" <?php echo (int)($log['client_id'] ?? 0) === (int)$c['id'] ? 'selected' : ''; ?>>
              <?php echo htmlspecialchars($c['name']); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="field" id="projectField" style="display:<?php echo !empty($log['is_billable']) ? 'block' : 'none'; ?>">
        <label class="label" for="project_id">Project</label>
        <input type="number" id="project_id" name="project_id" min="0"
               value="<?php echo $log['project_id'] ? htmlspecialchars((string)$log['project_id']) : ''; ?>" class="input">
        <div class="muted-note">Optional project ID to associate with this trip.</div>
      </div>

      <div class="card card-tight" style="margin-bottom:16px">
        <div class="label-muted">Estimated Deductible Amount</div>
        <div class="font-600" style="font-size:22px">$<span id="deductibleDisplay">0.00</span></div>
        <div class="muted-note">Based on stored miles × mileage rate (×2 if round trip).</div>
      </div>

      <div class="field" style="display:flex;gap:12px">
        <button type="submit" class="btn btn-primary"><?php echo $editMode ? 'Update Mileage' : 'Save Mileage'; ?></button>
        <a href="/?page=financial/mileage-list" class="btn">Cancel</a>
      </div>
    </form>
  </div>
</section>

<script>
(function () {
  var milesInput = document.getElementById('miles');
  var rateInput = document.getElementById('mileage_rate');
  var roundTripCheck = document.getElementById('round_trip');
  var billableCheck = document.getElementById('is_billable');
  var clientField = document.getElementById('clientField');
  var projectField = document.getElementById('projectField');
  var roundTripNote = document.getElementById('roundTripNote');
  var deductMilesBase = document.getElementById('deductMilesBase');
  var deductMilesTotal = document.getElementById('deductMilesTotal');
  var deductibleDisplay = document.getElementById('deductibleDisplay');
  var form = document.getElementById('mileageForm');

  function getNum(el) {
    var v = parseFloat(el.value);
    return isNaN(v) || v < 0 ? 0 : v;
  }

  function formatCurrency(n) {
    return n.toFixed(2);
  }

  function updateCalculations() {
    var miles = getNum(milesInput);
    var rate = getNum(rateInput);
    var multiplier = roundTripCheck.checked ? 2 : 1;
    var deductibleMiles = miles * multiplier;
    var amount = deductibleMiles * rate;

    deductibleDisplay.textContent = formatCurrency(amount);

    if (roundTripCheck.checked) {
      deductMilesBase.textContent = miles.toFixed(2);
      deductMilesTotal.textContent = deductibleMiles.toFixed(2);
      roundTripNote.style.display = 'block';
    } else {
      roundTripNote.style.display = 'none';
    }
  }

  function updateBillableFields() {
    var show = billableCheck.checked;
    clientField.style.display = show ? 'block' : 'none';
    projectField.style.display = show ? 'block' : 'none';
  }

  milesInput.addEventListener('input', updateCalculations);
  rateInput.addEventListener('input', updateCalculations);
  roundTripCheck.addEventListener('change', updateCalculations);
  billableCheck.addEventListener('change', updateBillableFields);

  // Initial state
  updateCalculations();
  updateBillableFields();

  // AJAX form submission for a smoother UX
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var data = new FormData(form);
    fetch(form.action, { method: 'POST', body: data })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (res.success && res.redirect) {
          window.location.href = res.redirect;
        } else {
          alert(res.message || 'Failed to save mileage entry');
        }
      })
      .catch(function () { alert('Request failed'); });
  });
})();
</script>
