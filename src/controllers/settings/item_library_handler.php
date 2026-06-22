<?php
// src/controllers/settings/item_library_handler.php
require_once __DIR__ . '/../../config/db.php';

$action = $_POST['action'] ?? '';
$redirect = '/?page=settings&tab=item-library';

try {
    switch ($action) {
        case 'create':
            $itemName = trim($_POST['item_name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $unitPrice = (float)($_POST['unit_price'] ?? 0);
            $isActive = isset($_POST['is_active']) ? 1 : 0;
            $isHourly = isset($_POST['is_hourly']) ? 1 : 0;
            $category = $isHourly ? 'Hourly' : null;

            if (empty($itemName)) {
                throw new Exception('Item name is required');
            }

            $stmt = $pdo->prepare('INSERT INTO item_library (item_name, description, unit_price, is_active, category) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$itemName, $description ?: null, $unitPrice, $isActive, $category]);

            header("Location: {$redirect}&created=1");
            exit;

        case 'update':
            $id = (int)($_POST['id'] ?? 0);
            $itemName = trim($_POST['item_name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $unitPrice = (float)($_POST['unit_price'] ?? 0);
            $isActive = isset($_POST['is_active']) ? 1 : 0;
            $isHourly = isset($_POST['is_hourly']) ? 1 : 0;

            if ($id <= 0) {
                throw new Exception('Invalid item ID');
            }

            if (empty($itemName)) {
                throw new Exception('Item name is required');
            }

            // Preserve any existing non-Hourly category when the hourly flag is unchecked
            $stmt = $pdo->prepare('SELECT category FROM item_library WHERE id=?');
            $stmt->execute([$id]);
            $existingCategory = $stmt->fetchColumn();
            $category = $isHourly ? 'Hourly' : ($existingCategory === 'Hourly' ? null : $existingCategory);

            $stmt = $pdo->prepare('UPDATE item_library SET item_name=?, description=?, unit_price=?, is_active=?, category=? WHERE id=?');
            $stmt->execute([$itemName, $description ?: null, $unitPrice, $isActive, $category, $id]);

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
