<?php
// src/views/pages/financial/receipts-list.php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/format.php';

$orgId = 1; // Should come from session/user context

// Get filter parameters
$filterStore = $_GET['store'] ?? '';
$filterMonth = $_GET['month'] ?? '';
$filterYear = $_GET['year'] ?? '';
$filterMinAmount = $_GET['min_amount'] ?? '';
$filterMaxAmount = $_GET['max_amount'] ?? '';

// Build WHERE clause
$whereClauses = ['r.org_id = ?'];
$params = [$orgId];

if (!empty($filterStore)) {
    $whereClauses[] = 'r.store_name = ?';
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

// Fetch filtered receipts
$stmt = $pdo->prepare("
    SELECT r.*, u.username as uploaded_by_name
    FROM receipts r
    LEFT JOIN users u ON r.uploaded_by = u.id
    WHERE {$whereSQL}
    ORDER BY r.receipt_date DESC, r.created_at DESC
");
$stmt->execute($params);
$receipts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate totals
$totalAmount = array_sum(array_column($receipts, 'amount'));

// Get all stores for filter dropdown
$storeStmt = $pdo->prepare('SELECT DISTINCT store_name FROM receipt_stores WHERE org_id = ? ORDER BY store_name');
$storeStmt->execute([$orgId]);
$stores = $storeStmt->fetchAll(PDO::FETCH_COLUMN);

// Get available years
$yearStmt = $pdo->prepare('SELECT DISTINCT YEAR(receipt_date) as year FROM receipts WHERE org_id = ? ORDER BY year DESC');
$yearStmt->execute([$orgId]);
$years = $yearStmt->fetchAll(PDO::FETCH_COLUMN);

require_once __DIR__ . '/../../partials/header.php';
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
    <form method="get" action="/?page=financial/receipts-list" style="margin-bottom:24px;background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:20px">
        <input type="hidden" name="page" value="financial/receipts-list">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-bottom:12px">
            <div>
                <label style="display:block;margin-bottom:4px;font-size:13px;font-weight:600">Store</label>
                <select name="store" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px">
                    <option value="">All Stores</option>
                    <?php foreach ($stores as $store): ?>
                        <option value="<?php echo htmlspecialchars($store); ?>" <?php echo $filterStore === $store ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($store); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label style="display:block;margin-bottom:4px;font-size:13px;font-weight:600">Year</label>
                <select name="year" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px">
                    <option value="">All Years</option>
                    <?php foreach ($years as $year): ?>
                        <option value="<?php echo $year; ?>" <?php echo $filterYear == $year ? 'selected' : ''; ?>>
                            <?php echo $year; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label style="display:block;margin-bottom:4px;font-size:13px;font-weight:600">Month</label>
                <select name="month" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px">
                    <option value="">All Months</option>
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?php echo $m; ?>" <?php echo $filterMonth == $m ? 'selected' : ''; ?>>
                            <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </div>
            <div>
                <label style="display:block;margin-bottom:4px;font-size:13px;font-weight:600">Min Amount</label>
                <input type="number" name="min_amount" value="<?php echo htmlspecialchars($filterMinAmount); ?>" step="0.01" placeholder="$0.00" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px">
            </div>
            <div>
                <label style="display:block;margin-bottom:4px;font-size:13px;font-weight:600">Max Amount</label>
                <input type="number" name="max_amount" value="<?php echo htmlspecialchars($filterMaxAmount); ?>" step="0.01" placeholder="$999.99" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px">
            </div>
        </div>
        <div style="display:flex;gap:8px">
            <button type="submit" style="padding:8px 16px;border-radius:6px;border:0;background:var(--nav-accent);color:#fff;font-weight:600;cursor:pointer">Apply Filters</button>
            <a href="/?page=financial/receipts-list" style="padding:8px 16px;border-radius:6px;border:1px solid #ddd;background:#fff;text-decoration:none;color:inherit;font-weight:600;display:inline-block">Clear</a>
        </div>
    </form>

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
                            <img src="<?php echo htmlspecialchars($receipt['file_path']); ?>" 
                                 alt="Receipt preview" 
                                 style="width:100%;height:100%;object-fit:cover"
                                 loading="lazy">
                        <?php endif; ?>
                    </div>
                    
                    <!-- Receipt Info -->
                    <div style="padding:12px">
                        <div style="font-weight:600;margin-bottom:4px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                            <?php echo htmlspecialchars($receipt['title']); ?>
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
                            <a href="<?php echo htmlspecialchars($receipt['file_path']); ?>" download
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

<?php require_once __DIR__ . '/../../partials/footer.php'; ?>
