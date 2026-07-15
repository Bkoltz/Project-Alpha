<?php
// src/controllers/project/project_add_document.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/csrf.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../services/JobAssignmentService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
csrf_verify_post_or_redirect('project/project-add-document');

$project_id = (int)($_POST['project_id'] ?? 0);
$document_type = $_POST['document_type'] ?? '';
$document_id = (int)($_POST['document_id'] ?? 0);
$stored_document_type = in_array($document_type, ['long_term_contract', 'on_demand_contract'], true) ? 'contract' : $document_type;

if (!$project_id || !$document_type || !$document_id) {
  header('Location: /?page=project/projects-list&error=Missing%20parameters'); exit;
}

require_record_ownership($pdo, 'projects', $project_id);

// For convenience, update the document's project_id column where available
if (in_array($document_type, ['quote','contract','invoice','recurring_invoice','long_term_contract','on_demand_contract'], true)) {
  $map = ['quote'=>'quotes', 'contract'=>'contracts', 'invoice'=>'invoices', 'recurring_invoice'=>'invoices', 'long_term_contract'=>'contracts', 'on_demand_contract'=>'contracts'];
  $table = $map[$document_type] ?? null;
  if ($table) {
    $pdo->beginTransaction();
    try {
    require_record_ownership($pdo, $table, $document_id);
    $document = $pdo->prepare("SELECT client_id,project_code,job_id,created_by FROM {$table} WHERE id=?");
    $document->execute([$document_id]);
    $row = $document->fetch(PDO::FETCH_ASSOC);
    if (!$row) { throw new RuntimeException('Document not found.'); }
    $jobId = (int)($row['job_id'] ?? 0);
    if ($jobId <= 0) {
      $jobId = JobAssignmentService::ensureForCode($pdo, (int)$row['client_id'], (string)$row['project_code'], null, (int)($row['created_by'] ?? 0));
      $pdo->prepare("UPDATE {$table} SET job_id=? WHERE id=?")->execute([$jobId, $document_id]);
    }
    JobAssignmentService::assignProject($pdo, $jobId, $project_id);
    $pdo->commit();
    } catch (Throwable $error) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      http_response_code($error->getCode() === 409 ? 409 : 422);
      exit($error->getMessage());
    }
  }
}
header('Location: /?page=project/projects-details&id=' . $project_id . '&added_job=1');
exit;
