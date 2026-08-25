<?php
declare(strict_types=1);

use App\Domain\Pricing\DocumentPricingSnapshotRepository;

require_once __DIR__ . '/document_pricing_adjustments.php';

/** @return array{table:string,document:array<string,mixed>,eligible:bool} */
function pricing_lock_current_document(PDO $pdo,?int $organizationId,string $documentType,int $documentId): array
{
    if(!$pdo->inTransaction())throw new LogicException('Frozen pricing finalization must run inside the document transaction.');
    $table=['quote'=>'quotes','contract'=>'contracts','invoice'=>'invoices'][$documentType]??null;
    if(!$table)throw new DomainException('Unsupported pricing document type.');
    $suffix=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
    $statement=$pdo->prepare("SELECT organization_id,project_id,revision_number FROM {$table} WHERE id=? AND (organization_id=? OR (organization_id IS NULL AND ? IS NULL))".$suffix);
    $statement->execute([$documentId,$organizationId,$organizationId]);$document=$statement->fetch(PDO::FETCH_ASSOC);
    if(!$document)throw new DomainException('Pricing document was not found.');
    return ['table'=>$table,'document'=>$document,'eligible'=>pricing_adjustments_enabled($pdo)&&(int)($organizationId??0)>0&&(int)($document['project_id']??0)>0];
}

/**
 * Recalculate a mutable document with the exact adjustment terms frozen on
 * its current revision. Live project/contract assignments are never read.
 */
function pricing_finalize_frozen_document_revision(PDO $pdo,?int $organizationId,string $documentType,int $documentId,?int $actor,string $currency='USD',?callable $afterPricing=null): int
{
    $locked=pricing_lock_current_document($pdo,$organizationId,$documentType,$documentId);
    if(!$locked['eligible'])return DocumentRevisionService::snapshotAndSave($pdo,$documentType,$documentId,$actor,true);
    $sourceRevision=max(1,(int)($locked['document']['revision_number']??1));
    $suffix=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
    $source=$pdo->prepare('SELECT currency FROM document_pricing_adjustment_snapshots WHERE organization_id=? AND document_type=? AND document_id=? AND document_revision=? LIMIT 1'.$suffix);
    $source->execute([(int)$organizationId,$documentType,$documentId,$sourceRevision]);
    $sourceCurrency=$source->fetchColumn();
    if($sourceCurrency===false){
        // Pre-feature mutable drafts have no frozen snapshot. Their first
        // monetary mutation deliberately adopts the current resolver and
        // creates the first authoritative snapshot on the new revision.
        return pricing_finalize_document_revision($pdo,$organizationId,$documentType,$documentId,$actor,true,$currency,$afterPricing);
    }
    $frozenCurrency=strtoupper((string)$sourceCurrency);
    if(!preg_match('/^[A-Z]{3}$/D',$frozenCurrency))throw new DomainException('The current frozen pricing currency is unavailable.');
    return pricing_finalize_document_revision($pdo,$organizationId,$documentType,$documentId,$actor,true,$frozenCurrency,$afterPricing,[
        'document_type'=>$documentType,'document_id'=>$documentId,'document_revision'=>$sourceRevision,
    ]);
}

/**
 * Advance a non-repriced revision while preserving its pricing snapshot byte
 * for byte. This is for non-monetary changes and explicit post-pricing invoice
 * adjustments, including settled invoices that must never be repriced.
 */
function pricing_carry_forward_document_revision(PDO $pdo,?int $organizationId,string $documentType,int $documentId,?int $actor,?callable $beforeSnapshot=null): int
{
    $locked=pricing_lock_current_document($pdo,$organizationId,$documentType,$documentId);
    if(!$locked['eligible']){
        if($beforeSnapshot!==null)$beforeSnapshot(null);
        return DocumentRevisionService::snapshotAndSave($pdo,$documentType,$documentId,$actor,true);
    }
    $sourceRevision=max(1,(int)($locked['document']['revision_number']??1));$targetRevision=$sourceRevision+1;
    $suffix=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
    $source=$pdo->prepare('SELECT id FROM document_pricing_adjustment_snapshots WHERE organization_id=? AND document_type=? AND document_id=? AND document_revision=? LIMIT 1'.$suffix);
    $source->execute([(int)$organizationId,$documentType,$documentId,$sourceRevision]);
    if($source->fetchColumn()===false){
        // A settled or non-monetary pre-feature document must retain its
        // legacy money. Do not resolve a current assignment during rollout.
        if($beforeSnapshot!==null)$beforeSnapshot(null);
        return DocumentRevisionService::snapshotAndSave($pdo,$documentType,$documentId,$actor,true);
    }
    $pdo->prepare("UPDATE {$locked['table']} SET revision_number=?,revision_updated_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$targetRevision,$documentId]);
    $snapshot=(new DocumentPricingSnapshotRepository($pdo))->carryForwardFrozen((int)$organizationId,$documentType,$documentId,$sourceRevision,$targetRevision,$actor);
    if($beforeSnapshot!==null)$beforeSnapshot($snapshot);
    DocumentRevisionService::snapshotAndSave($pdo,$documentType,$documentId,$actor,false);
    return $targetRevision;
}
