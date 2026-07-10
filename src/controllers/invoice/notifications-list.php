<?php
// src/controllers/invoice/notifications-list.php
// Admin view of sent invoice reminders with filtering and manual resend capability

$page = 'invoice/notifications-list';
$tab = 'notifications';

// Pagination
$perPage = 50;
$pageNum = (int)($_GET['p'] ?? 1);
if ($pageNum < 1) $pageNum = 1;
$offset = ($pageNum - 1) * $perPage;

// Build WHERE clause with filters
$where = [];
$params = [];

if (!empty($_GET['type'])) {
    $type = trim((string)$_GET['type']);
    if (in_array($type, ['due_7', 'overdue_weekly'], true)) {
        $where[] = 'n.notification_type = ?';
        $params[] = $type;
    }
}

if (!empty($_GET['invoice_id'])) {
    $iid = (int)$_GET['invoice_id'];
    $where[] = 'n.invoice_id = ?';
    $params[] = $iid;
}

if (!empty($_GET['date_from'])) {
    $df = trim((string)$_GET['date_from']);
    if (strtotime($df) !== false) {
        $where[] = 'DATE(n.sent_at) >= ?';
        $params[] = date('Y-m-d', strtotime($df));
    }
}

if (!empty($_GET['date_to'])) {
    $dt = trim((string)$_GET['date_to']);
    if (strtotime($dt) !== false) {
        $where[] = 'DATE(n.sent_at) <= ?';
        $params[] = date('Y-m-d', strtotime($dt));
    }
}

$whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Count total
$countSql = "SELECT COUNT(*) FROM invoice_notifications n $whereClause";
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalCount = (int)$countStmt->fetchColumn();
$totalPages = (int)ceil($totalCount / $perPage);

// Fetch notifications with invoice details
$sql = "
    SELECT n.id, n.invoice_id, n.notification_type, n.sent_at, 
           i.doc_number, i.invoice_type, i.total, i.status, i.due_date,
           c.name AS client_name, c.email AS client_email
    FROM invoice_notifications n
    JOIN invoices i ON i.id = n.invoice_id
    JOIN clients c ON c.id = i.client_id
    $whereClause
    ORDER BY n.sent_at DESC
    LIMIT ? OFFSET ?
";
$stmt = $pdo->prepare($sql);
$stmt->execute(array_merge($params, [$perPage, $offset]));
$notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Optionally render the view
?>
