<?php
if (empty($appConfig['job_project_locations_enabled'])) return;
require_once __DIR__ . '/../../utils/document_locations.php';
$documentServiceLocations = document_service_location_options($pdo);
$documentServiceLocationId = (int)($documentServiceLocationId ?? 0);
?>
<label data-document-service-location>
  <div>Service location</div>
  <select name="service_location_id" class="input" style="width:100%">
    <option value="">Use the Job or Project default</option>
    <?php foreach ($documentServiceLocations as $location): ?>
      <?php
      $context = trim((string)($location['client_name'] ?: $location['project_name'] ?: ''));
      $address = trim(implode(', ', array_filter([$location['address_line1'] ?? '', $location['city'] ?? '', $location['state'] ?? ''])));
      $label = (string)$location['name'] . ($context !== '' ? ' — ' . $context : '') . ($address !== '' ? ' (' . $address . ')' : '');
      ?>
      <option value="<?php echo (int)$location['id']; ?>" <?php echo $documentServiceLocationId === (int)$location['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></option>
    <?php endforeach; ?>
  </select>
  <small style="color:var(--muted)">Billing addresses remain separate. This location is copied into the document revision when saved.</small>
</label>
