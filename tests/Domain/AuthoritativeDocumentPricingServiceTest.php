<?php
declare(strict_types=1);

use App\Domain\Pricing\AuthoritativeDocumentPricingService;
use PHPUnit\Framework\TestCase;

final class AuthoritativeDocumentPricingServiceTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        require_once dirname(__DIR__,2).'/src/utils/document_pricing_adjustments.php';
        require_once dirname(__DIR__,2).'/src/utils/document_pricing_carry_forward.php';
        require_once dirname(__DIR__,2).'/src/services/DocumentRevisionService.php';
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(<<<'SQL'
CREATE TABLE app_config(organization_id INTEGER,config_key TEXT,config_value TEXT);
CREATE TABLE projects(id INTEGER PRIMARY KEY,organization_id INTEGER);
CREATE TABLE quotes(id INTEGER PRIMARY KEY,organization_id INTEGER,project_id INTEGER,revision_number INTEGER,subtotal TEXT,discount_type TEXT,discount_value TEXT,tax_percent TEXT,tax_amount TEXT,total TEXT);
CREATE TABLE contracts(id INTEGER PRIMARY KEY,organization_id INTEGER,project_id INTEGER,revision_number INTEGER,subtotal TEXT,discount_type TEXT,discount_value TEXT,tax_percent TEXT,tax_amount TEXT,total TEXT);
CREATE TABLE invoices(id INTEGER PRIMARY KEY,organization_id INTEGER,project_id INTEGER,contract_id INTEGER,revision_number INTEGER,subtotal TEXT,discount_type TEXT,discount_value TEXT,tax_percent TEXT,tax_amount TEXT,total TEXT,amount_paid TEXT,credit_applied TEXT,balance_due TEXT);
CREATE TABLE pricing_adjustment_definitions(id INTEGER PRIMARY KEY,organization_id INTEGER,scope_type TEXT,scope_key TEXT,name TEXT,adjustment_kind TEXT,percentage_rate TEXT,is_active INTEGER,effective_from TEXT,effective_until TEXT);
CREATE TABLE project_pricing_adjustment_assignments(id INTEGER PRIMARY KEY,organization_id INTEGER,project_id INTEGER UNIQUE,adjustment_definition_id INTEGER);
CREATE TABLE contract_pricing_adjustment_assignments(id INTEGER PRIMARY KEY,organization_id INTEGER,contract_id INTEGER UNIQUE,adjustment_definition_id INTEGER);
CREATE TABLE document_pricing_adjustment_overrides(id INTEGER PRIMARY KEY,organization_id INTEGER,document_type TEXT,document_id INTEGER,override_mode TEXT,adjustment_definition_id INTEGER,reason TEXT,UNIQUE(document_type,document_id));
CREATE TABLE document_pricing_adjustment_snapshots(id INTEGER PRIMARY KEY AUTOINCREMENT,organization_id INTEGER,document_type TEXT,document_id INTEGER,document_revision INTEGER,source_type TEXT,source_assignment_id INTEGER,adjustment_definition_id INTEGER,adjustment_name TEXT,adjustment_kind TEXT,percentage_rate TEXT,currency TEXT,basis_minor INTEGER,adjustment_minor INTEGER,adjusted_minor INTEGER,calculation_version TEXT,override_reason TEXT,applied_by INTEGER,derived_from_snapshot_id INTEGER,UNIQUE(document_type,document_id,document_revision));
CREATE TABLE invoice_items(id INTEGER PRIMARY KEY,invoice_id INTEGER);
CREATE TABLE quote_items(id INTEGER PRIMARY KEY,quote_id INTEGER);
CREATE TABLE contract_items(id INTEGER PRIMARY KEY,contract_id INTEGER);
CREATE TABLE invoice_adjustments(id INTEGER PRIMARY KEY,invoice_id INTEGER,adjustment_type TEXT,amount TEXT,affects_total INTEGER DEFAULT 0,superseded_at TEXT);
CREATE TABLE document_revisions(document_type TEXT,document_id INTEGER,revision_number INTEGER,snapshot TEXT,content_hash TEXT,created_by INTEGER,UNIQUE(document_type,document_id,revision_number));
CREATE TABLE addresses(id INTEGER PRIMARY KEY,archived INTEGER);
CREATE TABLE address_assignments(id INTEGER PRIMARY KEY,address_id INTEGER,entity_type TEXT,entity_id INTEGER,purpose TEXT,is_default INTEGER);
CREATE TABLE service_locations(id INTEGER PRIMARY KEY,address_id INTEGER,archived INTEGER);
SQL);
        $this->pdo->exec("INSERT INTO app_config VALUES(0,'pricing_adjustments_enabled','1'); INSERT INTO projects VALUES(10,1); INSERT INTO pricing_adjustment_definitions VALUES(50,NULL,'installation','installation','Agreement pricing','percentage_discount','20.0000',1,NULL,NULL); INSERT INTO project_pricing_adjustment_assignments VALUES(70,1,10,50)");
    }

    public function testInheritedThenManualThenTaxUsesExactMinorUnits(): void
    {
        $this->pdo->exec("INSERT INTO invoices VALUES(30,1,10,NULL,1,'100.05','percent','10.0000','5.0000','0','0','0.00','0.00','0')");
        $result = (new AuthoritativeDocumentPricingService($this->pdo))->apply(1,'invoice',30,1,'USD',9,'2026-08-20');

        self::assertSame(10005, $result['basis_minor']);
        self::assertSame(2001, $result['adjustment_minor']);
        self::assertSame(800, $result['manual_adjustment_minor']);
        self::assertSame(360, $result['tax_minor']);
        self::assertSame(7564, $result['total_minor']);
        $row = $this->pdo->query('SELECT subtotal,discount_type,discount_value,tax_amount,total,balance_due FROM invoices WHERE id=30')->fetch(PDO::FETCH_ASSOC);
        self::assertSame('100.05', $row['subtotal']);
        self::assertSame('percent', $row['discount_type']);
        self::assertSame('10.0000', $row['discount_value']);
        self::assertSame('3.60', $row['tax_amount']);
        self::assertSame('75.64', $row['total']);
        self::assertSame('75.64', $row['balance_due']);
    }

    public function testDocumentOptOutPreservesLegacyManualDiscount(): void
    {
        $this->pdo->exec("INSERT INTO quotes VALUES(31,1,10,1,'25.00','fixed','3.50','0','0','21.50'); INSERT INTO document_pricing_adjustment_overrides VALUES(80,1,'quote',31,'none',NULL,'Negotiated document price')");
        $result = (new AuthoritativeDocumentPricingService($this->pdo))->apply(1,'quote',31,1,'USD',9,'2026-08-20');
        self::assertSame('none', $result['source_type']);
        self::assertSame(0, $result['adjustment_minor']);
        self::assertSame(350, $result['manual_adjustment_minor']);
        self::assertSame('21.50', (string)$this->pdo->query('SELECT total FROM quotes WHERE id=31')->fetchColumn());
    }

    public function testOnlyExplicitTotalAdjustmentsApplyAfterTax(): void
    {
        $this->pdo->exec("INSERT INTO invoices VALUES(33,1,10,NULL,1,'100.00','none','0','0','0','0','0.00','0.00','0');");
        $this->pdo->exec("INSERT INTO invoice_adjustments VALUES(1,33,'charge','5.00',1,NULL),(2,33,'credit','2.50',1,NULL),(3,33,'charge','99.00',0,NULL)");
        $result=(new AuthoritativeDocumentPricingService($this->pdo))->apply(1,'invoice',33,1,'USD',9,'2026-08-20');
        self::assertSame(250,$result['invoice_adjustment_minor']);
        self::assertSame(8250,$result['total_minor']);
        self::assertSame('82.50',(string)$this->pdo->query('SELECT total FROM invoices WHERE id=33')->fetchColumn());
    }

    public function testCrossOrganizationDocumentAndStaleRevisionFailWithoutWrites(): void
    {
        $this->pdo->exec("INSERT INTO contracts VALUES(32,2,10,2,'10.00','none','0','0','0','10.00')");
        foreach ([[1,32,2],[2,32,1]] as [$organization,$document,$revision]) {
            try {
                (new AuthoritativeDocumentPricingService($this->pdo))->apply($organization,'contract',$document,$revision,'USD',9,'2026-08-20');
                self::fail('Expected authoritative scope or revision rejection.');
            } catch (DomainException) {
            }
        }
        self::assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM document_pricing_adjustment_snapshots')->fetchColumn());
        self::assertSame('10.00', (string)$this->pdo->query('SELECT total FROM contracts WHERE id=32')->fetchColumn());
    }

    public function testLegacyNegativeValuesClampAndInvalidCalculationRollsBackSnapshot(): void
    {
        $this->pdo->exec("INSERT INTO quotes VALUES(40,1,10,1,'10.00','percent','-1','-1','0','10.00'); INSERT INTO quotes VALUES(41,1,10,1,'10.00','percent','0','invalid','0','10.00')");
        $result=(new AuthoritativeDocumentPricingService($this->pdo))->apply(1,'quote',40,1,'USD',9,'2026-08-20');
        self::assertSame(0,$result['manual_adjustment_minor']);self::assertSame(0,$result['tax_minor']);self::assertSame('8.00',(string)$this->pdo->query('SELECT total FROM quotes WHERE id=40')->fetchColumn());
        try{(new AuthoritativeDocumentPricingService($this->pdo))->apply(1,'quote',41,1,'USD',9,'2026-08-20');self::fail('Expected invalid legacy tax to fail.');}catch(DomainException $expected){}
        self::assertSame(0,(int)$this->pdo->query("SELECT COUNT(*) FROM document_pricing_adjustment_snapshots WHERE document_id=41")->fetchColumn());
        self::assertSame('10.00',(string)$this->pdo->query('SELECT total FROM quotes WHERE id=41')->fetchColumn());
    }

    public function testClientSnapshotLookupRequiresOrganizationAndCurrentRevision(): void
    {
        $this->pdo->exec("INSERT INTO quotes VALUES(50,1,10,1,'10.00','none','0','0','0','10.00')");
        $snapshot=(new AuthoritativeDocumentPricingService($this->pdo))->apply(1,'quote',50,1,'USD',9,'2026-08-20');
        self::assertNotNull(pricing_document_snapshot($this->pdo,1,'quote',50,1));
        self::assertNull(pricing_document_snapshot($this->pdo,2,'quote',50,1));
        self::assertNull(pricing_document_snapshot($this->pdo,1,'quote',50,2));
        self::assertSame('Pricing adjustment',pricing_adjustment_client_label($snapshot+['adjustment_name'=>'Internal contract name']));
    }

    public function testPaidOrCreditedInvoiceRepricingFailsAtomically(): void
    {
        foreach([[60,'1.00','0.00'],[61,'0.00','1.00']] as [$id,$paid,$credit]){
            $this->pdo->prepare("INSERT INTO invoices VALUES(?,1,10,NULL,1,'10.00','none','0','0','0','10.00',?,?,'0')")->execute([$id,$paid,$credit]);
            try{(new AuthoritativeDocumentPricingService($this->pdo))->apply(1,'invoice',$id,1,'USD',9,'2026-08-20');self::fail('Expected settled invoice repricing rejection.');}catch(DomainException $expected){}
            self::assertSame(0,(int)$this->pdo->query("SELECT COUNT(*) FROM document_pricing_adjustment_snapshots WHERE document_type='invoice' AND document_id={$id}")->fetchColumn());
            self::assertSame('10.00',(string)$this->pdo->query("SELECT total FROM invoices WHERE id={$id}")->fetchColumn());
        }
    }

    public function testQuoteAndContractPersistExactInheritedTaxBreakdown(): void
    {
        $this->pdo->exec("INSERT INTO quotes VALUES(70,1,10,1,'100.00','fixed','10.00','5.0000','0','0');INSERT INTO contracts VALUES(71,1,10,1,'100.00','fixed','10.00','5.0000','0','0')");
        foreach([['quote','quotes',70],['contract','contracts',71]] as [$type,$table,$id]){
            (new AuthoritativeDocumentPricingService($this->pdo))->apply(1,$type,$id,1,'USD',9,'2026-08-20');
            $row=$this->pdo->query("SELECT tax_amount,total FROM {$table} WHERE id={$id}")->fetch(PDO::FETCH_ASSOC);
            self::assertSame('3.50',$row['tax_amount']);self::assertSame('73.50',$row['total']);
        }
    }

    public function testNullOrganizationDocumentUsesLegacyRevisionPathWithoutPricingSnapshot(): void
    {
        $this->pdo->exec("INSERT INTO invoices VALUES(80,NULL,NULL,NULL,1,'10.00','none','0','0','0','10.00','0','0','10.00')");
        $this->pdo->beginTransaction();
        $revision=pricing_finalize_document_revision($this->pdo,null,'invoice',80,null,false,'USD');
        $this->pdo->commit();
        self::assertSame(1,$revision);
        self::assertSame(1,(int)$this->pdo->query("SELECT COUNT(*) FROM document_revisions WHERE document_type='invoice' AND document_id=80")->fetchColumn());
        self::assertSame(0,(int)$this->pdo->query("SELECT COUNT(*) FROM document_pricing_adjustment_snapshots WHERE document_type='invoice' AND document_id=80")->fetchColumn());
        self::assertSame('10.00',(string)$this->pdo->query('SELECT total FROM invoices WHERE id=80')->fetchColumn());
    }

    public function testEligibleCoordinatorKeepsRevisionSnapshotAndAdjustedDocumentAtomic(): void
    {
        $this->pdo->exec('ALTER TABLE quotes ADD COLUMN revision_updated_at TEXT;ALTER TABLE quotes ADD COLUMN updated_at TEXT');
        $this->pdo->exec("INSERT INTO quotes(id,organization_id,project_id,revision_number,subtotal,discount_type,discount_value,tax_percent,tax_amount,total) VALUES(90,1,10,1,'100.00','fixed','10.00','5.0000','0','0')");
        $this->pdo->beginTransaction();$revision=pricing_finalize_document_revision($this->pdo,1,'quote',90,9,true,'USD');$this->pdo->commit();
        self::assertSame(2,$revision);
        $pricing=pricing_document_snapshot($this->pdo,1,'quote',90,2);self::assertNotNull($pricing);self::assertSame(2000,(int)$pricing['adjustment_minor']);
        $stored=$this->pdo->query("SELECT snapshot FROM document_revisions WHERE document_type='quote' AND document_id=90 AND revision_number=2")->fetchColumn();
        $document=json_decode((string)$stored,true,512,JSON_THROW_ON_ERROR)['document'];self::assertSame('3.50',(string)$document['tax_amount']);self::assertSame('73.50',(string)$document['total']);
    }

    public function testOuterTransactionRollbackRestoresRevisionWhenEligiblePricingFails(): void
    {
        $this->pdo->exec('ALTER TABLE invoices ADD COLUMN revision_updated_at TEXT;ALTER TABLE invoices ADD COLUMN updated_at TEXT');
        $this->pdo->exec("INSERT INTO invoices(id,organization_id,project_id,contract_id,revision_number,subtotal,discount_type,discount_value,tax_percent,tax_amount,total,amount_paid,credit_applied,balance_due) VALUES(91,1,10,NULL,1,'10.00','none','0','0','0','10.00','1.00','0','9.00')");
        $this->pdo->beginTransaction();
        try{pricing_finalize_document_revision($this->pdo,1,'invoice',91,9,true,'USD');self::fail('Expected paid invoice rejection.');}catch(DomainException $expected){$this->pdo->rollBack();}
        $row=$this->pdo->query('SELECT revision_number,total,balance_due FROM invoices WHERE id=91')->fetch(PDO::FETCH_ASSOC);self::assertSame(1,(int)$row['revision_number']);self::assertSame('10.00',$row['total']);self::assertSame('9.00',$row['balance_due']);
        self::assertSame(0,(int)$this->pdo->query("SELECT COUNT(*) FROM document_pricing_adjustment_snapshots WHERE document_type='invoice' AND document_id=91")->fetchColumn());
    }

    public function testContractPercentageDepositUsesFinalAdjustedTotalBeforeEachRevisionSnapshot(): void
    {
        $this->pdo->exec('ALTER TABLE contracts ADD COLUMN deposit_type TEXT;ALTER TABLE contracts ADD COLUMN deposit_amount TEXT;ALTER TABLE contracts ADD COLUMN revision_updated_at TEXT;ALTER TABLE contracts ADD COLUMN updated_at TEXT');
        $this->pdo->exec("INSERT INTO contracts(id,organization_id,project_id,revision_number,subtotal,discount_type,discount_value,tax_percent,tax_amount,total,deposit_type,deposit_amount) VALUES(100,1,10,1,'100.00','none','0','0','0','100.00','percent','50.00')");

        $this->pdo->beginTransaction();
        pricing_finalize_document_revision($this->pdo,1,'contract',100,9,false,'USD',
            fn(array $pricing)=>pricing_recompute_contract_percentage_deposit($this->pdo,1,100,'50'));
        $this->pdo->commit();
        $created=$this->pdo->query('SELECT revision_number,total,deposit_amount FROM contracts WHERE id=100')->fetch(PDO::FETCH_ASSOC);
        self::assertSame(1,(int)$created['revision_number']);self::assertSame('80.00',$created['total']);self::assertSame('40.00',$created['deposit_amount']);
        $createdSnapshot=json_decode((string)$this->pdo->query("SELECT snapshot FROM document_revisions WHERE document_type='contract' AND document_id=100 AND revision_number=1")->fetchColumn(),true,512,JSON_THROW_ON_ERROR)['document'];
        self::assertSame('40.00',(string)$createdSnapshot['deposit_amount']);

        $this->pdo->exec("UPDATE contracts SET subtotal='200.00',total='200.00',deposit_amount='50.00' WHERE id=100");
        $this->pdo->beginTransaction();
        pricing_finalize_document_revision($this->pdo,1,'contract',100,9,true,'USD',
            fn(array $pricing)=>pricing_recompute_contract_percentage_deposit($this->pdo,1,100,'25'));
        $this->pdo->commit();
        $updated=$this->pdo->query('SELECT revision_number,total,deposit_amount FROM contracts WHERE id=100')->fetch(PDO::FETCH_ASSOC);
        self::assertSame(2,(int)$updated['revision_number']);self::assertSame('160.00',$updated['total']);self::assertSame('40.00',$updated['deposit_amount']);
        $updatedSnapshot=json_decode((string)$this->pdo->query("SELECT snapshot FROM document_revisions WHERE document_type='contract' AND document_id=100 AND revision_number=2")->fetchColumn(),true,512,JSON_THROW_ON_ERROR)['document'];
        self::assertSame('40.00',(string)$updatedSnapshot['deposit_amount']);
    }

    public function testAcceptedQuoteSnapshotFreezesDerivedContractPricingWithoutOverride(): void
    {
        $this->pdo->exec("INSERT INTO quotes VALUES(110,1,10,1,'100.00','none','0','0','0','100.00')");
        $this->pdo->beginTransaction();pricing_finalize_document_revision($this->pdo,1,'quote',110,9,false,'USD');$this->pdo->commit();
        $sourceId=(int)$this->pdo->query("SELECT id FROM document_pricing_adjustment_snapshots WHERE document_type='quote' AND document_id=110")->fetchColumn();
        $this->pdo->exec("UPDATE pricing_adjustment_definitions SET percentage_rate='50.0000' WHERE id=50;INSERT INTO contracts VALUES(111,1,10,1,'100.00','none','0','0','0','100.00')");
        $this->pdo->beginTransaction();pricing_finalize_derived_document_revision($this->pdo,1,'contract',111,9,'EUR','quote',110,1);$this->pdo->commit();
        self::assertSame('80.00',(string)$this->pdo->query('SELECT total FROM contracts WHERE id=111')->fetchColumn());
        $derived=$this->pdo->query("SELECT percentage_rate,adjustment_minor,currency,derived_from_snapshot_id FROM document_pricing_adjustment_snapshots WHERE document_type='contract' AND document_id=111")->fetch(PDO::FETCH_ASSOC);
        self::assertSame('20.0000',$derived['percentage_rate']);self::assertSame(2000,(int)$derived['adjustment_minor']);self::assertSame('USD',$derived['currency']);self::assertSame($sourceId,(int)$derived['derived_from_snapshot_id']);
        self::assertSame(0,(int)$this->pdo->query("SELECT COUNT(*) FROM document_pricing_adjustment_overrides WHERE document_type='contract' AND document_id=111")->fetchColumn());
    }

    public function testDerivedPricingFailsClosedForStaleCrossOrganizationAndMissingSources(): void
    {
        $this->pdo->exec("ALTER TABLE quotes ADD COLUMN status TEXT DEFAULT 'approved'");
        $this->pdo->exec("INSERT INTO quotes(id,organization_id,project_id,revision_number,subtotal,discount_type,discount_value,tax_percent,tax_amount,total) VALUES(120,1,10,2,'100.00','none','0','0','0','100.00');INSERT INTO contracts VALUES(121,1,10,1,'100.00','none','0','0','0','100.00')");
        foreach([[1,120,1],[2,120,2],[1,999,1]] as [$organization,$sourceId,$missingRevision]){
            $this->pdo->beginTransaction();
            try{pricing_finalize_derived_document_revision($this->pdo,$organization,'contract',121,9,'USD','quote',$sourceId,$missingRevision);self::fail('Expected missing accepted snapshot rejection.');}
            catch(DomainException $expected){$this->pdo->rollBack();}
            self::assertSame('100.00',(string)$this->pdo->query('SELECT total FROM contracts WHERE id=121')->fetchColumn());
            self::assertSame(0,(int)$this->pdo->query("SELECT COUNT(*) FROM document_pricing_adjustment_snapshots WHERE document_type='contract' AND document_id=121")->fetchColumn());
            self::assertSame(0,(int)$this->pdo->query("SELECT COUNT(*) FROM document_revisions WHERE document_type='contract' AND document_id=121")->fetchColumn());
        }
    }

    public function testAcceptedLegacyQuoteAndContractCreateOrdinaryChildrenWithoutCurrentAssignment(): void
    {
        $this->pdo->exec("ALTER TABLE quotes ADD COLUMN status TEXT DEFAULT 'draft';ALTER TABLE contracts ADD COLUMN status TEXT DEFAULT 'draft';ALTER TABLE contracts ADD COLUMN signed_revision_number INTEGER;ALTER TABLE contracts ADD COLUMN signed_at TEXT;ALTER TABLE contracts ADD COLUMN signed_pdf_path TEXT");
        $this->pdo->exec("UPDATE pricing_adjustment_definitions SET percentage_rate='50.0000' WHERE id=50;INSERT INTO quotes VALUES(120,1,10,2,'100.00','none','0','0','0','100.00','approved');INSERT INTO contracts VALUES(121,1,10,1,'100.00','none','0','0','0','100.00','draft',NULL,NULL,NULL);INSERT INTO invoices VALUES(122,1,10,NULL,1,'100.00','none','0','0','0','100.00','0','0','100.00')");
        $this->pdo->beginTransaction();pricing_finalize_derived_document_revision($this->pdo,1,'contract',121,9,'EUR','quote',120,2);pricing_finalize_derived_document_revision($this->pdo,1,'invoice',122,9,'EUR','quote',120,2);$this->pdo->commit();
        self::assertSame('100.00',(string)$this->pdo->query('SELECT total FROM contracts WHERE id=121')->fetchColumn());
        self::assertSame('100.00',(string)$this->pdo->query('SELECT total FROM invoices WHERE id=122')->fetchColumn());
        self::assertSame(0,(int)$this->pdo->query("SELECT COUNT(*) FROM document_pricing_adjustment_snapshots WHERE document_id IN (121,122)")->fetchColumn());
        self::assertSame(2,(int)$this->pdo->query("SELECT COUNT(*) FROM document_revisions WHERE document_id IN (121,122)")->fetchColumn());

        $this->pdo->exec("INSERT INTO contracts VALUES(123,1,10,3,'75.00','none','0','0','0','75.00','active',3,'2026-08-01','legacy.pdf');INSERT INTO invoices VALUES(124,1,10,123,1,'25.00','none','0','0','0','25.00','0','0','25.00');INSERT INTO invoices VALUES(125,1,10,123,1,'50.00','none','0','0','0','50.00','0','0','50.00')");
        $this->pdo->beginTransaction();pricing_finalize_derived_document_revision($this->pdo,1,'invoice',124,9,'CAD','contract',123,3);pricing_finalize_derived_document_revision($this->pdo,1,'invoice',125,9,'CAD','contract',123,3);$this->pdo->commit();
        self::assertSame(['25.00','50.00'],$this->pdo->query('SELECT total FROM invoices WHERE id IN (124,125) ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
        self::assertSame(0,(int)$this->pdo->query("SELECT COUNT(*) FROM document_pricing_adjustment_snapshots WHERE document_id IN (124,125)")->fetchColumn());
        self::assertSame(2,(int)$this->pdo->query("SELECT COUNT(*) FROM document_revisions WHERE document_type='invoice' AND document_id IN (124,125)")->fetchColumn());
    }

    public function testDerivedSnapshotPreservesFrozenOptOutReasonAndLineage(): void
    {
        $this->pdo->exec("INSERT INTO quotes VALUES(130,1,10,1,'100.00','none','0','0','0','100.00');INSERT INTO document_pricing_adjustment_overrides VALUES(1300,1,'quote',130,'none',NULL,'Accepted without inherited pricing')");
        $this->pdo->beginTransaction();pricing_finalize_document_revision($this->pdo,1,'quote',130,9,false,'USD');$this->pdo->commit();
        $sourceId=(int)$this->pdo->query("SELECT id FROM document_pricing_adjustment_snapshots WHERE document_type='quote' AND document_id=130")->fetchColumn();
        $this->pdo->exec("INSERT INTO contracts VALUES(131,1,10,1,'100.00','none','0','0','0','100.00')");
        $this->pdo->beginTransaction();pricing_finalize_derived_document_revision($this->pdo,1,'contract',131,9,'USD','quote',130,1);$this->pdo->commit();
        $derived=$this->pdo->query("SELECT source_type,override_reason,adjustment_minor,derived_from_snapshot_id FROM document_pricing_adjustment_snapshots WHERE document_type='contract' AND document_id=131")->fetch(PDO::FETCH_ASSOC);
        self::assertSame('none',$derived['source_type']);self::assertSame('Accepted without inherited pricing',$derived['override_reason']);self::assertSame(0,(int)$derived['adjustment_minor']);self::assertSame($sourceId,(int)$derived['derived_from_snapshot_id']);
    }

    public function testRecurringInvoiceUsesFrozenContractSnapshotAfterAssignmentRateChanges(): void
    {
        $this->pdo->exec("INSERT INTO contracts VALUES(140,1,10,1,'100.00','none','0','0','0','100.00')");
        $this->pdo->beginTransaction();pricing_finalize_document_revision($this->pdo,1,'contract',140,9,false,'USD');$this->pdo->commit();
        $contractSnapshot=(int)$this->pdo->query("SELECT id FROM document_pricing_adjustment_snapshots WHERE document_type='contract' AND document_id=140")->fetchColumn();
        $this->pdo->exec("UPDATE pricing_adjustment_definitions SET percentage_rate='50.0000' WHERE id=50;INSERT INTO invoices VALUES(141,1,10,140,1,'50.00','none','0','0','0','50.00','0','0','50.00')");
        $this->pdo->beginTransaction();pricing_finalize_derived_document_revision($this->pdo,1,'invoice',141,9,'CAD','contract',140,1);$this->pdo->commit();
        self::assertSame('40.00',(string)$this->pdo->query('SELECT total FROM invoices WHERE id=141')->fetchColumn());
        $invoiceSnapshot=$this->pdo->query("SELECT percentage_rate,adjustment_minor,currency,derived_from_snapshot_id FROM document_pricing_adjustment_snapshots WHERE document_type='invoice' AND document_id=141")->fetch(PDO::FETCH_ASSOC);
        self::assertSame('20.0000',$invoiceSnapshot['percentage_rate']);self::assertSame(1000,(int)$invoiceSnapshot['adjustment_minor']);self::assertSame('USD',$invoiceSnapshot['currency']);self::assertSame($contractSnapshot,(int)$invoiceSnapshot['derived_from_snapshot_id']);
    }

    public function testFixedTotalInstallmentsAllocateEveryCentWithoutRepricing(): void
    {
        require_once dirname(__DIR__,2).'/src/utils/recurring_billing.php';
        $parts=[];$invoicedMinor=0;for($generated=0;$generated<3;$generated++){ $part=pa_recurring_fixed_installment_minor('100.00','0.00',pricing_minor_to_money($invoicedMinor),3,$generated);$parts[]=$part;$invoicedMinor+=$part; }
        self::assertSame([3334,3333,3333],$parts);self::assertSame(10000,array_sum($parts));
        $first=pa_recurring_fixed_installment_minor('100.00','0.00','0.00',3,0);
        $second=pa_recurring_fixed_installment_minor('100.00','10.00',pricing_minor_to_money($first),3,1);
        $third=pa_recurring_fixed_installment_minor('100.00','10.00',pricing_minor_to_money($first+$second),3,2);
        self::assertSame([3334,2833,2833],[$first,$second,$third]);self::assertSame(9000,$first+$second+$third);
        self::assertSame(0,pa_recurring_fixed_installment_minor('100.00','0.00','100.00',3,3));
        self::assertSame(10000,$first+$second+$third+1000);
        self::assertSame(2,pricing_contract_source_revision(['revision_number'=>3,'signed_revision_number'=>2]));
        self::assertSame(3,pricing_contract_source_revision(['revision_number'=>3,'signed_revision_number'=>null]));
    }

    public function testPreFeatureDraftAdoptsCurrentPricingInOriginalCurrencyButSettledCarryForwardDoesNot(): void
    {
        $this->pdo->exec('ALTER TABLE invoices ADD COLUMN status TEXT;ALTER TABLE invoices ADD COLUMN finalized_at TEXT;ALTER TABLE invoices ADD COLUMN revision_updated_at TEXT;ALTER TABLE invoices ADD COLUMN updated_at TEXT');
        $this->pdo->exec("INSERT INTO invoices VALUES(150,1,10,NULL,1,'200.00','none','0','0','0','200.00','0','0','200.00','draft',NULL,NULL,NULL);INSERT INTO invoices VALUES(151,1,10,NULL,1,'100.00','none','0','0','0','100.00','100.00','0','0','paid','2026-08-01',NULL,NULL)");
        $this->pdo->beginTransaction();pricing_finalize_frozen_document_revision($this->pdo,1,'invoice',150,9,'EUR');pricing_carry_forward_document_revision($this->pdo,1,'invoice',151,9);$this->pdo->commit();
        $draft=$this->pdo->query('SELECT revision_number,total FROM invoices WHERE id=150')->fetch(PDO::FETCH_ASSOC);self::assertSame(2,(int)$draft['revision_number']);self::assertSame('160.00',$draft['total']);
        $snapshot=$this->pdo->query("SELECT currency,percentage_rate FROM document_pricing_adjustment_snapshots WHERE document_type='invoice' AND document_id=150")->fetch(PDO::FETCH_ASSOC);self::assertSame('EUR',$snapshot['currency']);self::assertSame('20.0000',$snapshot['percentage_rate']);
        $settled=$this->pdo->query('SELECT revision_number,total,amount_paid FROM invoices WHERE id=151')->fetch(PDO::FETCH_ASSOC);self::assertSame(2,(int)$settled['revision_number']);self::assertSame('100.00',$settled['total']);self::assertSame('100.00',$settled['amount_paid']);
        self::assertSame(0,(int)$this->pdo->query("SELECT COUNT(*) FROM document_pricing_adjustment_snapshots WHERE document_type='invoice' AND document_id=151")->fetchColumn());
    }

    public function testFixedTotalInstallmentDetectionUsesAuthoritativeContractPricingType(): void
    {
        $this->pdo->exec('ALTER TABLE contracts ADD COLUMN pricing_type TEXT');
        $this->pdo->exec("INSERT INTO contracts VALUES(160,1,10,1,'100.00','none','0','0','0','100.00','fixed_total');INSERT INTO invoices VALUES(161,1,10,160,1,'50.00','none','0','0','0','50.00','0','0','50.00')");
        self::assertTrue(pricing_invoice_is_fixed_total_installment($this->pdo,161));
        self::assertFalse(pricing_invoice_is_fixed_total_installment($this->pdo,999));
    }
}
