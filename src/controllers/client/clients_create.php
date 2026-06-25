<?php
// src/controllers/clients_create.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../utils/audit.php';

$__orgId = get_active_org_id() ?: null;
$__creator = (int)($_SESSION['user']['id'] ?? 0) ?: null;

$name = trim($_POST['name'] ?? '');
$email = trim($_POST['email'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$organization_id = (int)($_POST['organization_id'] ?? 0) ?: $__orgId;
$notes = trim($_POST['notes'] ?? '');
$address_line1 = trim($_POST['address_line1'] ?? '');
$address_line2 = trim($_POST['address_line2'] ?? '');
$city = trim($_POST['city'] ?? '');
$state = trim($_POST['state'] ?? '');
if ($state === '') { $state = ($appConfig['primary_state'] ?? 'WI'); }
$postal = trim($_POST['postal'] ?? '');
$country = trim($_POST['country'] ?? '');
if ($country === '') { $country = 'USA'; }

if ($name === '') {
    header('Location: /?page=client/clients-create&error=Name%20is%20required');
    exit;
}

$stmt = $pdo->prepare('INSERT INTO clients (name, email, phone, organization_id, notes, address_line1, address_line2, city, state, postal_code, country, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
$stmt->execute([
  $name,
  $email ?: null,
  $phone ?: null,
  $organization_id > 0 ? $organization_id : null,
  $notes ?: null,
  $address_line1 ?: null,
  $address_line2 ?: null,
  $city ?: null,
  ($state ?: ($appConfig['primary_state'] ?? 'WI')),
  $postal ?: null,
  $country,
  $__creator
]);

$client_id = (int)$pdo->lastInsertId();
audit_log($pdo, 'client.create', 'client', $client_id, ['organization_id' => $organization_id > 0 ? $organization_id : null, 'created_by' => $__creator]);

header('Location: /?page=client/clients-list&created=1');
exit;
