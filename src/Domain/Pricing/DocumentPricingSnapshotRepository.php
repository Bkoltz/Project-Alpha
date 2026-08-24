<?php
declare(strict_types=1);
namespace App\Domain\Pricing;
use DomainException;
use PDO;
final class DocumentPricingSnapshotRepository
{
    public function __construct(private readonly PDO $pdo,private readonly ExactPercentageCalculator $calculator=new ExactPercentageCalculator()) {}
    public function createAuthoritative(int $organizationId,string $documentType,int $documentId,int $revision,string $currency,?int $actor,?string $asOf=null): array
    {
        $table=['quote'=>'quotes','contract'=>'contracts','invoice'=>'invoices','project_invoice'=>'project_invoices'][$documentType]??null;
        if(!$table)throw new DomainException('Unsupported pricing document type.');
        $owns=!$this->pdo->inTransaction();$savepoint='pricing_snapshot_create';
        if($owns)$this->pdo->beginTransaction();else$this->pdo->exec('SAVEPOINT '.$savepoint);
        try{
            $driver=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            $contractColumn=$documentType==='invoice'?',contract_id':($documentType==='contract'?',id AS contract_id':'');
            $sql="SELECT id,organization_id,project_id{$contractColumn},revision_number,subtotal FROM {$table} WHERE id=? AND organization_id=?".($driver==='mysql'?' FOR UPDATE':'');
            $statement=$this->pdo->prepare($sql);$statement->execute([$documentId,$organizationId]);$document=$statement->fetch(PDO::FETCH_ASSOC);
            if(!$document)throw new DomainException('Pricing document does not belong to this organization.');
            if((int)$document['revision_number']!==$revision)throw new DomainException('Pricing document revision changed before the snapshot was created.');
            $projectId=isset($document['project_id'])&&$document['project_id']!==null?(int)$document['project_id']:null;
            $contractId=isset($document['contract_id'])&&$document['contract_id']!==null?(int)$document['contract_id']:null;
            $resolution=(new PricingAdjustmentResolver($this->pdo))->resolve($organizationId,$documentType,$documentId,$projectId,$contractId,$asOf,true);
            $result=$this->insert($organizationId,$documentType,$documentId,$revision,$currency,$this->minorUnits((string)$document['subtotal']),$resolution,$actor);
            if($owns)$this->pdo->commit();else$this->pdo->exec('RELEASE SAVEPOINT '.$savepoint);return$result;
        }catch(\Throwable$error){if($owns&&$this->pdo->inTransaction())$this->pdo->rollBack();elseif(!$owns)$this->pdo->exec('ROLLBACK TO SAVEPOINT '.$savepoint);throw$error;}
    }
    public function createDerivedFromSnapshot(int $organizationId,string $documentType,int $documentId,int $revision,string $currency,?int $actor,string $sourceDocumentType,int $sourceDocumentId,int $sourceRevision): array
    {
        if(!in_array($sourceDocumentType,['quote','contract','invoice'],true))throw new DomainException('Unsupported source pricing document type.');
        $table=['quote'=>'quotes','contract'=>'contracts','invoice'=>'invoices'][$documentType]??null;
        if(!$table)throw new DomainException('Unsupported pricing document type.');
        $owns=!$this->pdo->inTransaction();$savepoint='pricing_snapshot_derive';
        if($owns)$this->pdo->beginTransaction();else$this->pdo->exec('SAVEPOINT '.$savepoint);
        try{
            $suffix=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
            $target=$this->pdo->prepare("SELECT revision_number,subtotal FROM {$table} WHERE id=? AND organization_id=?".$suffix);
            $target->execute([$documentId,$organizationId]);$document=$target->fetch(PDO::FETCH_ASSOC);
            if(!$document||(int)$document['revision_number']!==$revision)throw new DomainException('Derived pricing document revision changed before the snapshot was created.');
            $source=$this->pdo->prepare('SELECT * FROM document_pricing_adjustment_snapshots WHERE organization_id=? AND document_type=? AND document_id=? AND document_revision=? LIMIT 1'.$suffix);
            $source->execute([$organizationId,$sourceDocumentType,$sourceDocumentId,$sourceRevision]);$frozen=$source->fetch(PDO::FETCH_ASSOC)?:null;
            if($frozen===null)throw new DomainException('The accepted source pricing snapshot is unavailable.');
            $definition=$frozen['percentage_rate']!==null?[
                'id'=>$frozen['adjustment_definition_id']!==null?(int)$frozen['adjustment_definition_id']:null,
                'name'=>(string)($frozen['adjustment_name']??''),'adjustment_kind'=>(string)($frozen['adjustment_kind']??'percentage_discount'),
                'percentage_rate'=>(string)$frozen['percentage_rate'],
            ]:null;
            $resolution=['source_type'=>(string)$frozen['source_type'],'source_assignment_id'=>$frozen['source_assignment_id']!==null?(int)$frozen['source_assignment_id']:null,'override_reason'=>$frozen['override_reason']??null,'definition'=>$definition];
            $frozenCurrency=strtoupper((string)($frozen['currency']??''));
            if(!preg_match('/^[A-Z]{3}$/D',$frozenCurrency))throw new DomainException('The accepted source pricing currency is unavailable.');
            $result=$this->insert($organizationId,$documentType,$documentId,$revision,$frozenCurrency,$this->minorUnits((string)$document['subtotal']),$resolution,$actor,(int)($frozen['id']??0)?:null);
            if($owns)$this->pdo->commit();else$this->pdo->exec('RELEASE SAVEPOINT '.$savepoint);return$result;
        }catch(\Throwable$error){if($owns&&$this->pdo->inTransaction())$this->pdo->rollBack();elseif(!$owns)$this->pdo->exec('ROLLBACK TO SAVEPOINT '.$savepoint);throw$error;}
    }
    public function carryForwardFrozen(int $organizationId,string $documentType,int $documentId,int $sourceRevision,int $targetRevision,?int $actor): array
    {
        $table=['quote'=>'quotes','contract'=>'contracts','invoice'=>'invoices'][$documentType]??null;
        if(!$table)throw new DomainException('Unsupported pricing document type.');
        if($sourceRevision<1||$targetRevision!==$sourceRevision+1)throw new DomainException('Frozen pricing must be carried to the next document revision.');
        $owns=!$this->pdo->inTransaction();$savepoint='pricing_snapshot_carry';
        if($owns)$this->pdo->beginTransaction();else$this->pdo->exec('SAVEPOINT '.$savepoint);
        try{
            $suffix=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
            $target=$this->pdo->prepare("SELECT revision_number,subtotal FROM {$table} WHERE id=? AND organization_id=?".$suffix);
            $target->execute([$documentId,$organizationId]);$document=$target->fetch(PDO::FETCH_ASSOC);
            if(!$document||(int)$document['revision_number']!==$targetRevision)throw new DomainException('Pricing document revision changed before frozen pricing was carried forward.');
            $source=$this->pdo->prepare('SELECT * FROM document_pricing_adjustment_snapshots WHERE organization_id=? AND document_type=? AND document_id=? AND document_revision=? LIMIT 1'.$suffix);
            $source->execute([$organizationId,$documentType,$documentId,$sourceRevision]);$frozen=$source->fetch(PDO::FETCH_ASSOC)?:null;
            if($frozen===null)throw new DomainException('The current frozen pricing snapshot is unavailable.');
            $basisMinor=$this->minorUnits((string)$document['subtotal']);
            if($basisMinor!==(int)$frozen['basis_minor'])throw new DomainException('Exact pricing carry-forward cannot follow a changed subtotal.');
            $statement=$this->pdo->prepare('INSERT INTO document_pricing_adjustment_snapshots (organization_id,document_type,document_id,document_revision,source_type,source_assignment_id,adjustment_definition_id,adjustment_name,adjustment_kind,percentage_rate,currency,basis_minor,adjustment_minor,adjusted_minor,calculation_version,override_reason,applied_by,derived_from_snapshot_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $statement->execute([
                $organizationId,$documentType,$documentId,$targetRevision,(string)$frozen['source_type'],$frozen['source_assignment_id'],
                $frozen['adjustment_definition_id'],$frozen['adjustment_name'],$frozen['adjustment_kind'],$frozen['percentage_rate'],
                (string)$frozen['currency'],(int)$frozen['basis_minor'],(int)$frozen['adjustment_minor'],(int)$frozen['adjusted_minor'],
                (string)$frozen['calculation_version'],$frozen['override_reason'],$actor,(int)$frozen['id'],
            ]);
            $result=$frozen;$result['id']=(int)$this->pdo->lastInsertId();$result['document_revision']=$targetRevision;$result['applied_by']=$actor;$result['derived_from_snapshot_id']=(int)$frozen['id'];
            if($owns)$this->pdo->commit();else$this->pdo->exec('RELEASE SAVEPOINT '.$savepoint);return$result;
        }catch(\Throwable$error){if($owns&&$this->pdo->inTransaction())$this->pdo->rollBack();elseif(!$owns)$this->pdo->exec('ROLLBACK TO SAVEPOINT '.$savepoint);throw$error;}
    }
    private function insert(int $organizationId,string $documentType,int $documentId,int $revision,string $currency,int $basisMinor,array $resolution,?int $actor,?int $derivedFromSnapshotId=null): array
    {
        $currency=strtoupper(trim($currency));
        if(!preg_match('/^[A-Z]{3}$/',$currency)||$revision<1) throw new DomainException('A valid currency and positive document revision are required.');
        if($basisMinor<0) throw new DomainException('Pricing basis cannot be negative.');
        $definition=$resolution['definition']??null;
        $calculation=$definition?$this->calculator->discount($basisMinor,(string)$definition['percentage_rate']):['basis_minor'=>$basisMinor,'adjustment_minor'=>0,'adjusted_minor'=>$basisMinor,'percentage_rate'=>null,'calculation_version'=>ExactPercentageCalculator::VERSION];
        $statement=$this->pdo->prepare('INSERT INTO document_pricing_adjustment_snapshots (organization_id,document_type,document_id,document_revision,source_type,source_assignment_id,adjustment_definition_id,adjustment_name,adjustment_kind,percentage_rate,currency,basis_minor,adjustment_minor,adjusted_minor,calculation_version,override_reason,applied_by,derived_from_snapshot_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $statement->execute([$organizationId,$documentType,$documentId,$revision,(string)$resolution['source_type'],$resolution['source_assignment_id']??null,$definition['id']??null,$definition['name']??null,$definition['adjustment_kind']??null,$calculation['percentage_rate'],$currency,$calculation['basis_minor'],$calculation['adjustment_minor'],$calculation['adjusted_minor'],$calculation['calculation_version'],$resolution['override_reason']??null,$actor,$derivedFromSnapshotId]);
        return array_merge($calculation,['id'=>(int)$this->pdo->lastInsertId(),'source_type'=>(string)$resolution['source_type'],'definition_id'=>isset($definition['id'])?(int)$definition['id']:null]);
    }
    private function minorUnits(string $amount): int
    {
        $amount=trim($amount);if(!preg_match('/^(\d{1,16})(?:\.(\d{1,2}))?$/',$amount,$match))throw new DomainException('Authoritative document subtotal is invalid.');
        $whole=(int)$match[1];$fraction=(int)str_pad($match[2]??'',2,'0');
        if($whole>intdiv(PHP_INT_MAX-99,100))throw new DomainException('Authoritative document subtotal exceeds the supported range.');
        return $whole*100+$fraction;
    }
}
