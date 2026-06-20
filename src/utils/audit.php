<?php
// src/utils/audit.php
// SOC 2 audit trail — logs sensitive actions to the system_audit table.
// Every call records: who (user_id), what (action), which record (entity_type/entity_id),
// additional details (JSON), and from where (IP + user-agent).

/**
 * Write an audit log entry.
 *
 * @param PDO    $pdo         Database connection
 * @param string $action      Short verb describing the action (e.g. 'login_success', 'user_create')
 * @param string|null $entityType  Entity class affected ('user', 'contract', 'invoice', 'settings', …)
 * @param int|null    $entityId    Primary key of the affected record
 * @param array       $details     Additional key-value context (stored as JSON)
 * @param int|null    $userId      Override; defaults to current session user
 */
function audit_log(
    PDO $pdo,
    string $action,
    ?string $entityType = null,
    ?int $entityId = null,
    array $details = [],
    ?int $userId = null
): void {
    try {
        if ($userId === null && session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['user']['id'])) {
            $userId = (int)$_SESSION['user']['id'];
        }

        require_once __DIR__ . '/client_ip.php';
        $ip = get_client_ip();
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? mb_substr($_SERVER['HTTP_USER_AGENT'], 0, 255) : null;

        $stmt = $pdo->prepare('
            INSERT INTO system_audit (user_id, action, entity_type, entity_id, details, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $userId,
            mb_substr($action, 0, 100),
            $entityType ? mb_substr($entityType, 0, 100) : null,
            $entityId,
            !empty($details) ? json_encode($details, JSON_UNESCAPED_SLASHES) : null,
            $ip,
            $ua,
        ]);
        // Tell the router-level audit middleware a row was already written
        // for this request, so it skips its baseline entry.
        $GLOBALS['__audit_logged'] = true;
    } catch (Throwable $e) {
        // Never let audit logging break the primary operation
        @error_log('[audit] Failed to write audit log: ' . $e->getMessage());
    }
}
