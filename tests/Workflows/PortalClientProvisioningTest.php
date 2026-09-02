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
CREATE TABLE portal_projection_outbox(id INTEGER PRIMARY KEY AUTOINCREMENT,integration_profile_id INTEGER,delivery_id TEXT,workspace_public_id TEXT,schema_version INTEGER,source_sequence INTEGER,delivery_kind TEXT,route_type TEXT,is_revocation INTEGER DEFAULT 0,destination_url TEXT,signing_key_id TEXT,payload_json TEXT,attempts INTEGER DEFAULT 0,next_attempt_at TEXT,claim_token TEXT,claimed_at TEXT,delivered_at TEXT,dead_lettered_at TEXT,last_http_status INTEGER,last_error_code TEXT);
CREATE TABLE portal_projection_state(integration_profile_id INTEGER,workspace_public_id TEXT,source_generation TEXT,source_sequence INTEGER,last_snapshot_hash TEXT,PRIMARY KEY(integration_profile_id,workspace_public_id));
CREATE TABLE portal_projection_resource_state(integration_profile_id INTEGER,workspace_public_id TEXT,route_type TEXT,resource_type TEXT,resource_public_id TEXT,source_version TEXT,payload_hash TEXT,record_json TEXT,PRIMARY KEY(integration_profile_id,workspace_public_id,route_type,resource_type,resource_public_id));
CREATE TABLE portal_integration_audit(id INTEGER PRIMARY KEY AUTOINCREMENT,integration_profile_id INTEGER,api_key_id INTEGER,action TEXT,target_type TEXT,target_public_id TEXT,metadata_json TEXT);
INSERT INTO portal_integration_profiles(id,application_key,display_label,enabled,portal_projection_enabled) VALUES(1,'generic_operations','Generic operations',0,0);
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
                'enabled'=>1,
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

    public function testDisableQueuesOldRouteTombstoneAndKeepsDeliveryAlive():void
    {
        $this->withPortalCapabilities(['generic_operations'=>['portal'=>['keyId'=>'portal-v1','current'=>str_repeat('a',32)]]],function():void{
            $config=$this->connection('generic_operations','https://old.example.test/events',true);
            $profileId=(int)$this->service->configureConnection($this->pdo,$config,7);
            $this->activateWorkspace($profileId);

            $config['enabled']=0;$config['configured_enabled']=false;
            $this->service->configureConnection($this->pdo,$config,7);

            $profile=$this->pdo->query("SELECT * FROM portal_integration_profiles WHERE id={$profileId}")->fetch(PDO::FETCH_ASSOC);
            self::assertSame(0,(int)$profile['enabled']);self::assertSame(0,(int)$profile['portal_projection_enabled']);self::assertSame(1,(int)$profile['delivery_enabled']);
            self::assertSame('https://old.example.test/api/internal/project-alpha/portal-v2',$this->pdo->query("SELECT destination_url FROM portal_projection_outbox WHERE integration_profile_id={$profileId} AND is_revocation=1")->fetchColumn());
            self::assertSame('1',$this->pdo->query("SELECT config_value FROM app_config WHERE config_key='portal_outbound_delivery_enabled'")->fetchColumn());
        });
    }

    public function testOriginRotationWaitsForDrainThenReusesOnlyBoundProfile():void
    {
        $this->withPortalCapabilities(['generic_operations'=>['portal'=>['keyId'=>'portal-v1','current'=>str_repeat('a',32)]]],function():void{
            $profileId=(int)$this->service->configureConnection($this->pdo,$this->connection('generic_operations','https://old.example.test/events'),7);$this->activateWorkspace($profileId);
            $this->pdo->prepare("INSERT INTO portal_projection_outbox(integration_profile_id,delivery_id,workspace_public_id,schema_version,source_sequence,delivery_kind,route_type,is_revocation,destination_url,signing_key_id,payload_json)VALUES(?,'normal-before-rotation','workspace-legacy',3,1,'event','portal',0,'https://old.example.test/api/internal/project-alpha/portal-v2','portal-v1','{}')")->execute([$profileId]);
            $new=$this->connection('generic_operations','https://new.example.test/events');
            self::assertSame($profileId,$this->service->configureConnection($this->pdo,$new,7));
            self::assertSame(0,(int)$this->pdo->query("SELECT enabled FROM portal_integration_profiles WHERE id={$profileId}")->fetchColumn());
            self::assertSame('https://old.example.test/api/internal/project-alpha/portal-v2',$this->pdo->query("SELECT destination_url FROM portal_projection_outbox WHERE is_revocation=1")->fetchColumn());
            $this->service->configureConnection($this->pdo,$new,7);
            self::assertSame(1,(int)$this->pdo->query('SELECT COUNT(*) FROM portal_integration_profiles')->fetchColumn());
            self::assertSame(0,(int)$this->pdo->query("SELECT enabled FROM portal_integration_profiles WHERE id={$profileId}")->fetchColumn(),'Replacement must remain inactive before drain.');
            $this->pdo->exec("UPDATE portal_projection_outbox SET delivered_at=CURRENT_TIMESTAMP WHERE is_revocation=1");
            self::assertSame($profileId,$this->service->configureConnection($this->pdo,$new,7));
            $profile=$this->pdo->query("SELECT * FROM portal_integration_profiles WHERE id={$profileId}")->fetch(PDO::FETCH_ASSOC);
            self::assertSame(1,(int)$profile['enabled']);self::assertSame('https://new.example.test/api/internal/project-alpha/portal-v2',$profile['portal_route']);
            self::assertSame('profile_disabled_superseded',$this->pdo->query("SELECT last_error_code FROM portal_projection_outbox WHERE delivery_id='normal-before-rotation'")->fetchColumn());
            self::assertSame(1,(int)$this->pdo->query('SELECT COUNT(*) FROM portal_integration_profiles WHERE enabled=1 AND portal_projection_enabled=1')->fetchColumn());
        });
    }

    public function testApplicationRekeyRetiresDrainsAndRotatesSameProfile():void
    {
        $this->withPortalCapabilities([
            'generic_operations'=>['portal'=>['keyId'=>'portal-v1','current'=>str_repeat('a',32)]],
            'replacement_operations'=>['portal'=>['keyId'=>'portal-v2','current'=>str_repeat('b',32)]],
        ],function():void{
            $profileId=(int)$this->service->configureConnection($this->pdo,$this->connection('generic_operations','https://old.example.test/events'),7);$this->activateWorkspace($profileId);
            $new=$this->connection('replacement_operations','https://new.example.test/events');
            $this->service->configureConnection($this->pdo,$new,7);
            self::assertSame('generic_operations',$this->pdo->query("SELECT application_key FROM portal_integration_profiles WHERE id={$profileId}")->fetchColumn());
            $this->pdo->exec('UPDATE portal_projection_outbox SET delivered_at=CURRENT_TIMESTAMP WHERE is_revocation=1');
            $this->service->configureConnection($this->pdo,$new,7);
            $profile=$this->pdo->query("SELECT * FROM portal_integration_profiles WHERE id={$profileId}")->fetch(PDO::FETCH_ASSOC);
            self::assertSame('replacement_operations',$profile['application_key']);self::assertSame('portal-v2',$profile['delivery_key_id']);
            self::assertSame(1,(int)$this->pdo->query('SELECT COUNT(*) FROM portal_integration_profiles')->fetchColumn());
        });
    }

    /** @return array<string,mixed> */
    private function connection(string$key,string$url,bool$enabled=true):array{return['enabled'=>$enabled?1:0,'configured_enabled'=>$enabled,'application_key'=>$key,'label'=>'Operations','webhook_url'=>$url,'access_client_id'=>'access-id','access_client_secret'=>'access-secret','timeout_seconds'=>15,'max_attempts'=>12];}
    private function activateWorkspace(int$profileId):void{$this->pdo->exec("INSERT INTO organizations VALUES(90,'org-rotation','Rotation Org');INSERT INTO clients VALUES(91,'client-rotation','Rotation Person','rotation@example.test',90,'consumer',0,NULL,'v1')");$this->pdo->beginTransaction();$this->service->ensureScopes($this->pdo,[['root_type'=>'organization','root_public_id'=>'org-rotation']],7,$this->pdo->query("SELECT * FROM portal_integration_profiles WHERE id={$profileId}")->fetch(PDO::FETCH_ASSOC));$this->pdo->commit();$workspace=(string)$this->pdo->query("SELECT public_id FROM portal_v2_workspaces WHERE root_public_id='org-rotation'")->fetchColumn();$this->pdo->prepare('INSERT INTO portal_projection_state VALUES(?,?,?,?,?)')->execute([$profileId,$workspace,'generation-1',1,str_repeat('f',64)]);}
    /** @param array<string,mixed> $capabilities */
    private function withPortalCapabilities(array$capabilities,callable$test):void{$previousEncryption=getenv('APP_ENCRYPTION_KEY');$previousCapabilities=getenv('PORTAL_INTEGRATION_HMAC_SECRETS_JSON');putenv('APP_ENCRYPTION_KEY=portal-provisioning-lifecycle-test-key');putenv('PORTAL_INTEGRATION_HMAC_SECRETS_JSON='.json_encode($capabilities,JSON_THROW_ON_ERROR));try{$test();}finally{$previousEncryption===false?putenv('APP_ENCRYPTION_KEY'):putenv('APP_ENCRYPTION_KEY='.$previousEncryption);$previousCapabilities===false?putenv('PORTAL_INTEGRATION_HMAC_SECRETS_JSON'):putenv('PORTAL_INTEGRATION_HMAC_SECRETS_JSON='.$previousCapabilities);}}
}
