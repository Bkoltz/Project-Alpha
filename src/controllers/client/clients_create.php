<?php
// src/controllers/clients_create.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';

$name = trim($_POST['name'] ?? '');
$email = trim($_POST['email'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$organization = trim($_POST['organization'] ?? '');
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

$stmt = $pdo->prepare('INSERT INTO clients (name, email, phone, organization, notes, address_line1, address_line2, city, state, postal, country) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
$stmt->execute([
  $name,
  $email ?: null,
  $phone ?: null,
  $organization ?: null,
  $notes ?: null,
  $address_line1 ?: null,
  $address_line2 ?: null,
  $city ?: null,
  ($state ?: ($appConfig['primary_state'] ?? 'WI')),
  $postal ?: null,
  $country
]);

header('Location: /?page=client/clients-list&created=1');
exit;
