<?php
// src/controllers/clients_update.php
require_once __DIR__ . '/../config/db.php';

$id = (int)($_POST['id'] ?? 0);
$name = trim($_POST['name'] ?? '');
$email = trim($_POST['email'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$organization = trim($_POST['organization'] ?? '');
$notes = trim($_POST['notes'] ?? '');
$address_line1 = trim($_POST['address_line1'] ?? '');
$address_line2 = trim($_POST['address_line2'] ?? '');
$city = trim($_POST['city'] ?? '');
$state = trim($_POST['state'] ?? '');
$postal = trim($_POST['postal'] ?? '');
$country = trim($_POST['country'] ?? '');

if ($id <= 0 || $name === '') {
  header('Location: /?page=clients-edit&id='.(int)$id.'&error=Invalid%20input');
  exit;
}

$st = $pdo->prepare('UPDATE clients SET name=?, email=?, phone=?, organization=?, notes=?, address_line1=?, address_line2=?, city=?, state=?, postal=?, country=? WHERE id=?');
$st->execute([
  $name,
  $email ?: null,
  $phone ?: null,
  $organization ?: null,
  $notes ?: null,
  $address_line1 ?: null,
  $address_line2 ?: null,
  $city ?: null,
  ($state ?: 'WI'),
  $postal ?: null,
  $country ?: null,
  $id
]);

header('Location: /?page=clients-list&updated=1');
exit;
