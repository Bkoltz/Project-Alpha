<?php
// src/controllers/project/project_remove_document.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/csrf.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
csrf_verify_post_or_redirect('project/project-remove-document');

$id = (int)($_POST['id'] ?? 0);
$redirect = (string)($_POST['redirect'] ?? '/?page=project/projects-list');
if (!$id) { header('Location: ' . $redirect . '&error=Invalid'); exit; }

$row = $pdo->prepare('SELECT project_id, document_type, document_id FROM project_documents WHERE id=? LIMIT 1');
$row->execute([$id]);
$d = $row->fetch(PDO::FETCH_ASSOC);
if ($d) {
	$pdo->prepare('DELETE FROM project_documents WHERE id=?')->execute([$id]);
	$map = ['quote'=>'quotes', 'contract'=>'contracts', 'invoice'=>'invoices', 'recurring_invoice'=>'recurring_invoices', 'long_term_contract'=>'long_term_contracts'];
	$table = $map[$d['document_type']] ?? null;
	if ($table) {
		$pdo->prepare("UPDATE {$table} SET project_id=NULL WHERE id=? AND project_id=?")->execute([$d['document_id'], $d['project_id']]);
	}
}
header('Location: ' . $redirect . '&removed=1');
exit;
