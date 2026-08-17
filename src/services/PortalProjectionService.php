<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PDO;

final class PortalProjectionService
{
    /** Queue a complete bounded generation. Caller owns the surrounding transaction. */
    public function queueWorkspaceSnapshot(PDO $pdo, array $profile, string $workspacePublicId): array
    {
        $profileId = (int)($profile['id'] ?? 0);
        if ($profileId < 1) throw new DomainException('portal-profile-workspace-denied');
        $profile = self::lockProfileContract($pdo, $profileId);
        if (empty($profile['enabled']) || empty($profile['portal_projection_enabled'])) throw new DomainException('portal-profile-disabled');
        $workspace = (new PortalWorkspaceAuthorizationService())->requireWorkspace($pdo, $profileId, $workspacePublicId);
        $schemaVersion = !empty($profile['relation_projection_enabled']) ? 3 : 2;
        $generation = self::uuid();
        $sequence = $this->nextSequence($pdo, $profileId, $workspacePublicId, $generation);
        $projection = $this->workspaceProjection($pdo, $workspace, $schemaVersion);
        $records = [];
        foreach (['entities','principals','entitlements','relations','projectLifecycles'] as $family) {
            foreach ($projection[$family] ?? [] as $record) $records[] = [$family, $record];
        }
        if (count($records) > 2000) throw new DomainException('portal-workspace-too-large');
        $pages = array_chunk($records, 100);
        if ($pages === []) $pages = [[]];
        if (count($pages) > 100) throw new DomainException('portal-workspace-page-limit');
        $snapshotHash = hash('sha256', self::canonicalJson($projection));
        $now = self::now();
        foreach ($pages as $index => $pageRecords) {
            $pageFamilies = ['entities'=>[],'principals'=>[],'entitlements'=>[],'relations'=>[],'projectLifecycles'=>[]];
            foreach ($pageRecords as [$family,$record]) $pageFamilies[$family][] = $record;
            $payload = [
                'schemaVersion'=>$schemaVersion, 'applicationKey'=>(string)$profile['application_key'],
                'deliveryId'=>self::uuid(), 'occurredAt'=>$now, 'sourceGeneration'=>$generation,
                'sourceSequence'=>$sequence, 'workspaceId'=>$workspacePublicId, 'kind'=>'snapshot.page',
                'snapshotHash'=>$snapshotHash, 'pageNumber'=>$index+1, 'pageCount'=>count($pages),
                'recordCount'=>count($records), 'workspace'=>[
                    'publicId'=>$workspacePublicId, 'rootType'=>(string)$workspace['root_type'],
                    'rootPublicId'=>(string)$workspace['root_public_id'], 'displayName'=>(string)$workspace['display_name'],
                    'sourceVersion'=>PortalSourceVersion::from(['publicId'=>$workspacePublicId,'rootType'=>(string)$workspace['root_type'],'rootPublicId'=>(string)$workspace['root_public_id'],'displayName'=>(string)$workspace['display_name'],'active'=>true]), 'active'=>true,
                ],
                'entities'=>$pageFamilies['entities'], 'principals'=>$pageFamilies['principals'], 'entitlements'=>$pageFamilies['entitlements'],
            ];
            if ($schemaVersion === 3) {
                $payload['relations']=$pageFamilies['relations'];
                $payload['projectLifecycles']=$pageFamilies['projectLifecycles'];
            }
            PortalIntegrationContract::validatePortalDelivery($payload,$schemaVersion===3);
            $this->enqueue($pdo, $profile, $workspacePublicId, $schemaVersion, $sequence, 'snapshot.page', 'portal', $payload);
        }
        $activation = [
            'schemaVersion'=>$schemaVersion, 'applicationKey'=>(string)$profile['application_key'],
            'deliveryId'=>self::uuid(), 'occurredAt'=>$now, 'sourceGeneration'=>$generation,
            'sourceSequence'=>$sequence, 'workspaceId'=>$workspacePublicId, 'kind'=>'snapshot.activate',
            'snapshotHash'=>$snapshotHash, 'pageCount'=>count($pages), 'recordCount'=>count($records),
        ];
        PortalIntegrationContract::validatePortalDelivery($activation,$schemaVersion===3);
        $this->enqueue($pdo, $profile, $workspacePublicId, $schemaVersion, $sequence, 'snapshot.activate', 'portal', $activation);
        $pdo->prepare('UPDATE portal_projection_state SET last_snapshot_hash=? WHERE integration_profile_id=? AND workspace_public_id=?')
            ->execute([$snapshotHash,(int)$profile['id'],$workspacePublicId]);
        return ['sourceGeneration'=>$generation,'sourceSequence'=>$sequence,'snapshotHash'=>$snapshotHash,'pageCount'=>count($pages),'recordCount'=>count($records)];
    }

    /** Queue a complete, bounded Service Library generation. Caller owns the transaction. */
    public function queueCatalogSnapshot(PDO $pdo,array $profile):array
    {
        $profileId=(int)($profile['id']??0);if($profileId<1)throw new DomainException('portal-profile-disabled');
        $profile=self::lockProfileContract($pdo,$profileId);if(empty($profile['enabled'])||empty($profile['catalog_projection_enabled'])||empty($profile['catalog_route']))throw new DomainException('catalog-profile-disabled');
        $catalog=(new PortalIntegrationService())->catalog($pdo);$items=$catalog['items'];if(count($items)>500)throw new DomainException('catalog-item-limit');
        $generation='catalog-'.self::uuid();$sequence=$this->nextSequence($pdo,$profileId,'catalog',$generation);$pages=array_chunk($items,50);if($pages===[])$pages=[[]];
        $snapshotHash=hash('sha256',self::canonicalJson(['items'=>$items]));$now=self::now();$pageCount=count($pages);$itemCount=count($items);
        foreach($pages as$index=>$pageItems){$payload=[
            'schemaVersion'=>2,'applicationKey'=>(string)$profile['application_key'],'deliveryId'=>self::uuid(),'occurredAt'=>$now,
            'sourceGeneration'=>$generation,'sourceSequence'=>$sequence,'kind'=>'snapshot.page','snapshotHash'=>$snapshotHash,
            'pageNumber'=>$index+1,'pageCount'=>$pageCount,'itemCount'=>$itemCount,'items'=>$pageItems,
        ];PortalIntegrationContract::validateCatalogDelivery($payload);$this->enqueue($pdo,$profile,'catalog',2,$sequence,'snapshot.page','catalog',$payload);}
        $activation=[
            'schemaVersion'=>2,'applicationKey'=>(string)$profile['application_key'],'deliveryId'=>self::uuid(),'occurredAt'=>$now,
            'sourceGeneration'=>$generation,'sourceSequence'=>$sequence,'kind'=>'snapshot.activate','snapshotHash'=>$snapshotHash,
            'pageCount'=>$pageCount,'itemCount'=>$itemCount,
        ];PortalIntegrationContract::validateCatalogDelivery($activation);$this->enqueue($pdo,$profile,'catalog',2,$sequence,'snapshot.activate','catalog',$activation);
        $pdo->prepare('UPDATE portal_projection_state SET last_snapshot_hash=? WHERE integration_profile_id=? AND workspace_public_id=?')->execute([$snapshotHash,$profileId,'catalog']);
        return['sourceGeneration'=>$generation,'sourceSequence'=>$sequence,'snapshotHash'=>$snapshotHash,'pageCount'=>$pageCount,'itemCount'=>$itemCount];
    }

    /** Queue one ordered incremental delivery after a complete generation exists. */
    public function queueEvent(PDO $pdo, array $profile, string $workspacePublicId, array $event, bool $isRevocation=false): array
    {
        $profileId=(int)($profile['id']??0);if($profileId<1)throw new DomainException('portal-profile-workspace-denied');
        $profile=self::lockProfileContract($pdo,$profileId);if(empty($profile['enabled'])||empty($profile['portal_projection_enabled']))throw new DomainException('portal-profile-disabled');
        (new PortalWorkspaceAuthorizationService())->requireWorkspace($pdo,$profileId,$workspacePublicId);
        $state = $this->stateForUpdate($pdo, (int)$profile['id'], $workspacePublicId);
        if (!$state || empty($state['last_snapshot_hash'])) throw new DomainException('portal-snapshot-required');
        $sequence = (int)$state['source_sequence'] + 1;
        $pdo->prepare('UPDATE portal_projection_state SET source_sequence=? WHERE integration_profile_id=? AND workspace_public_id=?')
            ->execute([$sequence,(int)$profile['id'],$workspacePublicId]);
        $payload = [
            'schemaVersion'=>!empty($profile['relation_projection_enabled'])?3:2,
            'applicationKey'=>(string)$profile['application_key'], 'deliveryId'=>self::uuid(), 'occurredAt'=>self::now(),
            'sourceGeneration'=>(string)$state['source_generation'], 'sourceSequence'=>$sequence,
            'workspaceId'=>$workspacePublicId, 'kind'=>'event', 'event'=>$event,
        ];
        PortalIntegrationContract::validatePortalDelivery($payload,!empty($profile['relation_projection_enabled']));
        $this->enqueue($pdo,$profile,$workspacePublicId,(int)$payload['schemaVersion'],$sequence,'event','portal',$payload,$isRevocation);
        return $payload;
    }

    /**
     * Queue a final profile-scoped revocation for a workspace that was previously
     * activated. A never-published workspace has nothing downstream to revoke.
     * Caller owns the transaction and must deactivate the link/profile afterward.
     *
     * @return array<string,mixed>|null
     */
    public function queueWorkspaceRevocation(PDO $pdo,array $profile,string $workspacePublicId):?array
    {
        $profileId=(int)($profile['id']??0);if($profileId<1)throw new DomainException('portal-profile-workspace-denied');
        $profile=self::lockProfileContract($pdo,$profileId);if(empty($profile['enabled'])||empty($profile['portal_projection_enabled']))throw new DomainException('portal-profile-disabled');
        (new PortalWorkspaceAuthorizationService())->requireWorkspace($pdo,$profileId,$workspacePublicId);
        $state=$this->stateForUpdate($pdo,$profileId,$workspacePublicId);
        if(!$state||empty($state['last_snapshot_hash']))return null;
        $payload=$this->queueEvent($pdo,$profile,$workspacePublicId,[
            'resource'=>'workspace','action'=>'tombstone','publicId'=>$workspacePublicId,
            'sourceVersion'=>PortalSourceVersion::from(['publicId'=>$workspacePublicId,'active'=>false]),
        ],true);
        $pdo->prepare('UPDATE portal_projection_state SET last_snapshot_hash=NULL WHERE integration_profile_id=? AND workspace_public_id=?')->execute([$profileId,$workspacePublicId]);
        return$payload;
    }

    /**
     * Every outbox producer and profile contract mutation takes this same row
     * lock. The caller must own a transaction so the lock spans the enqueue or
     * key/route update rather than being released after this statement.
     *
     * @return array<string,mixed>
     */
    public static function lockProfileContract(PDO $pdo,int $profileId):array
    {
        if(!$pdo->inTransaction())throw new DomainException('portal-outbox-transaction-required');
        $suffix=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
        $statement=$pdo->prepare('SELECT * FROM portal_integration_profiles WHERE id=?'.$suffix);$statement->execute([$profileId]);$profile=$statement->fetch(PDO::FETCH_ASSOC);
        if(!$profile)throw new DomainException('Integration profile not found.');
        return$profile;
    }

    /** @return array<string,list<array<string,mixed>>> */
    private function workspaceProjection(PDO $pdo, array $workspace, int $schemaVersion): array
    {
        $rootType=(string)$workspace['root_type']; $rootId=(string)$workspace['root_public_id'];
        $entities=[]; $relations=[]; $lifecycles=[]; $scopeIds=['workspace'=>[(string)$workspace['public_id']]];
        if ($rootType==='organization') {
            $org=$this->one($pdo,'SELECT public_id,name,source_version FROM organizations WHERE public_id=?',[$rootId]);
            if(!$org) throw new DomainException('portal-workspace-root-missing');
            $entities[]=$this->entity('organization',$org,null,false);
            $scopeIds['organization']=[$rootId];
            $departments=$this->all($pdo,'SELECT public_id,name,source_version FROM organization_departments WHERE organization_id=(SELECT id FROM organizations WHERE public_id=?) ORDER BY public_id',[$rootId]);
            foreach($departments as $row){$entities[]=$this->entity('department',$row,$rootId,false);$scopeIds['department'][]=(string)$row['public_id'];}
            $clients=$this->all($pdo,'SELECT public_id,name,source_version,id FROM clients WHERE organization_id=(SELECT id FROM organizations WHERE public_id=?) AND archived=0 AND deleted_at IS NULL ORDER BY public_id',[$rootId]);
            foreach($clients as $row){$entities[]=$this->entity('client',$row,$rootId,false);$scopeIds['client'][]=(string)$row['public_id'];}
            $pdo->prepare('INSERT IGNORE INTO portal_v2_contacts (client_id,display_name) SELECT DISTINCT c.id,c.name FROM organization_department_contacts dc JOIN clients c ON c.id=dc.client_id JOIN organization_departments d ON d.id=dc.department_id JOIN organizations o ON o.id=d.organization_id WHERE o.public_id=?')->execute([$rootId]);
            $pdo->prepare('UPDATE portal_v2_contacts pc JOIN clients c ON c.id=pc.client_id SET pc.display_name=c.name,pc.active=1 WHERE c.organization_id=(SELECT id FROM organizations WHERE public_id=?)')->execute([$rootId]);
            $contacts=$this->all($pdo,'SELECT pc.public_id,pc.display_name,pc.source_version,pc.active,MIN(d.public_id) parent_public_id,MAX(dc.is_primary) primary_contact FROM portal_v2_contacts pc JOIN organization_department_contacts dc ON dc.client_id=pc.client_id JOIN organization_departments d ON d.id=dc.department_id JOIN organizations o ON o.id=d.organization_id WHERE o.public_id=? GROUP BY pc.public_id,pc.display_name,pc.source_version,pc.active ORDER BY pc.public_id',[$rootId]);
            foreach($contacts as$row){$contact=['type'=>'contact','publicId'=>(string)$row['public_id'],'parentPublicId'=>(string)$row['parent_public_id'],'displayName'=>(string)$row['display_name'],'active'=>(bool)$row['active'],'primaryContact'=>(bool)$row['primary_contact']];$entities[]=['type'=>$contact['type'],'publicId'=>$contact['publicId'],'parentPublicId'=>$contact['parentPublicId'],'displayName'=>$contact['displayName'],'sourceVersion'=>PortalSourceVersion::from($contact),'active'=>$contact['active'],'primaryContact'=>$contact['primaryContact']];}
            $projects=$this->all($pdo,"SELECT p.public_id,p.name,p.source_version,p.status,p.completed_at,p.department_id,p.client_id,d.public_id department_public_id,c.public_id client_public_id FROM projects p LEFT JOIN organization_departments d ON d.id=p.department_id LEFT JOIN clients c ON c.id=p.client_id WHERE p.organization_id=(SELECT id FROM organizations WHERE public_id=?) AND p.status<>'cancelled' ORDER BY p.public_id",[$rootId]);
        } else {
            $client=$this->one($pdo,'SELECT public_id,name,source_version,id FROM clients WHERE public_id=? AND organization_id IS NULL AND archived=0 AND deleted_at IS NULL',[$rootId]);
            if(!$client) throw new DomainException('portal-workspace-root-missing');
            $entities[]=$this->entity('standalone_client',$client,null,false);$scopeIds['standalone_client']=[$rootId];$scopeIds['client']=[$rootId];
            $clients=[$client];$projects=$this->all($pdo,"SELECT p.public_id,p.name,p.source_version,p.status,p.completed_at,p.department_id,p.client_id,NULL department_public_id,? client_public_id FROM projects p WHERE p.client_id=? AND p.status<>'cancelled' ORDER BY p.public_id",[$rootId,(int)$client['id']]);
        }
        foreach($projects as $row){
            $parent=(string)($row['department_public_id']?:($row['client_public_id']?:$rootId));
            $entities[]=$this->entity('project',$row,$parent,false);$scopeIds['project'][]=(string)$row['public_id'];
            $status=(string)$row['status']==='completed'?'completed':'active';if($status==='completed'&&empty($row['completed_at']))throw new DomainException('portal-project-completed-at-missing');
            $lifecycle=['projectPublicId'=>(string)$row['public_id'],'status'=>$status,'completedAt'=>$status==='completed'?$this->utc($row['completed_at']):null];$lifecycles[]=['projectPublicId'=>$lifecycle['projectPublicId'],'status'=>$lifecycle['status'],'completedAt'=>$lifecycle['completedAt'],'sourceVersion'=>PortalSourceVersion::from($lifecycle)];
        }
        if($schemaVersion===3){$entityMap=[];foreach($entities as$entity)$entityMap[(string)$entity['type'].'|'.(string)$entity['publicId']]=true;foreach($this->all($pdo,'SELECT * FROM portal_v2_relations ORDER BY public_id',[])as$row){if(!isset($entityMap[(string)$row['from_type'].'|'.(string)$row['from_public_id']],$entityMap[(string)$row['to_type'].'|'.(string)$row['to_public_id']]))continue;$relation=['publicId'=>(string)$row['public_id'],'relationType'=>(string)$row['relation_type'],'from'=>['type'=>(string)$row['from_type'],'publicId'=>(string)$row['from_public_id']],'to'=>['type'=>(string)$row['to_type'],'publicId'=>(string)$row['to_public_id']],'active'=>(bool)$row['active']];$relations[]=['publicId'=>$relation['publicId'],'relationType'=>$relation['relationType'],'from'=>$relation['from'],'to'=>$relation['to'],'sourceVersion'=>PortalSourceVersion::from($relation),'active'=>$relation['active']];}}
        $entitlements=[];$principalIds=[];
        foreach($scopeIds as $scopeType=>$ids){foreach(array_unique($ids??[]) as $scopeId){
            $rows=$this->all($pdo,'SELECT e.*,p.public_id principal_public_id FROM portal_v2_entitlements e JOIN portal_principals p ON p.id=e.portal_principal_id WHERE e.scope_type=? AND e.scope_public_id=?',[$scopeType,$scopeId]);
            foreach($rows as $row){$principalIds[(int)$row['portal_principal_id']]=true;$visible=['publicId'=>(string)$row['public_id'],'principalPublicId'=>(string)$row['principal_public_id'],'capability'=>(string)$row['capability'],'effect'=>(string)$row['effect'],'scopeType'=>(string)$row['scope_type'],'scopePublicId'=>(string)$row['scope_public_id'],'active'=>(bool)$row['active'],'validFrom'=>$this->utc($row['valid_from']),'expiresAt'=>$this->utc($row['expires_at'])];$entitlements[]=['publicId'=>$visible['publicId'],'principalPublicId'=>$visible['principalPublicId'],'capability'=>$visible['capability'],'effect'=>$visible['effect'],'scopeType'=>$visible['scopeType'],'scopePublicId'=>$visible['scopePublicId'],'sourceVersion'=>PortalSourceVersion::from($visible),'active'=>$visible['active'],'validFrom'=>$visible['validFrom'],'expiresAt'=>$visible['expiresAt']];}
        }}
        $principals=[];foreach(array_keys($principalIds) as $id){$row=$this->one($pdo,'SELECT * FROM portal_principals WHERE id=?',[$id]);if($row){$visible=['publicId'=>(string)$row['public_id'],'emailHint'=>(string)($row['email_hint']??''),'displayName'=>(string)($row['display_name']??''),'active'=>(bool)$row['enabled']&&empty($row['revoked_at'])];$principals[]=['publicId'=>$visible['publicId'],'emailHint'=>$visible['emailHint'],'displayName'=>$visible['displayName'],'sourceVersion'=>PortalSourceVersion::from($visible),'active'=>$visible['active']];}}
        return ['entities'=>$entities,'principals'=>$principals,'entitlements'=>$entitlements,'relations'=>$schemaVersion===3?$relations:[],'projectLifecycles'=>$schemaVersion===3?$lifecycles:[]];
    }

    private function nextSequence(PDO $pdo,int $profileId,string $workspaceId,string $generation):int
    {
        $state=$this->stateForUpdate($pdo,$profileId,$workspaceId);$sequence=$state?(int)$state['source_sequence']+1:1;
        if($state)$pdo->prepare('UPDATE portal_projection_state SET source_generation=?,source_sequence=?,last_snapshot_hash=NULL WHERE integration_profile_id=? AND workspace_public_id=?')->execute([$generation,$sequence,$profileId,$workspaceId]);
        else $pdo->prepare('INSERT INTO portal_projection_state (integration_profile_id,workspace_public_id,source_generation,source_sequence) VALUES (?,?,?,?)')->execute([$profileId,$workspaceId,$generation,$sequence]);
        return $sequence;
    }
    private function stateForUpdate(PDO $pdo,int $profileId,string $workspaceId):array|false{$suffix=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';$s=$pdo->prepare('SELECT * FROM portal_projection_state WHERE integration_profile_id=? AND workspace_public_id=?'.$suffix);$s->execute([$profileId,$workspaceId]);return $s->fetch(PDO::FETCH_ASSOC);}
    private function enqueue(PDO $pdo,array $profile,string $workspaceId,int $schema,int $sequence,string $kind,string $route,array $payload,bool$isRevocation=false):void{$destination=$route==='catalog'?($profile['catalog_route']??null):($profile['portal_route']??null);$pdo->prepare('INSERT INTO portal_projection_outbox (integration_profile_id,delivery_id,workspace_public_id,schema_version,source_sequence,delivery_kind,route_type,is_revocation,destination_url,signing_key_id,payload_json) VALUES (?,?,?,?,?,?,?,?,?,?,?)')->execute([(int)$profile['id'],$payload['deliveryId'],$workspaceId,$schema,$sequence,$kind,$route,$isRevocation?1:0,$destination?:null,$profile['delivery_key_id']??null,json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);}
    private function entity(string $type,array $row,?string $parent,bool $primary):array{$visible=['type'=>$type,'publicId'=>(string)$row['public_id'],'parentPublicId'=>$parent,'displayName'=>(string)$row['name'],'active'=>true,'primaryContact'=>$primary];return['type'=>$visible['type'],'publicId'=>$visible['publicId'],'parentPublicId'=>$visible['parentPublicId'],'displayName'=>$visible['displayName'],'sourceVersion'=>PortalSourceVersion::from($visible),'active'=>$visible['active'],'primaryContact'=>$visible['primaryContact']];}
    private function one(PDO $pdo,string $sql,array $params):array|false{$s=$pdo->prepare($sql);$s->execute($params);return $s->fetch(PDO::FETCH_ASSOC);}
    private function all(PDO $pdo,string $sql,array $params):array{$s=$pdo->prepare($sql);$s->execute($params);return $s->fetchAll(PDO::FETCH_ASSOC);}
    private function utc(mixed $value):?string{if($value===null||$value==='')return null;return(new DateTimeImmutable((string)$value,new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.000\Z');}
    private static function canonicalJson(array $value):string{$sort=function(&$v)use(&$sort){if(!is_array($v))return;if(array_is_list($v)){foreach($v as &$x)$sort($x);unset($x);return;}ksort($v);foreach($v as &$x)$sort($x);unset($x);};$sort($value);return json_encode($value,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
    private static function uuid():string{$hex=bin2hex(random_bytes(16));return substr($hex,0,8).'-'.substr($hex,8,4).'-4'.substr($hex,13,3).'-'.dechex((hexdec($hex[16])&3)|8).substr($hex,17,3).'-'.substr($hex,20);}
    private static function now():string{return(new DateTimeImmutable('now',new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');}
}
