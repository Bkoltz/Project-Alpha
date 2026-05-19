<?php
// src/controllers/public_link_revoke.php
// Revokes all existing public links for a document

require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');

$type = $_POST['type'] ?? '';
$id = (int)($_POST['id'] ?? 0);

if (!in_array($type, ['quote', 'contract', 'invoice'], true) || $id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
    exit;
}

try {
    // Revoke all existing links for this document
    $revokeSt = $pdo->prepare('UPDATE public_links SET revoked = 1 WHERE document_type = ? AND document_id = ? AND revoked = 0');
    $revokeSt->execute([$type, $id]);
    
    $revokedCount = $revokeSt->rowCount();
    
    echo json_encode([
        'success' => true,
        'message' => $revokedCount . ' link(s) revoked',
        'revoked_count' => $revokedCount
    ]);
    
} catch (Throwable $e) {
    @error_log('[PublicLinkRevoke] Error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Failed to revoke link']);
}
