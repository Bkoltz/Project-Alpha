<?php
declare(strict_types=1);
namespace App\Domain\Pricing;
use DomainException;
use PDO;
final class PricingAdjustmentResolver
{
    public function __construct(private readonly PDO $pdo) {}
    public function resolve(int $organizationId,string $documentType,int $documentId,?int $projectId,?int $contractId,?string $asOf=null,bool $lock=false): array
    {
        if(!in_array($documentType,['quote','contract','invoice','project_invoice'],true)) throw new \InvalidArgumentException('Unsupported pricing document type.');
        if(!$this->enabled()) return ['source_type'=>'none','source_assignment_id'=>null,'override_reason'=>null,'definition'=>null];
        $asOf ??= gmdate('Y-m-d');
        $suffix=$lock&&$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
        $override=$this->one('SELECT id,override_mode,adjustment_definition_id,reason FROM document_pricing_adjustment_overrides WHERE organization_id=? AND document_type=? AND document_id=? LIMIT 1'.$suffix,[$organizationId,$documentType,$documentId]);
        if($override){
            if($override['override_mode']==='none') return ['source_type'=>'none','source_assignment_id'=>(int)$override['id'],'override_reason'=>(string)$override['reason'],'definition'=>null];
            $definition=$this->definition($organizationId,(int)$override['adjustment_definition_id'],$asOf,$lock);
            if(!$definition)throw new DomainException('The document pricing override is no longer available. Select another adjustment or explicitly opt out.');
            return ['source_type'=>'document_override','source_assignment_id'=>(int)$override['id'],'override_reason'=>(string)$override['reason'],'definition'=>$definition];
        }
        if($contractId){
            $assignment=$this->one('SELECT a.id,a.adjustment_definition_id FROM contract_pricing_adjustment_assignments a JOIN contracts c ON c.id=a.contract_id AND c.organization_id=a.organization_id WHERE a.organization_id=? AND a.contract_id=? LIMIT 1'.$suffix,[$organizationId,$contractId]);
            if($assignment){$definition=$this->definition($organizationId,(int)$assignment['adjustment_definition_id'],$asOf,$lock);if($definition)return ['source_type'=>'contract','source_assignment_id'=>(int)$assignment['id'],'override_reason'=>null,'definition'=>$definition];}
        }
        if($projectId){
            $assignment=$this->one('SELECT a.id,a.adjustment_definition_id FROM project_pricing_adjustment_assignments a JOIN projects p ON p.id=a.project_id AND p.organization_id=a.organization_id WHERE a.organization_id=? AND a.project_id=? LIMIT 1'.$suffix,[$organizationId,$projectId]);
            if($assignment){$definition=$this->definition($organizationId,(int)$assignment['adjustment_definition_id'],$asOf,$lock);return ['source_type'=>$definition?'project':'none','source_assignment_id'=>(int)$assignment['id'],'override_reason'=>null,'definition'=>$definition];}
        }
        return ['source_type'=>'none','source_assignment_id'=>null,'override_reason'=>null,'definition'=>null];
    }
    private function definition(int $organizationId,int $id,string $asOf,bool $lock=false): ?array
    {
        $suffix=$lock&&$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
        return $this->one("SELECT id,name,adjustment_kind,percentage_rate FROM pricing_adjustment_definitions WHERE id=? AND ((scope_type='installation' AND organization_id IS NULL) OR (scope_type='customer' AND organization_id=?)) AND is_active=1 AND (effective_from IS NULL OR effective_from<=?) AND (effective_until IS NULL OR effective_until>=?) LIMIT 1".$suffix,[$id,$organizationId,$asOf,$asOf]);
    }
    private function one(string $sql,array $parameters): ?array
    {
        $statement=$this->pdo->prepare($sql);$statement->execute($parameters);$row=$statement->fetch(PDO::FETCH_ASSOC);return $row?:null;
    }
    private function enabled(): bool
    {
        try{
            $statement=$this->pdo->prepare("SELECT config_value FROM app_config WHERE organization_id=0 AND config_key='pricing_adjustments_enabled' LIMIT 1");
            $statement->execute();return (string)$statement->fetchColumn()==='1';
        }catch(\Throwable){return false;}
    }
}
