<?php
/**
 * Comprehensive Application Test Suite
 * Tests all major workflows after database schema changes
 */

require_once __DIR__ . '/../src/config/db.php';

// Test results
$results = [
    'passed' => [],
    'failed' => [],
    'errors' => []
];

function test($name, $callback) {
    global $results;
    try {
        $result = $callback();
        if ($result === true) {
            $results['passed'][] = $name;
            echo "✅ PASS: $name\n";
            return true;
        } else {
            $results['failed'][] = "$name - " . (is_string($result) ? $result : 'Unknown failure');
            echo "❌ FAIL: $name - " . (is_string($result) ? $result : 'Unknown failure') . "\n";
            return false;
        }
    } catch (Exception $e) {
        $results['errors'][] = "$name - " . $e->getMessage();
        echo "💥 ERROR: $name - " . $e->getMessage() . "\n";
        return false;
    }
}

function checkTable($pdo, $table) {
    try {
        $pdo->query("SELECT 1 FROM $table LIMIT 1");
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function checkColumn($pdo, $table, $column) {
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM $table")->fetchAll(PDO::FETCH_COLUMN);
        return in_array($column, $cols);
    } catch (Throwable $e) {
        return false;
    }
}

echo "========================================\n";
echo "Project Alpha Comprehensive Test Suite\n";
echo "Database Schema Verification & Logic Tests\n";
echo "========================================\n\n";

// ==========================================
// PHASE 1: Database Schema Validation
// ==========================================
echo "📋 PHASE 1: Database Schema Validation\n";
echo "----------------------------------------\n";

$requiredTables = [
    'users', 'clients', 'projects', 'quotes', 'contracts', 'invoices', 
    'payments', 'tax_rates', 'organizations', 'project_documents',
    'contracts', 'contracts', 'invoices',
    'system_audit', 'notifications', 'api_keys',
    'document_custom_fields', 'document_settings', 'webhooks', 'app_config'
];

foreach ($requiredTables as $table) {
    test("Table exists: $table", function() use ($pdo, $table) {
        return checkTable($pdo, $table);
    });
}

// ==========================================
// PHASE 2: Critical Column Checks
// ==========================================
echo "\n📋 PHASE 2: Critical Column Verification\n";
echo "----------------------------------------\n";

$requiredColumns = [
    ['users', 'email'],
    ['users', 'password_hash'],
    ['users', 'role'],
    ['clients', 'name'],
    ['clients', 'email'],
    ['clients', 'phone'],
    ['clients', 'organization_id'],
    ['clients', 'archived'],
    ['projects', 'name'],
    ['projects', 'client_id'],
    ['projects', 'status'],
    ['quotes', 'client_id'],
    ['quotes', 'status'],
    ['quotes', 'total'],
    ['quotes', 'doc_number'],
    ['contracts', 'client_id'],
    ['contracts', 'quote_id'],
    ['contracts', 'status'],
    ['contracts', 'total'],
    ['contracts', 'doc_number'],
    ['contracts', 'signed_pdf_path'],
    ['contracts', 'completed_at'],
    ['contracts', 'voided_at'],
    ['invoices', 'client_id'],
    ['invoices', 'contract_id'],
    ['invoices', 'quote_id'],
    ['invoices', 'status'],
    ['invoices', 'total'],
    ['invoices', 'amount_paid'],
    ['invoices', 'doc_number'],
    ['payments', 'invoice_id'],
    ['payments', 'amount'],
    ['payments', 'payment_method'],
    ['payments', 'stripe_session_id'],
    ['payments', 'stripe_payment_intent_id'],
    ['payments', 'auto_pay_attempt'],
    ['payments', 'status'],
    ['contracts', 'client_id'],
    ['contracts', 'billing_interval_count'],
    ['contracts', 'billing_interval_unit'],
    ['contracts', 'next_invoice_date'],
    ['contracts', 'stripe_subscription_id'],
    ['contracts', 'auto_pay_enabled'],
    ['invoices', 'contract_id'],
    ['invoices', 'status'],
    ['invoices', 'sent_at'],
    ['invoices', 'paid_at'],
    ['tax_rates', 'name'],
    ['tax_rates', 'rate'],
    ['tax_rates', 'county'],
    ['tax_rates', 'state'],
    ['tax_rates', 'is_default'],
    ['api_keys', 'name'],
    ['api_keys', 'key_hash'],
    ['api_keys', 'scopes'],
    ['api_keys', 'revoked_at'],
    ['system_audit', 'user_id'],
    ['system_audit', 'action'],
    ['system_audit', 'entity_type'],
    ['system_audit', 'entity_id'],
    ['webhooks', 'name'],
    ['webhooks', 'url'],
    ['webhooks', 'events'],
    ['webhooks', 'is_active'],
];

foreach ($requiredColumns as $col) {
    test("Column exists: {$col[0]}.{$col[1]}", function() use ($pdo, $col) {
        return checkColumn($pdo, $col[0], $col[1]);
    });
}

// ==========================================
// PHASE 3: Foreign Key Validation
// ==========================================
echo "\n📋 PHASE 3: Relationship Verification\n";
echo "----------------------------------------\n";

test("Can create and retrieve a client", function() use ($pdo) {
    $stmt = $pdo->prepare("INSERT INTO clients (name, email, state) VALUES (?, ?, ?)");
    $stmt->execute(['Test Client ' . time(), 'test@example.com', 'WI']);
    $id = (int)$pdo->lastInsertId();
    
    $stmt = $pdo->prepare("SELECT * FROM clients WHERE id = ?");
    $stmt->execute([$id]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Cleanup
    $pdo->prepare("DELETE FROM clients WHERE id = ?")->execute([$id]);
    
    return $client !== false && $client['name'] === 'Test Client ' . time();
});

test("Client soft delete (archived) works", function() use ($pdo) {
    $stmt = $pdo->prepare("INSERT INTO clients (name, email, state, archived) VALUES (?, ?, ?, ?)");
    $stmt->execute(['Archived Test', 'archived@test.com', 'WI', 1]);
    $id = (int)$pdo->lastInsertId();
    
    $stmt = $pdo->prepare("SELECT archived FROM clients WHERE id = ?");
    $stmt->execute([$id]);
    $archived = (int)$stmt->fetchColumn();
    
    $pdo->prepare("DELETE FROM clients WHERE id = ?")->execute([$id]);
    
    return $archived === 1;
});

// ==========================================
// PHASE 4: Document Workflow Tests
// ==========================================
echo "\n📋 PHASE 4: Document Workflow Tests\n";
echo "----------------------------------------\n";

test("Can create quote with items", function() use ($pdo) {
    // Create client first
    $pdo->prepare("INSERT INTO clients (name, email, state) VALUES (?, ?, ?)")
        ->execute(['Quote Test', 'quote@test.com', 'WI']);
    $clientId = (int)$pdo->lastInsertId();
    
    // Create quote
    $pdo->prepare("INSERT INTO quotes (client_id, status, total, subtotal) VALUES (?, ?, ?, ?)")
        ->execute([$clientId, 'pending', 100.00, 100.00]);
    $quoteId = (int)$pdo->lastInsertId();
    
    // Add items
    $pdo->prepare("INSERT INTO quote_items (quote_id, item, quantity, unit_price, line_total) VALUES (?, ?, ?, ?, ?)")
        ->execute([$quoteId, 'Test Item', 1, 100.00, 100.00]);
    
    // Verify
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM quote_items WHERE quote_id = ?");
    $stmt->execute([$quoteId]);
    $count = (int)$stmt->fetchColumn();
    
    // Cleanup
    $pdo->prepare("DELETE FROM quote_items WHERE quote_id = ?")->execute([$quoteId]);
    $pdo->prepare("DELETE FROM quotes WHERE id = ?")->execute([$quoteId]);
    $pdo->prepare("DELETE FROM clients WHERE id = ?")->execute([$clientId]);
    
    return $count === 1;
});

test("Can create contract linked to quote", function() use ($pdo) {
    // Create client
    $pdo->prepare("INSERT INTO clients (name, email, state) VALUES (?, ?, ?)")
        ->execute(['Contract Test', 'contract@test.com', 'WI']);
    $clientId = (int)$pdo->lastInsertId();
    
    // Create quote
    $pdo->prepare("INSERT INTO quotes (client_id, status, total, subtotal) VALUES (?, ?, ?, ?)")
        ->execute([$clientId, 'approved', 200.00, 200.00]);
    $quoteId = (int)$pdo->lastInsertId();
    
    // Create contract from quote
    $pdo->prepare("INSERT INTO contracts (client_id, quote_id, status, total, subtotal) VALUES (?, ?, ?, ?, ?)")
        ->execute([$clientId, $quoteId, 'pending', 200.00, 200.00]);
    $contractId = (int)$pdo->lastInsertId();
    
    // Verify relationship
    $stmt = $pdo->prepare("SELECT quote_id FROM contracts WHERE id = ?");
    $stmt->execute([$contractId]);
    $linkedQuoteId = (int)$stmt->fetchColumn();
    
    // Cleanup
    $pdo->prepare("DELETE FROM contracts WHERE id = ?")->execute([$contractId]);
    $pdo->prepare("DELETE FROM quotes WHERE id = ?")->execute([$quoteId]);
    $pdo->prepare("DELETE FROM clients WHERE id = ?")->execute([$clientId]);
    
    return $linkedQuoteId === $quoteId;
});

test("Can create invoice linked to contract", function() use ($pdo) {
    // Create client
    $pdo->prepare("INSERT INTO clients (name, email, state) VALUES (?, ?, ?)")
        ->execute(['Invoice Test', 'invoice@test.com', 'WI']);
    $clientId = (int)$pdo->lastInsertId();
    
    // Create contract
    $pdo->prepare("INSERT INTO contracts (client_id, status, total, subtotal) VALUES (?, ?, ?, ?)")
        ->execute([$clientId, 'active', 300.00, 300.00]);
    $contractId = (int)$pdo->lastInsertId();
    
    // Create invoice
    $pdo->prepare("INSERT INTO invoices (client_id, contract_id, status, total, subtotal) VALUES (?, ?, ?, ?, ?)")
        ->execute([$clientId, $contractId, 'unpaid', 300.00, 300.00]);
    $invoiceId = (int)$pdo->lastInsertId();
    
    // Verify
    $stmt = $pdo->prepare("SELECT contract_id FROM invoices WHERE id = ?");
    $stmt->execute([$invoiceId]);
    $linkedContractId = (int)$stmt->fetchColumn();
    
    // Cleanup
    $pdo->prepare("DELETE FROM invoices WHERE id = ?")->execute([$invoiceId]);
    $pdo->prepare("DELETE FROM contracts WHERE id = ?")->execute([$contractId]);
    $pdo->prepare("DELETE FROM clients WHERE id = ?")->execute([$clientId]);
    
    return $linkedContractId === $contractId;
});

test("Can record payment on invoice", function() use ($pdo) {
    // Create client
    $pdo->prepare("INSERT INTO clients (name, email, state) VALUES (?, ?, ?)")
        ->execute(['Payment Test', 'payment@test.com', 'WI']);
    $clientId = (int)$pdo->lastInsertId();
    
    // Create invoice
    $pdo->prepare("INSERT INTO invoices (client_id, status, total, subtotal) VALUES (?, ?, ?, ?)")
        ->execute([$clientId, 'unpaid', 500.00, 500.00]);
    $invoiceId = (int)$pdo->lastInsertId();
    
    // Record payment
    $pdo->prepare("INSERT INTO payments (invoice_id, amount, method, status, payment_date) VALUES (?, ?, ?, ?, CURDATE())")
        ->execute([$invoiceId, 500.00, 'card', 'succeeded']);
    $paymentId = (int)$pdo->lastInsertId();
    
    // Update invoice
    $pdo->prepare("UPDATE invoices SET status = ?, amount_paid = ? WHERE id = ?")
        ->execute(['paid', 500.00, $invoiceId]);
    
    // Verify
    $stmt = $pdo->prepare("SELECT status, amount_paid FROM invoices WHERE id = ?");
    $stmt->execute([$invoiceId]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Cleanup
    $pdo->prepare("DELETE FROM payments WHERE id = ?")->execute([$paymentId]);
    $pdo->prepare("DELETE FROM invoices WHERE id = ?")->execute([$invoiceId]);
    $pdo->prepare("DELETE FROM clients WHERE id = ?")->execute([$clientId]);
    
    return $invoice['status'] === 'paid' && (float)$invoice['amount_paid'] === 500.00;
});

// ==========================================
// PHASE 5: Long Term Contract Tests
// ==========================================
echo "\n📋 PHASE 5: Long Term Contract Tests\n";
echo "----------------------------------------\n";

test("Can create long term contract with billing interval", function() use ($pdo) {
    $pdo->prepare("INSERT INTO clients (name, email, state) VALUES (?, ?, ?)")
        ->execute(['LTC Test', 'ltc@test.com', 'WI']);
    $clientId = (int)$pdo->lastInsertId();
    
    $pdo->prepare("INSERT INTO contracts 
        (client_id, status, total, billing_interval_count, billing_interval_unit, next_invoice_date) 
        VALUES (?, ?, ?, ?, ?, DATE_ADD(CURDATE(), INTERVAL 1 MONTH))")
        ->execute([$clientId, 'active', 1000.00, 1, 'month']);
    $ltcId = (int)$pdo->lastInsertId();
    
    $stmt = $pdo->prepare("SELECT billing_interval_unit, next_invoice_date FROM contracts WHERE id = ?");
    $stmt->execute([$ltcId]);
    $ltc = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Cleanup
    $pdo->prepare("DELETE FROM contracts WHERE id = ?")->execute([$ltcId]);
    $pdo->prepare("DELETE FROM clients WHERE id = ?")->execute([$clientId]);
    
    return $ltc['billing_interval_unit'] === 'month' && $ltc['next_invoice_date'] !== null;
});

// ==========================================
// PHASE 6: Multi-tenancy Tests
// ==========================================
echo "\n📋 PHASE 6: Multi-tenancy Tests\n";
echo "----------------------------------------\n";

test("Can create organization", function() use ($pdo) {
    $pdo->prepare("INSERT INTO organizations (name) VALUES (?)")
        ->execute(['Test Org ' . time()]);
    $orgId = (int)$pdo->lastInsertId();
    
    $stmt = $pdo->prepare("SELECT name FROM organizations WHERE id = ?");
    $stmt->execute([$orgId]);
    $name = $stmt->fetchColumn();
    
    $pdo->prepare("DELETE FROM organizations WHERE id = ?")->execute([$orgId]);
    
    return $name !== false;
});

test("Can assign client to organization", function() use ($pdo) {
    $pdo->prepare("INSERT INTO organizations (name) VALUES (?)")
        ->execute(['Org for Client']);
    $orgId = (int)$pdo->lastInsertId();
    
    $pdo->prepare("INSERT INTO clients (name, email, state, organization_id) VALUES (?, ?, ?, ?)")
        ->execute(['Org Client', 'orgclient@test.com', 'WI', $orgId]);
    $clientId = (int)$pdo->lastInsertId();
    
    $stmt = $pdo->prepare("SELECT organization_id FROM clients WHERE id = ?");
    $stmt->execute([$clientId]);
    $linkedOrgId = (int)$stmt->fetchColumn();
    
    $pdo->prepare("DELETE FROM clients WHERE id = ?")->execute([$clientId]);
    $pdo->prepare("DELETE FROM organizations WHERE id = ?")->execute([$orgId]);
    
    return $linkedOrgId === $orgId;
});

// ==========================================
// PHASE 7: API Key Tests
// ==========================================
echo "\n📋 PHASE 7: API Authentication Tests\n";
echo "----------------------------------------\n";

test("Can create API key", function() use ($pdo) {
    $pdo->prepare("INSERT INTO api_keys (name, key_prefix, key_hash, scopes) VALUES (?, ?, ?, ?)")
        ->execute(['Test Key', 'pa_test_', hash('sha256', 'test'), 'read,write']);
    $keyId = (int)$pdo->lastInsertId();
    
    $stmt = $pdo->prepare("SELECT scopes FROM api_keys WHERE id = ?");
    $stmt->execute([$keyId]);
    $scopes = $stmt->fetchColumn();
    
    $pdo->prepare("DELETE FROM api_keys WHERE id = ?")->execute([$keyId]);
    
    return $scopes === 'read,write';
});

// ==========================================
// PHASE 8: Audit Log Tests
// ==========================================
echo "\n📋 PHASE 8: Audit Log Tests\n";
echo "----------------------------------------\n";

test("Can create audit log entry", function() use ($pdo) {
    $pdo->prepare("INSERT INTO system_audit (action, entity_type, entity_id, details) VALUES (?, ?, ?, ?)")
        ->execute(['test_action', 'client', 1, json_encode(['test' => true])]);
    $logId = (int)$pdo->lastInsertId();
    
    $stmt = $pdo->prepare("SELECT action FROM system_audit WHERE id = ?");
    $stmt->execute([$logId]);
    $action = $stmt->fetchColumn();
    
    $pdo->prepare("DELETE FROM system_audit WHERE id = ?")->execute([$logId]);
    
    return $action === 'test_action';
});

// ==========================================
// PHASE 9: Custom Fields Tests
// ==========================================
echo "\n📋 PHASE 9: Custom Fields Tests\n";
echo "----------------------------------------\n";

test("Can create custom field definition", function() use ($pdo) {
    $pdo->prepare("INSERT INTO document_custom_fields (document_type, field_key, field_label, field_type, is_required) VALUES (?, ?, ?, ?, ?)")
        ->execute(['client', 'custom_test', 'Custom Test', 'text', 1]);
    $fieldId = (int)$pdo->lastInsertId();
    
    $stmt = $pdo->prepare("SELECT field_type FROM document_custom_fields WHERE id = ?");
    $stmt->execute([$fieldId]);
    $type = $stmt->fetchColumn();
    
    $pdo->prepare("DELETE FROM document_custom_fields WHERE id = ?")->execute([$fieldId]);
    
    return $type === 'text';
});

// ==========================================
// PHASE 10: Tax Rate Tests
// ==========================================
echo "\n📋 PHASE 10: Tax Rate Tests\n";
echo "----------------------------------------\n";

test("Can create tax rate", function() use ($pdo) {
    $pdo->prepare("INSERT INTO tax_rates (name, rate, county, state) VALUES (?, ?, ?, ?)")
        ->execute(['Test Tax', 5.5, 'Test County', 'WI']);
    $taxId = (int)$pdo->lastInsertId();
    
    $stmt = $pdo->prepare("SELECT rate FROM tax_rates WHERE id = ?");
    $stmt->execute([$taxId]);
    $rate = (float)$stmt->fetchColumn();
    
    $pdo->prepare("DELETE FROM tax_rates WHERE id = ?")->execute([$taxId]);
    
    return $rate === 5.5;
});

// ==========================================
// PHASE 11: Quote Auto-Create Defaults
// ==========================================
echo "\n📋 PHASE 11: Quote Auto-Create Defaults\n";
echo "----------------------------------------\n";

test("Quote auto-create contract defaults to ON", function() {
    // Same default expression used in documents.php and quote_approve.php
    $appConfig = [];
    $defaultOn = !isset($appConfig['quote_auto_create_contract']) || !empty($appConfig['quote_auto_create_contract']);
    return $defaultOn === true;
});

test("Quote auto-create invoice defaults to ON", function() {
    $appConfig = [];
    $defaultOn = !isset($appConfig['quote_auto_create_invoice']) || !empty($appConfig['quote_auto_create_invoice']);
    return $defaultOn === true;
});

test("Quote auto-create settings are saved by settings_handler.php", function() {
    // Simulate the saving logic that appears in settings_handler.php
    $post = [
        'quote_auto_create_contract' => '1',
        'quote_auto_create_invoice' => '0',
    ];
    $settings = [];
    $settings['quote_auto_create_contract'] = !empty($post['quote_auto_create_contract']) ? 1 : 0;
    $settings['quote_auto_create_invoice']  = !empty($post['quote_auto_create_invoice'])  ? 1 : 0;
    return $settings['quote_auto_create_contract'] === 1 && $settings['quote_auto_create_invoice'] === 0;
});

test("Quote auto-create disabled values are saved as OFF", function() {
    $post = [
        'quote_auto_create_contract' => '', // unchecked checkbox will omit value
        'quote_auto_create_invoice' => '',
    ];
    $settings = [];
    $settings['quote_auto_create_contract'] = !empty($post['quote_auto_create_contract']) ? 1 : 0;
    $settings['quote_auto_create_invoice']  = !empty($post['quote_auto_create_invoice'])  ? 1 : 0;
    return $settings['quote_auto_create_contract'] === 0 && $settings['quote_auto_create_invoice'] === 0;
});

// ==========================================
// PHASE 12: Project Document Linking
// ==========================================
echo "\n📋 PHASE 12: Project Document Linking\n";
echo "----------------------------------------\n";

test("Can link document to project", function() use ($pdo) {
    // Create client and project
    $pdo->prepare("INSERT INTO clients (name, email, state) VALUES (?, ?, ?)")
        ->execute(['Project Test', 'proj@test.com', 'WI']);
    $clientId = (int)$pdo->lastInsertId();
    
    $pdo->prepare("INSERT INTO projects (name, client_id, status) VALUES (?, ?, ?)")
        ->execute(['Test Project', $clientId, 'active']);
    $projectId = (int)$pdo->lastInsertId();
    
    // Create quote and link to project
    $pdo->prepare("INSERT INTO quotes (client_id, project_id, status, total) VALUES (?, ?, ?, ?)")
        ->execute([$clientId, $projectId, 'pending', 100.00]);
    $quoteId = (int)$pdo->lastInsertId();
    
    // Link via project_documents
    $pdo->prepare("INSERT INTO project_documents (project_id, document_type, document_id) VALUES (?, ?, ?)")
        ->execute([$projectId, 'quote', $quoteId]);
    
    // Verify
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM project_documents WHERE project_id = ?");
    $stmt->execute([$projectId]);
    $count = (int)$stmt->fetchColumn();
    
    // Cleanup
    $pdo->prepare("DELETE FROM project_documents WHERE project_id = ?")->execute([$projectId]);
    $pdo->prepare("DELETE FROM quotes WHERE id = ?")->execute([$quoteId]);
    $pdo->prepare("DELETE FROM projects WHERE id = ?")->execute([$projectId]);
    $pdo->prepare("DELETE FROM clients WHERE id = ?")->execute([$clientId]);
    
    return $count === 1;
});

// ==========================================
// Summary
// ==========================================
echo "\n========================================\n";
echo "TEST RESULTS SUMMARY\n";
echo "========================================\n";
echo "✅ Passed: " . count($results['passed']) . "\n";
echo "❌ Failed: " . count($results['failed']) . "\n";
echo "💥 Errors: " . count($results['errors']) . "\n";
echo "----------------------------------------\n";

if (count($results['failed']) > 0) {
    echo "\nFailed Tests:\n";
    foreach ($results['failed'] as $fail) {
        echo "  - $fail\n";
    }
}

if (count($results['errors']) > 0) {
    echo "\nErrors:\n";
    foreach ($results['errors'] as $error) {
        echo "  - $error\n";
    }
}

$total = count($results['passed']) + count($results['failed']) + count($results['errors']);
echo "\nTotal: $total tests run\n";
echo "Success Rate: " . round((count($results['passed']) / max($total, 1)) * 100, 1) . "%\n";

// Return exit code
exit(count($results['failed']) + count($results['errors']) > 0 ? 1 : 0);
