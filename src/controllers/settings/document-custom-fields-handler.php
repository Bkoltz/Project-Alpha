<?php
// src/controllers/settings/document-custom-fields-handler.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/csrf.php';

header('Content-Type: application/json');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    // GET: Fetch single field for editing
    if ($action === 'get' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid field ID']);
            exit;
        }
        
        $stmt = $pdo->prepare('SELECT * FROM document_custom_fields WHERE id = ?');
        $stmt->execute([$id]);
        $field = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$field) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Field not found']);
            exit;
        }
        
        // Decode JSON options if present
        if ($field['field_options']) {
            $field['field_options'] = json_decode($field['field_options'], true);
        }
        
        echo json_encode(['success' => true, 'field' => $field]);
        exit;
    }
    
    // POST actions require CSRF validation
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        exit;
    }
    
    if (!csrf_validate()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'CSRF token invalid']);
        exit;
    }
    
    // CREATE: Add new custom field
    if ($action === 'create') {
        error_log('[custom-fields-handler] CREATE action started');
        error_log('[custom-fields-handler] POST data: ' . json_encode($_POST));
        
        $fieldLabel = trim($_POST['field_label'] ?? '');
        $fieldType = $_POST['field_type'] ?? 'text';
        $fieldOptions = trim($_POST['field_options'] ?? '');
        $isRequired = !empty($_POST['is_required']) ? 1 : 0;
        $documentTypes = $_POST['document_types'] ?? []; // Array of types to apply to
        
        error_log('[custom-fields-handler] documentTypes: ' . json_encode($documentTypes));
        
        if (empty($fieldLabel)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Field label is required']);
            exit;
        }
        
        if (!in_array($fieldType, ['text', 'date', 'number', 'textarea', 'select'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid field type']);
            exit;
        }
        
        if (empty($documentTypes) || !is_array($documentTypes)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'At least one document type must be selected']);
            exit;
        }
        
        // Generate field key from label (lowercase, underscores)
        $fieldKey = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $fieldLabel));
        $fieldKey = trim($fieldKey, '_');
        
        // Parse options for select fields
        $optionsJson = null;
        if ($fieldType === 'select' && !empty($fieldOptions)) {
            $options = array_filter(array_map('trim', explode("\n", $fieldOptions)));
            if (empty($options)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Select fields must have at least one option']);
                exit;
            }
            $optionsJson = json_encode($options);
        }
        
        $pdo->beginTransaction();
        
        try {
            foreach ($documentTypes as $docType) {
                if (!in_array($docType, ['regular', 'long_term', 'on_demand'])) {
                    continue;
                }
                
                // Get max display_order for this doc type
                $stmt = $pdo->prepare('SELECT COALESCE(MAX(display_order), 0) + 1 AS next_order FROM document_custom_fields WHERE document_type = ?');
                $stmt->execute([$docType]);
                $nextOrder = $stmt->fetchColumn();
                
                // Insert field
                $stmt = $pdo->prepare('
                    INSERT INTO document_custom_fields 
                    (document_type, field_key, field_label, field_type, field_options, is_required, is_builtin, is_enabled, display_order)
                    VALUES (?, ?, ?, ?, ?, ?, 0, 1, ?)
                ');
                $stmt->execute([
                    $docType,
                    $fieldKey,
                    $fieldLabel,
                    $fieldType,
                    $optionsJson,
                    $isRequired,
                    $nextOrder
                ]);
            }
            
            $pdo->commit();
            error_log('[custom-fields-handler] Field created successfully');
            echo json_encode(['success' => true, 'message' => 'Field created successfully']);
            exit;
            
        } catch (PDOException $e) {
            $pdo->rollBack();
            
            // Check for duplicate key error
            if ($e->getCode() == 23000) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'A field with this name already exists for one or more document types']);
                exit;
            }
            throw $e;
        }
    }
    
    // UPDATE: Edit existing custom field
    if ($action === 'update') {
        $id = (int)($_POST['field_id'] ?? 0);
        $fieldLabel = trim($_POST['field_label'] ?? '');
        $fieldType = $_POST['field_type'] ?? 'text';
        $fieldOptions = trim($_POST['field_options'] ?? '');
        $isRequired = !empty($_POST['is_required']) ? 1 : 0;
        
        if ($id <= 0 || empty($fieldLabel)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid input']);
            exit;
        }
        
        // Check if field exists and is not built-in
        $stmt = $pdo->prepare('SELECT is_builtin FROM document_custom_fields WHERE id = ?');
        $stmt->execute([$id]);
        $field = $stmt->fetch();
        
        if (!$field) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Field not found']);
            exit;
        }
        
        if ($field['is_builtin']) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Built-in fields cannot be edited']);
            exit;
        }
        
        // Parse options for select fields
        $optionsJson = null;
        if ($fieldType === 'select' && !empty($fieldOptions)) {
            $options = array_filter(array_map('trim', explode("\n", $fieldOptions)));
            if (empty($options)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Select fields must have at least one option']);
                exit;
            }
            $optionsJson = json_encode($options);
        }
        
        $stmt = $pdo->prepare('
            UPDATE document_custom_fields 
            SET field_label = ?, field_type = ?, field_options = ?, is_required = ?
            WHERE id = ?
        ');
        $stmt->execute([$fieldLabel, $fieldType, $optionsJson, $isRequired, $id]);
        
        echo json_encode(['success' => true, 'message' => 'Field updated successfully']);
        exit;
    }
    
    // DELETE: Remove custom field
    if ($action === 'delete') {
        $id = (int)($_POST['field_id'] ?? 0);
        
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid field ID']);
            exit;
        }
        
        // Check if field is built-in
        $stmt = $pdo->prepare('SELECT is_builtin FROM document_custom_fields WHERE id = ?');
        $stmt->execute([$id]);
        $field = $stmt->fetch();
        
        if (!$field) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Field not found']);
            exit;
        }
        
        if ($field['is_builtin']) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Built-in fields cannot be deleted']);
            exit;
        }
        
        $stmt = $pdo->prepare('DELETE FROM document_custom_fields WHERE id = ?');
        $stmt->execute([$id]);
        
        echo json_encode(['success' => true, 'message' => 'Field deleted successfully']);
        exit;
    }
    
    // TOGGLE: Enable/disable field
    if ($action === 'toggle') {
        $id = (int)($_POST['field_id'] ?? 0);
        $isEnabled = !empty($_POST['is_enabled']) ? 1 : 0;
        
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid field ID']);
            exit;
        }
        
        $stmt = $pdo->prepare('UPDATE document_custom_fields SET is_enabled = ? WHERE id = ?');
        $stmt->execute([$isEnabled, $id]);
        
        echo json_encode(['success' => true, 'message' => 'Field toggled successfully']);
        exit;
    }
    
    // REORDER: Update display_order for fields
    if ($action === 'reorder') {
        $order = json_decode($_POST['order'] ?? '[]', true);
        
        if (!is_array($order) || empty($order)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid order data']);
            exit;
        }
        
        $pdo->beginTransaction();
        
        try {
            $stmt = $pdo->prepare('UPDATE document_custom_fields SET display_order = ? WHERE id = ?');
            
            foreach ($order as $item) {
                $id = (int)($item['id'] ?? 0);
                $displayOrder = (int)($item['order'] ?? 0);
                
                if ($id > 0) {
                    $stmt->execute([$displayOrder, $id]);
                }
            }
            
            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Order updated successfully']);
            exit;
            
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
    
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
    
} catch (Exception $e) {
    error_log('Custom fields handler error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error occurred']);
}
