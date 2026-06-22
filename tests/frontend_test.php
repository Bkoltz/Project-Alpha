<?php
/**
 * Frontend Page Load Test Suite
 * Tests all major pages load without errors by including the files directly
 */

// Bootstrap minimal environment
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = 'localhost:1627';
$_SERVER['HTTPS'] = 'off';
$_SERVER['REQUEST_URI'] = '/';
$_GET = ['page' => 'login'];

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

function checkPageFile($page) {
    global $pdo;
    
    // Save original GET
    $origGet = $_GET;
    $_GET = ['page' => $page];
    
    ob_start();
    $error = null;
    try {
        // Include index.php
        $indexPath = '/var/www/public/index.php';
        if (file_exists($indexPath)) {
            require_once $indexPath;
        } else {
            $error = "index.php not found";
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
    $output = ob_get_clean();
    
    // Restore GET
    $_GET = $origGet;
    
    // Check for errors
    $hasDbError = strpos($output, 'SQLSTATE') !== false;
    $hasFatalError = strpos($output, 'Fatal error') !== false;
    $hasParseError = strpos($output, 'Parse error') !== false;
    
    return [
        'error' => $error,
        'db_error' => $hasDbError,
        'fatal_error' => $hasFatalError,
        'parse_error' => $hasParseError,
        'output' => $output
    ];
}

echo "========================================\n";
echo "Frontend Page Load Test Suite\n";
echo "========================================\n\n";

// Test pages that don't require auth
$publicPages = [
    'login' => 'Login Page',
    'public-redirect' => 'Public Redirect',
];

// Test pages that require auth (should redirect to login)
$authPages = [
    'home' => 'Dashboard',
    'clients-list' => 'Clients List',
    'projects-list' => 'Projects List',
    'quotes-list' => 'Quotes List',
    'contracts-list' => 'Contracts List',
    'invoices-list' => 'Invoices List',
    'payments-list' => 'Payments List',
    'financial-dashboard' => 'Financial Dashboard',
    'settings' => 'Settings',
    'accounts' => 'Accounts',
    'api-keys' => 'API Keys',
];

echo "📋 Public Pages\n";
echo "----------------------------------------\n";
foreach ($publicPages as $page => $name) {
    test($name, function() use ($page) {
        $result = checkPageFile($page);
        if ($result['error']) return "Error: " . $result['error'];
        if ($result['db_error']) return 'Database error on page';
        if ($result['fatal_error']) return 'Fatal error on page';
        if ($result['parse_error']) return 'Parse error on page';
        return true;
    });
}

echo "\n📋 Authenticated Pages (expect redirect to login)\n";
echo "----------------------------------------\n";
foreach ($authPages as $page => $name) {
    test($name, function() use ($page) {
        $result = checkPageFile($page);
        if ($result['error']) return "Error: " . $result['error'];
        if ($result['db_error']) return 'Database error on page';
        if ($result['fatal_error']) return 'Fatal error on page';
        if ($result['parse_error']) return 'Parse error on page';
        return true;
    });
}

// Summary
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

exit(count($results['failed']) + count($results['errors']) > 0 ? 1 : 0);
