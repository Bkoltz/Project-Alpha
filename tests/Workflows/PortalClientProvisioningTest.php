<?php

declare(strict_types=1);

namespace Tests\Workflows;

use App\Services\PortalClientProvisioningService;
use PDO;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__,2).'/src/services/PortalSourceVersion.php';
require_once dirname(__DIR__,2).'/src/services/PortalAuthorityService.php';
require_once dirname(__DIR__,2).'/src/services/PortalClientProvisioningService.php';

final class PortalClientProvisioningTest extends TestCase
{
    private PDO $pdo;
    private PortalClientProvisioningService $service;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(<<<'SQL'
CREATE TABLE organizations(id INTEGER PRIMARY KEY,public_id TEXT UNIQUE,name TEXT);
CREATE TABLE clients(id INTEGER PRIMARY KEY,public_id TEXT UNIQUE,name TEXT,email TEXT,organization_id INTEGER,client_type TEXT,archived INTEGER DEFAULT 0,deleted_at TEXT,source_version TEXT DEFAULT '1');
CREATE TABLE portal_integration_profiles(id INTEGER PRIMARY KEY AUTOINCREMENT,application_key TEXT UNIQUE,display_label TEXT,enabled INTEGER,portal_projection_enabled INTEGER,relation_projection_enabled INTEGER DEFAULT 0,catalog_projection_enabled INTEGER DEFAULT 0,pricing_preview_enabled INTEGER DEFAULT 0,draft_quote_enabled INTEGER DEFAULT 0,pricing_source TEXT,draft_source TEXT,portal_route TEXT,catalog_route TEXT,delivery_enabled INTEGER DEFAULT 0,delivery_key_id TEXT,delivery_previous_key_id TEXT,delivery_previous_valid_until TEXT,delivery_credentials_enc TEXT,delivery_timeout_seconds INTEGER DEFAULT 15,delivery_max_attempts INTEGER DEFAULT 12,created_by INTEGER,updated_by INTEGER);
CREATE TABLE portal_v2_workspaces(id INTEGER PRIMARY KEY AUTOINCREMENT,public_id TEXT UNIQUE,root_type TEXT,root_public_id TEXT,display_name TEXT,source_version TEXT,active INTEGER,created_by INTEGER,updated_by INTEGER,UNIQUE(root_type,root_public_id));
CREATE TABLE portal_integration_profile_workspaces(profile_id INTEGER,workspace_id INTEGER,active INTEGER,created_by INTEGER,updated_by INTEGER,PRIMARY KEY(profile_id,workspace_id));
CREATE TABLE portal_principals(id INTEGER PRIMARY KEY AUTOINCREMENT,public_id TEXT DEFAULT (lower(hex(randomblob(16)))),email_hint TEXT,display_name TEXT,source_version TEXT,enabled INTEGER,authorization_version INTEGER DEFAULT 1,activated_at TEXT,revoked_at TEXT,created_by INTEGER,updated_by INTEGER);
CREATE TABLE portal_principal_clients(portal_principal_id INTEGER,client_id INTEGER,created_by INTEGER,PRIMARY KEY(portal_principal_id,client_id));
CREATE TABLE portal_identity_bindings(id INTEGER PRIMARY KEY AUTOINCREMENT,portal_principal_id INTEGER,issuer TEXT,subject_hash TEXT,enabled INTEGER,bound_at TEXT,revoked_at TEXT,created_by INTEGER,updated_by INTEGER);
CREATE TABLE portal_v2_entitlements(id INTEGER PRIMARY KEY AUTOINCREMENT,public_id TEXT,portal_principal_id INTEGER,capability TEXT,effect TEXT,scope_type TEXT,scope_public_id TEXT,source_version TEXT,active INTEGER,valid_from TEXT,expires_at TEXT,created_by INTEGER,updated_by INTEGER);
CREATE TABLE portal_client_access_roots(root_type TEXT,root_public_id TEXT,access_state TEXT,state_reason TEXT,last_reconciled_at TEXT,created_by INTEGER,updated_by INTEGER,PRIMARY KEY(root_type,root_public_id));
CREATE TABLE portal_client_login_eligibility(client_id INTEGER PRIMARY KEY,portal_principal_id INTEGER,manual_state TEXT,eligibility_status TEXT,review_reason TEXT,canonical_email TEXT,source_version TEXT,last_reconciled_at TEXT,created_by INTEGER,updated_by INTEGER);
CREATE TABLE app_config(organization_id INTEGER,config_key TEXT,config_value TEXT,PRIMARY KEY(organization_id,config_key));
CREATE TABLE portal_projection_outbox(id INTEGER PRIMARY KEY AUTOINCREMENT,integration_profile_id INTEGER,delivered_at TEXT,dead_lettered_at TEXT);
CREATE TABLE portal_integration_audit(id INTEGER PRIMARY KEY AUTOINCREMENT,integration_profile_id INTEGER,api_key_id INTEGER,action TEXT,target_type TEXT,target_public_id TEXT,metadata_json TEXT);
INSERT INTO portal_integration_profiles(id,application_key,display_label,enabled,portal_projection_enabled) VALUES(1,'generic_operations','Generic operations',1,1);
SQL);
        $this->service = new PortalClientProvisioningService();
    }

    public function testOrganizationContactWithUniqueCanonicalEmailGetsInvitationIntentOnly(): void
    {
        $this->pdo->exec("INSERT INTO organizations VALUES(10,'org-a','Example Organization');
            INSERT INTO clients VALUES(20,'client-a','A Person',' Person@Example.test ',10,'business',0,NULL,'client-v1')");

        $this->pdo->beginTransaction();
        $summary = $this->service->ensureScopes($this->pdo, [['root_type'=>'organization','root_public_id'=>'org-a']], 7, ['id'=>1,'enabled'=>1,'portal_projection_enabled'=>1]);
        $this->pdo->commit();

        self::assertSame(['roots'=>1,'workspaces'=>1,'eligible'=>1,'review_required'=>0,'revoked'=>0], $summary);
        self::assertSame(1, (int)$this->pdo->query("SELECT COUNT(*) FROM portal_v2_workspaces WHERE root_type='organization' AND root_public_id='org-a' AND active=1")->fetchColumn());
        self::assertSame('person@example.test', $this->pdo->query('SELECT canonical_email FROM portal_client_login_eligibility WHERE client_id=20')->fetchColumn());
        self::assertSame(3, (int)$this->pdo->query('SELECT COUNT(*) FROM portal_v2_entitlements WHERE active=1')->fetchColumn());
        self::assertSame(['delivery.view','directory.read','workspace.view'], $this->pdo->query('SELECT capability FROM portal_v2_entitlements ORDER BY capability')->fetchAll(PDO::FETCH_COLUMN));
        self::assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM portal_identity_bindings')->fetchColumn(), 'Project Alpha must not bind an external identity.');
    }

    public function testDuplicateAndNonHumanStandaloneRecordsFailClosedForReview(): void
    {
        $this->pdo->exec("INSERT INTO organizations VALUES(10,'org-a','Example Organization');
            INSERT INTO clients VALUES
              (20,'client-a','First Person','same@example.test',10,'unknown',0,NULL,'a'),
              (21,'client-b','Second Person','SAME@example.test',10,'consumer',0,NULL,'b'),
              (22,'client-c','Unclassified Company','office@example.test',NULL,'unknown',0,NULL,'c'),
              (23,'client-d','Business Record','owner@example.test',NULL,'business',0,NULL,'d')");
        $scopes=[
            ['root_type'=>'organization','root_public_id'=>'org-a'],
            ['root_type'=>'standalone_client','root_public_id'=>'client-c'],
            ['root_type'=>'standalone_client','root_public_id'=>'client-d'],
        ];

        $this->pdo->beginTransaction();
        $summary=$this->service->ensureScopes($this->pdo,$scopes,7,['id'=>1,'enabled'=>1,'portal_projection_enabled'=>1]);
        $this->pdo->commit();

        self::assertSame(4,$summary['review_required']);
        self::assertSame(0,$summary['eligible']);
        self::assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM portal_principals')->fetchColumn());
        self::assertSame(['duplicate_email','duplicate_email','non_human_record','non_human_record'],$this->pdo->query('SELECT review_reason FROM portal_client_login_eligibility ORDER BY client_id')->fetchAll(PDO::FETCH_COLUMN));
    }

    public function testExplicitClientAndRootRevocationSurviveReconciliation(): void
    {
        $this->pdo->exec("INSERT INTO organizations VALUES(10,'org-a','Example Organization');
            INSERT INTO clients VALUES(20,'client-a','A Person','person@example.test',10,'unknown',0,NULL,'v1')");
        $scope=[['root_type'=>'organization','root_public_id'=>'org-a']];
        $profile=['id'=>1,'enabled'=>1,'portal_projection_enabled'=>1];
        $this->pdo->beginTransaction();$this->service->ensureScopes($this->pdo,$scope,7,$profile);$this->pdo->commit();
        $principal=(int)$this->pdo->query('SELECT portal_principal_id FROM portal_client_login_eligibility')->fetchColumn();

        $this->pdo->exec("UPDATE portal_client_login_eligibility SET manual_state='revoked'; UPDATE portal_client_access_roots SET access_state='revoked'");
        $this->pdo->beginTransaction();$summary=$this->service->ensureScopes($this->pdo,$scope,7,$profile);$this->pdo->commit();

        self::assertSame(1,$summary['revoked']);
        self::assertSame('revoked',$this->pdo->query('SELECT eligibility_status FROM portal_client_login_eligibility')->fetchColumn());
        self::assertSame(0,(int)$this->pdo->query("SELECT enabled FROM portal_principals WHERE id={$principal}")->fetchColumn());
        self::assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM portal_v2_entitlements WHERE active=1')->fetchColumn());
    }

    public function testFutureMutationPathInvokesProvisioningBeforeProjection(): void
    {
        $source=(string)file_get_contents(dirname(__DIR__,2).'/src/services/PortalProjectionMutationService.php');
        self::assertStringContainsString('(new PortalClientProvisioningService())->ensureScopes($pdo,$scopes);',$source);
        self::assertLessThan(strpos($source,'$this->reconcileRelations($pdo,$scopes);'),strpos($source,'PortalClientProvisioningService())->ensureScopes'));
    }

    public function testSingleConnectionDerivesPortalReceiverAndUsesDeploymentCapability(): void
    {
        $previousEncryption=getenv('APP_ENCRYPTION_KEY');
        $previousCapabilities=getenv('PORTAL_INTEGRATION_HMAC_SECRETS_JSON');
        putenv('APP_ENCRYPTION_KEY=portal-provisioning-test-key');
        putenv('PORTAL_INTEGRATION_HMAC_SECRETS_JSON='.json_encode([
            'new_operations'=>['portal'=>['keyId'=>'portal-v1','current'=>str_repeat('s',32)]],
        ],JSON_THROW_ON_ERROR));
        try{
            $profileId=$this->service->configureConnection($this->pdo,[
                'application_key'=>'new_operations',
                'label'=>'Operations',
                'webhook_url'=>'https://operations.example.test/api/integration/events?source=ignored',
                'access_client_id'=>'access-id',
                'access_client_secret'=>'access-secret',
                'timeout_seconds'=>15,
                'max_attempts'=>12,
            ],7);
            self::assertNotNull($profileId);
            $profile=$this->pdo->query('SELECT * FROM portal_integration_profiles WHERE id='.(int)$profileId)->fetch(PDO::FETCH_ASSOC);
            self::assertSame('https://operations.example.test/api/internal/project-alpha/portal-v2',$profile['portal_route']);
            self::assertSame('portal-v1',$profile['delivery_key_id']);
            self::assertSame(1,(int)$profile['portal_projection_enabled']);
            self::assertSame(1,(int)$profile['relation_projection_enabled']);
            $credentials=(new \App\Services\PortalProjectionDeliveryConfigService())->credentials($profile);
            self::assertSame(str_repeat('s',32),$credentials['currentSecret']);
            self::assertSame('access-id',$credentials['authHeaders']['CF-Access-Client-Id']);
            self::assertSame('access-secret',$credentials['authHeaders']['CF-Access-Client-Secret']);
        }finally{
            $previousEncryption===false?putenv('APP_ENCRYPTION_KEY'):putenv('APP_ENCRYPTION_KEY='.$previousEncryption);
            $previousCapabilities===false?putenv('PORTAL_INTEGRATION_HMAC_SECRETS_JSON'):putenv('PORTAL_INTEGRATION_HMAC_SECRETS_JSON='.$previousCapabilities);
        }
    }
}
