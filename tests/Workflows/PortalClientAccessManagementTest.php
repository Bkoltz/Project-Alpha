<?php

declare(strict_types=1);

use App\Services\PortalAuthorityService;
use PHPUnit\Framework\TestCase;

final class PortalClientAccessManagementTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo=new PDO('sqlite::memory:');$this->pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE portal_projection_resource_state(workspace_public_id TEXT,resource_type TEXT,resource_public_id TEXT)');
        $this->pdo->exec("CREATE TABLE portal_integration_profiles(id INTEGER PRIMARY KEY,enabled INTEGER,portal_projection_enabled INTEGER);CREATE TABLE portal_v2_workspaces(id INTEGER PRIMARY KEY,public_id TEXT,root_type TEXT,root_public_id TEXT,active INTEGER);CREATE TABLE portal_integration_profile_workspaces(profile_id INTEGER,workspace_id INTEGER,active INTEGER);CREATE TABLE portal_principals(id INTEGER PRIMARY KEY AUTOINCREMENT,public_id TEXT DEFAULT 'principal-a',email_hint TEXT,display_name TEXT,source_version TEXT,enabled INTEGER,authorization_version INTEGER DEFAULT 1,activated_at TEXT,revoked_at TEXT,created_by INTEGER,updated_by INTEGER);CREATE TABLE clients(id INTEGER PRIMARY KEY,public_id TEXT,name TEXT,organization_id INTEGER,archived INTEGER,deleted_at TEXT);CREATE TABLE organizations(id INTEGER PRIMARY KEY,public_id TEXT);CREATE TABLE organization_departments(id INTEGER PRIMARY KEY,public_id TEXT,organization_id INTEGER);CREATE TABLE projects(id INTEGER PRIMARY KEY,public_id TEXT,organization_id INTEGER,client_id INTEGER);CREATE TABLE portal_principal_clients(portal_principal_id INTEGER,client_id INTEGER,created_by INTEGER,PRIMARY KEY(portal_principal_id,client_id));CREATE TABLE portal_identity_bindings(id INTEGER PRIMARY KEY AUTOINCREMENT,portal_principal_id INTEGER,issuer TEXT,subject_hash TEXT,enabled INTEGER,bound_at TEXT,revoked_at TEXT,created_by INTEGER,updated_by INTEGER,UNIQUE(issuer,subject_hash));CREATE TABLE portal_v2_entitlements(id INTEGER PRIMARY KEY AUTOINCREMENT,public_id TEXT,portal_principal_id INTEGER,capability TEXT,effect TEXT,scope_type TEXT,scope_public_id TEXT,source_version TEXT,active INTEGER,valid_from TEXT,expires_at TEXT,created_by INTEGER,updated_by INTEGER);CREATE TABLE portal_integration_audit(id INTEGER PRIMARY KEY AUTOINCREMENT,integration_profile_id INTEGER,api_key_id INTEGER,action TEXT,target_type TEXT,target_public_id TEXT,metadata_json TEXT);INSERT INTO portal_integration_profiles VALUES(1,0,0);INSERT INTO portal_v2_workspaces VALUES(10,'workspace-a','organization','org-a',1);INSERT INTO portal_integration_profile_workspaces VALUES(1,10,1);INSERT INTO organizations VALUES(20,'org-a');INSERT INTO organizations VALUES(21,'org-b');INSERT INTO clients VALUES(30,'client-a','Client A',20,0,NULL);INSERT INTO clients VALUES(31,'client-b','Client B',21,0,NULL);INSERT INTO projects VALUES(40,'project-a',20,30);");
    }

    public function testPrincipalIdentityAndScopedAuthorityAreExplicitAndRevocable(): void
    {
        $service=new PortalAuthorityService();
        $principal=$service->savePrincipalAccess($this->pdo,1,'workspace-a',null,'Person@Example.test','Person',[30],99);
        self::assertSame('person@example.test',$this->pdo->query('SELECT email_hint FROM portal_principals')->fetchColumn());
        self::assertSame(1,(int)$this->pdo->query('SELECT COUNT(*) FROM portal_principal_clients')->fetchColumn());
        $service->saveScopedEntitlement($this->pdo,1,'workspace-a',$principal,'delivery.view','project','project-a','allow',true,99);
        $service->saveScopedEntitlement($this->pdo,1,'workspace-a',$principal,'delivery.view','project','project-a','deny',true,99);
        self::assertSame(['allow','deny'],$this->pdo->query("SELECT effect FROM portal_v2_entitlements WHERE active=1 ORDER BY effect")->fetchAll(PDO::FETCH_COLUMN));
        $service->revokePrincipalAccess($this->pdo,1,'workspace-a',$principal,99);
        self::assertSame(0,(int)$this->pdo->query('SELECT enabled FROM portal_principals')->fetchColumn());
        self::assertSame(0,(int)$this->pdo->query('SELECT SUM(active) FROM portal_v2_entitlements')->fetchColumn());
        self::assertSame(['portal.principal.saved','portal.entitlement.saved','portal.entitlement.saved','portal.principal.revoked'],$this->pdo->query('SELECT action FROM portal_integration_audit ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
    }

    public function testEmailNeverCreatesAnIdentityBindingOrGrant(): void
    {
        (new PortalAuthorityService())->savePrincipalAccess($this->pdo,1,'workspace-a',null,'person@example.test','Person',[],99);
        self::assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM portal_identity_bindings')->fetchColumn());
        self::assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM portal_v2_entitlements')->fetchColumn());
    }

    public function testClientAssociationCannotCrossWorkspaceBoundary(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('outside this workspace');
        (new PortalAuthorityService())->savePrincipalAccess($this->pdo,1,'workspace-a',null,'person@example.test','Person',[31],99);
    }

    public function testUpdatingOneWorkspacePreservesOtherWorkspaceAssociations(): void
    {
        $service=new PortalAuthorityService();$principal=$service->savePrincipalAccess($this->pdo,1,'workspace-a',null,'person@example.test','Person',[30],99);
        $this->pdo->exec("INSERT INTO portal_principal_clients VALUES({$principal},31,99)");
        $service->savePrincipalAccess($this->pdo,1,'workspace-a',$principal,'person@example.test','Person',[],99);
        self::assertSame([31],array_map('intval',$this->pdo->query('SELECT client_id FROM portal_principal_clients ORDER BY client_id')->fetchAll(PDO::FETCH_COLUMN)));
    }
}
