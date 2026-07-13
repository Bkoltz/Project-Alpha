<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping;

use PDO;

final class AuditRecorder
{
    public function __construct(private readonly PDO $pdo) {}

    public function record(
        string $action,
        string $entityType,
        string $entityUuid,
        ?int $actorId,
        array $before = [],
        array $after = [],
        ?string $correlationId = null
    ): void {
        $details = ['module' => 'workforce'];
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $agent = isset($_SERVER['HTTP_USER_AGENT']) ? mb_substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 255) : null;
        $stmt = $this->pdo->prepare(
            'INSERT INTO system_audit
             (user_id,action,entity_type,entity_uuid,details,before_data,after_data,correlation_id,ip_address,user_agent)
             VALUES (?,?,?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            $actorId,
            mb_substr($action, 0, 100),
            mb_substr($entityType, 0, 100),
            $entityUuid,
            json_encode($details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            $before === [] ? null : json_encode($before, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            $after === [] ? null : json_encode($after, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            $correlationId,
            $ip,
            $agent,
        ]);
        $GLOBALS['__audit_logged'] = true;
    }
}
