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

try {
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) throw new DomainException('Invalid item ID.');
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
    if ($itemName === '') throw new DomainException('Item name is required.');
    if ($action === 'update' && $id <= 0) throw new DomainException('Invalid item ID.');
    if ($unitPrice < 0) throw new DomainException('Client price cannot be negative.');
    if (!in_array($entryType, $types, true) || !in_array($billingUnit, $billingUnits, true)) {
        throw new DomainException('Choose valid catalog settings.');
    }
    $components = json_decode((string)($_POST['components_json'] ?? '[]'), true);
    if (!is_array($components)) throw new DomainException('Worker compensation components are invalid.');
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
            if (!$exists->fetchColumn()) throw new DomainException('Catalog item not found.');
        }
    }

    $retained = [];
    foreach (array_values($components) as $order => $component) {
        if (!is_array($component)) continue;
        $componentId = (int)($component['id'] ?? 0);
        $workTypeId = (int)($component['work_type_id'] ?? 0);
        $name = trim((string)($component['name'] ?? ''));
        $method = (string)($component['compensation_method'] ?? 'nonpayable');
        $basis = (string)($component['percentage_basis'] ?? 'net_line');
        $trigger = (string)($component['eligibility_trigger'] ?? 'completed_approved');
        $quantityBehavior = in_array(($component['quantity_behavior'] ?? ''), ['per_line','per_unit','fixed'], true) ? $component['quantity_behavior'] : 'per_line';
        if ($workTypeId <= 0 || $name === '') throw new DomainException('Every work component needs a name and Work Type.');
        if (!in_array($method, $methods, true) || !in_array($basis, $bases, true) || !in_array($trigger, $triggers, true)) throw new DomainException('A work component has an invalid compensation rule.');
        if ($basis === 'cash_collected' && $method === 'percentage' && $trigger !== 'invoice_paid') throw new DomainException('Cash-collected percentages require the invoice-paid trigger.');
        $values = [
            $workTypeId,$name,trim((string)($component['description'] ?? '')) ?: null,$quantityBehavior,
            ($component['fixed_quantity'] ?? '') === '' ? null : max(0,(float)$component['fixed_quantity']),
            ($component['expected_duration_minutes'] ?? '') === '' ? null : max(0,(int)$component['expected_duration_minutes']),
            !empty($component['assignment_required']) ? 1 : 0,$method,
            ($component['compensation_amount'] ?? '') === '' ? null : max(0,(float)$component['compensation_amount']),
            ($component['included_minutes'] ?? '') === '' ? null : max(0,(int)$component['included_minutes']),
            ($component['overage_rate'] ?? '') === '' ? null : max(0,(float)$component['overage_rate']),
            ($component['percentage'] ?? '') === '' ? null : min(100,max(0,(float)$component['percentage'])),
            $basis,$trigger,strtoupper((string)($component['currency'] ?? 'USD')),$order,
        ];
        if ($componentId > 0) {
            $stmt = $pdo->prepare(
                'UPDATE catalog_work_components SET work_type_id=?,name=?,description=?,quantity_behavior=?,fixed_quantity=?,expected_duration_minutes=?,assignment_required=?,compensation_method=?,compensation_amount=?,included_minutes=?,overage_rate=?,percentage=?,percentage_basis=?,eligibility_trigger=?,currency=?,display_order=?,is_active=1 WHERE id=? AND item_library_id=?'
            );
            $stmt->execute(array_merge($values,[$componentId,$id]));
            if ($stmt->rowCount() === 0) {
                $check=$pdo->prepare('SELECT 1 FROM catalog_work_components WHERE id=? AND item_library_id=?');$check->execute([$componentId,$id]);
                if(!$check->fetchColumn()) throw new DomainException('A work component does not belong to this catalog item.');
            }
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO catalog_work_components (item_library_id,work_type_id,name,description,quantity_behavior,fixed_quantity,expected_duration_minutes,assignment_required,compensation_method,compensation_amount,included_minutes,overage_rate,percentage,percentage_basis,eligibility_trigger,currency,display_order)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
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
    $message = $error instanceof DomainException ? $error->getMessage() : 'The catalog item could not be saved.';
    header("Location: {$redirect}&error=" . rawurlencode($message));
    exit;
}
