<?php
// Resolve the organization_id to stamp on a new document.
if (!function_exists('org_id_for_client')) {
    function org_id_for_client(PDO $pdo, int $clientId): ?int {
        if ($clientId <= 0) { return null; }
        $st = $pdo->prepare('SELECT organization_id FROM clients WHERE id = ?');
        $st->execute([$clientId]);
        $v = $st->fetchColumn();
        return ($v !== false && $v !== null && (int)$v > 0) ? (int)$v : null;
    }
}
