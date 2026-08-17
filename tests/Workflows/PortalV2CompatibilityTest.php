<?php

declare(strict_types=1);

namespace Tests\Workflows;

use App\Services\PortalIntegrationContract;
use App\Services\PortalSourceVersion;
use App\Services\QuoteDraftDomainService;
use App\Services\PortalIntegrationSecurityService;
use App\Services\PortalWorkspaceAuthorizationService;
use App\Services\PortalAuthorityService;
use PDO;
use DomainException;
use PHPUnit\Framework\TestCase;

final class PortalV2CompatibilityTest extends TestCase
{
    private string $fixtures;

    protected function setUp(): void
    {
        $this->fixtures = dirname(__DIR__) . '/fixtures/';
    }

    public function testNormativeCorpusIsByteExact(): void
    {
        $hashes = [
            'project-alpha-portal-v2.json'=>'808185cb582476f7e64f5a2c1f8c9c283d1bb5c4db1550227b19ff82887301bd',
            'project-alpha-portal-relations-v3.json'=>'87508874a56c76eb768e1b2a87fe77dec28b58fb06802c45d85c640685890a28',
            'project-alpha-catalog-v2.json'=>'9626ee5147ac9cd2198e6bca58eee9bb464c2105861c679a16745e1d9bf022fe',
            'project-alpha-pricing-hint-v1.json'=>'6354ad8fb2439e4463202290516a05198ec03cf0a966fbfb4bd83fcf18449d6b',
            'project-alpha-draft-quote-v1.json'=>'fc47be82960b11ab6cb705e2dcaa11f76f39ef9c5c3199ff6861e8a787034f90',
            'portal-integration-wire-v1.json'=>'063c993d8d1a20d7860517342e132efcf65a00b98b251b89ebf979333979d88a',
        ];
        foreach ($hashes as $file=>$expected) self::assertSame($expected,hash_file('sha256',$this->fixtures.$file),$file);
    }

    public function testEveryNormativeValidAndInvalidContractCase(): void
    {
        $v2=$this->json('project-alpha-portal-v2.json');foreach($v2['valid'] as $delivery)PortalIntegrationContract::validatePortalDelivery($delivery,false);foreach($v2['invalid'] as $case)$this->assertDomain(static fn()=>PortalIntegrationContract::validatePortalDelivery($case['delivery'],false));
        $v3=$this->json('project-alpha-portal-relations-v3.json');foreach($v3['valid'] as $delivery)PortalIntegrationContract::validatePortalDelivery($delivery,true);foreach($v3['invalid'] as $case)$this->assertDomain(static fn()=>PortalIntegrationContract::validatePortalDelivery($case['delivery'],true));
        $catalog=$this->json('project-alpha-catalog-v2.json');foreach($catalog['validItems'] as $item)PortalIntegrationContract::validateCatalogItem($item);foreach($catalog['invalidItems'] as $case)$this->assertDomain(static fn()=>PortalIntegrationContract::validateCatalogItem($case['item']));
        $pricing=$this->json('project-alpha-pricing-hint-v1.json');PortalIntegrationContract::validatePricingRequest($pricing['request']);PortalIntegrationContract::validatePricingResponse($pricing['response']);foreach($pricing['invalidRequests'] as $case)$this->assertDomain(static fn()=>PortalIntegrationContract::validatePricingRequest($case['request']));foreach($pricing['invalidResponses'] as $case)$this->assertDomain(static fn()=>PortalIntegrationContract::validatePricingResponse($case['response']));
        $draft=$this->json('project-alpha-draft-quote-v1.json');PortalIntegrationContract::validateDraftRequest($draft['valid']['request']);PortalIntegrationContract::validateDraftResponse($draft['valid']['response']);foreach($draft['invalidRequests'] as $case)$this->assertDomain(static fn()=>PortalIntegrationContract::validateDraftRequest($case['request']));foreach($draft['invalidResponses'] as $case)$this->assertDomain(static fn()=>PortalIntegrationContract::validateDraftResponse($case['response']));
        self::addToAssertionCount(1);
    }

    public function testNeutralHmacContractIsByteAndPathBound(): void
    {
        $body='{"schemaVersion":1}';$digest=hash('sha256',$body);$timestamp=gmdate('Y-m-d\TH:i:s.000\Z');$path='/api/v2/integrations/example/pricing-hints';$secret=str_repeat('s',32);$input=$timestamp."\nPOST\n".$path."\n".PortalIntegrationContract::PRICING_SCOPE."\n".$digest;
        $server=['HTTP_X_PORTAL_INTEGRATION_APPLICATION_KEY'=>'example','HTTP_X_PORTAL_INTEGRATION_TIMESTAMP'=>$timestamp,'HTTP_X_PORTAL_INTEGRATION_BODY_SHA256'=>$digest,'HTTP_X_PORTAL_INTEGRATION_SCOPE'=>PortalIntegrationContract::PRICING_SCOPE,'HTTP_X_PORTAL_INTEGRATION_SIGNATURE'=>'sha256='.hash_hmac('sha256',$input,$secret)];
        PortalIntegrationContract::verifySignedRequest('example',PortalIntegrationContract::PRICING_SCOPE,$path,$body,$server,$secret);
        $this->assertDomain(static fn()=>PortalIntegrationContract::verifySignedRequest('example',PortalIntegrationContract::PRICING_SCOPE,$path,$body.' ',$server,$secret));
        $this->assertDomain(static fn()=>PortalIntegrationContract::verifySignedRequest('example',PortalIntegrationContract::PRICING_SCOPE,$path.'/wrong',$body,$server,$secret));
        self::assertArrayNotHasKey('HTTP_X_LTDS_SIGNATURE',$server);
        $draftPath='/api/v2/integrations/example/draft-quotes';$draftId='command-one';$draftInput=$timestamp."\nPOST\n".$draftPath."\n".$draftId."\n".$digest;$draftServer=$server;unset($draftServer['HTTP_X_PORTAL_INTEGRATION_SCOPE']);$draftServer['HTTP_IDEMPOTENCY_KEY']=$draftId;$draftServer['HTTP_X_PORTAL_INTEGRATION_SIGNATURE']='sha256='.hash_hmac('sha256',$draftInput,$secret);PortalIntegrationContract::verifySignedRequest('example',PortalIntegrationContract::DRAFT_SCOPE,$draftPath,$body,$draftServer,$secret);
        $draftServer['HTTP_X_PORTAL_INTEGRATION_TIMESTAMP']='2020-01-01T00:00:00.000Z';$this->assertDomain(static fn()=>PortalIntegrationContract::verifySignedRequest('example',PortalIntegrationContract::DRAFT_SCOPE,$draftPath,$body,$draftServer,$secret));
    }

    public function testNeutralGoldenWireCorpusPinsEveryCanonicalRequest():void
    {
        $fixture=$this->json('portal-integration-wire-v1.json');$secret=(string)$fixture['testSecret'];
        foreach(['portalProjection','catalogProjection']as$name){$case=$fixture['cases'][$name];self::assertSame($case['bodySha256'],hash('sha256',$case['body']));$canonical=$case['timestamp']."\nPOST\n".$case['path']."\n".$case['keyId']."\n".$case['deliveryId']."\n".$case['body'];self::assertSame(str_replace('\\n',"\n",$case['canonical']),$canonical);self::assertSame($case['signature'],'sha256='.hash_hmac('sha256',$canonical,$secret));$delivery=json_decode($case['body'],true,32,JSON_THROW_ON_ERROR);if($name==='portalProjection')PortalIntegrationContract::validatePortalDelivery($delivery,false);else PortalIntegrationContract::validateCatalogDelivery($delivery);}
        foreach(['pricing'=>PortalIntegrationContract::PRICING_SCOPE,'draft'=>PortalIntegrationContract::DRAFT_SCOPE]as$name=>$scope){$case=$fixture['cases'][$name];self::assertSame($case['bodySha256'],hash('sha256',$case['body']));$canonical=$case['timestamp']."\nPOST\n".$case['path']."\n".$case['scopeOrIdempotencyKey']."\n".$case['bodySha256'];self::assertSame(str_replace('\\n',"\n",$case['canonical']),$canonical);self::assertSame($case['signature'],'sha256='.hash_hmac('sha256',$canonical,$secret));$timestamp=gmdate('Y-m-d\TH:i:s.000\Z');$liveCanonical=$timestamp."\nPOST\n".$case['path']."\n".$case['scopeOrIdempotencyKey']."\n".$case['bodySha256'];$server=['HTTP_X_PORTAL_INTEGRATION_APPLICATION_KEY'=>$case['applicationKey'],'HTTP_X_PORTAL_INTEGRATION_TIMESTAMP'=>$timestamp,'HTTP_X_PORTAL_INTEGRATION_BODY_SHA256'=>$case['bodySha256'],'HTTP_X_PORTAL_INTEGRATION_SIGNATURE'=>'sha256='.hash_hmac('sha256',$liveCanonical,$secret)];if($name==='pricing')$server['HTTP_X_PORTAL_INTEGRATION_SCOPE']=$scope;else$server['HTTP_IDEMPOTENCY_KEY']=$case['scopeOrIdempotencyKey'];PortalIntegrationContract::verifySignedRequest($case['applicationKey'],$scope,$case['path'],$case['body'],$server,$secret);}
        $rotation=$fixture['rotationOverlap'];$portal=$fixture['cases']['portalProjection'];$previousCanonical=$portal['timestamp']."\nPOST\n".$portal['path']."\n".$rotation['previousKeyId']."\n".$portal['deliveryId']."\n".$portal['body'];self::assertSame(str_replace('\\n',"\n",$rotation['previousCanonical']),$previousCanonical);self::assertSame($rotation['previousSignature'],'sha256='.hash_hmac('sha256',$previousCanonical,$rotation['previousTestSecret']));self::assertNotSame($rotation['currentKeyId'],$rotation['previousKeyId']);self::assertNotContains($rotation['unknownKeyId'],[$rotation['currentKeyId'],$rotation['previousKeyId']]);
        self::assertSame(['X-Portal-Integration-Application-Key','X-Portal-Integration-Timestamp','X-Portal-Integration-Body-SHA256','X-Portal-Integration-Key-Id','X-Portal-Integration-Delivery-Id','X-Portal-Integration-Signature'],$fixture['projectionHeaders']);
    }

    public function testCatalogSnapshotsAreBoundedAndMonotonicPerProfile():void
    {
        if(!in_array('sqlite',PDO::getAvailableDrivers(),true))self::markTestSkipped('pdo_sqlite unavailable');$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
        $pdo->exec("CREATE TABLE portal_integration_profiles(id INTEGER PRIMARY KEY,application_key TEXT,enabled INTEGER,catalog_projection_enabled INTEGER,catalog_route TEXT,delivery_key_id TEXT);CREATE TABLE portal_projection_state(integration_profile_id INTEGER,workspace_public_id TEXT,source_generation TEXT,source_sequence INTEGER,last_snapshot_hash TEXT,PRIMARY KEY(integration_profile_id,workspace_public_id));CREATE TABLE portal_projection_outbox(id INTEGER PRIMARY KEY AUTOINCREMENT,integration_profile_id INTEGER,delivery_id TEXT,workspace_public_id TEXT,schema_version INTEGER,source_sequence INTEGER,delivery_kind TEXT,route_type TEXT,is_revocation INTEGER DEFAULT 0,destination_url TEXT,signing_key_id TEXT,payload_json TEXT);CREATE TABLE item_library(portal_public_id TEXT,portal_source_version TEXT,item_name TEXT,portal_summary TEXT,portal_category TEXT,portal_display_order INTEGER,portal_geometry_requirement TEXT,portal_questions_json TEXT,portal_requestable INTEGER,is_active INTEGER,entry_type TEXT);INSERT INTO portal_integration_profiles VALUES(1,'generic_catalog',1,1,'https://receiver.example/api/internal/project-alpha/catalog-v2','catalog-v1')");
        $service=new \App\Services\PortalProjectionMutationService();$pdo->beginTransaction();$first=$service->queueCatalog($pdo,1);$pdo->commit();$pdo->beginTransaction();$second=$service->queueCatalog($pdo,1);$pdo->commit();self::assertSame(1,$first[0]['sourceSequence']);self::assertSame(2,$second[0]['sourceSequence']);self::assertNotSame($first[0]['sourceGeneration'],$second[0]['sourceGeneration']);self::assertSame(['snapshot.page','snapshot.activate','snapshot.page','snapshot.activate'],$pdo->query('SELECT delivery_kind FROM portal_projection_outbox ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));foreach($pdo->query('SELECT payload_json FROM portal_projection_outbox')->fetchAll(PDO::FETCH_COLUMN)as$json)PortalIntegrationContract::validateCatalogDelivery(json_decode((string)$json,true,32,JSON_THROW_ON_ERROR));
    }

    public function testAlternateServiceLibraryMutationsQueueCatalogRecovery():void
    {
        $source=(string)file_get_contents(dirname(__DIR__,2).'/src/controllers/settings/workforce_catalog_handler.php');self::assertSame(2,substr_count($source,'(new PortalProjectionMutationService())->queueCatalog($pdo)'));
    }

    public function testContentSourceVersionsChangeForEveryProjectedMutation(): void
    {
        $base=['type'=>'project','publicId'=>'project-one','parentPublicId'=>'department-one','displayName'=>'Survey','active'=>true];
        $version=PortalSourceVersion::from($base);self::assertSame($version,PortalSourceVersion::from($base));
        foreach ([['displayName'=>'Survey renamed'],['parentPublicId'=>'department-two'],['active'=>false],['questions'=>[['id'=>'height']]],['completedAt'=>'2026-08-16T00:00:00.000Z']] as $change) self::assertNotSame($version,PortalSourceVersion::from(array_replace($base,$change)));
    }

    public function testRuntimeIsGenericAndDefaultOff(): void
    {
        $root=dirname(__DIR__,2);$migration=(string)file_get_contents($root.'/database/migrations/0066_generic_portal_v2_integration.sql');
        foreach(['portal_v2_integration_enabled','portal_v2_relations_enabled','portal_catalog_v2_enabled','portal_pricing_preview_enabled','portal_draft_quotes_enabled']as$key)self::assertStringContainsString("'{$key}','0'",$migration);
        $runtime='';foreach(['src/services/PortalIntegrationContract.php','src/controllers/api/integration_pricing_hints.php','src/controllers/api/integration_draft_quotes.php']as$file)$runtime.=(string)file_get_contents($root.'/'.$file);
        self::assertStringNotContainsString('LTDS',$runtime);self::assertStringNotContainsString('ledgetopdroneservices.com',$runtime);self::assertStringContainsString('X_PORTAL_INTEGRATION',$runtime);
    }

    public function testServerRoutesPreserveAclIdorAndRejectBrowserCors():void
    {
        $root=dirname(__DIR__,2);$front=(string)file_get_contents($root.'/public/index.php');$quote=(string)file_get_contents($root.'/src/views/pages/quote/quotes-edit-public.php');
        self::assertStringContainsString("api_require_key([\$requiredApiScope], false)",$front);self::assertStringContainsString('BROWSER_ORIGIN_DENIED',$front);self::assertStringContainsString("!\$isServerOnlyIntegration",$front);self::assertStringContainsString('/quotes/([a-f0-9]{32})/edit',$front);
        self::assertStringContainsString('require_record_ownership',$quote);self::assertStringContainsString('http_response_code(404)',$quote);
        $acl=(string)file_get_contents($root.'/src/utils/acl_middleware.php');self::assertStringContainsString("'quote/quotes-edit-public' => 'quotes.edit'",$acl);
    }

    public function testStaffAuthorityUxIsResponsiveKeyboardNativeAndSeparatedFromContacts():void
    {
        $page=(string)file_get_contents(dirname(__DIR__,2).'/src/views/pages/settings/external-ops.php');
        self::assertStringContainsString('@media(max-width:390px)',$page);self::assertStringContainsString('@media(max-width:640px)',$page);
        self::assertStringContainsString('<details>',$page);self::assertStringContainsString('<summary class="btn btn-sm">Edit profile</summary>',$page);
        self::assertStringContainsString('primary contact',$page);self::assertStringContainsString('public-project link',$page);self::assertStringContainsString('never grants portal access',$page);
        self::assertStringContainsString('appoint-portal-manager',$page);self::assertStringContainsString('offboard-portal-manager',$page);self::assertStringContainsString('queue-portal-snapshot',$page);
    }

    public function testAbuseStateHasIndexedBoundedRetention():void
    {
        $root=dirname(__DIR__,2);$migration=(string)file_get_contents($root.'/database/migrations/0066_generic_portal_v2_integration.sql');$maintenance=(string)file_get_contents($root.'/src/services/PortalIntegrationMaintenanceService.php');$cron=(string)file_get_contents($root.'/cron/crontab');
        self::assertStringContainsString('idx_portal_integration_receipt_age (last_seen_at,id)',$migration);self::assertStringContainsString('idx_portal_rate_bucket_age (window_minute,integration_profile_id,api_key_id,capability,source_hash)',$migration);self::assertStringContainsString("min(5000,\$batch)",$maintenance);self::assertStringContainsString('MAX_ROWS_PER_RUN = 100000',$maintenance);self::assertStringContainsString('MAX_PASSES_PER_RUN = 40',$maintenance);self::assertStringContainsString('MAX_RUNTIME_MILLISECONDS = 2500',$maintenance);self::assertStringContainsString('ORDER BY last_seen_at,id LIMIT',$maintenance);self::assertStringContainsString('ORDER BY window_minute,integration_profile_id,api_key_id,capability,source_hash LIMIT',$maintenance);self::assertStringContainsString('RECEIPT_RETENTION_HOURS = 24',$maintenance);self::assertStringContainsString('RATE_RETENTION_MINUTES = 2880',$maintenance);self::assertStringContainsString('* * * * * root . /etc/environment && php /var/www/src/cron/prune_portal_integration_security.php',$cron);
    }

    public function testDraftPricingUsesExactMinorUnitsAndRefusesVariableAutomation():void
    {
        $fixed=['id'=>1,'item_name'=>'Survey','description'=>null,'unit_price'=>'10.10','client_pricing_model'=>'fixed','billing_unit'=>'project','pricing_currency'=>'USD','portal_public_id'=>'service-one','portal_source_version'=>'version-one'];$second=$fixed;$second['id']=2;$second['unit_price']='0.20';$second['portal_public_id']='service-two';
        $priced=(new QuoteDraftDomainService())->priceServices([$fixed,$second]);self::assertTrue($priced['available']);self::assertSame('10.30',$priced['total']);self::assertSame('10.10',$priced['lines'][0]['line_total']);
        $fixed['client_pricing_model']='base_overage';$variable=(new QuoteDraftDomainService())->priceServices([$fixed]);self::assertFalse($variable['available']);self::assertSame('0.00',$variable['total']);self::assertSame('variable',$variable['lines'][0]['pricing_status']);
        $service=(string)file_get_contents(dirname(__DIR__,2).'/src/services/PortalIntegrationService.php');foreach(['send','approve','invoice','payment','email']as$sideEffect)self::assertDoesNotMatchRegularExpression('/\\b'.$sideEffect.'\\s*\\(/i',$service);
    }

    public function testDraftTimeoutRetriesAreIdempotentButStillRateBounded():void
    {
        if(!in_array('sqlite',PDO::getAvailableDrivers(),true))self::markTestSkipped('pdo_sqlite unavailable');$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE portal_integration_profiles(id INTEGER PRIMARY KEY,application_key TEXT,enabled INTEGER,draft_quote_enabled INTEGER,pricing_preview_enabled INTEGER);CREATE TABLE portal_integration_rate_buckets(integration_profile_id INTEGER,api_key_id INTEGER,capability TEXT,source_hash TEXT,window_minute INTEGER,request_count INTEGER DEFAULT 0,PRIMARY KEY(integration_profile_id,api_key_id,capability,source_hash,window_minute));CREATE TABLE portal_integration_request_receipts(id INTEGER PRIMARY KEY AUTOINCREMENT,integration_profile_id INTEGER,api_key_id INTEGER,capability TEXT,signature_hash TEXT,idempotency_hash TEXT,body_hash TEXT,first_seen_at TEXT DEFAULT CURRENT_TIMESTAMP,last_seen_at TEXT DEFAULT CURRENT_TIMESTAMP,replay_count INTEGER DEFAULT 0,UNIQUE(integration_profile_id,api_key_id,capability,signature_hash));INSERT INTO portal_integration_profiles VALUES(1,\'example\',1,1,1)');
        $security=new PortalIntegrationSecurityService();for($i=0;$i<10;$i++)$security->claim($pdo,'example',7,PortalIntegrationContract::DRAFT_SCOPE,str_repeat('a',64),'sha256='.str_repeat('b',64),'same-command','192.0.2.1');
        $replays=(int)$pdo->query('SELECT replay_count FROM portal_integration_request_receipts')->fetchColumn();self::assertSame(9,$replays);
        try{$security->claim($pdo,'example',7,PortalIntegrationContract::DRAFT_SCOPE,str_repeat('a',64),'sha256='.str_repeat('b',64),'same-command','192.0.2.1');self::fail('Expected bounded abuse rate.');}catch(DomainException$error){self::assertSame('integration-rate-limited',$error->getMessage());}
        // A newly signed timeout retry remains safe because quote-domain idempotency is keyed by command and payload hash.
        $service=(string)file_get_contents(dirname(__DIR__,2).'/src/services/PortalIntegrationService.php');self::assertStringContainsString('idempotencyHash = hash(\'sha256\', $idempotencyKey)',$service);self::assertStringContainsString("return ['status' => 409, 'body' => ['code' => 'IDEMPOTENCY_CONFLICT']]",$service);
    }

    public function testPricingReplayDenialDurablyConsumesAdmission():void
    {
        if(!in_array('sqlite',PDO::getAvailableDrivers(),true))self::markTestSkipped('pdo_sqlite unavailable');$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE portal_integration_profiles(id INTEGER PRIMARY KEY,application_key TEXT,enabled INTEGER,draft_quote_enabled INTEGER,pricing_preview_enabled INTEGER);CREATE TABLE portal_integration_rate_buckets(integration_profile_id INTEGER,api_key_id INTEGER,capability TEXT,source_hash TEXT,window_minute INTEGER,request_count INTEGER DEFAULT 0,PRIMARY KEY(integration_profile_id,api_key_id,capability,source_hash,window_minute));CREATE TABLE portal_integration_request_receipts(id INTEGER PRIMARY KEY AUTOINCREMENT,integration_profile_id INTEGER,api_key_id INTEGER,capability TEXT,signature_hash TEXT,idempotency_hash TEXT,body_hash TEXT,first_seen_at TEXT DEFAULT CURRENT_TIMESTAMP,last_seen_at TEXT DEFAULT CURRENT_TIMESTAMP,replay_count INTEGER DEFAULT 0,UNIQUE(integration_profile_id,api_key_id,capability,signature_hash));INSERT INTO portal_integration_profiles VALUES(1,\'example\',1,1,1)');
        $security=new PortalIntegrationSecurityService();$security->claim($pdo,'example',7,PortalIntegrationContract::PRICING_SCOPE,str_repeat('a',64),'sha256='.str_repeat('b',64),'','192.0.2.1');
        try{$security->claim($pdo,'example',7,PortalIntegrationContract::PRICING_SCOPE,str_repeat('a',64),'sha256='.str_repeat('b',64),'','192.0.2.1');self::fail('Expected pricing replay denial.');}catch(DomainException$error){self::assertSame('integration-replay-denied',$error->getMessage());}
        self::assertSame(2,(int)$pdo->query('SELECT request_count FROM portal_integration_rate_buckets')->fetchColumn());
    }

    public function testProfileWorkspaceAllowlistDeniesCrossProfileAndCrossRoot():void
    {
        if(!in_array('sqlite',PDO::getAvailableDrivers(),true))self::markTestSkipped('pdo_sqlite unavailable');$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
        $pdo->exec("CREATE TABLE portal_v2_workspaces(id INTEGER PRIMARY KEY,public_id TEXT,root_type TEXT,root_public_id TEXT,display_name TEXT,active INTEGER);CREATE TABLE portal_integration_profile_workspaces(profile_id INTEGER,workspace_id INTEGER,active INTEGER,PRIMARY KEY(profile_id,workspace_id));INSERT INTO portal_v2_workspaces VALUES(11,'workspace-a','organization','root-a','A',1),(22,'workspace-b','standalone_client','root-b','B',1);INSERT INTO portal_integration_profile_workspaces VALUES(1,11,1),(2,22,1)");
        $auth=new PortalWorkspaceAuthorizationService();self::assertSame('workspace-a',$auth->requireWorkspace($pdo,1,'workspace-a')['public_id']);self::assertSame('root-b',$auth->requireRoot($pdo,2,'standalone_client','root-b')['root_public_id']);
        foreach([[1,'workspace-b'],[2,'workspace-a']]as[$profile,$workspace])$this->assertDomain(static fn()=>$auth->requireWorkspace($pdo,$profile,$workspace));
        foreach([[1,'standalone_client','root-b'],[2,'organization','root-a']]as[$profile,$type,$root])$this->assertDomain(static fn()=>$auth->requireRoot($pdo,$profile,$type,$root));
        $root=dirname(__DIR__,2);$integration=(string)file_get_contents($root.'/src/services/PortalIntegrationService.php');$projection=(string)file_get_contents($root.'/src/services/PortalProjectionService.php');$authority=(string)file_get_contents($root.'/src/services/PortalAuthorityService.php');$mutations=(string)file_get_contents($root.'/src/services/PortalProjectionMutationService.php');
        self::assertGreaterThanOrEqual(3,substr_count($integration,'requireRoot('));self::assertStringContainsString('requireWorkspace($pdo, $profileId, $workspacePublicId)',$projection);self::assertStringContainsString('portal_integration_profile_workspaces',$authority);self::assertStringContainsString('portal_integration_profile_workspaces',$mutations);
    }

    public function testUnlinkQueuesOnlyFormerlyLinkedProfileRevocation():void
    {
        $pdo=$this->revocationDatabase();
        (new PortalAuthorityService())->setWorkspaceLink($pdo,1,'1abc-workspace',false,99);
        self::assertSame([[1,'event']],$pdo->query('SELECT integration_profile_id,delivery_kind FROM portal_projection_outbox ORDER BY id')->fetchAll(PDO::FETCH_NUM));
        self::assertSame(0,(int)$pdo->query('SELECT active FROM portal_integration_profile_workspaces WHERE profile_id=1')->fetchColumn());
        self::assertSame(1,(int)$pdo->query('SELECT active FROM portal_integration_profile_workspaces WHERE profile_id=2')->fetchColumn());
        self::assertNull($pdo->query('SELECT last_snapshot_hash FROM portal_projection_state WHERE integration_profile_id=1')->fetchColumn());
        self::assertSame(str_repeat('b',64),$pdo->query('SELECT last_snapshot_hash FROM portal_projection_state WHERE integration_profile_id=2')->fetchColumn());
        $payload=json_decode((string)$pdo->query('SELECT payload_json FROM portal_projection_outbox')->fetchColumn(),true,32,JSON_THROW_ON_ERROR);self::assertSame('portal_a',$payload['applicationKey']);self::assertSame(['resource'=>'workspace','action'=>'tombstone','publicId'=>'1abc-workspace','sourceVersion'=>$payload['event']['sourceVersion']],$payload['event']);
    }

    public function testDisablingProfileQueuesScopedRevocationBeforeStateChange():void
    {
        $pdo=$this->revocationDatabase();
        $pdo->exec("INSERT INTO portal_projection_outbox(integration_profile_id,delivery_id,workspace_public_id,schema_version,source_sequence,delivery_kind,route_type,is_revocation,payload_json) VALUES(1,'normal-before-disable','1abc-workspace',2,7,'snapshot.activate','portal',0,'{}')");
        (new PortalAuthorityService())->saveProfile($pdo,['profile_id'=>1,'application_key'=>'portal_a','display_label'=>'Portal A','portal_route'=>'https://a.example/portal','catalog_route'=>'https://a.example/catalog'],99);
        self::assertSame(2,(int)$pdo->query('SELECT COUNT(*) FROM portal_projection_outbox')->fetchColumn());
        self::assertSame('profile_disabled_superseded',$pdo->query("SELECT last_error_code FROM portal_projection_outbox WHERE delivery_id='normal-before-disable'")->fetchColumn());
        self::assertSame(1,(int)$pdo->query("SELECT is_revocation FROM portal_projection_outbox WHERE delivery_id<>'normal-before-disable'")->fetchColumn());
        self::assertSame([0,0],array_map('intval',$pdo->query('SELECT enabled,portal_projection_enabled FROM portal_integration_profiles WHERE id=1')->fetch(PDO::FETCH_NUM)));
        self::assertSame([1,1],array_map('intval',$pdo->query('SELECT active FROM portal_integration_profile_workspaces ORDER BY profile_id')->fetchAll(PDO::FETCH_COLUMN)));
        self::assertNull($pdo->query('SELECT last_snapshot_hash FROM portal_projection_state WHERE integration_profile_id=1')->fetchColumn());
        self::assertSame(str_repeat('b',64),$pdo->query('SELECT last_snapshot_hash FROM portal_projection_state WHERE integration_profile_id=2')->fetchColumn());
        self::assertSame(1,(int)$pdo->query("SELECT COUNT(*) FROM portal_integration_audit WHERE integration_profile_id=1 AND action='portal.profile.revocation_queued'")->fetchColumn());
    }

    public function testOpaqueIdsAcceptStableRandomHexButNeverLegacyNumericIds():void
    {
        $delivery=array_values(array_filter($this->json('project-alpha-portal-v2.json')['valid'],static fn(array$row):bool=>($row['kind']??null)==='snapshot.page'))[0];$delivery['workspaceId']='1abc-workspace';$delivery['workspace']['publicId']='1abc-workspace';PortalIntegrationContract::validatePortalDelivery($delivery,false);
        $delivery['workspaceId']='12345';$this->assertDomain(static fn()=>PortalIntegrationContract::validatePortalDelivery($delivery,false));
    }

    public function testProfileContractRotationAndEveryOutboxProducerShareOneRowLock():void
    {
        $root=dirname(__DIR__,2);$projection=(string)file_get_contents($root.'/src/services/PortalProjectionService.php');$authority=(string)file_get_contents($root.'/src/services/PortalAuthorityService.php');$mutations=(string)file_get_contents($root.'/src/services/PortalProjectionMutationService.php');
        self::assertStringContainsString("'SELECT * FROM portal_integration_profiles WHERE id=?'.\$suffix",$projection);
        self::assertStringContainsString("==='mysql'?' FOR UPDATE':''",$projection);
        self::assertStringContainsString("if(!\$pdo->inTransaction())throw new DomainException('portal-outbox-transaction-required')",$projection);
        self::assertGreaterThanOrEqual(4,substr_count($projection,'lockProfileContract('));
        self::assertStringContainsString('PortalProjectionService::lockProfileContract($pdo,$id)',$authority);
        self::assertStringContainsString("\$projection->queueCatalogSnapshot(\$pdo,['id'=>(int)\$profileId])",$mutations);
        self::assertStringContainsString("empty(\$profile['catalog_projection_enabled'])",$projection);
    }

    public function testOutboxProducerRequiresTransactionAndReloadsLockedProfileState():void
    {
        $pdo=$this->revocationDatabase();$projection=new \App\Services\PortalProjectionService();$stale=['id'=>1,'application_key'=>'stale-key','enabled'=>1,'portal_projection_enabled'=>1];
        try{$projection->queueWorkspaceRevocation($pdo,$stale,'1abc-workspace');self::fail('Expected transaction requirement.');}catch(DomainException$error){self::assertSame('portal-outbox-transaction-required',$error->getMessage());}
        $pdo->beginTransaction();$pdo->exec('UPDATE portal_integration_profiles SET enabled=0,portal_projection_enabled=0 WHERE id=1');
        try{$projection->queueWorkspaceRevocation($pdo,$stale,'1abc-workspace');self::fail('Expected locked state reload.');}catch(DomainException$error){self::assertSame('portal-profile-disabled',$error->getMessage());}
        self::assertSame(0,(int)$pdo->query('SELECT COUNT(*) FROM portal_projection_outbox')->fetchColumn());$pdo->rollBack();
    }

    private function revocationDatabase():PDO
    {
        if(!in_array('sqlite',PDO::getAvailableDrivers(),true))self::markTestSkipped('pdo_sqlite unavailable');$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
        $pdo->exec("CREATE TABLE portal_integration_profiles(id INTEGER PRIMARY KEY,application_key TEXT,display_label TEXT,enabled INTEGER,portal_projection_enabled INTEGER,relation_projection_enabled INTEGER,catalog_projection_enabled INTEGER,pricing_preview_enabled INTEGER,draft_quote_enabled INTEGER,pricing_source TEXT,draft_source TEXT,portal_route TEXT,catalog_route TEXT,delivery_key_id TEXT,updated_by INTEGER);CREATE TABLE portal_v2_workspaces(id INTEGER PRIMARY KEY,public_id TEXT,root_type TEXT,root_public_id TEXT,display_name TEXT,active INTEGER);CREATE TABLE portal_integration_profile_workspaces(profile_id INTEGER,workspace_id INTEGER,active INTEGER,created_by INTEGER,updated_by INTEGER,updated_at TEXT DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(profile_id,workspace_id));CREATE TABLE portal_projection_state(integration_profile_id INTEGER,workspace_public_id TEXT,source_generation TEXT,source_sequence INTEGER,last_snapshot_hash TEXT,PRIMARY KEY(integration_profile_id,workspace_public_id));CREATE TABLE portal_projection_outbox(id INTEGER PRIMARY KEY AUTOINCREMENT,integration_profile_id INTEGER,delivery_id TEXT,workspace_public_id TEXT,schema_version INTEGER,source_sequence INTEGER,delivery_kind TEXT,route_type TEXT,is_revocation INTEGER DEFAULT 0,destination_url TEXT,signing_key_id TEXT,payload_json TEXT,claimed_at TEXT,delivered_at TEXT,dead_lettered_at TEXT,last_error_code TEXT);CREATE TABLE portal_integration_audit(id INTEGER PRIMARY KEY AUTOINCREMENT,integration_profile_id INTEGER,api_key_id INTEGER,action TEXT,target_type TEXT,target_public_id TEXT,metadata_json TEXT);INSERT INTO portal_integration_profiles VALUES(1,'portal_a','Portal A',1,1,0,0,0,0,NULL,NULL,'https://a.example/portal','https://a.example/catalog',NULL,NULL),(2,'portal_b','Portal B',1,1,0,0,0,0,NULL,NULL,'https://b.example/portal','https://b.example/catalog',NULL,NULL);INSERT INTO portal_v2_workspaces VALUES(10,'1abc-workspace','organization','org-a','Workspace A',1);INSERT INTO portal_integration_profile_workspaces(profile_id,workspace_id,active) VALUES(1,10,1),(2,10,1);INSERT INTO portal_projection_state VALUES(1,'1abc-workspace','generation-a',7,'".str_repeat('a',64)."'),(2,'1abc-workspace','generation-b',9,'".str_repeat('b',64)."')");return$pdo;
    }

    private function json(string $file): array{return json_decode((string)file_get_contents($this->fixtures.$file),true,32,JSON_THROW_ON_ERROR);}
    private function assertDomain(callable $callback):void{try{$callback();self::fail('Expected strict contract rejection.');}catch(DomainException){self::addToAssertionCount(1);}}
}
