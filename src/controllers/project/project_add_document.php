<?php
// src/controllers/project/project_add_document.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/csrf.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
csrf_verify_post_or_redirect('project/project-add-document');

$project_id = (int)($_POST['project_id'] ?? 0);
$document_type = $_POST['document_type'] ?? '';
$document_id = (int)($_POST['document_id'] ?? 0);
$stored_document_type = in_array($document_type, ['long_term_contract', 'on_demand_contract'], true) ? 'contract' : $document_type;

if (!$project_id || !$document_type || !$document_id) {
  header('Location: /?page=project/projects-list&error=Missing%20parameters'); exit;
}

// Add mapping
$pdo->prepare('INSERT INTO project_documents (project_id, document_type, document_id) VALUES (?,?,?)')->execute([$project_id, $stored_document_type, $document_id]);
// For convenience, update the document's project_id column where available
if (in_array($document_type, ['quote','contract','invoice','recurring_invoice','long_term_contract','on_demand_contract'], true)) {
  $map = ['quote'=>'quotes', 'contract'=>'contracts', 'invoice'=>'invoices', 'recurring_invoice'=>'recurring_invoices', 'long_term_contract'=>'contracts', 'on_demand_contract'=>'contracts'];
  $table = $map[$document_type] ?? null;
  if ($table) {
    $pdo->prepare("UPDATE {$table} SET project_id=? WHERE id=?")->execute([$project_id, $document_id]);
  }
}
header('Location: /?page=project/projects-list&id=' . $project_id . '&added=1');
exit;
