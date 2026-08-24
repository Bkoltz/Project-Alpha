<?php
declare(strict_types=1);
namespace App\Domain\Pricing;
use DomainException;
use PDO;
final class PricingAdjustmentManager
{
    private readonly \Closure $authorizer;
    private readonly \Closure $auditor;
    public function __construct(private readonly PDO $pdo,private readonly int $actorUserId,?callable $authorizer=null,?callable $auditor=null)
    {
        $this->authorizer=$authorizer?\Closure::fromCallable($authorizer):function(int $organizationId): bool {
            return function_exists('user_can')&&user_can($this->pdo,$this->actorUserId,'financial.manage',0);
        };
        $this->auditor=$auditor?\Closure::fromCallable($auditor):function(string $action,string $entityType,int $entityId,int $organizationId,array $details): void {
            $statement=$this->pdo->prepare('INSERT INTO system_audit (user_id,organization_id,action,entity_type,entity_id,details,ip_address,user_agent) VALUES (?,?,?,?,?,?,?,?)');
            $statement->execute([$this->actorUserId,$organizationId>0?$organizationId:null,mb_substr($action,0,100),mb_substr($entityType,0,100),$entityId,json_encode(['organization_id'=>$organizationId]+$details,JSON_UNESCAPED_SLASHES),null,null]);
        };
    }
    public function createDefinition(int $organizationId,string $name,string $percentageRate,?string $effectiveFrom=null,?string $effectiveUntil=null): int
    {
        $this->assertPermission();
        $this->assertCustomerExists($organizationId);
        return $this->createScopedDefinition($organizationId,'customer','customer:'.$organizationId,$name,$percentageRate,$effectiveFrom,$effectiveUntil);
    }
    public function createInstallationDefinition(string $name,string $percentageRate,?string $effectiveFrom=null,?string $effectiveUntil=null): int
    {
        return $this->createScopedDefinition(null,'installation','installation',$name,$percentageRate,$effectiveFrom,$effectiveUntil);
    }
    private function createScopedDefinition(?int $organizationId,string $scopeType,string $scopeKey,string $name,string $percentageRate,?string $effectiveFrom,?string $effectiveUntil): int
    {
        $this->assertPermission();
        (new ExactPercentageCalculator())->discount(1,$percentageRate);
        $name=trim($name);if($name===''||mb_strlen($name)>150) throw new DomainException('Pricing adjustment name is required and must be 150 characters or fewer.');
        if($effectiveFrom&&$effectiveUntil&&$effectiveUntil<$effectiveFrom) throw new DomainException('Effective end date cannot precede the start date.');
        return $this->atomic(function()use($organizationId,$scopeType,$scopeKey,$name,$percentageRate,$effectiveFrom,$effectiveUntil): int {
            $statement=$this->pdo->prepare("INSERT INTO pricing_adjustment_definitions (organization_id,scope_type,scope_key,name,adjustment_kind,percentage_rate,effective_from,effective_until,created_by,updated_by) VALUES (?,?,?,?,'percentage_discount',?,?,?,?,?)");
            $statement->execute([$organizationId,$scopeType,$scopeKey,$name,$percentageRate,$effectiveFrom,$effectiveUntil,$this->actorUserId,$this->actorUserId]);
            $id=(int)$this->pdo->lastInsertId();$this->audit('pricing_adjustment.definition_created','pricing_adjustment',$id,$organizationId??0,['scope_type'=>$scopeType,'percentage_rate'=>$percentageRate]);return $id;
        });
    }
    public function assignProject(int $organizationId,int $projectId,int $definitionId): void
    { $this->assign($organizationId,'projects','project_pricing_adjustment_assignments','project_id',$projectId,$definitionId,'pricing_adjustment.project_assigned','project'); }
    public function assignContract(int $organizationId,int $contractId,int $definitionId): void
    { $this->assertPermission();$this->assertProjectContext('contracts',$contractId,$organizationId);$this->assign($organizationId,'contracts','contract_pricing_adjustment_assignments','contract_id',$contractId,$definitionId,'pricing_adjustment.contract_assigned','contract'); }
    public function unassignProject(int $organizationId,int $projectId): void
    { $this->unassign($organizationId,'projects','project_pricing_adjustment_assignments','project_id',$projectId,'pricing_adjustment.project_unassigned','project'); }
    public function unassignContract(int $organizationId,int $contractId): void
    { $this->unassign($organizationId,'contracts','contract_pricing_adjustment_assignments','contract_id',$contractId,'pricing_adjustment.contract_unassigned','contract'); }
    public function updateDefinition(int $definitionId,string $name,string $percentageRate,?string $effectiveFrom=null,?string $effectiveUntil=null): void
    {
        $this->assertPermission();$definition=$this->definitionScope($definitionId);$scopeOrganization=(int)($definition['organization_id']??0);
        (new ExactPercentageCalculator())->discount(1,$percentageRate);$name=trim($name);
        if($name===''||mb_strlen($name)>150)throw new DomainException('Pricing adjustment name is required and must be 150 characters or fewer.');
        if($effectiveFrom&&$effectiveUntil&&$effectiveUntil<$effectiveFrom)throw new DomainException('Effective end date cannot precede the start date.');
        $this->atomic(function()use($definitionId,$scopeOrganization,$name,$percentageRate,$effectiveFrom,$effectiveUntil): void {
            $this->pdo->prepare('UPDATE pricing_adjustment_definitions SET name=?,percentage_rate=?,effective_from=?,effective_until=?,updated_by=? WHERE id=?')->execute([$name,$percentageRate,$effectiveFrom,$effectiveUntil,$this->actorUserId,$definitionId]);
            $this->audit('pricing_adjustment.definition_updated','pricing_adjustment',$definitionId,$scopeOrganization,['percentage_rate'=>$percentageRate]);
        });
    }
    public function deactivateDefinition(int $definitionId): void
    {
        $this->assertPermission();$definition=$this->definitionScope($definitionId);$scopeOrganization=(int)($definition['organization_id']??0);
        $this->atomic(function()use($definitionId,$scopeOrganization): void {
            $this->pdo->prepare('UPDATE pricing_adjustment_definitions SET is_active=0,updated_by=? WHERE id=?')->execute([$this->actorUserId,$definitionId]);
            $this->audit('pricing_adjustment.definition_deactivated','pricing_adjustment',$definitionId,$scopeOrganization,[]);
        });
    }
    public function setDocumentOverride(int $organizationId,string $documentType,int $documentId,?int $definitionId,string $reason): void
    {
        $this->assertPermission();$table=['quote'=>'quotes','contract'=>'contracts','invoice'=>'invoices'][$documentType]??null;
        if(!$table) throw new DomainException('Unsupported pricing document type.');
        $this->assertSameOrganization($table,$documentId,$organizationId);
        $this->assertProjectContext($table,$documentId,$organizationId);
        if($definitionId!==null)$this->assertDefinitionAssignable($definitionId,$organizationId);
        $reason=trim($reason);if($reason===''||mb_strlen($reason)>500)throw new DomainException('A concise override reason is required.');
        $mode=$definitionId===null?'none':'adjustment';$base='INSERT INTO document_pricing_adjustment_overrides (organization_id,document_type,document_id,override_mode,adjustment_definition_id,reason,created_by) VALUES (?,?,?,?,?,?,?)';
        $sql=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?$base.' ON CONFLICT(document_type,document_id) DO UPDATE SET organization_id=excluded.organization_id,override_mode=excluded.override_mode,adjustment_definition_id=excluded.adjustment_definition_id,reason=excluded.reason,created_by=excluded.created_by':$base.' ON DUPLICATE KEY UPDATE organization_id=VALUES(organization_id),override_mode=VALUES(override_mode),adjustment_definition_id=VALUES(adjustment_definition_id),reason=VALUES(reason),created_by=VALUES(created_by)';
        $this->atomic(function()use($sql,$organizationId,$documentType,$documentId,$mode,$definitionId,$reason): void {
            $this->pdo->prepare($sql)->execute([$organizationId,$documentType,$documentId,$mode,$definitionId,$reason,$this->actorUserId]);
            $this->audit('pricing_adjustment.document_overridden',$documentType,$documentId,$organizationId,['mode'=>$mode,'adjustment_definition_id'=>$definitionId,'reason'=>$reason]);
        });
    }
    public function clearDocumentOverride(int $organizationId,string $documentType,int $documentId): void
    {
        $this->assertPermission();$table=['quote'=>'quotes','contract'=>'contracts','invoice'=>'invoices'][$documentType]??null;
        if(!$table)throw new DomainException('Unsupported pricing document type.');
        $this->assertSameOrganization($table,$documentId,$organizationId);
        $this->atomic(function()use($organizationId,$documentType,$documentId): void {
            $this->pdo->prepare('DELETE FROM document_pricing_adjustment_overrides WHERE organization_id=? AND document_type=? AND document_id=?')->execute([$organizationId,$documentType,$documentId]);
            $this->audit('pricing_adjustment.document_override_cleared',$documentType,$documentId,$organizationId,[]);
        });
    }
    private function assign(int $organizationId,string $targetTable,string $assignmentTable,string $column,int $targetId,int $definitionId,string $action,string $entityType): void
    {
        $this->assertPermission();$this->assertSameOrganization($targetTable,$targetId,$organizationId);$this->assertDefinitionAssignable($definitionId,$organizationId);
        $base="INSERT INTO {$assignmentTable} (organization_id,{$column},adjustment_definition_id,assigned_by) VALUES (?,?,?,?)";
        $sql=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?$base." ON CONFLICT({$column}) DO UPDATE SET organization_id=excluded.organization_id,adjustment_definition_id=excluded.adjustment_definition_id,assigned_by=excluded.assigned_by":$base.' ON DUPLICATE KEY UPDATE organization_id=VALUES(organization_id),adjustment_definition_id=VALUES(adjustment_definition_id),assigned_by=VALUES(assigned_by)';
        $this->atomic(function()use($sql,$organizationId,$targetId,$definitionId,$action,$entityType): void {
            $this->pdo->prepare($sql)->execute([$organizationId,$targetId,$definitionId,$this->actorUserId]);$this->audit($action,$entityType,$targetId,$organizationId,['adjustment_definition_id'=>$definitionId]);
        });
    }
    private function unassign(int $organizationId,string $targetTable,string $assignmentTable,string $column,int $targetId,string $action,string $entityType): void
    {
        $this->assertPermission();$this->assertSameOrganization($targetTable,$targetId,$organizationId);
        $this->atomic(function()use($organizationId,$assignmentTable,$column,$targetId,$action,$entityType): void {
            $this->pdo->prepare("DELETE FROM {$assignmentTable} WHERE organization_id=? AND {$column}=?")->execute([$organizationId,$targetId]);
            $this->audit($action,$entityType,$targetId,$organizationId,[]);
        });
    }
    private function assertPermission(): void
    {
        if(!$this->enabled())throw new DomainException('Pricing adjustments are not enabled.');
        if(!($this->authorizer)(0))throw new DomainException('Financial management permission is required.');
    }
    private function assertCustomerExists(int $organizationId): void
    {
        if($organizationId<1)throw new DomainException('A customer organization is required.');
        $statement=$this->pdo->prepare('SELECT 1 FROM organizations WHERE id=? LIMIT 1');$statement->execute([$organizationId]);if(!$statement->fetchColumn())throw new DomainException('Customer organization was not found.');
    }
    private function definitionScope(int $definitionId): array
    {
        $statement=$this->pdo->prepare('SELECT id,organization_id,scope_type FROM pricing_adjustment_definitions WHERE id=? LIMIT 1');$statement->execute([$definitionId]);$row=$statement->fetch(PDO::FETCH_ASSOC);if(!$row)throw new DomainException('Pricing adjustment definition was not found.');return$row;
    }
    private function assertDefinitionAssignable(int $definitionId,int $organizationId): void
    {
        $statement=$this->pdo->prepare("SELECT 1 FROM pricing_adjustment_definitions WHERE id=? AND is_active=1 AND ((scope_type='installation' AND organization_id IS NULL) OR (scope_type='customer' AND organization_id=?)) LIMIT 1");$statement->execute([$definitionId,$organizationId]);if(!$statement->fetchColumn())throw new DomainException('Pricing adjustment is not available for this customer organization.');
    }
    private function assertProjectContext(string $table,int $id,int $organizationId): void
    {
        if(!in_array($table,['contracts','quotes','invoices'],true))throw new DomainException('Unsupported project pricing scope.');
        $statement=$this->pdo->prepare("SELECT 1 FROM {$table} WHERE id=? AND organization_id=? AND project_id IS NOT NULL LIMIT 1");$statement->execute([$id,$organizationId]);if(!$statement->fetchColumn())throw new DomainException('Inherited pricing adjustments require a project-context document.');
    }
    private function assertSameOrganization(string $table,int $id,int $organizationId): void
    {
        if(!in_array($table,['projects','contracts','quotes','invoices','project_invoices'],true))throw new DomainException('Unsupported pricing scope.');
        $statement=$this->pdo->prepare("SELECT 1 FROM {$table} WHERE id=? AND organization_id=? LIMIT 1");$statement->execute([$id,$organizationId]);if(!$statement->fetchColumn())throw new DomainException('Pricing scope does not belong to this organization.');
    }
    private function enabled(): bool
    {
        try{$statement=$this->pdo->prepare("SELECT config_value FROM app_config WHERE organization_id=0 AND config_key='pricing_adjustments_enabled' LIMIT 1");$statement->execute();return(string)$statement->fetchColumn()==='1';}catch(\Throwable){return false;}
    }
    private function audit(string $action,string $entityType,int $entityId,int $organizationId,array $details): void
    {
        ($this->auditor)($action,$entityType,$entityId,$organizationId,$details);
    }
    private function atomic(callable $operation): mixed
    {
        $owns=!$this->pdo->inTransaction();$savepoint='pricing_adjustment_mutation';
        if($owns)$this->pdo->beginTransaction();else$this->pdo->exec('SAVEPOINT '.$savepoint);
        try{$result=$operation();if($owns)$this->pdo->commit();else$this->pdo->exec('RELEASE SAVEPOINT '.$savepoint);return$result;}
        catch(\Throwable$error){if($owns&&$this->pdo->inTransaction())$this->pdo->rollBack();elseif(!$owns)$this->pdo->exec('ROLLBACK TO SAVEPOINT '.$savepoint);throw$error;}
    }
}
