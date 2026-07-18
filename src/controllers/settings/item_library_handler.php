<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/csrf.php';

$redirect = '/?page=settings&tab=item-library';
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !csrf_validate()) {
    http_response_code(403);
    header("Location: {$redirect}&error=" . rawurlencode('The request could not be verified.'));
    exit;
}

$action = (string)($_POST['action'] ?? '');
$types = ['service','fee','bundle'];
$billingUnits = ['each','hour','day','mile','project'];
$methods = ['nonpayable','hourly','fixed','base_overage','percentage'];
$bases = ['gross_line','net_line','cash_collected'];
$triggers = ['completed_approved','delivered','invoice_paid','manual_release'];
$clientBillingTreatments = ['hourly','fixed_price_included','base_overage','internal'];
$userId = (int)($_SESSION['user']['id'] ?? 0);

$uniqueWorkActivityCode = static function (PDO $pdo, string $name): string {
    $base = trim(strtoupper((string)preg_replace('/[^A-Za-z0-9]+/', '_', $name)), '_');
    $base = substr($base !== '' ? $base : 'WORK_ACTIVITY', 0, 54);
    $candidate = $base;
    $suffix = 1;
    $check = $pdo->prepare('SELECT 1 FROM work_types WHERE code=?');
    while (true) {
        $check->execute([$candidate]);
        if (!$check->fetchColumn()) return $candidate;
        $candidate = substr($base, 0, 54) . '_' . ++$suffix;
    }
};

try {
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) throw new DomainException('Invalid service ID.');
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE item_library SET is_active=0 WHERE id=?')->execute([$id]);
        $pdo->prepare('UPDATE catalog_work_components SET is_active=0 WHERE item_library_id=?')->execute([$id]);
        $pdo->commit();
        header("Location: {$redirect}&deleted=1");
        exit;
    }
    if (!in_array($action, ['create','update'], true)) throw new DomainException('Invalid action.');

    $id = (int)($_POST['id'] ?? 0);
    $itemName = trim((string)($_POST['item_name'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $unitPrice = round((float)($_POST['unit_price'] ?? 0), 2);
    $entryType = (string)($_POST['entry_type'] ?? 'service');
    $billingUnit = (string)($_POST['billing_unit'] ?? 'each');
    $fulfillmentNotes = trim((string)($_POST['fulfillment_notes'] ?? ''));
    $isActive = isset($_POST['is_active']) ? 1 : 0;
    if ($itemName === '') throw new DomainException('Service name is required.');
    if ($action === 'update' && $id <= 0) throw new DomainException('Invalid service ID.');
    if ($unitPrice < 0) throw new DomainException('Client price cannot be negative.');
    if (!in_array($entryType, $types, true) || !in_array($billingUnit, $billingUnits, true)) {
        throw new DomainException('Choose valid service settings.');
    }
    $components = json_decode((string)($_POST['components_json'] ?? '[]'), true);
    if (!is_array($components)) throw new DomainException('Work Activity settings are invalid.');
    $bundleItems = json_decode((string)($_POST['bundle_items_json'] ?? '[]'), true);
    if (!is_array($bundleItems)) throw new DomainException('Bundle contents are invalid.');

    $pdo->beginTransaction();
    if ($action === 'create') {
        $stmt = $pdo->prepare(
            'INSERT INTO item_library (item_name,description,entry_type,unit_price,billing_unit,tax_behavior,fulfillment_notes,category,is_active)
             VALUES (?,?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([$itemName,$description ?: null,$entryType,$unitPrice,$billingUnit,'inherit',$fulfillmentNotes ?: null,$billingUnit === 'hour' ? 'Hourly' : null,$isActive]);
        $id = (int)$pdo->lastInsertId();
    } else {
        $stmt = $pdo->prepare(
            'UPDATE item_library SET item_name=?,description=?,entry_type=?,unit_price=?,billing_unit=?,tax_behavior=?,fulfillment_notes=?,category=?,is_active=? WHERE id=?'
        );
        $stmt->execute([$itemName,$description ?: null,$entryType,$unitPrice,$billingUnit,'inherit',$fulfillmentNotes ?: null,$billingUnit === 'hour' ? 'Hourly' : null,$isActive,$id]);
        if ($stmt->rowCount() === 0) {
            $exists = $pdo->prepare('SELECT 1 FROM item_library WHERE id=?'); $exists->execute([$id]);
            if (!$exists->fetchColumn()) throw new DomainException('Service not found.');
        }
    }

    $retained = [];
    foreach (array_values($components) as $order => $component) {
        if (!is_array($component)) continue;
        $componentId = (int)($component['id'] ?? 0);
        $requestedWorkType = (string)($component['work_type_id'] ?? '');
        $workTypeId = ctype_digit($requestedWorkType) ? (int)$requestedWorkType : 0;
        $name = trim((string)($component['name'] ?? ''));
        $clientBillingTreatment = (string)($component['client_billing_treatment'] ?? 'fixed_price_included');
        $clientBillingRate = ($component['client_billing_rate'] ?? '') === '' ? null : (float)$component['client_billing_rate'];
        $clientIncludedMinutes = ($component['client_included_minutes'] ?? '') === '' ? null : (int)$component['client_included_minutes'];
        $clientOverageRate = ($component['client_overage_rate'] ?? '') === '' ? null : (float)$component['client_overage_rate'];
        $clientBillingCurrency = strtoupper(trim((string)($component['client_billing_currency'] ?? 'USD')));
        $method = (string)($component['compensation_method'] ?? 'nonpayable');
        $basis = (string)($component['percentage_basis'] ?? 'net_line');
        $trigger = (string)($component['eligibility_trigger'] ?? 'completed_approved');
        $quantityBehavior = in_array(($component['quantity_behavior'] ?? ''), ['per_line','per_unit','fixed'], true) ? $component['quantity_behavior'] : 'per_line';
        if ($name === '') throw new DomainException('Every service activity needs a name.');
        if ($workTypeId <= 0 && $requestedWorkType !== 'new') throw new DomainException('Choose an existing Work Activity or create a matching one.');
        if (!in_array($clientBillingTreatment, $clientBillingTreatments, true)) throw new DomainException('A service activity has an invalid client billing treatment.');
        if (!preg_match('/^[A-Z]{3}$/', $clientBillingCurrency)) throw new DomainException('Client billing currency must use a three-letter code.');
        foreach ([$clientBillingRate,$clientOverageRate] as $clientRate) if ($clientRate !== null && $clientRate < 0) throw new DomainException('Client billing rates cannot be negative.');
        if ($clientIncludedMinutes !== null && $clientIncludedMinutes < 0) throw new DomainException('Included client minutes cannot be negative.');
        if ($clientBillingTreatment === 'base_overage' && ($clientIncludedMinutes === null || $clientOverageRate === null)) throw new DomainException('Base-plus-overage client billing requires included minutes and an hourly overage rate.');
        if ($clientBillingTreatment !== 'hourly') $clientBillingRate = null;
        if ($clientBillingTreatment !== 'base_overage') { $clientIncludedMinutes = null; $clientOverageRate = null; }
        if (!in_array($clientBillingTreatment, ['hourly','base_overage'], true)) $clientBillingCurrency = 'USD';
        if (!in_array($method, $methods, true) || !in_array($basis, $bases, true) || !in_array($trigger, $triggers, true)) throw new DomainException('A Work Activity has an invalid compensation rule.');
        if ($basis === 'cash_collected' && $method === 'percentage' && $trigger !== 'invoice_paid') throw new DomainException('Cash-collected percentages require the invoice-paid trigger.');

        if ($requestedWorkType === 'new') {
            $existingActivity = $pdo->prepare('SELECT id FROM work_types WHERE LOWER(name)=LOWER(?) ORDER BY is_active DESC,id LIMIT 1');
            $existingActivity->execute([$name]);
            $workTypeId = (int)($existingActivity->fetchColumn() ?: 0);
            if ($workTypeId > 0) {
                $pdo->prepare('UPDATE work_types SET is_active=1 WHERE id=?')->execute([$workTypeId]);
            } else {
                $code = $uniqueWorkActivityCode($pdo, $name);
                $pdo->prepare(
                    'INSERT INTO work_types (name,code,description,is_active,default_compensation_method,default_amount,default_base_minutes,default_overage_rate,default_percentage,default_percentage_basis,default_eligibility_trigger,currency,created_by)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
                )->execute([
                    $name,$code,null,1,$method,
                    ($component['compensation_amount'] ?? '') === '' ? null : max(0,(float)$component['compensation_amount']),
                    ($component['included_minutes'] ?? '') === '' ? null : max(0,(int)$component['included_minutes']),
                    ($component['overage_rate'] ?? '') === '' ? null : max(0,(float)$component['overage_rate']),
                    ($component['percentage'] ?? '') === '' ? null : min(100,max(0,(float)$component['percentage'])),
                    $basis,$trigger,strtoupper((string)($component['currency'] ?? 'USD')),$userId ?: null,
                ]);
                $workTypeId = (int)$pdo->lastInsertId();
                $workTypeTreatment = $clientBillingTreatment === 'base_overage' ? 'fixed_price_included' : $clientBillingTreatment;
                $pdo->prepare('INSERT INTO work_type_billing_defaults (work_type_id,default_treatment,default_billing_rate,currency,created_by,updated_by) VALUES (?,?,?,?,?,?)')
                    ->execute([$workTypeId,$workTypeTreatment,$clientBillingRate,$clientBillingCurrency,$userId ?: null,$userId ?: null]);
            }
        }
        $values = [
            $workTypeId,$name,trim((string)($component['description'] ?? '')) ?: null,$quantityBehavior,
            ($component['fixed_quantity'] ?? '') === '' ? null : max(0,(float)$component['fixed_quantity']),
            ($component['expected_duration_minutes'] ?? '') === '' ? null : max(0,(int)$component['expected_duration_minutes']),
            !empty($component['assignment_required']) ? 1 : 0,
            $clientBillingTreatment,$clientBillingRate,$clientIncludedMinutes,$clientOverageRate,$clientBillingCurrency,$method,
            ($component['compensation_amount'] ?? '') === '' ? null : max(0,(float)$component['compensation_amount']),
            ($component['included_minutes'] ?? '') === '' ? null : max(0,(int)$component['included_minutes']),
            ($component['overage_rate'] ?? '') === '' ? null : max(0,(float)$component['overage_rate']),
            ($component['percentage'] ?? '') === '' ? null : min(100,max(0,(float)$component['percentage'])),
            $basis,$trigger,strtoupper((string)($component['currency'] ?? 'USD')),$order,
        ];
        if ($componentId > 0) {
            $stmt = $pdo->prepare(
                'UPDATE catalog_work_components SET work_type_id=?,name=?,description=?,quantity_behavior=?,fixed_quantity=?,expected_duration_minutes=?,assignment_required=?,client_billing_treatment=?,client_billing_rate=?,client_included_minutes=?,client_overage_rate=?,client_billing_currency=?,compensation_method=?,compensation_amount=?,included_minutes=?,overage_rate=?,percentage=?,percentage_basis=?,eligibility_trigger=?,currency=?,display_order=?,is_active=1 WHERE id=? AND item_library_id=?'
            );
            $stmt->execute(array_merge($values,[$componentId,$id]));
            if ($stmt->rowCount() === 0) {
                $check=$pdo->prepare('SELECT 1 FROM catalog_work_components WHERE id=? AND item_library_id=?');$check->execute([$componentId,$id]);
                if(!$check->fetchColumn()) throw new DomainException('A Work Activity does not belong to this service.');
            }
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO catalog_work_components (item_library_id,work_type_id,name,description,quantity_behavior,fixed_quantity,expected_duration_minutes,assignment_required,client_billing_treatment,client_billing_rate,client_included_minutes,client_overage_rate,client_billing_currency,compensation_method,compensation_amount,included_minutes,overage_rate,percentage,percentage_basis,eligibility_trigger,currency,display_order)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            $stmt->execute(array_merge([$id],$values));
            $componentId = (int)$pdo->lastInsertId();
        }
        $retained[] = $componentId;
    }
    $sql = 'UPDATE catalog_work_components SET is_active=0 WHERE item_library_id=?';
    $params = [$id];
    if ($retained) {
        $sql .= ' AND id NOT IN (' . implode(',',array_fill(0,count($retained),'?')) . ')';
        $params = array_merge($params,$retained);
    }
    $pdo->prepare($sql)->execute($params);
    $pdo->prepare('DELETE FROM catalog_bundle_items WHERE bundle_item_library_id=?')->execute([$id]);
    if ($entryType === 'bundle') {
        $bundleInsert = $pdo->prepare('INSERT INTO catalog_bundle_items (bundle_item_library_id,child_item_library_id,quantity,display_order) VALUES (?,?,?,?)');
        foreach (array_values($bundleItems) as $order => $bundleItem) {
            $childId = (int)($bundleItem['item_library_id'] ?? 0);
            $childQuantity = (float)($bundleItem['quantity'] ?? 0);
            if ($childId <= 0 || $childId === $id || $childQuantity <= 0) throw new DomainException('Choose valid bundle contents and quantities.');
            $child = $pdo->prepare("SELECT 1 FROM item_library WHERE id=? AND is_active=1 AND entry_type<>'bundle'");
            $child->execute([$childId]);
            if (!$child->fetchColumn()) throw new DomainException('Packages may contain active services or fees, but not another package.');
            $bundleInsert->execute([$id,$childId,$childQuantity,$order]);
        }
    }
    $pdo->commit();
    header("Location: {$redirect}&" . ($action === 'create' ? 'created' : 'updated') . '=1');
    exit;
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $message = $error instanceof DomainException ? $error->getMessage() : 'The service could not be saved.';
    header("Location: {$redirect}&error=" . rawurlencode($message));
    exit;
}
