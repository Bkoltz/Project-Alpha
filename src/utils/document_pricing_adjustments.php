<?php
declare(strict_types=1);

use App\Domain\Pricing\AuthoritativeDocumentPricingService;
use App\Domain\Pricing\PricingAdjustmentManager;

require_once __DIR__ . '/../services/DocumentRevisionService.php';

function pricing_adjustments_enabled(PDO $pdo): bool
{
    try {
        $statement = $pdo->prepare(
            "SELECT config_value FROM app_config WHERE organization_id=0 AND config_key='pricing_adjustments_enabled' LIMIT 1"
        );
        $statement->execute();
        return (string)$statement->fetchColumn() === '1';
    } catch (Throwable) {
        return false;
    }
}

function pricing_finalize_document_revision(PDO $pdo,?int $organizationId,string $documentType,int $documentId,?int $actor,bool $increment,string $currency='USD',?callable $afterPricing=null,?array $sourceSnapshot=null): int
{
    if(!$pdo->inTransaction())throw new LogicException('Document pricing finalization must run inside the document transaction.');
    $table=['quote'=>'quotes','contract'=>'contracts','invoice'=>'invoices'][$documentType]??null;
    if(!$table)throw new DomainException('Unsupported pricing document type.');
    $suffix=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
    $statement=$pdo->prepare("SELECT organization_id,project_id,revision_number FROM {$table} WHERE id=? AND (organization_id=? OR (organization_id IS NULL AND ? IS NULL))".$suffix);
    $statement->execute([$documentId,$organizationId,$organizationId]);$document=$statement->fetch(PDO::FETCH_ASSOC);
    if(!$document)throw new DomainException('Pricing document was not found.');
    $eligible=pricing_adjustments_enabled($pdo)&&(int)($organizationId??0)>0&&(int)($document['project_id']??0)>0;
    if(!$eligible)return DocumentRevisionService::snapshotAndSave($pdo,$documentType,$documentId,$actor,$increment);
    $revision=max(1,(int)($document['revision_number']??1));
    if($increment){$revision++;$pdo->prepare("UPDATE {$table} SET revision_number=?,revision_updated_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$revision,$documentId]);}
    $pricing=(new AuthoritativeDocumentPricingService($pdo))->apply((int)$organizationId,$documentType,$documentId,$revision,$currency,$actor,null,$sourceSnapshot);
    if($afterPricing!==null)$afterPricing($pricing);
    DocumentRevisionService::snapshotAndSave($pdo,$documentType,$documentId,$actor,false);
    return $revision;
}

function pricing_is_exact_legacy_accepted_source(PDO $pdo,int $organizationId,string $sourceDocumentType,int $sourceDocumentId,int $sourceRevision): bool
{
    if($sourceRevision<1||!in_array($sourceDocumentType,['quote','contract'],true))return false;
    $snapshots=$pdo->prepare('SELECT COUNT(*) FROM document_pricing_adjustment_snapshots WHERE document_type=? AND document_id=?');
    $snapshots->execute([$sourceDocumentType,$sourceDocumentId]);
    if((int)$snapshots->fetchColumn()!==0)return false;
    $suffix=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
    if($sourceDocumentType==='quote'){
        $statement=$pdo->prepare('SELECT organization_id,revision_number,status FROM quotes WHERE id=?'.$suffix);
        $statement->execute([$sourceDocumentId]);$source=$statement->fetch(PDO::FETCH_ASSOC);
        return $source!==false&&(int)($source['organization_id']??0)===$organizationId
            &&(int)($source['revision_number']??0)===$sourceRevision
            &&(string)($source['status']??'')==='approved';
    }
    $statement=$pdo->prepare('SELECT organization_id,revision_number,signed_revision_number,signed_at,signed_pdf_path,status FROM contracts WHERE id=?'.$suffix);
    $statement->execute([$sourceDocumentId]);$source=$statement->fetch(PDO::FETCH_ASSOC);
    if($source===false||(int)($source['organization_id']??0)!==$organizationId)return false;
    $acceptedRevision=(int)($source['signed_revision_number']??0)>0
        ?(int)$source['signed_revision_number']:(int)($source['revision_number']??0);
    $signed=!empty($source['signed_at'])||trim((string)($source['signed_pdf_path']??''))!==''
        ||in_array((string)($source['status']??''),['active','signed'],true);
    return $signed&&$acceptedRevision===$sourceRevision;
}

function pricing_finalize_derived_document_revision(PDO $pdo,?int $organizationId,string $documentType,int $documentId,?int $actor,string $currency,string $sourceDocumentType,int $sourceDocumentId,int $sourceRevision,?callable $afterPricing=null): int
{
    if(pricing_adjustments_enabled($pdo)&&(int)($organizationId??0)>0){
        $suffix=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
        $source=$pdo->prepare('SELECT id FROM document_pricing_adjustment_snapshots WHERE organization_id=? AND document_type=? AND document_id=? AND document_revision=? LIMIT 1'.$suffix);
        $source->execute([(int)$organizationId,$sourceDocumentType,$sourceDocumentId,$sourceRevision]);
        if($source->fetchColumn()===false){
            // Accepted pre-feature terms have no pricing snapshot. Preserve
            // the values copied into the child and never resolve today's
            // assignment during conversion or scheduled generation.
            if(!pricing_is_exact_legacy_accepted_source($pdo,(int)$organizationId,$sourceDocumentType,$sourceDocumentId,$sourceRevision)){
                throw new DomainException('The accepted source pricing snapshot is unavailable.');
            }
            if($afterPricing!==null)$afterPricing([]);
            return DocumentRevisionService::snapshotAndSave($pdo,$documentType,$documentId,$actor,false);
        }
    }
    return pricing_finalize_document_revision($pdo,$organizationId,$documentType,$documentId,$actor,false,$currency,$afterPricing,[
        'document_type'=>$sourceDocumentType,'document_id'=>$sourceDocumentId,'document_revision'=>$sourceRevision,
    ]);
}

function pricing_recompute_contract_percentage_deposit(PDO $pdo,int $organizationId,int $contractId,string $percentage): void
{
    $normalized=number_format(max(0.0,min(100.0,(float)$percentage)),4,'.','');
    $statement=$pdo->prepare('SELECT total FROM contracts WHERE id=? AND organization_id=?');
    $statement->execute([$contractId,$organizationId]);
    $total=(string)$statement->fetchColumn();
    if(!preg_match('/^(\d{1,16})(?:\.(\d{1,2}))?$/',$total,$match))throw new DomainException('Contract total is invalid.');
    $totalMinor=((int)$match[1]*100)+(int)str_pad($match[2]??'',2,'0');
    $depositMinor=$normalized==='0.0000'?0:(int)(new App\Domain\Pricing\ExactPercentageCalculator())->discount($totalMinor,$normalized)['adjustment_minor'];
    $deposit=intdiv($depositMinor,100).'.'.str_pad((string)($depositMinor%100),2,'0',STR_PAD_LEFT);
    $pdo->prepare('UPDATE contracts SET deposit_amount=? WHERE id=? AND organization_id=?')->execute([$deposit,$contractId,$organizationId]);
}

function pricing_money_to_minor(string $amount): int
{
    $amount=trim($amount);if(!preg_match('/^(\d{1,16})(?:\.(\d{1,2}))?$/',$amount,$match))throw new DomainException('Money value is invalid.');
    return ((int)$match[1]*100)+(int)str_pad($match[2]??'',2,'0');
}
function pricing_minor_to_money(int $minor): string
{
    if($minor<0)throw new DomainException('Money value cannot be negative.');
    return intdiv($minor,100).'.'.str_pad((string)($minor%100),2,'0',STR_PAD_LEFT);
}
function pricing_contract_source_revision(array $contract): int
{
    return max(1,(int)($contract['signed_revision_number']??0)>0?(int)$contract['signed_revision_number']:(int)($contract['revision_number']??1));
}

/** Fixed-total recurring children contain an allocated, already-priced slice of the contract total. */
function pricing_invoice_is_fixed_total_installment(PDO $pdo,int $invoiceId): bool
{
    if($invoiceId<=0)return false;
    $statement=$pdo->prepare("SELECT 1 FROM invoices i JOIN contracts c ON c.id=i.contract_id WHERE i.id=? AND c.pricing_type='fixed_total' LIMIT 1");
    $statement->execute([$invoiceId]);
    return $statement->fetchColumn()!==false;
}

function pricing_apply_posted_override(
    PDO $pdo,
    int $organizationId,
    string $documentType,
    int $documentId,
    int $actor,
    array $input,
): void {
    if (!pricing_adjustments_enabled($pdo) || !array_key_exists('pricing_adjustment_mode', $input)) {
        return;
    }
    $mode = (string)$input['pricing_adjustment_mode'];
    if ($mode === 'inherit') {
        (new PricingAdjustmentManager($pdo,$actor))->clearDocumentOverride($organizationId,$documentType,$documentId);
        return;
    }
    if (!in_array($mode, ['none','adjustment'], true)) {
        throw new DomainException('Invalid pricing adjustment override.');
    }
    $definitionId = $mode === 'adjustment' ? (int)($input['pricing_adjustment_definition_id'] ?? 0) : null;
    if ($mode === 'adjustment' && $definitionId <= 0) {
        throw new DomainException('Select a pricing adjustment for this override.');
    }
    (new PricingAdjustmentManager($pdo, $actor))->setDocumentOverride(
        $organizationId,
        $documentType,
        $documentId,
        $definitionId,
        trim((string)($input['pricing_adjustment_reason'] ?? '')),
    );
}

/** @return array<string,mixed>|null */
function pricing_document_snapshot(PDO $pdo,int $organizationId,string $documentType,int $documentId,int $revision): ?array
{
    try {
        $sql = 'SELECT * FROM document_pricing_adjustment_snapshots WHERE organization_id=? AND document_type=? AND document_id=? AND document_revision=? LIMIT 1';
        $parameters = [$organizationId,$documentType,$documentId,$revision];
        $statement = $pdo->prepare($sql);
        $statement->execute($parameters);
        return $statement->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable) {
        return null;
    }
}

function pricing_adjustment_client_label(array $snapshot): string
{
    if ((int)($snapshot['adjustment_minor'] ?? 0) <= 0) {
        return '';
    }
    return 'Pricing adjustment';
}

function pricing_currency_amount(mixed $amount,string $currency='USD',bool $negative=false): string
{
    $currency=strtoupper(trim($currency));if(!preg_match('/^[A-Z]{3}$/',$currency))$currency='USD';
    $number=number_format(max(0,(float)$amount),2,'.',',');
    $formatted=$currency==='USD'?'$'.$number:$currency.' '.$number;
    return $negative?'-'.$formatted:$formatted;
}

/**
 * Pricing UI is deliberately fail-closed on partially migrated installations.
 * This probe is also used by views so an unavailable feature never breaks a
 * document or Settings page.
 */
function pricing_adjustment_schema_available(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT id,scope_type,scope_key,is_active FROM pricing_adjustment_definitions LIMIT 0');
        $pdo->query('SELECT id FROM project_pricing_adjustment_assignments LIMIT 0');
        $pdo->query('SELECT id FROM contract_pricing_adjustment_assignments LIMIT 0');
        $pdo->query('SELECT id FROM document_pricing_adjustment_overrides LIMIT 0');
        $pdo->query('SELECT id,adjustment_minor,derived_from_snapshot_id FROM document_pricing_adjustment_snapshots LIMIT 0');
        $pdo->query('SELECT id,affects_total FROM invoice_adjustments LIMIT 0');
        return true;
    } catch (Throwable) {
        return false;
    }
}

/** @return list<array<string,mixed>> */
function pricing_adjustment_available_definitions(PDO $pdo,int $organizationId,bool $includeInactive=false): array
{
    if (!pricing_adjustment_schema_available($pdo)) return [];
    $active = $includeInactive ? '' : ' AND is_active=1';
    $statement=$pdo->prepare("SELECT id,organization_id,scope_type,name,percentage_rate,is_active,effective_from,effective_until FROM pricing_adjustment_definitions WHERE ((scope_type='installation' AND organization_id IS NULL) OR (scope_type='customer' AND organization_id=?)){$active} ORDER BY scope_type='customer' DESC,name,id");
    $statement->execute([$organizationId]);
    return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** @return array<string,mixed>|null */
function pricing_adjustment_current_assignment(PDO $pdo,int $organizationId,string $targetType,int $targetId): ?array
{
    if (!pricing_adjustment_schema_available($pdo) || $organizationId < 1 || $targetId < 1) return null;
    $configuration = match ($targetType) {
        'project' => ['project_pricing_adjustment_assignments','project_id'],
        'contract' => ['contract_pricing_adjustment_assignments','contract_id'],
        default => null,
    };
    if ($configuration === null) return null;
    [$table,$column]=$configuration;
    try {
        $statement=$pdo->prepare("SELECT a.adjustment_definition_id,d.name,d.percentage_rate,d.scope_type,d.is_active,d.effective_from,d.effective_until FROM {$table} a JOIN pricing_adjustment_definitions d ON d.id=a.adjustment_definition_id WHERE a.organization_id=? AND a.{$column}=? LIMIT 1");
        $statement->execute([$organizationId,$targetId]);
        return $statement->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable) {
        return null;
    }
}

function pricing_adjustment_assignment_controls(PDO $pdo,int $organizationId,string $targetType,int $targetId,string $returnTo,string $csrf): string
{
    $actor=(int)($_SESSION['user']['id']??0);
    if (!pricing_adjustments_enabled($pdo) || !pricing_adjustment_schema_available($pdo) || $organizationId<1 || $targetId<1 || $actor<1 || !function_exists('user_can') || !user_can($pdo,$actor,'financial.manage',0)) return '';
    $targetConfiguration=match($targetType){'project'=>['projects','Project','assign-project','unassign-project'],'contract'=>['contracts','Contract','assign-contract','unassign-contract'],default=>null};
    if($targetConfiguration===null)return '';
    [$targetTable,$targetLabel,$assignAction,$unassignAction]=$targetConfiguration;
    try {
        $targetColumn=$targetType==='contract'?'project_id':'id';
        $target=$pdo->prepare("SELECT {$targetColumn} AS project_id FROM {$targetTable} WHERE id=? AND organization_id=? LIMIT 1");
        $target->execute([$targetId,$organizationId]);
        $targetRow=$target->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        return '';
    }
    if(!$targetRow)return '';
    $h=static fn(mixed $value):string=>htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    if($targetType==='contract' && (int)($targetRow['project_id']??0)<1){
        return '<section class="pricing-assignment-panel" data-pricing-assignment><div><h3>Inherited pricing</h3><p>Save this Contract with a Project before assigning reusable pricing.</p></div></section>';
    }
    $definitions=pricing_adjustment_available_definitions($pdo,$organizationId);
    $assignment=pricing_adjustment_current_assignment($pdo,$organizationId,$targetType,$targetId);
    $current='No reusable adjustment assigned.';
    if($assignment){
        $rate=rtrim(rtrim(number_format((float)$assignment['percentage_rate'],4,'.',''),'0'),'.');
        $current=$assignment['name'].' - '.$rate.'%'.(empty($assignment['is_active'])?' - inactive (not applied)':'');
    }
    ob_start();
    ?>
    <section class="pricing-assignment-panel" data-pricing-assignment>
      <div class="pricing-assignment-copy"><h3>Inherited pricing</h3><p><?php echo $h($current); ?> New document revisions resolve the active <?php echo $h(strtolower($targetLabel)); ?> assignment and preserve the result in history.</p></div>
      <?php if($definitions): ?>
      <form method="post" action="/?page=settings/pricing-adjustments-handler" class="pricing-assignment-form">
        <input type="hidden" name="csrf" value="<?php echo $h($csrf); ?>"><input type="hidden" name="action" value="<?php echo $h($assignAction); ?>"><input type="hidden" name="organization_id" value="<?php echo $organizationId; ?>"><input type="hidden" name="target_id" value="<?php echo $targetId; ?>"><input type="hidden" name="return_to" value="<?php echo $h($returnTo); ?>">
        <label><span>Reusable adjustment</span><select name="definition_id" required><option value="">Select adjustment</option><?php foreach($definitions as $definition): ?><option value="<?php echo (int)$definition['id']; ?>" <?php echo (int)($assignment['adjustment_definition_id']??0)===(int)$definition['id']?'selected':''; ?>><?php echo $h($definition['name']); ?> - <?php echo $h(rtrim(rtrim(number_format((float)$definition['percentage_rate'],4,'.',''),'0'),'.')); ?>%<?php echo $definition['scope_type']==='customer'?' - customer':''; ?></option><?php endforeach; ?></select></label>
        <button class="btn btn-primary" type="submit"><?php echo $assignment?'Change assignment':'Assign'; ?></button>
      </form>
      <?php else: ?><p class="pricing-assignment-empty">No active adjustment is available. <a href="/?page=settings&amp;tab=pricing-adjustments">Create one in Settings</a>.</p><?php endif; ?>
      <?php if($assignment): ?><form method="post" action="/?page=settings/pricing-adjustments-handler" class="pricing-unassign-form" onsubmit="return confirm('Remove this inherited pricing assignment? Existing document snapshots will not change.');"><input type="hidden" name="csrf" value="<?php echo $h($csrf); ?>"><input type="hidden" name="action" value="<?php echo $h($unassignAction); ?>"><input type="hidden" name="organization_id" value="<?php echo $organizationId; ?>"><input type="hidden" name="target_id" value="<?php echo $targetId; ?>"><input type="hidden" name="return_to" value="<?php echo $h($returnTo); ?>"><button class="btn" type="submit">Remove assignment</button></form><?php endif; ?>
    </section>
    <?php
    return (string)ob_get_clean();
}

function pricing_adjustment_override_controls(PDO $pdo,int $organizationId,string $documentType,int $documentId): string
{
    $actor=(int)($_SESSION['user']['id']??0);
    if(!pricing_adjustments_enabled($pdo)||!pricing_adjustment_schema_available($pdo)||$organizationId<1||$documentId<1||$actor<1||!function_exists('user_can')||!user_can($pdo,$actor,'financial.manage',0))return '';
    $table=['quote'=>'quotes','contract'=>'contracts','invoice'=>'invoices'][$documentType]??null;
    if($table===null)return '';
    try{$target=$pdo->prepare("SELECT project_id FROM {$table} WHERE id=? AND organization_id=? LIMIT 1");$target->execute([$documentId,$organizationId]);$projectId=(int)$target->fetchColumn();}catch(Throwable){return '';}
    if($projectId<1)return '';
    $definitions=pricing_adjustment_available_definitions($pdo,$organizationId);
    $override=pricing_adjustment_document_override($pdo,$organizationId,$documentType,$documentId);
    $mode=$override?((string)$override['override_mode']==='none'?'none':'adjustment'):'inherit';
    $selected=(int)($override['adjustment_definition_id']??0);
    $selectedAvailable=false;foreach($definitions as $definition)if((int)$definition['id']===$selected)$selectedAvailable=true;
    $h=static fn(mixed $value):string=>htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    ob_start();
    ?>
    <fieldset class="pricing-override-panel" data-pricing-override>
      <legend>Pricing adjustment</legend>
      <p>Use inherited Project or Contract pricing, explicitly opt this document out, or choose a document-only adjustment. Changes are audited and apply to the new revision only.</p>
      <p class="pricing-override-warning" data-pricing-override-warning hidden>Saving an opt-out or document-only adjustment can change the document total. Review the reason and pricing before continuing.</p>
      <div class="pricing-override-grid">
        <label><span>Pricing source</span><select name="pricing_adjustment_mode" data-pricing-override-mode><option value="inherit" <?php echo $mode==='inherit'?'selected':''; ?>>Inherit from Contract or Project</option><option value="none" <?php echo $mode==='none'?'selected':''; ?>>No inherited pricing on this document</option><option value="adjustment" <?php echo $mode==='adjustment'?'selected':''; ?>>Use a different adjustment</option></select></label>
        <label data-pricing-override-definition><span>Document adjustment</span><select name="pricing_adjustment_definition_id"><option value="">Select adjustment</option><?php if($selected>0&&!$selectedAvailable): ?><option value="<?php echo $selected; ?>" selected disabled>Previously selected adjustment is unavailable</option><?php endif; ?><?php foreach($definitions as $definition): ?><option value="<?php echo (int)$definition['id']; ?>" <?php echo $selected===(int)$definition['id']?'selected':''; ?>><?php echo $h($definition['name']); ?> - <?php echo $h(rtrim(rtrim(number_format((float)$definition['percentage_rate'],4,'.',''),'0'),'.')); ?>%</option><?php endforeach; ?></select></label>
        <label class="pricing-override-reason" data-pricing-override-reason><span>Reason</span><textarea name="pricing_adjustment_reason" maxlength="500" rows="2" placeholder="Required for an override or opt-out"><?php echo $h($override['reason']??''); ?></textarea></label>
      </div>
    </fieldset>
    <?php
    return (string)ob_get_clean();
}

/** @return array<string,mixed>|null */
function pricing_adjustment_document_override(PDO $pdo,int $organizationId,string $documentType,int $documentId): ?array
{
    if (!pricing_adjustment_schema_available($pdo)) return null;
    try {
        $statement=$pdo->prepare('SELECT override_mode,adjustment_definition_id,reason FROM document_pricing_adjustment_overrides WHERE organization_id=? AND document_type=? AND document_id=? LIMIT 1');
        $statement->execute([$organizationId,$documentType,$documentId]);
        return $statement->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable) {
        return null;
    }
}

/**
 * Render the client-safe immutable adjustment row. Definition names, IDs,
 * sources, assignment IDs, and override reasons must never enter this output.
 */
function pricing_adjustment_client_row(?array $snapshot,string $cellStyle='padding:8px 10px',string $labelStyle='font-weight:600;text-align:right',int $leadingCells=0): string
{
    $minor=(int)($snapshot['adjustment_minor']??0);
    if ($minor<=0) return '';
    $amount=number_format(intdiv($minor,100)).'.'.str_pad((string)($minor%100),2,'0',STR_PAD_LEFT);
    $currency=strtoupper(trim((string)($snapshot['currency']??'USD')));
    if(!preg_match('/^[A-Z]{3}$/',$currency))$currency='USD';
    $formatted=$currency==='USD'?'-$'.$amount:'-'.$currency.' '.$amount;
    $leadingCells=max(0,min(8,$leadingCells));
    return '<tr data-pricing-adjustment-client-row>'.str_repeat('<td aria-hidden="true"></td>',$leadingCells).'<td style="'.htmlspecialchars($cellStyle.';'.$labelStyle,ENT_QUOTES,'UTF-8').'">Pricing adjustment</td><td style="'.htmlspecialchars($cellStyle.';text-align:right',ENT_QUOTES,'UTF-8').'">'.$formatted.'</td></tr>';
}

/** @return list<array{adjustment_type:string,amount:string}> */
function pricing_invoice_total_adjustments(PDO $pdo,?int $organizationId,int $invoiceId): array
{
    if($invoiceId<1||!pricing_adjustment_schema_available($pdo))return [];
    try{
        $statement=$pdo->prepare("SELECT ia.adjustment_type,ia.amount FROM invoice_adjustments ia JOIN invoices i ON i.id=ia.invoice_id WHERE ia.invoice_id=? AND (i.organization_id=? OR (i.organization_id IS NULL AND ? IS NULL)) AND ia.affects_total=1 AND ia.superseded_at IS NULL AND ia.adjustment_type IN ('charge','credit') ORDER BY ia.id");
        $statement->execute([$invoiceId,$organizationId,$organizationId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC)?:[];
    }catch(Throwable){return [];}
}

/** Render only generic aggregate total-affecting charges/credits; private adjustment metadata is never selected. */
function pricing_invoice_adjustment_client_rows(array $adjustments,string $currency='USD',string $cellStyle='padding:8px 10px'): string
{
    $currency=strtoupper(trim($currency));if(!preg_match('/^[A-Z]{3}$/',$currency))$currency='USD';
    $totals=['charge'=>0,'credit'=>0];
    foreach($adjustments as $adjustment){
        $type=(string)($adjustment['adjustment_type']??'');
        if(!array_key_exists($type,$totals))continue;
        try{$minor=pricing_money_to_minor((string)($adjustment['amount']??''));}catch(Throwable){continue;}
        if($minor<=0||$minor>PHP_INT_MAX-$totals[$type])continue;
        $totals[$type]+=$minor;
    }
    $html='';
    foreach(['charge'=>'Invoice charge','credit'=>'Invoice credit'] as $type=>$label){
        $minor=$totals[$type];if($minor<=0)continue;
        $formatted=pricing_currency_amount(pricing_minor_to_money($minor),$currency,$type==='credit');
        $html.='<tr data-invoice-total-adjustment="'.$type.'"><td style="'.htmlspecialchars($cellStyle.';font-weight:600;text-align:right',ENT_QUOTES,'UTF-8').'">'.$label.'</td><td style="'.htmlspecialchars($cellStyle.';text-align:right',ENT_QUOTES,'UTF-8').'">'.$formatted.'</td></tr>';
    }
    return $html;
}

function pricing_adjustment_staff_provenance(?array $snapshot): string
{
    if (!$snapshot) return '';
    $source=!empty($snapshot['derived_from_snapshot_id'])?'Accepted document terms':match((string)($snapshot['source_type']??'')){
        'project'=>'Project assignment','contract'=>'Contract assignment','document_override'=>'Document override','none'=>'No inherited adjustment',default=>'Pricing snapshot',
    };
    $rate=number_format((float)($snapshot['percentage_rate']??0),4,'.','');
    $rate=rtrim(rtrim($rate,'0'),'.');
    $name=trim((string)($snapshot['adjustment_name']??''));
    $reason=trim((string)($snapshot['override_reason']??''));
    $parts=[$source];
    if($name!=='')$parts[]=$name;
    if($rate!==''&&(float)$rate>0)$parts[]=$rate.'%';
    if($reason!=='')$parts[]='Reason: '.$reason;
    return implode(' - ',$parts);
}
