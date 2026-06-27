<?php
// src/controllers/project/project_add_document.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/csrf.php';
require_once __DIR__ . '/../../utils/acl.php';

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
    require_record_ownership($pdo, $table, $document_id);
    $exists = $pdo->prepare('SELECT id FROM project_documents WHERE project_id = ? AND document_type = ? AND document_id = ? LIMIT 1');
    $exists->execute([$project_id, $stored_document_type, $document_id]);
    if (!$exists->fetchColumn()) {
      $pdo->prepare('INSERT INTO project_documents (project_id, document_type, document_id) VALUES (?,?,?)')->execute([$project_id, $stored_document_type, $document_id]);
    }
    $pdo->prepare("UPDATE {$table} SET project_id=? WHERE id=?")->execute([$project_id, $document_id]);
  }
}
header('Location: /?page=project/projects-details&id=' . $project_id . '&added=1');
exit;
