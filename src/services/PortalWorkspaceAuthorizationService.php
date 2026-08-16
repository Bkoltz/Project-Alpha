<?php

declare(strict_types=1);

namespace App\Services;

use DomainException;
use PDO;

/** Fail-closed authorization for the explicit integration-profile/workspace allowlist. */
final class PortalWorkspaceAuthorizationService
{
    /** @return array<string,mixed> */
    public function requireWorkspace(PDO $pdo, int $profileId, string $workspacePublicId): array
    {
        $statement = $pdo->prepare(
            'SELECT w.* FROM portal_v2_workspaces w
             JOIN portal_integration_profile_workspaces pw ON pw.workspace_id=w.id
             WHERE pw.profile_id=? AND w.public_id=? AND pw.active=1 AND w.active=1'
        );
        $statement->execute([$profileId, $workspacePublicId]);
        $workspace = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$workspace) throw new DomainException('portal-profile-workspace-denied');
        return $workspace;
    }

    /** @return array<string,mixed> */
    public function requireRoot(PDO $pdo, int $profileId, string $rootType, string $rootPublicId): array
    {
        if (!in_array($rootType, ['organization', 'standalone_client'], true)) {
            throw new DomainException('portal-profile-workspace-denied');
        }
        $statement = $pdo->prepare(
            'SELECT w.* FROM portal_v2_workspaces w
             JOIN portal_integration_profile_workspaces pw ON pw.workspace_id=w.id
             WHERE pw.profile_id=? AND w.root_type=? AND w.root_public_id=? AND pw.active=1 AND w.active=1'
        );
        $statement->execute([$profileId, $rootType, $rootPublicId]);
        $workspace = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$workspace) throw new DomainException('portal-profile-workspace-denied');
        return $workspace;
    }
}
