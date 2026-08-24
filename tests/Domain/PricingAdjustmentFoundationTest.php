<?php
declare(strict_types=1);
namespace Tests\Domain;
use App\Domain\Pricing\DocumentPricingSnapshotRepository;
use App\Domain\Pricing\ExactPercentageCalculator;
use App\Domain\Pricing\PricingAdjustmentResolver;
use App\Domain\Pricing\PricingAdjustmentManager;
use DomainException;
use PDO;
use PHPUnit\Framework\TestCase;
final class PricingAdjustmentFoundationTest extends TestCase
{
    public function testExactPercentageUsesMinorUnitsAndHalfUpRounding(): void
    {
        $calculator=new ExactPercentageCalculator();
        self::assertSame(1500,$calculator->discount(10000,'15')['adjustment_minor']);
        self::assertSame(1,$calculator->discount(3,'16.6667')['adjustment_minor']);
        self::assertSame('16.6667',$calculator->discount(3,'16.6667')['percentage_rate']);
        self::assertSame(9_000_000_000_000_000_000,$calculator->discount(9_000_000_000_000_000_000,'100')['adjustment_minor']);
        $this->expectException(DomainException::class);$calculator->discount(100,'100.0001');
    }
    public function testResolutionPrecedenceAndRevisionSnapshot(): void
    {
        $pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$this->schema($pdo);
        $pdo->exec("INSERT INTO app_config VALUES(0,'pricing_adjustments_enabled','1');INSERT INTO organizations VALUES(1),(2);INSERT INTO projects VALUES(10,1);INSERT INTO contracts VALUES(20,1,10,1,0);INSERT INTO invoices VALUES(30,1,10,20,1,'123.45');INSERT INTO pricing_adjustment_definitions(id,organization_id,scope_type,scope_key,name,adjustment_kind,percentage_rate,is_active,effective_from,effective_until) VALUES(1,1,'customer','customer:1','Project','percentage_discount','10.0000',1,NULL,NULL),(2,NULL,'installation','installation','Contract','percentage_discount','20.0000',1,NULL,NULL),(3,2,'customer','customer:2','Other tenant','percentage_discount','50.0000',1,NULL,NULL);INSERT INTO project_pricing_adjustment_assignments(id,organization_id,project_id,adjustment_definition_id) VALUES(100,1,10,1);INSERT INTO contract_pricing_adjustment_assignments(id,organization_id,contract_id,adjustment_definition_id) VALUES(200,1,20,2)");
        $resolver=new PricingAdjustmentResolver($pdo);$resolved=$resolver->resolve(1,'invoice',30,10,20,'2026-08-20');
        self::assertSame('contract',$resolved['source_type']);self::assertSame('Contract',$resolved['definition']['name']);
        $pdo->exec("INSERT INTO document_pricing_adjustment_overrides VALUES(300,1,'invoice',30,'none',NULL,'Negotiated exception')");
        $resolved=$resolver->resolve(1,'invoice',30,10,20,'2026-08-20');self::assertSame('none',$resolved['source_type']);self::assertNull($resolved['definition']);
        $snapshots=new DocumentPricingSnapshotRepository($pdo);$snapshot=$snapshots->createAuthoritative(1,'invoice',30,1,'usd',9,'2026-08-20');self::assertSame(12345,$snapshot['adjusted_minor']);
        $this->expectException(\PDOException::class);$snapshots->createAuthoritative(1,'invoice',30,1,'USD',9,'2026-08-20');
    }
    public function testAuthoritativeSnapshotRejectsCrossOrganizationAndStaleRevision(): void
    {
        $pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$this->schema($pdo);
        $pdo->exec("INSERT INTO app_config VALUES(0,'pricing_adjustments_enabled','1');INSERT INTO projects VALUES(10,1);INSERT INTO contracts VALUES(20,1,10,2,0);INSERT INTO invoices VALUES(30,1,10,20,2,'10.00')");
        $snapshots=new DocumentPricingSnapshotRepository($pdo);
        foreach([[2,2],[1,1]] as [$organization,$revision]){try{$snapshots->createAuthoritative($organization,'invoice',30,$revision,'USD',9);self::fail('Expected authoritative scope rejection.');}catch(DomainException $expected){}self::assertSame(0,(int)$pdo->query('SELECT COUNT(*) FROM document_pricing_adjustment_snapshots')->fetchColumn());}
    }
    public function testManagerRollsBackMutationWhenStrictAuditFails(): void
    {
        $pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$this->schema($pdo);
        $pdo->exec("INSERT INTO app_config VALUES(0,'pricing_adjustments_enabled','1');INSERT INTO organizations VALUES(1)");
        $manager=new PricingAdjustmentManager($pdo,9,fn():bool=>true,fn()=>throw new \RuntimeException('injected audit failure'));
        try{$manager->createDefinition(1,'Rollback','10');self::fail('Expected audit failure.');}catch(\RuntimeException $expected){}
        self::assertSame(0,(int)$pdo->query('SELECT COUNT(*) FROM pricing_adjustment_definitions')->fetchColumn());
    }
    public function testInstallationDefinitionsAssignAcrossCustomersButCustomerDefinitionsDoNot(): void
    {
        $pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$this->schema($pdo);
        $pdo->exec("INSERT INTO app_config VALUES(0,'pricing_adjustments_enabled','1');INSERT INTO organizations VALUES(1),(2);INSERT INTO projects VALUES(10,1)");
        $events=[];$manager=new PricingAdjustmentManager($pdo,9,fn(int $scope):bool=>in_array($scope,[0,1,2],true),function(string $action)use(&$events):void{$events[]=$action;});
        $global=$manager->createInstallationDefinition('Global agreement','12.5');$manager->assignProject(1,10,$global);
        self::assertSame('installation',(string)$pdo->query("SELECT scope_type FROM pricing_adjustment_definitions WHERE id={$global}")->fetchColumn());
        $manager->unassignProject(1,10);self::assertSame(0,(int)$pdo->query('SELECT COUNT(*) FROM project_pricing_adjustment_assignments')->fetchColumn());
        $customer=$manager->createDefinition(2,'Customer only','5');
        try{$manager->assignProject(1,10,$customer);self::fail('Expected customer scope rejection.');}catch(DomainException $expected){}
        self::assertContains('pricing_adjustment.project_unassigned',$events);
    }
    public function testInactiveContractAssignmentFallsBackButUnavailableExplicitOverrideFailsClosed(): void
    {
        $pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$this->schema($pdo);
        $pdo->exec("INSERT INTO app_config VALUES(0,'pricing_adjustments_enabled','1');INSERT INTO organizations VALUES(1);INSERT INTO projects VALUES(10,1);INSERT INTO contracts VALUES(20,1,10,1,'100.00');INSERT INTO invoices VALUES(30,1,10,20,1,'100.00');INSERT INTO pricing_adjustment_definitions(id,organization_id,scope_type,scope_key,name,adjustment_kind,percentage_rate,is_active,effective_from,effective_until) VALUES(1,NULL,'installation','installation','Project','percentage_discount','10.0000',1,NULL,NULL),(2,NULL,'installation','installation','Expired','percentage_discount','20.0000',0,NULL,NULL);INSERT INTO project_pricing_adjustment_assignments(id,organization_id,project_id,adjustment_definition_id) VALUES(100,1,10,1);INSERT INTO contract_pricing_adjustment_assignments(id,organization_id,contract_id,adjustment_definition_id) VALUES(200,1,20,2)");
        $resolver=new PricingAdjustmentResolver($pdo);self::assertSame('project',$resolver->resolve(1,'invoice',30,10,20,'2026-08-20')['source_type']);
        $pdo->exec("INSERT INTO document_pricing_adjustment_overrides VALUES(300,1,'invoice',30,'adjustment',2,'Specific negotiated rate')");
        $this->expectException(DomainException::class);$resolver->resolve(1,'invoice',30,10,20,'2026-08-20');
    }
    public function testManagerFailsClosedWhileFeatureIsDisabled(): void
    {
        $pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$this->schema($pdo);
        $pdo->exec("INSERT INTO app_config VALUES(0,'pricing_adjustments_enabled','0');INSERT INTO organizations VALUES(1)");
        $manager=new PricingAdjustmentManager($pdo,9,fn():bool=>true,fn()=>null);
        $this->expectException(DomainException::class);$manager->createDefinition(1,'Disabled','10');
    }
    public function testManagerRejectsProjectlessTargetsAndAuthorizesBeforeLookup(): void
    {
        $pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$this->schema($pdo);
        $pdo->exec("INSERT INTO app_config VALUES(0,'pricing_adjustments_enabled','1');INSERT INTO organizations VALUES(1);INSERT INTO contracts VALUES(20,1,NULL,1,'10.00');INSERT INTO quotes VALUES(21,1,NULL,1,'10.00');INSERT INTO pricing_adjustment_definitions(id,organization_id,scope_type,scope_key,name,adjustment_kind,percentage_rate,is_active) VALUES(1,NULL,'installation','installation','Global','percentage_discount','10.0000',1)");
        $manager=new PricingAdjustmentManager($pdo,9,fn():bool=>true,fn()=>null);
        foreach([fn()=>$manager->assignContract(1,20,1),fn()=>$manager->setDocumentOverride(1,'quote',21,null,'No inherited pricing')] as $operation){try{$operation();self::fail('Expected project context rejection.');}catch(DomainException $expected){self::assertStringContainsString('project-context',$expected->getMessage());}}
        $denied=new PricingAdjustmentManager($pdo,9,fn():bool=>false,fn()=>null);$messages=[];
        foreach([fn()=>$denied->assignContract(1,20,1),fn()=>$denied->assignContract(1,999,1),fn()=>$denied->updateDefinition(1,'X','5'),fn()=>$denied->updateDefinition(999,'X','5')] as $operation){try{$operation();self::fail('Expected permission rejection.');}catch(DomainException $expected){$messages[]=$expected->getMessage();}}
        self::assertCount(1,array_unique($messages));self::assertSame('Financial management permission is required.',$messages[0]);
    }
    public function testNonCreateMutationRollsBackWhenAuditFails(): void
    {
        $pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$this->schema($pdo);
        $pdo->exec("INSERT INTO app_config VALUES(0,'pricing_adjustments_enabled','1');INSERT INTO organizations VALUES(1);INSERT INTO projects VALUES(10,1);INSERT INTO pricing_adjustment_definitions(id,organization_id,scope_type,scope_key,name,adjustment_kind,percentage_rate,is_active) VALUES(1,NULL,'installation','installation','Global','percentage_discount','10.0000',1)");
        $manager=new PricingAdjustmentManager($pdo,9,fn():bool=>true,fn()=>throw new \RuntimeException('audit unavailable'));
        try{$manager->assignProject(1,10,1);self::fail('Expected audit failure.');}catch(\RuntimeException $expected){}
        self::assertSame(0,(int)$pdo->query('SELECT COUNT(*) FROM project_pricing_adjustment_assignments')->fetchColumn());
    }
    private function schema(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE app_config(organization_id INTEGER,config_key TEXT,config_value TEXT);CREATE TABLE organizations(id INTEGER PRIMARY KEY);CREATE TABLE projects(id INTEGER PRIMARY KEY,organization_id INTEGER);CREATE TABLE quotes(id INTEGER PRIMARY KEY,organization_id INTEGER,project_id INTEGER,revision_number INTEGER,subtotal TEXT);CREATE TABLE contracts(id INTEGER PRIMARY KEY,organization_id INTEGER,project_id INTEGER,revision_number INTEGER,subtotal TEXT);CREATE TABLE invoices(id INTEGER PRIMARY KEY,organization_id INTEGER,project_id INTEGER,contract_id INTEGER,revision_number INTEGER,subtotal TEXT);CREATE TABLE pricing_adjustment_definitions(id INTEGER PRIMARY KEY AUTOINCREMENT,organization_id INTEGER,scope_type TEXT,scope_key TEXT,name TEXT,adjustment_kind TEXT,percentage_rate TEXT,is_active INTEGER DEFAULT 1,effective_from TEXT,effective_until TEXT,created_by INTEGER,updated_by INTEGER);CREATE TABLE project_pricing_adjustment_assignments(id INTEGER PRIMARY KEY,organization_id INTEGER,project_id INTEGER UNIQUE,adjustment_definition_id INTEGER,assigned_by INTEGER);CREATE TABLE contract_pricing_adjustment_assignments(id INTEGER PRIMARY KEY,organization_id INTEGER,contract_id INTEGER UNIQUE,adjustment_definition_id INTEGER,assigned_by INTEGER);CREATE TABLE document_pricing_adjustment_overrides(id INTEGER PRIMARY KEY,organization_id INTEGER,document_type TEXT,document_id INTEGER,override_mode TEXT,adjustment_definition_id INTEGER,reason TEXT,UNIQUE(document_type,document_id));CREATE TABLE document_pricing_adjustment_snapshots(id INTEGER PRIMARY KEY AUTOINCREMENT,organization_id INTEGER,document_type TEXT,document_id INTEGER,document_revision INTEGER,source_type TEXT,source_assignment_id INTEGER,adjustment_definition_id INTEGER,adjustment_name TEXT,adjustment_kind TEXT,percentage_rate TEXT,currency TEXT,basis_minor INTEGER,adjustment_minor INTEGER,adjusted_minor INTEGER,calculation_version TEXT,override_reason TEXT,applied_by INTEGER,derived_from_snapshot_id INTEGER,UNIQUE(document_type,document_id,document_revision))");
    }
}
