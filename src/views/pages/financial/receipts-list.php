<?php
// src/views/pages/financial/receipts-list.php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/format.php';
require_once __DIR__ . '/../../../utils/twig.php';

$orgId = 1; // Should come from session/user context

// Get filter parameters with defaults to current year/month
$filterStore = $_GET['store'] ?? '';
$filterMonth = $_GET['month'] ?? date('n'); // Current month (1-12)
$filterYear = $_GET['year'] ?? date('Y'); // Current year
$filterMinAmount = $_GET['min_amount'] ?? '';
$filterMaxAmount = $_GET['max_amount'] ?? '';

// Build WHERE clause
$whereClauses = ['r.organization_id = ?'];
$params = [$orgId];

if (!empty($filterStore)) {
    $whereClauses[] = 'rs.name = ?';
    $params[] = $filterStore;
}
if (!empty($filterMonth) && !empty($filterYear)) {
    $whereClauses[] = 'YEAR(r.receipt_date) = ? AND MONTH(r.receipt_date) = ?';
    $params[] = $filterYear;
    $params[] = $filterMonth;
} elseif (!empty($filterYear)) {
    $whereClauses[] = 'YEAR(r.receipt_date) = ?';
    $params[] = $filterYear;
}
if (!empty($filterMinAmount)) {
    $whereClauses[] = 'r.amount >= ?';
    $params[] = $filterMinAmount;
}
if (!empty($filterMaxAmount)) {
    $whereClauses[] = 'r.amount <= ?';
    $params[] = $filterMaxAmount;
}

$whereSQL = implode(' AND ', $whereClauses);

// Fetch filtered receipts with expense status
$stmt = $pdo->prepare("
    SELECT r.*, v.name as store_name, e.id as expense_id
    FROM receipts r
    LEFT JOIN vendors v ON r.store_id = v.id
    LEFT JOIN expenses e ON e.receipt_id = r.id AND e.status != 'void'
    WHERE {$whereSQL}
    ORDER BY r.receipt_date DESC, r.created_at DESC
");
$stmt->execute($params);
$receipts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate totals
$totalAmount = array_sum(array_column($receipts, 'amount'));

// Get all stores for filter dropdown
$storeStmt = $pdo->prepare('SELECT DISTINCT name FROM vendors WHERE organization_id = ? ORDER BY name');
$storeStmt->execute([$orgId]);
$stores = $storeStmt->fetchAll(PDO::FETCH_COLUMN);

// Get available years
$yearStmt = $pdo->prepare('SELECT DISTINCT YEAR(receipt_date) as year FROM receipts WHERE organization_id = ? ORDER BY year DESC');
$yearStmt->execute([$orgId]);
$years = $yearStmt->fetchAll(PDO::FETCH_COLUMN);

// Always include current year if not present
$currentYear = (int)date('Y');
if (!in_array($currentYear, $years)) {
    array_unshift($years, $currentYear);
}
?>

<div style="max-width:1400px;margin:0 auto;padding:24px">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px">
        <div>
            <h1 style="margin:0 0 8px 0;font-size:28px">Receipts</h1>
            <p style="margin:0;color:var(--muted)">Manage business expense receipts</p>
        </div>
        <a href="/?page=financial/receipt-upload" style="padding:10px 16px;border-radius:8px;background:var(--nav-accent);color:#fff;text-decoration:none;font-weight:600">
            + Upload Receipt
        </a>
    </div>

    <!-- Filters -->
    <?php
    // Prepare store options
    $storeOptions = [['value' => '', 'label' => 'All Stores']];
    foreach ($stores as $store) {
        $storeOptions[] = ['value' => $store, 'label' => $store];
    }
    
    // Prepare year options
    $yearOptions = [['value' => '', 'label' => 'All Years']];
    foreach ($years as $year) {
        $yearOptions[] = ['value' => (string)$year, 'label' => (string)$year];
    }
    
    // Prepare month options
    $monthOptions = [['value' => '', 'label' => 'All Months']];
    for ($m = 1; $m <= 12; $m++) {
        $monthOptions[] = ['value' => (string)$m, 'label' => date('F', mktime(0, 0, 0, $m, 1))];
    }
    
    $filterConfig = [
        'page' => 'financial/receipts-list',
        'filters' => [
            'store' => [
                'type' => 'select',
                'label' => 'Store',
                'value' => $filterStore,
                'options' => $storeOptions
            ],
            'year' => [
                'type' => 'select',
                'label' => 'Year',
                'value' => (string)$filterYear,
                'options' => $yearOptions
            ],
            'month' => [
                'type' => 'select',
                'label' => 'Month',
                'value' => (string)$filterMonth,
                'options' => $monthOptions
            ],
            'min_amount' => [
                'type' => 'number',
                'label' => 'Min Amount ($)',
                'value' => $filterMinAmount,
                'step' => '0.01',
                'placeholder' => '0.00'
            ],
            'max_amount' => [
                'type' => 'number',
                'label' => 'Max Amount ($)',
                'value' => $filterMaxAmount,
                'step' => '0.01',
                'placeholder' => '999.99'
            ]
        ]
    ];
    echo render_template('components/document-filter.html.twig', $filterConfig);
    ?>

    <!-- Summary Card -->
    <div style="margin-bottom:24px;padding:16px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px">
            <div>
                <div style="color:var(--muted);font-size:13px;margin-bottom:4px">Total Receipts</div>
                <div style="font-size:24px;font-weight:700"><?php echo count($receipts); ?></div>
            </div>
            <div>
                <div style="color:var(--muted);font-size:13px;margin-bottom:4px">Total Amount</div>
                <div style="font-size:24px;font-weight:700">$<?php echo number_format($totalAmount, 2); ?></div>
            </div>
        </div>
    </div>

    <?php if (empty($receipts)): ?>
        <div style="text-align:center;padding:64px 24px;border:2px dashed #e5e7eb;border-radius:12px">
            <div style="font-size:48px;margin-bottom:16px">📄</div>
            <h2 style="margin:0 0 8px 0;font-size:20px">No receipts yet</h2>
            <p style="margin:0 0 24px 0;color:var(--muted)">Upload your first business expense receipt to get started</p>
            <a href="/?page=financial/receipt-upload" style="display:inline-block;padding:12px 24px;border-radius:8px;background:var(--nav-accent);color:#fff;text-decoration:none;font-weight:600">
                Upload Receipt
            </a>
        </div>
    <?php else: ?>
        <!-- Receipts Grid -->
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:20px">
            <?php foreach ($receipts as $receipt): 
                $fileExt = strtolower(pathinfo($receipt['file_path'], PATHINFO_EXTENSION));
                $isPdf = $fileExt === 'pdf';
            ?>
                <div style="border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;background:#fff">
                    <!-- Receipt Preview -->
                    <div style="height:200px;background:#f9fafb;display:flex;align-items:center;justify-content:center;overflow:hidden">
                        <?php if ($isPdf): ?>
                            <div style="text-align:center;color:var(--muted)">
                                <div style="font-size:48px;margin-bottom:8px">📄</div>
                                <div style="font-size:12px;font-weight:600">PDF Document</div>
                            </div>
                        <?php else: ?>
                            <?php 
                            $fileParam = str_replace('/src/uploads/', '', $receipt['file_path']);
                            ?>
                            <img src="/?page=serve-upload&file=<?php echo urlencode($fileParam); ?>" 
                                 alt="Receipt preview" 
                                 style="width:100%;height:100%;object-fit:cover"
                                 loading="lazy">
                        <?php endif; ?>
                    </div>
                    
                    <!-- Receipt Info -->
                    <div style="padding:12px">
                        <div style="font-weight:600;margin-bottom:4px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                            <?php echo htmlspecialchars($receipt['description'] ?? 'Receipt'); ?>
                        </div>
                        <?php if (!empty($receipt['store_name'])): ?>
                            <div style="font-size:13px;color:var(--muted);margin-bottom:4px">
                                <?php echo htmlspecialchars($receipt['store_name']); ?>
                            </div>
                        <?php endif; ?>
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
                            <div style="font-size:20px;font-weight:700;color:var(--nav-accent)">
                                $<?php echo number_format($receipt['amount'], 2); ?>
                            </div>
                            <div style="font-size:13px;color:var(--muted)">
                                <?php echo date('M j, Y', strtotime($receipt['receipt_date'])); ?>
                            </div>
                        </div>
                        
                        <!-- Action Buttons -->
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:12px;padding-top:12px;border-top:1px solid #e5e7eb">
                            <?php 
                            $fileParam = str_replace('/src/uploads/', '', $receipt['file_path']);
                            ?>
                            <a href="/?page=serve-upload&file=<?php echo urlencode($fileParam); ?>&download=1" download
                               style="padding:8px;border-radius:6px;background:#f9fafb;border:1px solid #e5e7eb;text-align:center;text-decoration:none;color:inherit;font-size:13px;font-weight:600">
                                📥 Download
                            </a>
                            <a href="/?page=financial/receipt-detail&id=<?php echo $receipt['id']; ?>"
                               style="padding:8px;border-radius:6px;background:var(--nav-accent);color:#fff;text-align:center;text-decoration:none;font-size:13px;font-weight:600">
                                👁️ View
                            </a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
