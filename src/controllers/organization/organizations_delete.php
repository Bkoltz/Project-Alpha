<?php
// src/controllers/organization/organizations_delete.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/csrf.php';
require_once __DIR__ . '/../../utils/portal_projection_hooks.php';

// Verify CSRF token
csrf_verify_post_or_redirect('organization/organizations-edit');

error_log('ORG_DELETE: Request received. POST: ' . json_encode($_POST));

$id = (int)($_POST['id'] ?? 0);
error_log('ORG_DELETE: Organization ID: ' . $id);

if ($id <= 0) {
    header('Location: /?page=organization/organizations-list&error=Invalid%20organization');
    exit;
}

// Delete the organization (clients will have organization_id set to NULL via ON DELETE SET NULL)
$projection = new App\Services\PortalProjectionMutationService();
$before = $projection->organizationScopes($pdo, $id);
$deleted = portal_projection_mutate($pdo, $before, static function () use ($pdo, $id): int {
    $stmt = $pdo->prepare('DELETE FROM organizations WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->rowCount();
}, static fn(): array => []);
error_log('ORG_DELETE: Organization deleted. Rows affected: ' . (int)$deleted);

header('Location: /?page=organization/organizations-list&deleted=1');
exit;
