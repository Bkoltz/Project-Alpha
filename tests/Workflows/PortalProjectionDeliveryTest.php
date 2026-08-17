<?php

declare(strict_types=1);

namespace Tests\Workflows;

use App\Services\PortalProjectionDeliveryConfigService;
use App\Services\PortalProjectionMutationService;
use App\Services\PortalProjectionOutboxSender;
use DomainException;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PortalProjectionDeliveryTest extends TestCase
{
    private string|false $previousEncryptionKey;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite unavailable');
        }
        $this->previousEncryptionKey = getenv('APP_ENCRYPTION_KEY');
        putenv('APP_ENCRYPTION_KEY=portal-delivery-test-key');
    }

    protected function tearDown(): void
    {
        $this->previousEncryptionKey === false
            ? putenv('APP_ENCRYPTION_KEY')
            : putenv('APP_ENCRYPTION_KEY=' . $this->previousEncryptionKey);
    }

    public function testDisabledProfileRevocationDrainsWithExactBodySignatureAndOrdering(): void
    {
        $pdo = $this->deliveryDatabase();
        $this->insertDelivery($pdo, 1, 'delivery-one', '{"kind":"snapshot.activate"}');
        $body = '{"kind":"event","event":{"action":"tombstone"}}';
        $this->insertDelivery($pdo, 1, 'delivery-two', $body, true);
        $captured = [];
        $transport = static function (string $url, array $headers, string $rawBody, int $timeout) use (&$captured): array {
            $captured = compact('url', 'headers', 'rawBody', 'timeout');
            return ['status' => 204];
        };

        $summary = (new PortalProjectionOutboxSender())->deliverDue($pdo, 1, $transport);

        self::assertSame(['processed'=>1, 'delivered'=>1, 'failed'=>0, 'dead_lettered'=>0], $summary);
        self::assertSame('https://receiver.example/internal/portal', $captured['url']);
        self::assertSame($body, $captured['rawBody']);
        self::assertSame(15, $captured['timeout']);
        $headers = $this->headers($captured['headers']);
        self::assertSame('portal_test', $headers['x-portal-integration-application-key']);
        self::assertSame('key-current', $headers['x-portal-integration-key-id']);
        self::assertSame('delivery-two', $headers['x-portal-integration-delivery-id']);
        self::assertSame(hash('sha256', $body), $headers['x-portal-integration-body-sha256']);
        $canonical = $headers['x-portal-integration-timestamp'] . "\nPOST\n/internal/portal\nkey-current\ndelivery-two\n" . $body;
        self::assertSame('sha256=' . hash_hmac('sha256', $canonical, str_repeat('c', 32)), $headers['x-portal-integration-signature']);
        self::assertSame('Bearer opaque-test-value', $headers['authorization']);
        self::assertSame(1, (int)$pdo->query("SELECT COUNT(*) FROM portal_projection_outbox WHERE delivered_at IS NOT NULL")->fetchColumn());
        self::assertSame(1, (int)$pdo->query("SELECT COUNT(*) FROM portal_projection_outbox WHERE delivered_at IS NULL")->fetchColumn());
        self::assertSame('profile_disabled_superseded', $pdo->query("SELECT last_error_code FROM portal_projection_outbox WHERE delivery_id='delivery-one'")->fetchColumn());
    }

    public function testRedirectRetriesBlockLaterDeliveryAndTransportFailureDeadLettersAtBound(): void
    {
        $pdo = $this->deliveryDatabase();
        $pdo->exec('UPDATE portal_integration_profiles SET enabled=1,portal_projection_enabled=1 WHERE id=1');
        $this->insertDelivery($pdo, 1, 'delivery-one', '{}');
        $this->insertDelivery($pdo, 1, 'delivery-two', '{}');
        $sender = new PortalProjectionOutboxSender();

        $summary = $sender->deliverDue($pdo, 2, static fn(): array => ['status'=>302, 'body'=>'do not follow']);
        self::assertSame(1, $summary['processed']);
        self::assertSame(1, $summary['failed']);
        self::assertSame('redirect_rejected', $pdo->query('SELECT last_error_code FROM portal_projection_outbox WHERE id=1')->fetchColumn());
        self::assertSame(0, (int)$pdo->query('SELECT attempts FROM portal_projection_outbox WHERE id=2')->fetchColumn());

        $pdo->exec("UPDATE portal_projection_outbox SET next_attempt_at='2000-01-01 00:00:00.000000',attempts=11 WHERE id=1");
        $summary = $sender->deliverDue($pdo, 1, static function (): array { throw new RuntimeException('secret host detail'); });
        self::assertSame(1, $summary['dead_lettered']);
        self::assertSame('transport_failed', $pdo->query('SELECT last_error_code FROM portal_projection_outbox WHERE id=1')->fetchColumn());
        self::assertNotNull($pdo->query('SELECT dead_lettered_at FROM portal_projection_outbox WHERE id=1')->fetchColumn());
        self::assertStringNotContainsString('secret host detail', (string)$pdo->query('SELECT last_error_code FROM portal_projection_outbox WHERE id=1')->fetchColumn());
    }

    public function testSigningKeyRotationIsBlockedUntilPendingRowsAreResolved(): void
    {
        $pdo = $this->deliveryDatabase();
        $this->insertDelivery($pdo, 1, 'delivery-one', '{}');
        $service = new PortalProjectionDeliveryConfigService();
        $pdo->beginTransaction();
        try {
            $service->saveProfile($pdo, 1, [
                'delivery_enabled'=>1,
                'delivery_key_id'=>'key-next',
                'delivery_secret'=>str_repeat('n', 32),
            ], 9);
            self::fail('Expected pending-delivery key rotation denial.');
        } catch (DomainException $error) {
            self::assertStringContainsString('pending projection records', $error->getMessage());
        } finally {
            $pdo->rollBack();
        }
    }

    public function testSameKeyIdCannotReplaceSecretWithOrWithoutPendingRows(): void
    {
        foreach ([false, true] as $withPendingRow) {
            $pdo = $this->deliveryDatabase();
            if ($withPendingRow) {
                $this->insertDelivery($pdo, 1, 'delivery-pending', '{}');
            }
            try {
                (new PortalProjectionDeliveryConfigService())->saveProfile($pdo, 1, [
                    'delivery_enabled'=>1,
                    'delivery_key_id'=>'key-current',
                    'delivery_secret'=>str_repeat('s', 32),
                ], 9);
                self::fail('Expected same-key signing secret replacement denial.');
            } catch (DomainException $error) {
                self::assertSame('Changing the signing secret requires a new signing key ID.', $error->getMessage());
            }
            $profile = $pdo->query('SELECT * FROM portal_integration_profiles WHERE id=1')->fetch(PDO::FETCH_ASSOC);
            self::assertSame(str_repeat('c', 32), (new PortalProjectionDeliveryConfigService())->credentials($profile)['currentSecret']);
        }
    }

    public function testSameKeyIdAcceptsBlankOrIdenticalSecretWithoutMutation(): void
    {
        foreach (['', str_repeat('c', 32)] as $submittedSecret) {
            $pdo = $this->deliveryDatabase();
            $this->insertDelivery($pdo, 1, 'delivery-pending', '{}');
            (new PortalProjectionDeliveryConfigService())->saveProfile($pdo, 1, [
                'delivery_enabled'=>1,
                'delivery_key_id'=>'key-current',
                'delivery_secret'=>$submittedSecret,
            ], 9);
            $profile = $pdo->query('SELECT * FROM portal_integration_profiles WHERE id=1')->fetch(PDO::FETCH_ASSOC);
            $credentials = (new PortalProjectionDeliveryConfigService())->credentials($profile);
            self::assertSame('key-current', $profile['delivery_key_id']);
            self::assertSame(str_repeat('c', 32), $credentials['currentSecret']);
            self::assertSame(str_repeat('p', 32), $credentials['previousSecret']);
        }
    }

    public function testDistinctKeyRotationPreservesPreviousKeyOverlap(): void
    {
        $pdo = $this->deliveryDatabase();
        (new PortalProjectionDeliveryConfigService())->saveProfile($pdo, 1, [
            'delivery_enabled'=>1,
            'delivery_key_id'=>'key-next',
            'delivery_secret'=>str_repeat('n', 32),
            'delivery_overlap_hours'=>24,
        ], 9);
        $profile = $pdo->query('SELECT * FROM portal_integration_profiles WHERE id=1')->fetch(PDO::FETCH_ASSOC);
        $credentials = (new PortalProjectionDeliveryConfigService())->credentials($profile);
        self::assertSame('key-next', $profile['delivery_key_id']);
        self::assertSame('key-current', $profile['delivery_previous_key_id']);
        self::assertSame(str_repeat('n', 32), $credentials['currentSecret']);
        self::assertSame(str_repeat('c', 32), $credentials['previousSecret']);
        $overlapUntil = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', (string)$profile['delivery_previous_valid_until'], new \DateTimeZone('UTC'));
        self::assertInstanceOf(\DateTimeImmutable::class, $overlapUntil);
        self::assertGreaterThan(time() + 23 * 3600, $overlapUntil->getTimestamp());
    }

    public function testPendingRevocationAlsoBlocksDistinctKeyRotation(): void
    {
        $pdo = $this->deliveryDatabase();
        $this->insertDelivery($pdo, 1, 'revocation-pending', '{"event":{"action":"tombstone"}}', true);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('pending projection records');
        (new PortalProjectionDeliveryConfigService())->saveProfile($pdo, 1, [
            'delivery_enabled'=>1,
            'delivery_key_id'=>'key-next',
            'delivery_secret'=>str_repeat('n', 32),
        ], 9);
    }

    public function testOperatorUiExplainsImmutableKeySecretAndSurfacesDomainError(): void
    {
        $root = dirname(__DIR__, 2);
        $view = (string)file_get_contents($root . '/src/views/pages/settings/external-ops.php');
        $handler = (string)file_get_contents($root . '/src/controllers/settings/external_ops_handler.php');
        self::assertStringContainsString('Signing secrets are immutable for a key ID.', $view);
        self::assertStringContainsString('rotation requires both a distinct new key ID and a new secret.', $view);
        self::assertStringContainsString('$error instanceof DomainException?$error->getMessage()', $handler);
    }

    public function testRevocationWaitsForLiveClaimThenSupersedesExpiredNormalRow(): void
    {
        $pdo = $this->deliveryDatabase();
        $this->insertDelivery($pdo, 1, 'normal-claimed', '{}');
        $this->insertDelivery($pdo, 1, 'revoke-after-claim', '{"event":{"action":"tombstone"}}', true);
        $pdo->exec("UPDATE portal_projection_outbox SET claim_token='old-worker',claimed_at=CURRENT_TIMESTAMP WHERE delivery_id='normal-claimed'");
        $sender = new PortalProjectionOutboxSender();
        self::assertSame(0, $sender->deliverDue($pdo, 2, static fn(): array => ['status'=>204])['processed']);

        $pdo->exec("UPDATE portal_projection_outbox SET claimed_at='2000-01-01 00:00:00.000000' WHERE delivery_id='normal-claimed'");
        $seen = [];
        $summary = $sender->deliverDue($pdo, 1, static function (string $url, array $headers, string $body) use (&$seen): array {
            $seen[] = $body;
            return ['status'=>204];
        });
        self::assertSame(1, $summary['delivered']);
        self::assertSame(['{"event":{"action":"tombstone"}}'], $seen);
        self::assertSame(['profile_disabled_superseded', null], $pdo->query('SELECT last_error_code FROM portal_projection_outbox ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
        self::assertNull($pdo->query("SELECT claim_token FROM portal_projection_outbox WHERE delivery_id='normal-claimed'")->fetchColumn());
    }

    public function testScopedReparentTombstonesOnlyOldRootAndNeverTouchesThirdWorkspace(): void
    {
        $pdo = $this->mutationDatabase();
        $service = new PortalProjectionMutationService();
        $before = $service->clientScopes($pdo, 10);
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE clients SET organization_id=2,source_version=? WHERE id=10')->execute(['changed']);
        $after = $service->clientScopes($pdo, 10);
        self::assertTrue($service->afterMutation($pdo, array_merge($before, $after)));
        $pdo->commit();

        self::assertSame(0, $this->edgeActive($pdo, 'org-a', 'client-a'));
        self::assertSame(1, $this->edgeActive($pdo, 'org-b', 'client-a'));
        self::assertSame(1, $this->edgeActive($pdo, 'org-c', 'client-c'));
        self::assertSame('third-version', $pdo->query("SELECT source_version FROM portal_v2_relations WHERE from_public_id='org-c'")->fetchColumn());
        self::assertSame(0, (int)$pdo->query('SELECT COUNT(*) FROM portal_projection_outbox')->fetchColumn());
    }

    public function testStaleCrossRootRelationCannotExpandReconciliationIntoAnotherWorkspace(): void
    {
        $pdo = $this->mutationDatabase();
        $pdo->exec("UPDATE clients SET organization_id=2 WHERE id=10;
            INSERT INTO projects VALUES(40,'project-b','Project B',2,NULL,10,'active','p',NULL);
            INSERT INTO portal_v2_relations(public_id,relation_type,from_type,from_public_id,to_type,to_public_id,source_version,active) VALUES
              ('edge-b-client','contains','organization','org-b','client','client-a','b-client',1),
              ('edge-b-project','contains','client','client-a','project','project-b','b-project',1);");
        $pdo->beginTransaction();
        self::assertTrue((new PortalProjectionMutationService())->afterMutation($pdo, [['root_type'=>'organization','root_public_id'=>'org-a']]));
        $pdo->commit();

        self::assertSame(0, $this->edgeActive($pdo, 'org-a', 'client-a'));
        self::assertSame(1, $this->edgeActive($pdo, 'org-b', 'client-a'));
        self::assertSame(1, $this->edgeActive($pdo, 'client-a', 'project-b'));
    }

    public function testAuthoritativeCreateAndRelationReconciliationShareTheTransaction(): void
    {
        $pdo=$this->mutationDatabase();$service=new PortalProjectionMutationService();
        $pdo->beginTransaction();$pdo->exec("INSERT INTO clients VALUES(50,'client-new','New Client',1,0,NULL,'new-v1')");self::assertTrue($service->afterMutation($pdo,$service->clientScopes($pdo,50)));self::assertSame(1,$this->edgeActive($pdo,'org-a','client-new'));$pdo->rollBack();
        self::assertSame(0,(int)$pdo->query("SELECT COUNT(*) FROM clients WHERE id=50")->fetchColumn());self::assertSame(0,(int)$pdo->query("SELECT COUNT(*) FROM portal_v2_relations WHERE to_public_id='client-new'")->fetchColumn());
        $pdo->beginTransaction();$pdo->exec("INSERT INTO clients VALUES(50,'client-new','New Client',1,0,NULL,'new-v2')");self::assertTrue($service->afterMutation($pdo,$service->clientScopes($pdo,50)));$pdo->commit();self::assertSame(1,$this->edgeActive($pdo,'org-a','client-new'));
    }

    public function testMutationHookIsDefaultOffAndControllerCoverageIsTransactional(): void
    {
        $root = dirname(__DIR__, 2);
        $migration = (string)file_get_contents($root . '/database/migrations/0067_portal_projection_delivery.sql');
        self::assertStringContainsString("'portal_outbound_delivery_enabled','0'", $migration);
        self::assertStringContainsString("'portal_authoritative_hooks_enabled','0'", $migration);
        $sender = (string)file_get_contents($root . '/src/services/PortalProjectionOutboxSender.php');
        foreach (['CURLOPT_FOLLOWLOCATION=>false', "CURLOPT_PROXY=>''", "CURLOPT_NOPROXY=>'*'", 'CURLOPT_SSL_VERIFYPEER=>true', 'CURLOPT_RESOLVE=>[$resolve]'] as $control) {
            self::assertStringContainsString($control, $sender);
        }
        $authoritativeMutationPaths=[];$authoritativeCreatePaths=[];
        foreach ([$root.'/src/controllers',$root.'/src/services'] as $directory) {
            $iterator=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory,\FilesystemIterator::SKIP_DOTS));
            foreach($iterator as$fileInfo){if(!$fileInfo->isFile()||$fileInfo->getExtension()!=='php')continue;$source=(string)file_get_contents($fileInfo->getPathname());$table='(?:clients|organizations|projects|organization_departments|organization_department_contacts)(?![A-Za-z0-9_])';$creates=preg_match('/INSERT\s+INTO\s+`?'.$table.'`?/i',$source)===1;$restores=preg_match('/UPDATE\s+`?'.$table.'`?\s+SET[\s\S]{0,300}?(?:archived\s*=\s*0|deleted_at\s*=\s*NULL)/i',$source)===1;$updates=preg_match('/UPDATE\s+`?'.$table.'`?\s+SET[\s\S]{0,300}?\b(?:name|organization_id|department_id|client_id|status|archived|deleted_at|is_primary|role)\s*=/i',$source)===1;$deletes=preg_match('/DELETE\s+FROM\s+`?'.$table.'`?/i',$source)===1;if(!$creates&&!$restores&&!$updates&&!$deletes)continue;$file=str_replace('\\','/',substr($fileInfo->getPathname(),strlen($root)+1));$authoritativeMutationPaths[]=$file;if($creates||$restores){$authoritativeCreatePaths[]=$file;self::assertStringContainsString('source_version',$source,$file);}self::assertStringContainsString('PortalProjectionMutationService',$source,$file);self::assertTrue(str_contains($source,'beginTransaction')||str_contains($source,'portal_projection_mutate'),$file);self::assertTrue(str_contains($source,'afterMutation')||str_contains($source,'queueProject')||str_contains($source,'portal_projection_mutate'),$file);}
        }
        sort($authoritativeMutationPaths);sort($authoritativeCreatePaths);self::assertGreaterThanOrEqual(8,count($authoritativeCreatePaths));self::assertGreaterThan(count($authoritativeCreatePaths),count($authoritativeMutationPaths));self::assertSame($authoritativeMutationPaths,array_values(array_unique($authoritativeMutationPaths)));
    }

    public function testEveryClientAndProjectOldScopeCaptureUsesTransactionScopedRowLock(): void
    {
        $root = dirname(__DIR__, 2);
        $lockedPaths = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/src/controllers', \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile() || $fileInfo->getExtension() !== 'php') {
                continue;
            }
            $source = (string)file_get_contents($fileInfo->getPathname());
            self::assertDoesNotMatchRegularExpression(
                '/(?:before|Before)[A-Za-z]*\s*=\s*[^;\r\n]*(?<!locked)(?:clientScopes|projectScopes)\s*\(/i',
                $source,
                $fileInfo->getPathname()
            );
            if (preg_match('/locked(?:Client|Project)Scopes\s*\(/', $source) !== 1) {
                continue;
            }
            $path = str_replace('\\', '/', substr($fileInfo->getPathname(), strlen($root) + 1));
            $lockedPaths[] = $path;
            $lockPosition = min(array_filter([
                strpos($source, 'lockedClientScopes') ?: PHP_INT_MAX,
                strpos($source, 'lockedProjectScopes') ?: PHP_INT_MAX,
            ]));
            $transactionPosition = strpos($source, 'beginTransaction');
            $deferredByHelper = preg_match('/portal_projection_mutate\([\s\S]{0,300}?static fn\(\):array=>[^;\r\n]*locked(?:Client|Project)Scopes\s*\(/', $source) === 1;
            self::assertTrue($deferredByHelper || ($transactionPosition !== false && $transactionPosition < $lockPosition), $path);
        }
        sort($lockedPaths);
        self::assertGreaterThanOrEqual(7, count($lockedPaths));
    }

    public function testPortalMutationDefersOldScopeReadUntilTransactionStarts(): void
    {
        require_once dirname(__DIR__, 2) . '/src/utils/portal_projection_hooks.php';
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("CREATE TABLE app_config(organization_id INTEGER,config_key TEXT,config_value TEXT); INSERT INTO app_config VALUES(0,'portal_authoritative_hooks_enabled','0')");
        $order = [];
        $result = portal_projection_mutate(
            $pdo,
            function () use ($pdo, &$order): array { self::assertTrue($pdo->inTransaction()); $order[] = 'locked-before'; return []; },
            function () use ($pdo, &$order): string { self::assertTrue($pdo->inTransaction()); $order[] = 'mutation'; return 'ok'; },
            function () use ($pdo, &$order): array { self::assertTrue($pdo->inTransaction()); $order[] = 'after'; return []; }
        );
        self::assertSame('ok', $result);
        self::assertSame(['locked-before', 'mutation', 'after'], $order);
        self::assertFalse($pdo->inTransaction());
    }

    public function testIpv4EmbeddedIpv6CannotBypassPublicDestinationFilter(): void
    {
        $sender = new PortalProjectionOutboxSender();
        $method = new \ReflectionMethod($sender, 'publicAddresses');
        foreach (['::ffff:127.0.0.1', '::ffff:7f00:1', '::127.0.0.1', '::ffff:0:127.0.0.1', '64:ff9b::127.0.0.1', '64:ff9b:1::7f00:1', '2002:7f00:1::', '2001:0000::1', '2001:4860::5efe:127.0.0.1'] as $literal) {
            self::assertSame([], $method->invoke($sender, $literal), $literal);
        }
        self::assertSame([], $method->invoke($sender, 'receiver.example', static fn(): array => [
            ['type'=>'AAAA', 'ipv6'=>'::ffff:127.0.0.1'],
            ['type'=>'AAAA', 'ipv6'=>'64:ff9b::10.0.0.1'],
            ['type'=>'AAAA', 'ipv6'=>'2606:4700:4700::1111'],
        ]));
        self::assertSame(['2606:4700:4700::1111'], $method->invoke($sender, 'receiver.example', static fn(): array => [
            ['type'=>'AAAA', 'ipv6'=>'2606:4700:4700::1111'],
        ]));
    }

    private function deliveryDatabase(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        require_once dirname(__DIR__, 2) . '/src/utils/crypto.php';
        $credentials = crypto_encrypt(json_encode([
            'currentSecret'=>str_repeat('c', 32),
            'previousSecret'=>str_repeat('p', 32),
            'authHeaders'=>['Authorization'=>'Bearer opaque-test-value'],
        ], JSON_THROW_ON_ERROR));
        $pdo->exec("CREATE TABLE app_config(organization_id INTEGER,config_key TEXT,config_value TEXT,PRIMARY KEY(organization_id,config_key));
            CREATE TABLE portal_integration_profiles(id INTEGER PRIMARY KEY,application_key TEXT,enabled INTEGER,portal_projection_enabled INTEGER,catalog_projection_enabled INTEGER,portal_route TEXT,catalog_route TEXT,delivery_enabled INTEGER,delivery_key_id TEXT,delivery_previous_key_id TEXT,delivery_previous_valid_until TEXT,delivery_credentials_enc TEXT,delivery_timeout_seconds INTEGER,delivery_max_attempts INTEGER,updated_by INTEGER);
            CREATE TABLE portal_projection_outbox(id INTEGER PRIMARY KEY AUTOINCREMENT,integration_profile_id INTEGER,delivery_id TEXT,workspace_public_id TEXT,schema_version INTEGER,source_sequence INTEGER,delivery_kind TEXT,route_type TEXT,is_revocation INTEGER DEFAULT 0,destination_url TEXT,signing_key_id TEXT,payload_json TEXT,attempts INTEGER DEFAULT 0,next_attempt_at TEXT DEFAULT '2000-01-01 00:00:00.000000',claim_token TEXT,claimed_at TEXT,delivered_at TEXT,dead_lettered_at TEXT,last_http_status INTEGER,last_error_code TEXT);
            INSERT INTO app_config VALUES(0,'portal_outbound_delivery_enabled','1'),(0,'portal_authoritative_hooks_enabled','0');");
        $insert = $pdo->prepare("INSERT INTO portal_integration_profiles VALUES(1,'portal_test',0,0,0,'https://receiver.example/internal/portal','https://receiver.example/internal/catalog',1,'key-current','key-previous','2099-01-01 00:00:00.000000',?,15,12,NULL)");
        $insert->execute([$credentials]);
        return $pdo;
    }

    private function insertDelivery(PDO $pdo, int $profileId, string $deliveryId, string $body, bool $revocation=false): void
    {
        $pdo->prepare("INSERT INTO portal_projection_outbox(integration_profile_id,delivery_id,workspace_public_id,schema_version,source_sequence,delivery_kind,route_type,is_revocation,destination_url,signing_key_id,payload_json) VALUES(?,?,'workspace-a',3,1,'event','portal',?,'https://receiver.example/internal/portal','key-current',?)")
            ->execute([$profileId, $deliveryId, $revocation ? 1 : 0, $body]);
    }

    /** @param list<string> $headers @return array<string,string> */
    private function headers(array $headers): array
    {
        $result = [];
        foreach ($headers as $header) {
            [$name, $value] = array_map('trim', explode(':', $header, 2));
            $result[strtolower($name)] = $value;
        }
        return $result;
    }

    private function mutationDatabase(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("CREATE TABLE app_config(organization_id INTEGER,config_key TEXT,config_value TEXT,PRIMARY KEY(organization_id,config_key));
            INSERT INTO app_config VALUES(0,'portal_authoritative_hooks_enabled','1');
            CREATE TABLE organizations(id INTEGER PRIMARY KEY,public_id TEXT,name TEXT,source_version TEXT);
            CREATE TABLE clients(id INTEGER PRIMARY KEY,public_id TEXT,name TEXT,organization_id INTEGER,archived INTEGER DEFAULT 0,deleted_at TEXT,source_version TEXT);
            CREATE TABLE organization_departments(id INTEGER PRIMARY KEY,public_id TEXT,organization_id INTEGER,name TEXT);
            CREATE TABLE organization_department_contacts(department_id INTEGER,client_id INTEGER,is_primary INTEGER DEFAULT 0);
            CREATE TABLE projects(id INTEGER PRIMARY KEY,public_id TEXT,name TEXT,organization_id INTEGER,department_id INTEGER,client_id INTEGER,status TEXT,source_version TEXT,completed_at TEXT);
            CREATE TABLE portal_v2_workspaces(id INTEGER PRIMARY KEY,public_id TEXT,root_type TEXT,root_public_id TEXT,display_name TEXT,source_version TEXT,active INTEGER);
            CREATE TABLE portal_integration_profiles(id INTEGER PRIMARY KEY,enabled INTEGER,portal_projection_enabled INTEGER);
            CREATE TABLE portal_integration_profile_workspaces(profile_id INTEGER,workspace_id INTEGER,active INTEGER,PRIMARY KEY(profile_id,workspace_id));
            CREATE TABLE portal_v2_contacts(id INTEGER PRIMARY KEY AUTOINCREMENT,public_id TEXT DEFAULT (lower(hex(randomblob(16)))),client_id INTEGER UNIQUE,display_name TEXT,source_version TEXT,active INTEGER);
            CREATE TABLE portal_v2_relations(id INTEGER PRIMARY KEY AUTOINCREMENT,public_id TEXT DEFAULT (lower(hex(randomblob(16)))),relation_type TEXT,from_type TEXT,from_public_id TEXT,to_type TEXT,to_public_id TEXT,source_version TEXT,active INTEGER,UNIQUE(relation_type,from_type,from_public_id,to_type,to_public_id));
            CREATE TABLE portal_projection_outbox(id INTEGER PRIMARY KEY AUTOINCREMENT);
            INSERT INTO organizations VALUES(1,'org-a','A','a'),(2,'org-b','B','b'),(3,'org-c','C','c');
            INSERT INTO clients VALUES(10,'client-a','Client A',1,0,NULL,'a'),(30,'client-c','Client C',3,0,NULL,'c');
            INSERT INTO portal_v2_workspaces VALUES(1,'workspace-a','organization','org-a','A','a',1),(2,'workspace-b','organization','org-b','B','b',1),(3,'workspace-c','organization','org-c','C','third-workspace-version',1);
            INSERT INTO portal_v2_relations(public_id,relation_type,from_type,from_public_id,to_type,to_public_id,source_version,active) VALUES('edge-a','contains','organization','org-a','client','client-a','old-a',1),('edge-c','contains','organization','org-c','client','client-c','third-version',1);");
        return $pdo;
    }

    private function edgeActive(PDO $pdo, string $from, string $to): int
    {
        $query = $pdo->prepare('SELECT active FROM portal_v2_relations WHERE from_public_id=? AND to_public_id=?');
        $query->execute([$from, $to]);
        return (int)$query->fetchColumn();
    }
}
