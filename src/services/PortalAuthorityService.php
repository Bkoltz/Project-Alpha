<?php

declare(strict_types=1);

namespace App\Services;

use DomainException;
use PDO;
use Throwable;

final class PortalAuthorityService
{
    public const MANAGER_CAPABILITIES=['workspace.view','directory.read','request.create','delivery.view','member.manage','share.create'];

    /** @param array<string,mixed> $input */
    public function saveProfile(PDO $pdo,array $input,int $actorId):int
    {
        $id=max(0,(int)($input['profile_id']??0));$key=ExternalOpsIntegrationService::normalizeApplicationKey((string)($input['application_key']??''));
        $label=trim((string)($input['display_label']??''));if($label===''||mb_strlen($label)>100)throw new DomainException('Integration display label is required and must be at most 100 characters.');
        $pricingSource=$this->nullableAscii($input['pricing_source']??null,100);$draftSource=$this->nullableAscii($input['draft_source']??null,100);
        $enabled=!empty($input['enabled']);$portal=!empty($input['portal_projection_enabled']);$relations=!empty($input['relation_projection_enabled']);$catalog=!empty($input['catalog_projection_enabled']);$pricing=!empty($input['pricing_preview_enabled']);$draft=!empty($input['draft_quote_enabled']);
        if($relations&&!$portal)throw new DomainException('Relation projection requires portal projection.');
        if($pricing&&$pricingSource===null)throw new DomainException('Pricing source is required before pricing preview can be enabled.');
        if($draft&&$draftSource===null)throw new DomainException('Draft source is required before draft creation can be enabled.');
        $portalRoute=$this->httpsRoute($input['portal_route']??null);$catalogRoute=$this->httpsRoute($input['catalog_route']??null);
        if($id>0){
            $owns=!$pdo->inTransaction();
            try{if($owns)$pdo->beginTransaction();$current=PortalProjectionService::lockProfileContract($pdo,$id);$revoking=!empty($current['enabled'])&&!empty($current['portal_projection_enabled'])&&(!$enabled||!$portal);$routingChanged=!hash_equals((string)$current['application_key'],$key)||($current['portal_route']??null)!==$portalRoute||($current['catalog_route']??null)!==$catalogRoute;if(!empty($current['enabled'])&&$routingChanged)throw new DomainException('Disable the integration before changing its application key or receiver routes.');if($revoking&&$routingChanged)throw new DomainException('Disable the profile without changing its key or routes so queued revocations retain their delivery contract.');if($routingChanged){$pending=$pdo->prepare('SELECT COUNT(*) FROM portal_projection_outbox WHERE integration_profile_id=? AND delivered_at IS NULL');$pending->execute([$id]);if((int)$pending->fetchColumn()>0)throw new DomainException('Deliver or administratively resolve pending projection records before changing this profile key or routes.');}$revoked=0;if($revoking)$revoked=$this->queueProfileRevocations($pdo,$current);$pdo->prepare('UPDATE portal_integration_profiles SET application_key=?,display_label=?,enabled=?,portal_projection_enabled=?,relation_projection_enabled=?,catalog_projection_enabled=?,pricing_preview_enabled=?,draft_quote_enabled=?,pricing_source=?,draft_source=?,portal_route=?,catalog_route=?,updated_by=? WHERE id=?')->execute([$key,$label,$enabled,$portal,$relations,$catalog,$pricing,$draft,$pricingSource,$draftSource,$portalRoute,$catalogRoute,$actorId,$id]);if($revoking)$this->audit($pdo,$id,null,'portal.profile.revocation_queued','profile',(string)$id,['workspace_count'=>$revoked,'actor_id'=>$actorId]);if($owns)$pdo->commit();return$id;}catch(Throwable$e){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw$e;}
        }
        $pdo->prepare('INSERT INTO portal_integration_profiles (application_key,display_label,enabled,portal_projection_enabled,relation_projection_enabled,catalog_projection_enabled,pricing_preview_enabled,draft_quote_enabled,pricing_source,draft_source,portal_route,catalog_route,created_by,updated_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$key,$label,$enabled,$portal,$relations,$catalog,$pricing,$draft,$pricingSource,$draftSource,$portalRoute,$catalogRoute,$actorId,$actorId]);return(int)$pdo->lastInsertId();
    }

    public function saveWorkspace(PDO $pdo,int $profileId,string $rootType,string $rootPublicId,string $displayName,int $actorId):string
    {
        $this->profileRequired($pdo,$profileId);if(!in_array($rootType,['organization','standalone_client'],true))throw new DomainException('Workspace root type is invalid.');
        $table=$rootType==='organization'?'organizations':'clients';$sql="SELECT public_id,name FROM {$table} WHERE public_id=?".($rootType==='standalone_client'?' AND organization_id IS NULL AND archived=0 AND deleted_at IS NULL':'');$s=$pdo->prepare($sql);$s->execute([$rootPublicId]);$root=$s->fetch(PDO::FETCH_ASSOC);if(!$root)throw new DomainException('Workspace root not found.');
        $displayName=trim($displayName)?:((string)$root['name']);if(mb_strlen($displayName)>150)throw new DomainException('Workspace label is too long.');
        $owns=!$pdo->inTransaction();
        try{
            if($owns)$pdo->beginTransaction();
            $existing=$pdo->prepare('SELECT id,public_id FROM portal_v2_workspaces WHERE root_type=? AND root_public_id=?');$existing->execute([$rootType,$rootPublicId]);$workspace=$existing->fetch(PDO::FETCH_ASSOC);
            if($workspace){$publicId=(string)$workspace['public_id'];$workspaceId=(int)$workspace['id'];$pdo->prepare('UPDATE portal_v2_workspaces SET display_name=?,source_version=?,active=1,updated_by=? WHERE id=?')->execute([$displayName,self::version(),$actorId,$workspaceId]);}
            else{$publicId=bin2hex(random_bytes(16));$pdo->prepare('INSERT INTO portal_v2_workspaces (public_id,root_type,root_public_id,display_name,source_version,active,created_by,updated_by) VALUES (?,?,?,?,?,1,?,?)')->execute([$publicId,$rootType,$rootPublicId,$displayName,self::version(),$actorId,$actorId]);$workspaceId=(int)$pdo->lastInsertId();}
            $this->upsertWorkspaceLink($pdo,$profileId,$workspaceId,true,$actorId);
            $this->audit($pdo,$profileId,null,'portal.workspace.linked','workspace',$publicId,['actor_id'=>$actorId]);
            if($owns)$pdo->commit();
            return$publicId;
        }catch(Throwable$e){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw$e;}
    }

    public function setWorkspaceLink(PDO $pdo,int $profileId,string $workspacePublicId,bool $active,int $actorId):void
    {
        $profile=$this->profileRequired($pdo,$profileId);
        $workspace=$pdo->prepare('SELECT id FROM portal_v2_workspaces WHERE public_id=? AND active=1');$workspace->execute([$workspacePublicId]);$workspaceId=$workspace->fetchColumn();if(!$workspaceId)throw new DomainException('Workspace not found.');
        $owns=!$pdo->inTransaction();
        try{if($owns)$pdo->beginTransaction();$revocation=null;if(!$active){$linked=$pdo->prepare('SELECT active FROM portal_integration_profile_workspaces WHERE profile_id=? AND workspace_id=?');$linked->execute([$profileId,(int)$workspaceId]);if((int)$linked->fetchColumn()===1)$revocation=(new PortalProjectionService())->queueWorkspaceRevocation($pdo,$profile,$workspacePublicId);}$this->upsertWorkspaceLink($pdo,$profileId,(int)$workspaceId,$active,$actorId);$this->audit($pdo,$profileId,null,$active?'portal.workspace.linked':'portal.workspace.unlinked','workspace',$workspacePublicId,['actor_id'=>$actorId,'revocation_queued'=>$revocation!==null]);if($owns)$pdo->commit();}catch(Throwable$e){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw$e;}
    }

    public function createPrincipal(PDO $pdo,string $email,string $displayName,int $actorId):int
    {
        $email=strtolower(trim($email));if(!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($email)>254)throw new DomainException('A valid portal email is required.');$displayName=trim($displayName);if($displayName===''||mb_strlen($displayName)>150)throw new DomainException('A manager display name is required.');
        $existing=$pdo->prepare('SELECT id FROM portal_principals WHERE email_hint=?');$existing->execute([$email]);$id=$existing->fetchColumn();
        if($id){$pdo->prepare('UPDATE portal_principals SET display_name=?,source_version=?,enabled=1,revoked_at=NULL,updated_by=? WHERE id=?')->execute([$displayName,self::version(),$actorId,$id]);return(int)$id;}
        $pdo->prepare('INSERT INTO portal_principals (email_hint,display_name,source_version,enabled,activated_at,created_by,updated_by) VALUES (?,?,?,1,CURRENT_TIMESTAMP,?,?)')->execute([$email,$displayName,self::version(),$actorId,$actorId]);return(int)$pdo->lastInsertId();
    }

    public function appointManager(PDO $pdo,int $profileId,string $workspaceId,int $principalId,string $scopeType,string $scopePublicId,?int $replacePrincipalId,int $actorId):void
    {
        if(!in_array($scopeType,['workspace','organization','department','project'],true))throw new DomainException('Manager scope is invalid.');
        $this->profileRequired($pdo,$profileId);(new PortalWorkspaceAuthorizationService())->requireWorkspace($pdo,$profileId,$workspaceId);$this->assertScopeInWorkspace($pdo,$workspaceId,$scopeType,$scopePublicId);$this->principalRequired($pdo,$principalId);
        $owns=!$pdo->inTransaction();try{if($owns)$pdo->beginTransaction();
            if($replacePrincipalId&&$replacePrincipalId!==$principalId)$pdo->prepare("UPDATE portal_v2_entitlements SET active=0,source_version=?,updated_by=? WHERE portal_principal_id=? AND scope_type=? AND scope_public_id=? AND capability IN ('workspace.view','directory.read','request.create','delivery.view','member.manage','share.create')")->execute([self::version(),$actorId,$replacePrincipalId,$scopeType,$scopePublicId]);
            foreach(self::MANAGER_CAPABILITIES as $capability){$existing=$pdo->prepare('SELECT id FROM portal_v2_entitlements WHERE portal_principal_id=? AND scope_type=? AND scope_public_id=? AND capability=?');$existing->execute([$principalId,$scopeType,$scopePublicId,$capability]);$id=$existing->fetchColumn();if($id)$pdo->prepare('UPDATE portal_v2_entitlements SET effect=\'allow\',active=1,source_version=?,valid_from=CURRENT_TIMESTAMP,expires_at=NULL,updated_by=? WHERE id=?')->execute([self::version(),$actorId,$id]);else$pdo->prepare('INSERT INTO portal_v2_entitlements (portal_principal_id,capability,effect,scope_type,scope_public_id,source_version,active,valid_from,created_by,updated_by) VALUES (?,?,\'allow\',?,?,?,1,CURRENT_TIMESTAMP,?,?)')->execute([$principalId,$capability,$scopeType,$scopePublicId,self::version(),$actorId,$actorId]);}
            $this->audit($pdo,$profileId,null,'portal.manager.appointed',$scopeType,$scopePublicId,['principal_id'=>$principalId,'replaced_principal_id'=>$replacePrincipalId,'actor_id'=>$actorId]);
            $this->queueEveryEnabledProfile($pdo,$workspaceId);
            if($owns)$pdo->commit();
        }catch(Throwable$e){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw$e;}
    }

    public function offboardManager(PDO $pdo,int $profileId,string $workspaceId,int $principalId,string $scopeType,string $scopePublicId,int $actorId):void
    {
        $this->profileRequired($pdo,$profileId);(new PortalWorkspaceAuthorizationService())->requireWorkspace($pdo,$profileId,$workspaceId);$this->assertScopeInWorkspace($pdo,$workspaceId,$scopeType,$scopePublicId);$owns=!$pdo->inTransaction();try{if($owns)$pdo->beginTransaction();$pdo->prepare("UPDATE portal_v2_entitlements SET active=0,source_version=?,updated_by=? WHERE portal_principal_id=? AND scope_type=? AND scope_public_id=? AND capability IN ('workspace.view','directory.read','request.create','delivery.view','member.manage','share.create')")->execute([self::version(),$actorId,$principalId,$scopeType,$scopePublicId]);$this->audit($pdo,$profileId,null,'portal.manager.offboarded',$scopeType,$scopePublicId,['principal_id'=>$principalId,'actor_id'=>$actorId]);$this->queueEveryEnabledProfile($pdo,$workspaceId);if($owns)$pdo->commit();}catch(Throwable$e){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw$e;}
    }

    private function assertScopeInWorkspace(PDO $pdo,string $workspaceId,string $scopeType,string $scopeId):void
    {
        $w=$pdo->prepare('SELECT * FROM portal_v2_workspaces WHERE public_id=? AND active=1');$w->execute([$workspaceId]);$workspace=$w->fetch(PDO::FETCH_ASSOC);if(!$workspace)throw new DomainException('Workspace not found.');
        if($scopeType==='workspace'){if(!hash_equals($workspaceId,$scopeId))throw new DomainException('Scope is outside the workspace.');return;}
        $rootType=(string)$workspace['root_type'];$root=(string)$workspace['root_public_id'];
        $sql=match($scopeType){'organization'=>'SELECT COUNT(*) FROM organizations WHERE public_id=? AND ?=\'organization\' AND public_id=?','department'=>'SELECT COUNT(*) FROM organization_departments d JOIN organizations o ON o.id=d.organization_id WHERE d.public_id=? AND ?=\'organization\' AND o.public_id=?','project'=>$rootType==='organization'?'SELECT COUNT(*) FROM projects p JOIN organizations o ON o.id=p.organization_id WHERE p.public_id=? AND ?=\'organization\' AND o.public_id=?':'SELECT COUNT(*) FROM projects p JOIN clients c ON c.id=p.client_id WHERE p.public_id=? AND ?=\'standalone_client\' AND c.public_id=?',default=>throw new DomainException('Scope is invalid.')};$s=$pdo->prepare($sql);$s->execute([$scopeId,$rootType,$root]);if((int)$s->fetchColumn()!==1)throw new DomainException('Scope is outside the workspace.');
    }
    private function profileRequired(PDO $pdo,int$id):array{$p=$this->profile($pdo,$id);if(!$p)throw new DomainException('Integration profile not found.');return$p;}
    private function profile(PDO$pdo,int$id):array|false{$s=$pdo->prepare('SELECT * FROM portal_integration_profiles WHERE id=?');$s->execute([$id]);return$s->fetch(PDO::FETCH_ASSOC);}
    private function principalRequired(PDO$pdo,int$id):void{$s=$pdo->prepare('SELECT COUNT(*) FROM portal_principals WHERE id=? AND enabled=1 AND revoked_at IS NULL');$s->execute([$id]);if((int)$s->fetchColumn()!==1)throw new DomainException('Active portal principal not found.');}
    private function queueEveryEnabledProfile(PDO$pdo,string$workspaceId):void{$profiles=$pdo->prepare('SELECT p.* FROM portal_integration_profiles p JOIN portal_integration_profile_workspaces pw ON pw.profile_id=p.id AND pw.active=1 JOIN portal_v2_workspaces w ON w.id=pw.workspace_id WHERE p.enabled=1 AND p.portal_projection_enabled=1 AND w.public_id=? ORDER BY p.id');$profiles->execute([$workspaceId]);$projection=new PortalProjectionService();foreach($profiles->fetchAll(PDO::FETCH_ASSOC)as$profile)$projection->queueWorkspaceSnapshot($pdo,$profile,$workspaceId);}
    private function queueProfileRevocations(PDO$pdo,array$profile):int{$workspaces=$pdo->prepare('SELECT w.public_id FROM portal_integration_profile_workspaces pw JOIN portal_v2_workspaces w ON w.id=pw.workspace_id AND w.active=1 WHERE pw.profile_id=? AND pw.active=1 ORDER BY w.public_id');$workspaces->execute([(int)$profile['id']]);$count=0;$projection=new PortalProjectionService();foreach($workspaces->fetchAll(PDO::FETCH_COLUMN)as$workspaceId)if($projection->queueWorkspaceRevocation($pdo,$profile,(string)$workspaceId)!==null)$count++;return$count;}
    private function upsertWorkspaceLink(PDO$pdo,int$profileId,int$workspaceId,bool$active,int$actorId):void{$driver=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);if($driver==='sqlite')$sql='INSERT INTO portal_integration_profile_workspaces (profile_id,workspace_id,active,created_by,updated_by) VALUES (?,?,?,?,?) ON CONFLICT(profile_id,workspace_id) DO UPDATE SET active=excluded.active,updated_by=excluded.updated_by,updated_at=CURRENT_TIMESTAMP';else$sql='INSERT INTO portal_integration_profile_workspaces (profile_id,workspace_id,active,created_by,updated_by) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE active=VALUES(active),updated_by=VALUES(updated_by)';$pdo->prepare($sql)->execute([$profileId,$workspaceId,$active?1:0,$actorId,$actorId]);}
    private function audit(PDO$pdo,int$profileId,?int$keyId,string$action,?string$type,?string$id,array$meta):void{$pdo->prepare('INSERT INTO portal_integration_audit (integration_profile_id,api_key_id,action,target_type,target_public_id,metadata_json) VALUES (?,?,?,?,?,?)')->execute([$profileId,$keyId,$action,$type,$id,json_encode($meta,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);}
    private function nullableAscii(mixed$v,int$max):?string{$v=trim((string)$v);if($v==='')return null;if(strlen($v)>$max||preg_match('/^[A-Za-z0-9._:-]+$/D',$v)!==1)throw new DomainException('Source identifier is invalid.');return$v;}
    private function httpsRoute(mixed$v):?string{$v=trim((string)$v);if($v==='')return null;$parts=parse_url($v);if(!filter_var($v,FILTER_VALIDATE_URL)||strtolower((string)($parts['scheme']??''))!=='https')throw new DomainException('Integration routes must use HTTPS.');return$v;}
    private static function version():string{return'v-'.bin2hex(random_bytes(16));}
}
