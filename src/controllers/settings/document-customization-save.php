<?php
// src/controllers/settings/document-customization-save.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/csrf.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

if (!csrf_validate()) {
    http_response_code(403);
    exit('CSRF token invalid');
}

try {
    $pdo->beginTransaction();
    
    // Process settings for each document type
    $documentTypes = ['regular', 'long_term', 'on_demand'];
    
    foreach ($documentTypes as $type) {
        $settings = [];
        
        // Get the posted settings for this document type
        $postedSettings = $_POST[$type] ?? [];
        
        // Define all possible settings for each type
        $allSettings = [
            'regular' => ['show_deposit', 'show_fulfillment_date', 'show_scope'],
            'long_term' => ['show_deposit', 'show_fulfillment_date', 'show_scope', 'show_billing_settings'],
            'on_demand' => ['show_deposit', 'show_fulfillment_date', 'show_scope', 'show_billing_settings']
        ];
        
        // Build settings array - unchecked checkboxes won't be in $_POST
        foreach ($allSettings[$type] as $setting) {
            $settings[$setting] = isset($postedSettings[$setting]) && $postedSettings[$setting] == '1';
        }
        
        // Save to database as JSON
        $settingsJson = json_encode($settings);
        
        $stmt = $pdo->prepare('
            INSERT INTO document_settings (document_type, settings, updated_at) 
            VALUES (?, ?, NOW())
            ON DUPLICATE KEY UPDATE settings = VALUES(settings), updated_at = NOW()
        ');
        $stmt->execute([$type, $settingsJson]);
    }
    
    $pdo->commit();
    
    header('Location: /?page=settings&tab=documents&doc_tab=customization&saved=1');
    exit;
    
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    error_log('Document customization save error: ' . $e->getMessage());
    header('Location: /?page=settings&tab=documents&doc_tab=customization&error=' . urlencode('Failed to save settings'));
    exit;
}
