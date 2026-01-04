<?php
// src/controllers/settings/item_library_handler.php
require_once __DIR__ . '/../../config/db.php';

$action = $_POST['action'] ?? '';
$redirect = '/?page=settings/item-library';

try {
    switch ($action) {
        case 'create':
            $itemName = trim($_POST['item_name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $unitPrice = (float)($_POST['unit_price'] ?? 0);
            $isActive = isset($_POST['is_active']) ? 1 : 0;

            if (empty($itemName)) {
                throw new Exception('Item name is required');
            }

            $stmt = $pdo->prepare('INSERT INTO item_library (item_name, description, unit_price, is_active) VALUES (?, ?, ?, ?)');
            $stmt->execute([$itemName, $description ?: null, $unitPrice, $isActive]);

            header("Location: {$redirect}&created=1");
            exit;

        case 'update':
            $id = (int)($_POST['id'] ?? 0);
            $itemName = trim($_POST['item_name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $unitPrice = (float)($_POST['unit_price'] ?? 0);
            $isActive = isset($_POST['is_active']) ? 1 : 0;

            if ($id <= 0) {
                throw new Exception('Invalid item ID');
            }

            if (empty($itemName)) {
                throw new Exception('Item name is required');
            }

            $stmt = $pdo->prepare('UPDATE item_library SET item_name=?, description=?, unit_price=?, is_active=? WHERE id=?');
            $stmt->execute([$itemName, $description ?: null, $unitPrice, $isActive, $id]);

            header("Location: {$redirect}&updated=1");
            exit;

        case 'delete':
            $id = (int)($_POST['id'] ?? 0);

            if ($id <= 0) {
                throw new Exception('Invalid item ID');
            }

            $stmt = $pdo->prepare('DELETE FROM item_library WHERE id=?');
            $stmt->execute([$id]);

            header("Location: {$redirect}&deleted=1");
            exit;

        default:
            throw new Exception('Invalid action');
    }
} catch (Exception $e) {
    $error = urlencode($e->getMessage());
    header("Location: {$redirect}&error={$error}");
    exit;
}
