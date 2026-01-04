<?php
// src/controllers/settings/custom_fields_handler.php
require_once __DIR__ . '/../../config/db.php';

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// GET request - Fetch field data
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'get') {
    header('Content-Type: application/json');
    $fieldId = (int)($_GET['id'] ?? 0);
    
    if ($fieldId <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid field ID']);
        exit;
    }
    
    try {
        $stmt = $pdo->prepare('SELECT * FROM document_custom_fields WHERE id = ?');
        $stmt->execute([$fieldId]);
        $field = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$field) {
            echo json_encode(['success' => false, 'error' => 'Field not found']);
            exit;
        }
        
        echo json_encode(['success' => true, 'field' => $field]);
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// POST request - Handle JSON reorder
if ($_SERVER['REQUEST_METHOD'] === 'POST' && strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
    header('Content-Type: application/json');
    $data = json_decode(file_get_contents('php://input'), true);
    
    if ($data['action'] === 'reorder' && isset($data['order'])) {
        try {
            $pdo->beginTransaction();
            
            foreach ($data['order'] as $item) {
                $stmt = $pdo->prepare('UPDATE document_custom_fields SET display_order = ? WHERE id = ?');
                $stmt->execute([$item['order'], $item['id']]);
            }
            
            $pdo->commit();
            echo json_encode(['success' => true]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }
    exit;
}

// POST request - Handle form actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    try {
        if ($action === 'create') {
            // Create new custom field
            $fieldLabel = trim($_POST['field_label'] ?? '');
            $fieldDataType = $_POST['field_data_type'] ?? 'text';
            $isRequired = isset($_POST['is_required']) ? 1 : 0;
            $defaultValue = trim($_POST['default_value'] ?? '') ?: null;
            $minValue = null;
            $maxValue = null;
            
            // Only set min/max for number fields
            if ($fieldDataType === 'number') {
                $minValue = isset($_POST['min_value']) && $_POST['min_value'] !== '' ? (float)$_POST['min_value'] : null;
                $maxValue = isset($_POST['max_value']) && $_POST['max_value'] !== '' ? (float)$_POST['max_value'] : null;
            }
            
            // Build field_type from checkboxes (comma-separated list)
            $types = [];
            if (!empty($_POST['include_quote'])) $types[] = 'quote';
            if (!empty($_POST['include_contract'])) $types[] = 'contract';
            if (!empty($_POST['include_invoice'])) $types[] = 'invoice';
            $fieldType = implode(',', $types);
            
            if (empty($fieldLabel)) {
                header('Location: /?page=settings&tab=documents&doc_tab=customization&error=' . urlencode('Field label is required'));
                exit;
            }
            
            if (empty($fieldType)) {
                header('Location: /?page=settings&tab=documents&doc_tab=customization&error=' . urlencode('Must select at least one document type'));
                exit;
            }
            
            // Validate data type
            if (!in_array($fieldDataType, ['text', 'textarea', 'date', 'number'])) {
                $fieldDataType = 'text';
            }
            
            // Generate field key from label
            $fieldKey = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $fieldLabel));
            $fieldKey = trim($fieldKey, '_');
            
            // Get max display order
            $maxOrder = (int)$pdo->query('SELECT COALESCE(MAX(display_order), 0) FROM document_custom_fields')->fetchColumn();
            
            $stmt = $pdo->prepare('INSERT INTO document_custom_fields (field_type, field_key, field_label, field_data_type, default_value, min_value, max_value, is_builtin, is_required, display_order) VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, ?)');
            $stmt->execute([$fieldType, $fieldKey, $fieldLabel, $fieldDataType, $defaultValue, $minValue, $maxValue, $isRequired, $maxOrder + 1]);
            
            header('Location: /?page=settings&tab=documents&doc_tab=customization&saved=1');
            exit;
            
        } elseif ($action === 'update') {
            // Update existing field
            $fieldId = (int)($_POST['field_id'] ?? 0);
            $fieldLabel = trim($_POST['field_label'] ?? '');
            $fieldDataType = $_POST['field_data_type'] ?? 'text';
            $isRequired = isset($_POST['is_required']) ? 1 : 0;
            $defaultValue = trim($_POST['default_value'] ?? '') ?: null;
            $minValue = null;
            $maxValue = null;
            
            // Only set min/max for number fields
            if ($fieldDataType === 'number') {
                $minValue = isset($_POST['min_value']) && $_POST['min_value'] !== '' ? (float)$_POST['min_value'] : null;
                $maxValue = isset($_POST['max_value']) && $_POST['max_value'] !== '' ? (float)$_POST['max_value'] : null;
            }
            
            // Build field_type from checkboxes
            $types = [];
            if (!empty($_POST['include_quote'])) $types[] = 'quote';
            if (!empty($_POST['include_contract'])) $types[] = 'contract';
            if (!empty($_POST['include_invoice'])) $types[] = 'invoice';
            $fieldType = implode(',', $types);
            
            if ($fieldId <= 0 || empty($fieldLabel)) {
                header('Location: /?page=settings&tab=documents&doc_tab=customization&error=' . urlencode('Invalid input'));
                exit;
            }
            
            if (empty($fieldType)) {
                header('Location: /?page=settings&tab=documents&doc_tab=customization&error=' . urlencode('Must select at least one document type'));
                exit;
            }
            
            // Validate data type
            if (!in_array($fieldDataType, ['text', 'textarea', 'date', 'number'])) {
                $fieldDataType = 'text';
            }
            
            // Update field (don't change field_key to preserve data integrity)
            $stmt = $pdo->prepare('UPDATE document_custom_fields SET field_label = ?, field_data_type = ?, default_value = ?, min_value = ?, max_value = ?, is_required = ?, field_type = ? WHERE id = ?');
            $stmt->execute([$fieldLabel, $fieldDataType, $defaultValue, $minValue, $maxValue, $isRequired, $fieldType, $fieldId]);
            
            header('Location: /?page=settings&tab=documents&doc_tab=customization&saved=1');
            exit;
            
        } elseif ($action === 'delete') {
            // Delete custom field (only non-builtin)
            $fieldId = (int)($_POST['field_id'] ?? 0);
            
            if ($fieldId <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid field ID']);
                exit;
            }
            
            // Check if builtin
            $stmt = $pdo->prepare('SELECT is_builtin FROM document_custom_fields WHERE id = ?');
            $stmt->execute([$fieldId]);
            $field = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($field && $field['is_builtin']) {
                echo json_encode(['success' => false, 'message' => 'Cannot delete built-in fields']);
                exit;
            }
            
            $stmt = $pdo->prepare('DELETE FROM document_custom_fields WHERE id = ? AND is_builtin = 0');
            $stmt->execute([$fieldId]);
            
            echo json_encode(['success' => true]);
            exit;
        }
        
    } catch (Throwable $e) {
        @error_log('[custom_fields_handler] Error: ' . $e->getMessage());
        if ($action === 'delete') {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        } else {
            header('Location: /?page=settings&tab=documents&doc_tab=customization&error=' . urlencode('An error occurred'));
        }
        exit;
    }
}

// Invalid request
header('Location: /?page=settings&tab=documents&doc_tab=customization');
exit;
