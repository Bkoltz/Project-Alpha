<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use Throwable;

/** Transactional fan-out used by authoritative PA mutations. Caller owns the transaction. */
final class PortalProjectionMutationService
{
    public function queueProject(PDO $pdo,int $projectId):void
    {
        $this->afterMutation($pdo,$this->projectScopes($pdo,$projectId));
    }

    public function queueOrganization(PDO $pdo,int $organizationId):void
    {
        $this->afterMutation($pdo,$this->organizationScopes($pdo,$organizationId));
    }

    public function queueClient(PDO $pdo,int $clientId):void
    {
        $this->afterMutation($pdo,$this->clientScopes($pdo,$clientId));
    }

    /** @return list<array{root_type:string,root_public_id:string}> */
    public function organizationScopes(PDO$pdo,int$id):array{$s=$pdo->prepare('SELECT public_id FROM organizations WHERE id=?');$s->execute([$id]);$public=(string)($s->fetchColumn()?:'');return$public!==''?[['root_type'=>'organization','root_public_id'=>$public]]:[];}
    /** @return list<array{root_type:string,root_public_id:string}> */
    public function clientScopes(PDO$pdo,int$id):array{$s=$pdo->prepare('SELECT c.public_id,c.organization_id,o.public_id organization_public_id FROM clients c LEFT JOIN organizations o ON o.id=c.organization_id WHERE c.id=?');$s->execute([$id]);$row=$s->fetch(PDO::FETCH_ASSOC);if(!$row)return[];return!empty($row['organization_id'])&&$row['organization_public_id']?[['root_type'=>'organization','root_public_id'=>(string)$row['organization_public_id']]]:[['root_type'=>'standalone_client','root_public_id'=>(string)$row['public_id']]];}
    /** @return list<array{root_type:string,root_public_id:string}> */
    public function lockedClientScopes(PDO$pdo,int$id):array{$this->lockAuthoritativeRow($pdo,'clients',$id);return$this->clientScopes($pdo,$id);}
    /** @return list<array{root_type:string,root_public_id:string}> */
    public function projectScopes(PDO$pdo,int$id):array{$s=$pdo->prepare('SELECT o.public_id organization_public_id,c.public_id client_public_id,c.organization_id client_organization_id FROM projects p LEFT JOIN organizations o ON o.id=p.organization_id LEFT JOIN clients c ON c.id=p.client_id WHERE p.id=?');$s->execute([$id]);$row=$s->fetch(PDO::FETCH_ASSOC);if(!$row)return[];if($row['organization_public_id'])return[['root_type'=>'organization','root_public_id'=>(string)$row['organization_public_id']]];if($row['client_public_id']&&!$row['client_organization_id'])return[['root_type'=>'standalone_client','root_public_id'=>(string)$row['client_public_id']]];return[];}
    /** @return list<array{root_type:string,root_public_id:string}> */
    public function lockedProjectScopes(PDO$pdo,int$id):array{$this->lockAuthoritativeRow($pdo,'projects',$id);return$this->projectScopes($pdo,$id);}

    /**
     * Reconcile only the supplied roots. Successful outbox writes commit with
     * the caller's mutation; projection faults roll back to a savepoint so the
     * PA mutation remains available for a later complete reconciliation.
     *
     * @param list<array{root_type:string,root_public_id:string}> $scopes
     */
    public function afterMutation(PDO$pdo,array$scopes):bool
    {
        if(!$pdo->inTransaction()||!$this->hooksEnabled($pdo)||$scopes===[])return true;$scopes=$this->uniqueScopes($scopes);$savepoint='portal_projection_hook';
        try{$pdo->exec('SAVEPOINT '.$savepoint);$this->reconcileRelations($pdo,$scopes);foreach($scopes as$scope)$this->reconcileWorkspace($pdo,$scope);$pdo->exec('RELEASE SAVEPOINT '.$savepoint);return true;}catch(Throwable$error){try{$pdo->exec('ROLLBACK TO SAVEPOINT '.$savepoint);$pdo->exec('RELEASE SAVEPOINT '.$savepoint);}catch(Throwable){}@error_log('[portal_projection_hook] reconciliation deferred code='.substr(hash('sha256',get_class($error).':'.$error->getMessage()),0,12));return false;}
    }

    public function queueCatalog(PDO $pdo):void
    {
        $payload=(new PortalIntegrationService())->catalog($pdo);$profileIds=$pdo->query('SELECT id FROM portal_integration_profiles WHERE enabled=1 AND catalog_projection_enabled=1 AND catalog_route IS NOT NULL ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
        foreach($profileIds as$profileId){$profile=PortalProjectionService::lockProfileContract($pdo,(int)$profileId);if(empty($profile['enabled'])||empty($profile['catalog_projection_enabled'])||empty($profile['catalog_route']))continue;$deliveryId=self::uuid();$document=['applicationKey'=>(string)$profile['application_key'],'deliveryId'=>$deliveryId]+$payload;$pdo->prepare("INSERT INTO portal_projection_outbox (integration_profile_id,delivery_id,workspace_public_id,schema_version,source_sequence,delivery_kind,route_type,destination_url,signing_key_id,payload_json) VALUES (?,?,?,?,?,'catalog.snapshot','catalog',?,?,?)")->execute([(int)$profile['id'],$deliveryId,'catalog',(int)$payload['schemaVersion'],(int)$payload['sourceSequence'],(string)$profile['catalog_route'],$profile['delivery_key_id']??null,json_encode($document,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);}
    }

    private function queueWorkspaces(PDO $pdo,array $workspaceIds):void
    {
        if($workspaceIds===[])return;
        $profiles=$pdo->prepare('SELECT p.id FROM portal_integration_profiles p JOIN portal_integration_profile_workspaces pw ON pw.profile_id=p.id AND pw.active=1 JOIN portal_v2_workspaces w ON w.id=pw.workspace_id AND w.active=1 WHERE p.enabled=1 AND p.portal_projection_enabled=1 AND w.public_id=? ORDER BY p.id');
        $projection=new PortalProjectionService();
        foreach(array_unique(array_map('strval',$workspaceIds))as$workspaceId){$profiles->execute([$workspaceId]);foreach($profiles->fetchAll(PDO::FETCH_COLUMN)as$profileId)$projection->queueWorkspaceSnapshot($pdo,['id'=>(int)$profileId],$workspaceId);}
    }

    /** @param array{root_type:string,root_public_id:string} $scope */
    private function reconcileWorkspace(PDO$pdo,array$scope):void
    {
        $workspace=$pdo->prepare('SELECT * FROM portal_v2_workspaces WHERE root_type=? AND root_public_id=?');$workspace->execute([$scope['root_type'],$scope['root_public_id']]);$row=$workspace->fetch(PDO::FETCH_ASSOC);if(!$row)return;$rootTable=$scope['root_type']==='organization'?'organizations':'clients';$root=$pdo->prepare("SELECT name FROM {$rootTable} WHERE public_id=?");$root->execute([$scope['root_public_id']]);$name=$root->fetchColumn();
        if($name===false){$profiles=$pdo->prepare('SELECT p.* FROM portal_integration_profiles p JOIN portal_integration_profile_workspaces pw ON pw.profile_id=p.id AND pw.active=1 WHERE pw.workspace_id=? AND p.enabled=1 AND p.portal_projection_enabled=1 ORDER BY p.id');$profiles->execute([(int)$row['id']]);$projection=new PortalProjectionService();foreach($profiles->fetchAll(PDO::FETCH_ASSOC)as$profile)$projection->queueWorkspaceRevocation($pdo,$profile,(string)$row['public_id']);$pdo->prepare('UPDATE portal_v2_workspaces SET active=0,source_version=? WHERE id=?')->execute([PortalSourceVersion::from(['publicId'=>(string)$row['public_id'],'active'=>false]),(int)$row['id']]);$pdo->prepare('UPDATE portal_integration_profile_workspaces SET active=0 WHERE workspace_id=?')->execute([(int)$row['id']]);return;}
        $pdo->prepare('UPDATE portal_v2_workspaces SET display_name=?,source_version=?,active=1 WHERE id=?')->execute([(string)$name,PortalSourceVersion::from(['publicId'=>(string)$row['public_id'],'rootType'=>$scope['root_type'],'rootPublicId'=>$scope['root_public_id'],'displayName'=>(string)$name,'active'=>true]),(int)$row['id']]);$this->queueWorkspaces($pdo,[(string)$row['public_id']]);
    }

    /** @param list<array{root_type:string,root_public_id:string}> $scopes */
    private function reconcileRelations(PDO$pdo,array$scopes):void
    {
        $desired=[];$seed=[];$allowedRoots=[];foreach($scopes as$scope){$seed[]=$scope['root_public_id'];$allowedRoots[$scope['root_type'].'|'.$scope['root_public_id']]=true;if($scope['root_type']==='organization'){$this->organizationGraph($pdo,$scope['root_public_id'],$desired,$seed);}else{$this->standaloneGraph($pdo,$scope['root_public_id'],$desired,$seed);}}
        $existing=$this->relationClosure($pdo,array_values(array_unique($seed)),$allowedRoots);foreach($desired as$edge)$this->upsertRelation($pdo,$edge,true);foreach($existing as$row){$key=$this->edgeKey($row);if(!isset($desired[$key]))$this->upsertRelation($pdo,$row,false);}
    }

    /** @param array<string,array<string,string>> $desired @param list<string> $seed */
    private function organizationGraph(PDO$pdo,string$root,array&$desired,array&$seed):void
    {
        $departments=$this->all($pdo,'SELECT d.public_id FROM organization_departments d JOIN organizations o ON o.id=d.organization_id WHERE o.public_id=?',[$root]);$clients=$this->all($pdo,'SELECT c.id,c.public_id,c.name FROM clients c JOIN organizations o ON o.id=c.organization_id WHERE o.public_id=? AND c.archived=0 AND c.deleted_at IS NULL',[$root]);$projects=$this->all($pdo,"SELECT p.public_id,d.public_id department_public_id,c.public_id client_public_id FROM projects p JOIN organizations o ON o.id=p.organization_id LEFT JOIN organization_departments d ON d.id=p.department_id LEFT JOIN clients c ON c.id=p.client_id WHERE o.public_id=? AND p.status<>'cancelled'",[$root]);
        foreach($departments as$row){$seed[]=(string)$row['public_id'];$this->addEdge($desired,'contains','organization',$root,'department',(string)$row['public_id']);}foreach($clients as$row){$seed[]=(string)$row['public_id'];$this->addEdge($desired,'contains','organization',$root,'client',(string)$row['public_id']);$this->syncContact($pdo,(int)$row['id'],(string)$row['name'],false);}
        foreach($projects as$row){$seed[]=(string)$row['public_id'];$this->addEdge($desired,'contains','organization',$root,'project',(string)$row['public_id']);if($row['department_public_id'])$this->addEdge($desired,'contains','department',(string)$row['department_public_id'],'project',(string)$row['public_id']);if($row['client_public_id'])$this->addEdge($desired,'contains','client',(string)$row['client_public_id'],'project',(string)$row['public_id']);}
        $contacts=$this->all($pdo,'SELECT dc.department_id,dc.client_id,d.public_id department_public_id,c.name,pc.public_id contact_public_id FROM organization_department_contacts dc JOIN organization_departments d ON d.id=dc.department_id JOIN organizations o ON o.id=d.organization_id JOIN clients c ON c.id=dc.client_id LEFT JOIN portal_v2_contacts pc ON pc.client_id=c.id WHERE o.public_id=?',[$root]);foreach($contacts as$row){$contact=$this->syncContact($pdo,(int)$row['client_id'],(string)$row['name'],true);$seed[]=$contact;$this->addEdge($desired,'contact_assignment','department',(string)$row['department_public_id'],'contact',$contact);}
    }
    /** @param array<string,array<string,string>> $desired @param list<string> $seed */
    private function standaloneGraph(PDO$pdo,string$root,array&$desired,array&$seed):void{$projects=$this->all($pdo,"SELECT p.public_id FROM projects p JOIN clients c ON c.id=p.client_id WHERE c.public_id=? AND c.organization_id IS NULL AND c.archived=0 AND c.deleted_at IS NULL AND p.status<>'cancelled'",[$root]);foreach($projects as$row){$seed[]=(string)$row['public_id'];$this->addEdge($desired,'contains','standalone_client',$root,'project',(string)$row['public_id']);}}

    private function syncContact(PDO$pdo,int$clientId,string$name,bool$active):string
    {
        $s=$pdo->prepare('SELECT public_id FROM portal_v2_contacts WHERE client_id=?');$s->execute([$clientId]);$public=(string)($s->fetchColumn()?:'');$version=PortalSourceVersion::from(['clientId'=>$clientId,'displayName'=>$name,'active'=>$active]);if($public!==''){$pdo->prepare('UPDATE portal_v2_contacts SET display_name=?,source_version=?,active=? WHERE client_id=?')->execute([$name,$version,$active?1:0,$clientId]);return$public;}$public=bin2hex(random_bytes(16));$pdo->prepare('INSERT INTO portal_v2_contacts(public_id,client_id,display_name,source_version,active)VALUES(?,?,?,?,?)')->execute([$public,$clientId,$name,$version,$active?1:0]);return$public;
    }
    /** @param array<string,array<string,string>> $desired */
    private function addEdge(array&$desired,string$type,string$fromType,string$from,string$toType,string$to):void{$edge=['relation_type'=>$type,'from_type'=>$fromType,'from_public_id'=>$from,'to_type'=>$toType,'to_public_id'=>$to];$desired[$this->edgeKey($edge)]=$edge;}
    /** @param array<string,mixed> $edge */
    private function upsertRelation(PDO$pdo,array$edge,bool$active):void{$version=PortalSourceVersion::from(['relationType'=>(string)$edge['relation_type'],'from'=>['type'=>(string)$edge['from_type'],'publicId'=>(string)$edge['from_public_id']],'to'=>['type'=>(string)$edge['to_type'],'publicId'=>(string)$edge['to_public_id']],'active'=>$active]);$sql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?'INSERT INTO portal_v2_relations(relation_type,from_type,from_public_id,to_type,to_public_id,source_version,active)VALUES(?,?,?,?,?,?,?) ON CONFLICT(relation_type,from_type,from_public_id,to_type,to_public_id)DO UPDATE SET source_version=excluded.source_version,active=excluded.active':'INSERT INTO portal_v2_relations(relation_type,from_type,from_public_id,to_type,to_public_id,source_version,active)VALUES(?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE source_version=VALUES(source_version),active=VALUES(active)';$pdo->prepare($sql)->execute([(string)$edge['relation_type'],(string)$edge['from_type'],(string)$edge['from_public_id'],(string)$edge['to_type'],(string)$edge['to_public_id'],$version,$active?1:0]);}
    /** @param array<string,bool> $allowedRoots @return array<string,array<string,mixed>> */
    private function relationClosure(PDO$pdo,array$seed,array$allowedRoots):array
    {
        $seen=array_fill_keys($seed,true);$rows=[];$frontier=$seed;$rootCache=[];
        for($depth=0;$depth<6&&$frontier!==[];$depth++){
            $marks=implode(',',array_fill(0,count($frontier),'?'));$s=$pdo->prepare("SELECT * FROM portal_v2_relations WHERE from_public_id IN ({$marks}) OR to_public_id IN ({$marks}) ORDER BY id LIMIT 5001");$s->execute(array_merge($frontier,$frontier));$found=$s->fetchAll(PDO::FETCH_ASSOC);if(count($found)>5000)throw new \RuntimeException('portal-relation-scope-limit');$next=[];
            foreach($found as$row){$fromType=(string)$row['from_type'];$fromId=(string)$row['from_public_id'];$toType=(string)$row['to_type'];$toId=(string)$row['to_public_id'];if(($this->isRootNode($fromType)&&!isset($allowedRoots[$fromType.'|'.$fromId]))||($this->isRootNode($toType)&&!isset($allowedRoots[$toType.'|'.$toId])))continue;$rows[$this->edgeKey($row)]=$row;
                foreach([[$fromType,$fromId],[$toType,$toId]]as[$type,$id])if(!isset($seen[$id])){$owner=$this->entityRoot($pdo,$type,$id,$rootCache);if($owner!==null&&!isset($allowedRoots[$owner]))continue;$seen[$id]=true;$next[]=$id;}
            }$frontier=array_values(array_unique($next));
        }return$rows;
    }
    /** @param array<string,string|null> $cache */
    private function entityRoot(PDO$pdo,string$type,string$id,array&$cache):?string
    {
        $cacheKey=$type.'|'.$id;if(array_key_exists($cacheKey,$cache))return$cache[$cacheKey];$sql=match($type){
            'organization'=>"SELECT 'organization' root_type,public_id root_public_id FROM organizations WHERE public_id=?",
            'standalone_client'=>"SELECT 'standalone_client' root_type,public_id root_public_id FROM clients WHERE public_id=? AND organization_id IS NULL",
            'department'=>"SELECT 'organization' root_type,o.public_id root_public_id FROM organization_departments d JOIN organizations o ON o.id=d.organization_id WHERE d.public_id=?",
            'client'=>"SELECT CASE WHEN o.public_id IS NULL THEN 'standalone_client' ELSE 'organization' END root_type,COALESCE(o.public_id,c.public_id) root_public_id FROM clients c LEFT JOIN organizations o ON o.id=c.organization_id WHERE c.public_id=?",
            'project'=>"SELECT CASE WHEN o.public_id IS NOT NULL THEN 'organization' WHEN c.organization_id IS NULL THEN 'standalone_client' ELSE NULL END root_type,COALESCE(o.public_id,c.public_id) root_public_id FROM projects p LEFT JOIN organizations o ON o.id=p.organization_id LEFT JOIN clients c ON c.id=p.client_id WHERE p.public_id=?",
            'contact'=>"SELECT CASE WHEN o.public_id IS NULL THEN 'standalone_client' ELSE 'organization' END root_type,COALESCE(o.public_id,c.public_id) root_public_id FROM portal_v2_contacts pc JOIN clients c ON c.id=pc.client_id LEFT JOIN organizations o ON o.id=c.organization_id WHERE pc.public_id=?",
            default=>null};if($sql===null)return$cache[$cacheKey]=null;$s=$pdo->prepare($sql);$s->execute([$id]);$row=$s->fetch(PDO::FETCH_ASSOC);return$cache[$cacheKey]=!$row||empty($row['root_type'])||empty($row['root_public_id'])?null:(string)$row['root_type'].'|'.(string)$row['root_public_id'];
    }
    private function isRootNode(string$type):bool{return in_array($type,['organization','standalone_client'],true);}
    /** @param array<string,mixed> $edge */
    private function edgeKey(array$edge):string{return implode('|',[(string)$edge['relation_type'],(string)$edge['from_type'],(string)$edge['from_public_id'],(string)$edge['to_type'],(string)$edge['to_public_id']]);}
    private function hooksEnabled(PDO$pdo):bool{try{$s=$pdo->prepare("SELECT config_value FROM app_config WHERE organization_id=0 AND config_key='portal_authoritative_hooks_enabled'");$s->execute();return filter_var($s->fetchColumn()?:'0',FILTER_VALIDATE_BOOLEAN);}catch(Throwable){return false;}}
    /** @param list<array{root_type:string,root_public_id:string}> $scopes @return list<array{root_type:string,root_public_id:string}> */
    private function uniqueScopes(array$scopes):array{$out=[];foreach($scopes as$scope){$type=(string)($scope['root_type']??'');$id=(string)($scope['root_public_id']??'');if(!in_array($type,['organization','standalone_client'],true)||$id==='')continue;$out[$type.'|'.$id]=['root_type'=>$type,'root_public_id'=>$id];}ksort($out);return array_values($out);}
    private function all(PDO$pdo,string$sql,array$params):array{$s=$pdo->prepare($sql);$s->execute($params);return$s->fetchAll(PDO::FETCH_ASSOC);}
    private function lockAuthoritativeRow(PDO$pdo,string$table,int$id):void{if(!$pdo->inTransaction())throw new \LogicException('portal-projection-authoritative-lock-requires-transaction');$suffix=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?'':' FOR UPDATE';$s=$pdo->prepare("SELECT id FROM {$table} WHERE id=?{$suffix}");$s->execute([$id]);}

    private static function uuid():string{$hex=bin2hex(random_bytes(16));return substr($hex,0,8).'-'.substr($hex,8,4).'-4'.substr($hex,13,3).'-'.dechex((hexdec($hex[16])&3)|8).substr($hex,17,3).'-'.substr($hex,20);}
}
