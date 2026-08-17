<?php
// src/controllers/clients_purge.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/portal_projection_hooks.php';

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
  header('Location: /?page=client/clients-list&error=Invalid%20client');
  exit;
}

try {
  $projection=new App\Services\PortalProjectionMutationService();portal_projection_mutate($pdo,static fn():array=>$projection->lockedClientScopes($pdo,$id),static function()use($pdo,$id):void{$pdo->prepare('DELETE FROM clients WHERE id=?')->execute([$id]);},static fn():array=>[]);
  header('Location: /?page=client/clients-list&deleted=1');
  exit;
} catch (Throwable $e) {
  header('Location: /?page=client/clients-edit&id='.$id.'&error=Delete%20failed');
  exit;
}
