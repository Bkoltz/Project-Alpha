<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

/** Transactional fan-out used by authoritative PA mutations. Caller owns the transaction. */
final class PortalProjectionMutationService
{
    public function queueProject(PDO $pdo,int $projectId):void
    {
        $statement=$pdo->prepare("SELECT DISTINCT w.public_id FROM portal_v2_workspaces w JOIN projects p ON p.id=? LEFT JOIN organizations o ON o.id=p.organization_id LEFT JOIN clients c ON c.id=p.client_id WHERE w.active=1 AND ((w.root_type='organization' AND w.root_public_id=o.public_id) OR (w.root_type='standalone_client' AND c.organization_id IS NULL AND w.root_public_id=c.public_id))");$statement->execute([$projectId]);$this->queueWorkspaces($pdo,$statement->fetchAll(PDO::FETCH_COLUMN));
    }

    public function queueOrganization(PDO $pdo,int $organizationId):void
    {
        $s=$pdo->prepare("SELECT w.public_id FROM portal_v2_workspaces w JOIN organizations o ON o.public_id=w.root_public_id WHERE w.root_type='organization' AND w.active=1 AND o.id=?");$s->execute([$organizationId]);$this->queueWorkspaces($pdo,$s->fetchAll(PDO::FETCH_COLUMN));
    }

    public function queueClient(PDO $pdo,int $clientId):void
    {
        $s=$pdo->prepare("SELECT DISTINCT w.public_id FROM portal_v2_workspaces w JOIN clients c ON c.id=? LEFT JOIN organizations o ON o.id=c.organization_id WHERE w.active=1 AND ((w.root_type='organization' AND w.root_public_id=o.public_id) OR (w.root_type='standalone_client' AND c.organization_id IS NULL AND w.root_public_id=c.public_id))");$s->execute([$clientId]);$this->queueWorkspaces($pdo,$s->fetchAll(PDO::FETCH_COLUMN));
    }

    public function queueCatalog(PDO $pdo):void
    {
        $payload=(new PortalIntegrationService())->catalog($pdo);$profileIds=$pdo->query('SELECT id FROM portal_integration_profiles WHERE enabled=1 AND catalog_projection_enabled=1 AND catalog_route IS NOT NULL ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
        foreach($profileIds as$profileId){$profile=PortalProjectionService::lockProfileContract($pdo,(int)$profileId);if(empty($profile['enabled'])||empty($profile['catalog_projection_enabled'])||empty($profile['catalog_route']))continue;$deliveryId=self::uuid();$document=['applicationKey'=>(string)$profile['application_key'],'deliveryId'=>$deliveryId]+$payload;$pdo->prepare("INSERT INTO portal_projection_outbox (integration_profile_id,delivery_id,workspace_public_id,schema_version,source_sequence,delivery_kind,route_type,payload_json) VALUES (?,?,?,?,?,'catalog.snapshot','catalog',?)")->execute([(int)$profile['id'],$deliveryId,'catalog',(int)$payload['schemaVersion'],(int)$payload['sourceSequence'],json_encode($document,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);}
    }

    private function queueWorkspaces(PDO $pdo,array $workspaceIds):void
    {
        if($workspaceIds===[])return;
        $profiles=$pdo->prepare('SELECT p.id FROM portal_integration_profiles p JOIN portal_integration_profile_workspaces pw ON pw.profile_id=p.id AND pw.active=1 JOIN portal_v2_workspaces w ON w.id=pw.workspace_id AND w.active=1 WHERE p.enabled=1 AND p.portal_projection_enabled=1 AND w.public_id=? ORDER BY p.id');
        $projection=new PortalProjectionService();
        foreach(array_unique(array_map('strval',$workspaceIds))as$workspaceId){$profiles->execute([$workspaceId]);foreach($profiles->fetchAll(PDO::FETCH_COLUMN)as$profileId)$projection->queueWorkspaceSnapshot($pdo,['id'=>(int)$profileId],$workspaceId);}
    }

    private static function uuid():string{$hex=bin2hex(random_bytes(16));return substr($hex,0,8).'-'.substr($hex,8,4).'-4'.substr($hex,13,3).'-'.dechex((hexdec($hex[16])&3)|8).substr($hex,17,3).'-'.substr($hex,20);}
}
