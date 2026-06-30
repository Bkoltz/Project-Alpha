<?php
// src/views/pages/project/projects-details.php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/csrf.php';
require_once __DIR__ . '/../../../utils/escaper.php';
require_once __DIR__ . '/../../../utils/acl.php';
require_once __DIR__ . '/../../../utils/project_invoice_billing.php';

$projectId = (int)($_GET['id'] ?? 0);
if ($projectId <= 0) {
    header('Location: /?page=project/projects-list');
    exit;
}
require_record_ownership($pdo, 'projects', $projectId);

// Fetch project details
$stmt = $pdo->prepare('
    SELECT p.*, c.name AS client_name, o.name AS organization_name, od.name AS department_name
    FROM projects p
    LEFT JOIN clients c ON c.id = p.client_id
    LEFT JOIN organizations o ON o.id = p.organization_id
    LEFT JOIN organization_departments od ON od.id = p.department_id
    WHERE p.id = ?
');
$stmt->execute([$projectId]);
$project = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$project) {
    header('Location: /?page=project/projects-list');
    exit;
}

// Fetch associated quotes
$stmt = $pdo->prepare('SELECT id, doc_number, status, total, created_at FROM quotes WHERE project_id = ? ORDER BY created_at DESC');
$stmt->execute([$projectId]);
$quotes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch associated contracts
$stmt = $pdo->prepare('SELECT id, doc_number, status, total, created_at FROM contracts WHERE project_id = ? ORDER BY created_at DESC');
$stmt->execute([$projectId]);
$contracts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch associated invoices
$stmt = $pdo->prepare('SELECT id, doc_number, status, total, amount_paid, balance_due, created_at FROM invoices WHERE project_id = ? ORDER BY created_at DESC');
$stmt->execute([$projectId]);
$invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

$invoiceStats = [
    'paid_count' => 0,
    'unpaid_count' => 0,
    'paid_total' => 0.0,
    'unpaid_total' => 0.0,
];
foreach ($invoices as $invoice) {
    $total = (float)($invoice['total'] ?? 0);
    $balanceDue = array_key_exists('balance_due', $invoice) && $invoice['balance_due'] !== null
        ? (float)$invoice['balance_due']
        : max(0.0, $total - (float)($invoice['amount_paid'] ?? 0));
    if (($invoice['status'] ?? '') === 'paid') {
        $invoiceStats['paid_count']++;
        $invoiceStats['paid_total'] += $total;
    } elseif (!in_array(($invoice['status'] ?? ''), ['void', 'cancelled'], true)) {
        $invoiceStats['unpaid_count']++;
        $invoiceStats['unpaid_total'] += $balanceDue;
    }
}

$stmt = $pdo->prepare('
    SELECT pi.*, COUNT(pii.id) AS child_count
    FROM project_invoices pi
    LEFT JOIN project_invoice_items pii ON pii.project_invoice_id = pi.id
    WHERE pi.project_id = ?
    GROUP BY pi.id
    ORDER BY pi.billing_period_end DESC, pi.id DESC
');
$stmt->execute([$projectId]);
$projectInvoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

$projectClientsSendSelect = project_invoice_table_has_column($pdo, 'project_clients', 'send_project_invoices')
    ? 'pc.send_project_invoices'
    : '1 AS send_project_invoices';
$projectClientsLinkSelect = project_invoice_table_has_column($pdo, 'project_clients', 'can_view_invoice_links')
    ? 'pc.can_view_invoice_links'
    : '1 AS can_view_invoice_links';
$stmt = $pdo->prepare("
    SELECT c.id, c.name, c.email, pc.is_primary_billing, {$projectClientsSendSelect}, {$projectClientsLinkSelect}
    FROM project_clients pc
    JOIN clients c ON c.id = pc.client_id
    WHERE pc.project_id = ?
    ORDER BY pc.is_primary_billing DESC, pc.sort_order ASC, c.name ASC
");
$stmt->execute([$projectId]);
$projectClients = $stmt->fetchAll(PDO::FETCH_ASSOC);

$projectOrganizationId = (int)($project['organization_id'] ?? 0);
$projectDepartments = [];
if ($projectOrganizationId > 0) {
    $deptStmt = $pdo->prepare('
        SELECT id, name, folder_name
        FROM organization_departments
        WHERE organization_id = ?
        ORDER BY name
    ');
    $deptStmt->execute([$projectOrganizationId]);
    $projectDepartments = $deptStmt->fetchAll(PDO::FETCH_ASSOC);
}
if ($projectOrganizationId > 0) {
    $allClientsStmt = $pdo->prepare('
        SELECT id, name, email
        FROM clients
        WHERE organization_id = ? AND archived = 0
        ORDER BY name
    ');
    $allClientsStmt->execute([$projectOrganizationId]);
    $allClients = $allClientsStmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $activeOrgId = get_active_org_id();
    if ($activeOrgId > 0) {
        $allClientsStmt = $pdo->prepare('
            SELECT id, name, email
            FROM clients
            WHERE organization_id = ? AND archived = 0
            ORDER BY name
        ');
        $allClientsStmt->execute([$activeOrgId]);
        $allClients = $allClientsStmt->fetchAll(PDO::FETCH_ASSOC);
    } elseif (($_SESSION['user']['role'] ?? '') === 'admin') {
        $allClients = $pdo->query('SELECT id, name, email FROM clients WHERE archived = 0 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $allClientsStmt = $pdo->prepare('
            SELECT id, name, email
            FROM clients
            WHERE organization_id IS NULL AND created_by = ? AND archived = 0
            ORDER BY name
        ');
        $allClientsStmt->execute([(int)($_SESSION['user']['id'] ?? 0)]);
        $allClients = $allClientsStmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
$selectedProjectClientIds = array_map(static fn($row) => (int)$row['id'], $projectClients);
$selectedProjectInvoiceRecipientIds = array_map(
    static fn($row) => (int)$row['id'],
    array_values(array_filter($projectClients, static fn($row) => !empty($row['send_project_invoices'])))
);
$selectedProjectInvoiceLinkClientIds = array_map(
    static fn($row) => (int)$row['id'],
    array_values(array_filter($projectClients, static fn($row) => !empty($row['can_view_invoice_links'])))
);
$projectDepartmentContactIds = [];
if (!empty($project['department_id'])) {
    $deptContactStmt = $pdo->prepare('SELECT client_id FROM organization_department_contacts WHERE department_id = ?');
    $deptContactStmt->execute([(int)$project['department_id']]);
    $projectDepartmentContactIds = array_map('intval', $deptContactStmt->fetchAll(PDO::FETCH_COLUMN));
    if ($projectDepartmentContactIds) {
        usort($allClients, static function (array $a, array $b) use ($projectDepartmentContactIds): int {
            $aDept = in_array((int)$a['id'], $projectDepartmentContactIds, true) ? 0 : 1;
            $bDept = in_array((int)$b['id'], $projectDepartmentContactIds, true) ? 0 : 1;
            if ($aDept !== $bDept) {
                return $aDept <=> $bDept;
            }
            return strcasecmp((string)$a['name'], (string)$b['name']);
        });
    }
}

// Fetch associated form documents
$stmt = $pdo->prepare('
    SELECT fd.*, fc.title as category_title
    FROM form_documents fd
    LEFT JOIN form_categories fc ON fc.id = fd.category_id
    WHERE fd.project_id = ?
    ORDER BY fd.uploaded_at DESC
');
$stmt->execute([$projectId]);
$formDocuments = $stmt->fetchAll(PDO::FETCH_ASSOC);

$docScopeWhere = [];
$docScopeParams = [];
if (!empty($project['client_id'])) {
    $docScopeWhere[] = 'client_id = ?';
    $docScopeParams[] = (int)$project['client_id'];
}
if (!empty($project['organization_id'])) {
    $docScopeWhere[] = 'organization_id = ?';
    $docScopeParams[] = (int)$project['organization_id'];
}
$docScopeSql = $docScopeWhere ? '(' . implode(' OR ', $docScopeWhere) . ')' : '1=0';

$stmt = $pdo->prepare("SELECT id, doc_number, status, total, created_at FROM quotes WHERE {$docScopeSql} AND project_id IS NULL ORDER BY created_at DESC LIMIT 50");
$stmt->execute($docScopeParams);
$availableQuotes = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("SELECT id, doc_number, status, total, created_at FROM contracts WHERE {$docScopeSql} AND project_id IS NULL ORDER BY created_at DESC LIMIT 50");
$stmt->execute($docScopeParams);
$availableContracts = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("SELECT id, doc_number, status, total, created_at FROM invoices WHERE {$docScopeSql} AND project_id IS NULL ORDER BY created_at DESC LIMIT 50");
$stmt->execute($docScopeParams);
$availableInvoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Status colors
$statusColors = [
    'not_started' => ['bg' => '#fef3c7', 'color' => '#92400e', 'text' => 'Not Started'],
    'active' => ['bg' => '#d1fae5', 'color' => '#065f46', 'text' => 'Active'],
    'overdue' => ['bg' => '#fee2e2', 'color' => '#dc2626', 'text' => 'Overdue'],
    'completed' => ['bg' => '#e0e7ff', 'color' => '#3730a3', 'text' => 'Completed'],
    'cancelled' => ['bg' => '#f3f4f6', 'color' => '#6b7280', 'text' => 'Cancelled']
];

$currentStatus = $statusColors[$project['status']] ?? $statusColors['not_started'];
$autoEmailEnabled = !array_key_exists('project_invoice_auto_email', $project) || !empty($project['project_invoice_auto_email']);
$lastProjectInvoice = $projectInvoices[0] ?? null;
$monthlyBilling = ($project['invoice_billing_period'] ?? 'per_invoice') === 'monthly';
$nextBillingLabel = $monthlyBilling ? date('M j, Y', strtotime('first day of next month')) : 'Per invoice';

?>

<?php
$projectReturnUrl = '/?page=project/projects-details&id=' . $projectId;
$renderDocumentAttachForm = static function (string $type, string $label, array $documents) use ($projectId): void {
    ?>
    <form method="post" action="/?page=project/project-add-document" style="display:grid;gap:8px;margin:0">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
        <input type="hidden" name="project_id" value="<?php echo (int)$projectId; ?>">
        <input type="hidden" name="document_type" value="<?php echo htmlspecialchars($type); ?>">
        <label>
            <div style="font-size:13px;font-weight:600;margin-bottom:4px"><?php echo htmlspecialchars($label); ?></div>
            <select name="document_id" <?php echo empty($documents) ? 'disabled' : ''; ?> style="width:100%;padding:9px;border-radius:8px;border:1px solid #ddd">
                <?php if (empty($documents)): ?>
                    <option value="">No available <?php echo htmlspecialchars(strtolower($label)); ?></option>
                <?php else: ?>
                    <option value="">Select <?php echo htmlspecialchars(strtolower($label)); ?></option>
                    <?php foreach ($documents as $document): ?>
                        <option value="<?php echo (int)$document['id']; ?>">
                            #<?php echo htmlspecialchars((string)($document['doc_number'] ?? $document['id'])); ?>
                            - <?php echo htmlspecialchars(ucfirst((string)$document['status'])); ?>
                            - $<?php echo number_format((float)$document['total'], 2); ?>
                        </option>
                    <?php endforeach; ?>
                <?php endif; ?>
            </select>
        </label>
        <button type="submit" class="btn btn-sm" <?php echo empty($documents) ? 'disabled' : ''; ?>>Add <?php echo htmlspecialchars($label); ?></button>
    </form>
    <?php
};
?>

<style>
.project-page{max-width:1440px;margin:0 auto;padding:24px}.project-layout{display:grid;grid-template-columns:minmax(0,1fr) 360px;gap:24px;align-items:start}.project-main,.project-sidebar{min-width:0}.project-panel{background:#fff;border:1px solid #dfe3e8;border-radius:8px;padding:20px;margin-bottom:18px;box-shadow:0 1px 2px rgba(15,23,42,.04)}.project-header{display:flex;justify-content:space-between;align-items:flex-start;gap:20px}.project-header h1{margin:0 0 6px;font-size:26px;line-height:1.2}.project-subtitle{color:var(--muted);font-size:13px}.project-status{display:inline-flex;align-items:center;padding:6px 10px;border-radius:6px;font-size:13px;font-weight:700;white-space:nowrap}.project-facts{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px 24px;padding-top:18px;margin-top:18px;border-top:1px solid #e5e7eb}.project-fact-label{font-size:12px;color:var(--muted);margin-bottom:3px}.project-fact-value{font-size:14px;font-weight:600}.project-metrics{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));border:1px solid #dfe3e8;border-radius:8px;background:#fff;margin-bottom:18px;overflow:hidden}.project-metric{padding:15px 16px;border-right:1px solid #e5e7eb}.project-metric:last-child{border-right:0}.project-metric-label{font-size:12px;color:var(--muted)}.project-metric-value{font-size:22px;font-weight:750;line-height:1.25;margin-top:2px}.project-metric-note{font-size:12px;margin-top:2px}.project-section-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:14px}.project-section-head h2{font-size:18px;margin:0}.project-doc-row{display:flex;justify-content:space-between;align-items:center;gap:14px;padding:11px 12px;border:1px solid #e5e7eb;border-radius:6px;background:#fafbfc;text-decoration:none;color:inherit}.project-doc-row:hover{border-color:#c9d1d9;background:#fff}.project-actions{display:grid;grid-template-columns:1fr 1fr;gap:8px}.project-actions .btn{display:flex;align-items:center;justify-content:center;text-align:center;min-height:38px}.project-actions .project-action-wide{grid-column:1/-1}.project-field{display:grid;gap:5px}.project-field>span,.project-field>div:first-child{font-size:13px;font-weight:600}.project-field input,.project-field select,.project-field textarea{width:100%;padding:9px 10px;border:1px solid #cfd5dc;border-radius:6px;background:#fff}.project-field small{color:var(--muted);font-size:12px;line-height:1.4}.project-check-list{display:grid;gap:7px;padding:10px;border:1px solid #dfe3e8;border-radius:6px;max-height:180px;overflow:auto}.project-check{display:flex;align-items:flex-start;gap:8px;font-size:13px}.project-check input{width:auto;margin-top:2px}.project-sidebar-title{font-size:15px;font-weight:700;margin-bottom:12px}.project-muted{color:var(--muted);font-size:13px}.project-danger{border-color:#fecaca;background:#fffafa}.project-danger .project-sidebar-title{color:#991b1b}@media(max-width:1050px){.project-layout{grid-template-columns:1fr}.project-sidebar{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px}.project-sidebar>.project-panel{margin:0}.project-sidebar>.project-panel:nth-child(3),.project-sidebar>.project-panel:nth-child(4){grid-column:1/-1}}@media(max-width:760px){.site-shell{display:block!important}.main-content{width:100%!important;min-width:0!important}.project-page{width:100%;padding:16px}.project-header{display:grid}.project-facts{grid-template-columns:1fr}.project-metrics{grid-template-columns:repeat(2,minmax(0,1fr))}.project-metric{border-bottom:1px solid #e5e7eb}.project-metric:nth-child(2n){border-right:0}.project-metric:last-child{grid-column:1/-1;border-bottom:0}.project-sidebar{display:block}.project-sidebar>.project-panel{margin-bottom:16px}.project-actions{grid-template-columns:1fr}.project-actions .project-action-wide{grid-column:auto}.project-doc-row{align-items:flex-start;flex-direction:column}.project-doc-row>div:last-child{width:100%;justify-content:space-between}}
</style>
<style>
.project-settings-form{display:grid;gap:14px}.project-settings-card{border:1px solid #dfe3e8;border-radius:10px;background:#fbfcfd;padding:14px;display:grid;gap:11px}.project-settings-card h3{margin:0;font-size:14px;color:#111827}.project-settings-card p{margin:0;color:var(--muted);font-size:12px;line-height:1.45}.project-settings-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}.project-help-icon{display:inline-flex;align-items:center;justify-content:center;width:16px;height:16px;border-radius:999px;background:#e0e7ff;color:#3730a3;font-size:11px;cursor:help}.project-contact-card{display:grid;grid-template-columns:auto 1fr;gap:10px;padding:11px;border:1px solid #dfe3e8;border-radius:10px;background:#fff}.project-contact-card.is-primary{border-color:#93c5fd;background:#eff6ff}.project-contact-heading{display:flex;align-items:center;justify-content:space-between;gap:8px}.project-pill{display:inline-flex;align-items:center;border-radius:999px;padding:2px 7px;font-size:11px;font-weight:700;background:#dbeafe;color:#1d4ed8;white-space:nowrap}.project-contact-options{display:grid;gap:6px;margin-top:9px}.project-add-contact-list{display:grid;gap:7px;max-height:190px;overflow:auto;padding:10px;border:1px solid #dfe3e8;border-radius:8px;background:#fff}.project-info-box{padding:10px 12px;border:1px solid #bfdbfe;background:#eff6ff;border-radius:8px;color:#1e3a8a;font-size:12px;line-height:1.45}.project-settings-save{position:sticky;bottom:12px;z-index:1;box-shadow:0 10px 24px rgba(15,23,42,.12)}@media(max-width:760px){.project-settings-grid{grid-template-columns:1fr}.project-settings-save{position:static}}
</style>

<div class="project-page">
    <div style="margin-bottom:24px">
        <a href="/?page=project/projects-list" style="color:var(--nav-accent);text-decoration:none;font-size:14px">
            ← Back to Projects
        </a>
    </div>

    <div class="project-layout">
        <!-- Main Content -->
        <div class="project-main">
            <!-- Project Header -->
            <div class="project-panel">
                <div class="project-header">
                    <div>
                        <h1 style="margin:0 0 8px 0;font-size:28px"><?php echo htmlspecialchars($project['name']); ?></h1>
                        <div class="project-subtitle">
                            Created <?php echo date('F j, Y', strtotime($project['created_at'])); ?>
                        </div>
                    </div>
                    <div class="project-status" style="background:<?php echo $currentStatus['bg']; ?>;color:<?php echo $currentStatus['color']; ?>">
                        <?php echo $currentStatus['text']; ?>
                    </div>
                </div>

                <div class="project-facts">
                    <?php if ($project['client_name']): ?>
                    <div>
                        <div style="font-size:12px;color:var(--muted);margin-bottom:4px">Client</div>
                        <div class="font-600"><?php echo htmlspecialchars($project['client_name']); ?></div>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($projectClients)): ?>
                    <div>
                        <div style="font-size:12px;color:var(--muted);margin-bottom:4px">Project Clients</div>
                        <div class="font-600">
                            <?php echo htmlspecialchars(implode(', ', array_map(static fn($c) => $c['name'] . (!empty($c['is_primary_billing']) ? ' (primary)' : ''), $projectClients))); ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($project['organization_name']): ?>
                    <div>
                        <div style="font-size:12px;color:var(--muted);margin-bottom:4px">Organization</div>
                        <div class="font-600"><?php echo htmlspecialchars($project['organization_name']); ?></div>
                    </div>
                    <?php endif; ?>

                    <div>
                        <div style="font-size:12px;color:var(--muted);margin-bottom:4px">Department</div>
                        <div class="font-600"><?php echo htmlspecialchars((string)($project['department_name'] ?? '') !== '' ? (string)$project['department_name'] : 'Org-level / no department'); ?></div>
                    </div>

                    <?php if ($project['estimated_start'] || $project['estimated_end']): ?>
                    <div>
                        <div style="font-size:12px;color:var(--muted);margin-bottom:4px">Timeline</div>
                        <div class="font-600">
                            <?php if ($project['estimated_start']): ?>
                                <?php echo date('M j, Y', strtotime($project['estimated_start'])); ?>
                            <?php endif; ?>
                            <?php if ($project['estimated_start'] && $project['estimated_end']): ?>
                                →
                            <?php endif; ?>
                            <?php if ($project['estimated_end']): ?>
                                <?php echo date('M j, Y', strtotime($project['estimated_end'])); ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div>
                        <div style="font-size:12px;color:var(--muted);margin-bottom:4px">Invoice Billing</div>
                        <div class="font-600">
                            <?php echo ($project['invoice_billing_period'] ?? 'per_invoice') === 'monthly' ? 'Monthly project billing' : 'Each invoice uses its own due date'; ?>
                            <?php if (($project['invoice_billing_period'] ?? 'per_invoice') === 'monthly'): ?>
                                <?php if ($project['invoice_net_terms_days'] !== null && $project['invoice_net_terms_days'] !== ''): ?>
                                    - NET <?php echo (int)$project['invoice_net_terms_days']; ?>
                                <?php else: ?>
                                    - system NET terms
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if ($project['notes']): ?>
                    <div>
                        <div style="font-size:12px;color:var(--muted);margin-bottom:4px">Notes</div>
                        <div style="white-space:pre-wrap"><?php echo htmlspecialchars($project['notes']); ?></div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="project-metrics">
                <div class="project-metric">
                    <div class="project-metric-label">Quotes</div>
                    <div class="project-metric-value"><?php echo count($quotes); ?></div>
                </div>
                <div class="project-metric">
                    <div class="project-metric-label">Contracts</div>
                    <div class="project-metric-value"><?php echo count($contracts); ?></div>
                </div>
                <div class="project-metric">
                    <div class="project-metric-label">Paid Invoices</div>
                    <div class="project-metric-value"><?php echo (int)$invoiceStats['paid_count']; ?></div>
                    <div class="project-metric-note" style="color:#067647">$<?php echo number_format((float)$invoiceStats['paid_total'], 2); ?></div>
                </div>
                <div class="project-metric">
                    <div class="project-metric-label">Open Invoices</div>
                    <div class="project-metric-value"><?php echo (int)$invoiceStats['unpaid_count']; ?></div>
                    <div class="project-metric-note" style="color:#b54708">$<?php echo number_format((float)$invoiceStats['unpaid_total'], 2); ?></div>
                </div>
                <div class="project-metric">
                    <div class="project-metric-label">Project Invoices</div>
                    <div class="project-metric-value"><?php echo count($projectInvoices); ?></div>
                </div>
            </div>

            <?php if (!empty($_GET['billing_msg'])): ?>
                <div class="alert alert-info" style="margin-bottom:16px"><?php echo htmlspecialchars((string)$_GET['billing_msg']); ?></div>
            <?php endif; ?>

            <?php if (!empty($projectInvoices)): ?>
            <div class="project-panel">
                <div class="project-section-head">
                    <h2 style="margin:0;font-size:20px">Project Invoices</h2>
                    <a class="btn btn-sm" href="/?page=project/project-invoices-list&project_id=<?php echo $projectId; ?>">View All</a>
                </div>
                <div style="display:grid;gap:10px">
                    <?php foreach (array_slice($projectInvoices, 0, 5) as $projectInvoice): ?>
                        <div class="project-doc-row">
                            <div>
                                <div class="font-600">PI-<?php echo htmlspecialchars((string)($projectInvoice['doc_number'] ?: $projectInvoice['id'])); ?></div>
                                <div style="font-size:13px;color:var(--muted)">
                                    <?php echo htmlspecialchars(date('M j', strtotime($projectInvoice['billing_period_start']))); ?> -
                                    <?php echo htmlspecialchars(date('M j, Y', strtotime($projectInvoice['billing_period_end']))); ?>
                                    · <?php echo (int)$projectInvoice['child_count']; ?> invoice(s)
                                    · <?php echo htmlspecialchars(ucfirst((string)$projectInvoice['status'])); ?>
                                </div>
                            </div>
                            <div style="display:flex;gap:10px;align-items:center">
                                <div style="font-weight:700">$<?php echo number_format((float)$projectInvoice['balance_due'], 2); ?> due</div>
                                <a class="btn btn-sm" href="/?page=project/project-invoice-details&id=<?php echo (int)$projectInvoice['id']; ?>">View</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Associated Documents Section -->
            <div class="project-panel">
                <div class="project-section-head"><h2>Associated Documents</h2></div>

                <!-- Quotes -->
                <?php if (!empty($quotes)): ?>
                <div style="margin-bottom:24px">
                    <h3 style="margin:0 0 12px 0;font-size:16px;color:#374151">Quotes (<?php echo count($quotes); ?>)</h3>
                    <div class="grid">
                        <?php foreach ($quotes as $quote): ?>
                        <a href="/?page=quote/quote-details&id=<?php echo (int)$quote['id']; ?>" 
                           style="display:flex;justify-content:space-between;align-items:center;padding:12px;border:1px solid #e5e7eb;border-radius:8px;text-decoration:none;color:inherit;background:#f9fafb">
                            <div>
                                <div class="font-600">Quote #<?php echo e($quote['doc_number'] ?? $quote['id']); ?></div>
                                <div style="font-size:13px;color:var(--muted)">
                                    <?php echo e(ucfirst($quote['status'])); ?> · 
                                    <?php echo date('M j, Y', strtotime($quote['created_at'])); ?>
                                </div>
                            </div>
                            <div style="font-weight:600;color:var(--nav-accent)">
                                $<?php echo number_format($quote['total'], 2); ?>
                            </div>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Contracts -->
                <?php if (!empty($contracts)): ?>
                <div style="margin-bottom:24px">
                    <h3 style="margin:0 0 12px 0;font-size:16px;color:#374151">Contracts (<?php echo count($contracts); ?>)</h3>
                    <div class="grid">
                        <?php foreach ($contracts as $contract): ?>
                        <a href="/?page=contract/contract-details&id=<?php echo (int)$contract['id']; ?>" 
                           style="display:flex;justify-content:space-between;align-items:center;padding:12px;border:1px solid #e5e7eb;border-radius:8px;text-decoration:none;color:inherit;background:#f9fafb">
                            <div>
                                <div class="font-600">Contract #<?php echo e($contract['doc_number'] ?? $contract['id']); ?></div>
                                <div style="font-size:13px;color:var(--muted)">
                                    <?php echo e(ucfirst($contract['status'])); ?> · 
                                    <?php echo date('M j, Y', strtotime($contract['created_at'])); ?>
                                </div>
                            </div>
                            <div style="font-weight:600;color:var(--nav-accent)">
                                $<?php echo number_format($contract['total'], 2); ?>
                            </div>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Invoices -->
                <?php if (!empty($invoices)): ?>
                <div style="margin-bottom:24px">
                    <h3 style="margin:0 0 12px 0;font-size:16px;color:#374151">Invoices (<?php echo count($invoices); ?>)</h3>
                    <div class="grid">
                        <?php foreach ($invoices as $invoice): ?>
                        <div style="display:flex;justify-content:space-between;gap:12px;align-items:center;padding:12px;border:1px solid #e5e7eb;border-radius:8px;background:#f9fafb">
                            <div>
                                <div class="font-600">Invoice #<?php echo e($invoice['doc_number'] ?? $invoice['id']); ?></div>
                                <div style="font-size:13px;color:var(--muted)">
                                    <?php echo e(ucfirst($invoice['status'])); ?> · 
                                    <?php echo date('M j, Y', strtotime($invoice['created_at'])); ?>
                                </div>
                                <div class="document-actions" style="margin-top:8px">
                                    <a href="/?page=invoice/invoice-details&id=<?php echo (int)$invoice['id']; ?>" class="btn btn-sm">View</a>
                                    <a href="/?page=invoice/invoice-pdf&id=<?php echo (int)$invoice['id']; ?>" target="_blank" rel="noopener" class="btn btn-sm">View PDF</a>
                                    <form method="post" action="/?page=invoice/email-send" style="display:inline">
                                        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                                        <input type="hidden" name="type" value="invoice">
                                        <input type="hidden" name="id" value="<?php echo (int)$invoice['id']; ?>">
                                        <input type="hidden" name="redirect_to" value="<?php echo htmlspecialchars($projectReturnUrl); ?>">
                                        <button type="submit" class="btn btn-sm btn-success">Email</button>
                                    </form>
                                </div>
                            </div>
                            <div style="font-weight:600;color:var(--nav-accent);white-space:nowrap">
                                $<?php echo number_format($invoice['total'], 2); ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Form Documents -->
                <?php if (!empty($formDocuments)): ?>
                <div>
                    <h3 style="margin:0 0 12px 0;font-size:16px;color:#374151">Files (<?php echo count($formDocuments); ?>)</h3>
                    <div class="grid">
                        <?php foreach ($formDocuments as $doc): ?>
                        <a href="/?page=financial/form-detail&id=<?php echo (int)$doc['category_id']; ?>" 
                           style="display:flex;justify-content:space-between;align-items:center;padding:12px;border:1px solid #e5e7eb;border-radius:8px;text-decoration:none;color:inherit;background:#f9fafb">
                            <div>
                                <div class="font-600"><?php echo htmlspecialchars($doc['file_name']); ?></div>
                                <div style="font-size:13px;color:var(--muted)">
                                    <?php echo htmlspecialchars($doc['category_title'] ?? 'Uncategorized'); ?> · 
                                    <?php echo date('M j, Y', strtotime($doc['uploaded_at'])); ?>
                                </div>
                            </div>
                            <div style="font-size:13px;color:var(--muted)">
                                <?php echo e(strtoupper(pathinfo($doc['file_path'], PATHINFO_EXTENSION))); ?>
                            </div>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (empty($quotes) && empty($contracts) && empty($invoices) && empty($formDocuments)): ?>
                <div style="text-align:center;padding:48px;color:var(--muted)">
                    <div style="font-size:48px;margin-bottom:16px">📄</div>
                    <div style="font-size:16px">No documents associated with this project yet</div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Sidebar Actions -->
        <div class="project-sidebar">
            <!-- Status Management -->
            <div class="project-panel">
                <div class="project-sidebar-title">Change Status</div>
                <div class="grid">
                    <?php foreach ($statusColors as $statusKey => $statusInfo): ?>
                        <?php if ($statusKey !== $project['status']): ?>
                        <form method="post" action="/?page=project/projects-update-status" style="margin:0">
                            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                            <input type="hidden" name="project_id" value="<?php echo $projectId; ?>">
                            <input type="hidden" name="status" value="<?php echo $statusKey; ?>">
                            <input type="hidden" name="redirect" value="/?page=project/projects-details&amp;id=<?php echo $projectId; ?>">
                            <button type="submit" 
                                    style="width:100%;padding:10px;border-radius:6px;border:1px solid #e5e7eb;background:<?php echo $statusInfo['bg']; ?>;color:<?php echo $statusInfo['color']; ?>;font-weight:600;cursor:pointer;text-align:left">
                                → <?php echo $statusInfo['text']; ?>
                            </button>
                        </form>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="project-panel">
                <div class="project-sidebar-title">Billing Actions</div>
                <div class="project-muted" style="margin-bottom:12px">
                    <?php if ($monthlyBilling): ?>Next automatic cycle: <?php echo htmlspecialchars($nextBillingLabel); ?><?php else: ?>Monthly billing is currently disabled.<?php endif; ?>
                </div>
                <div class="project-actions">
                    <form method="post" action="/?page=project/project-invoice-generate" onsubmit="return confirm('Generate a project invoice for the current month through today without emailing it?');" style="margin:0">
                        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                        <input type="hidden" name="project_id" value="<?php echo $projectId; ?>">
                        <input type="hidden" name="period" value="current">
                        <button type="submit" class="btn btn-sm" style="width:100%">Generate Only</button>
                    </form>
                    <form method="post" action="/?page=project/project-invoice-generate" onsubmit="return confirm('Generate this project invoice and email the selected default recipients?');" style="margin:0">
                        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                        <input type="hidden" name="project_id" value="<?php echo $projectId; ?>">
                        <input type="hidden" name="period" value="current">
                        <input type="hidden" name="send_email" value="1">
                        <button type="submit" class="btn btn-sm btn-success" style="width:100%">Generate &amp; Email</button>
                    </form>
                    <a href="/?page=project/project-invoices-list&project_id=<?php echo $projectId; ?>" class="btn btn-sm project-action-wide">View Project Invoices</a>
                    <div class="project-sidebar-title project-action-wide" style="margin:10px 0 0">Create Document</div>
                    <a href="/?page=quote/quotes-create&project_id=<?php echo $projectId; ?>" 
                       style="display:block;padding:10px;border-radius:6px;background:#f9fafb;border:1px solid #e5e7eb;text-align:center;text-decoration:none;color:inherit;font-weight:600">
                        📄 Create Quote
                    </a>
                    <a href="/?page=contract/contracts-create&project_id=<?php echo $projectId; ?>" 
                       style="display:block;padding:10px;border-radius:6px;background:#f9fafb;border:1px solid #e5e7eb;text-align:center;text-decoration:none;color:inherit;font-weight:600">
                        📋 Create Contract
                    </a>
                    <a href="/?page=invoice/invoices-create&project_id=<?php echo $projectId; ?>" 
                       style="display:block;padding:10px;border-radius:6px;background:#f9fafb;border:1px solid #e5e7eb;text-align:center;text-decoration:none;color:inherit;font-weight:600">
                        💰 Create Invoice
                    </a>
                </div>
            </div>

            <!-- Project Settings -->
            <div class="project-panel">
                <div class="project-section-head" style="margin-bottom:10px">
                    <div>
                        <div class="project-sidebar-title" style="margin-bottom:3px">Project Settings</div>
                        <div class="project-muted">Control this project’s scope, contact list, invoice recipients, and content-link access.</div>
                    </div>
                </div>
                <form method="post" action="/?page=project/projects-update" class="project-settings-form">
                    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                    <input type="hidden" name="id" value="<?php echo $projectId; ?>">
                    <input type="hidden" name="organization_id" value="<?php echo (int)($project['organization_id'] ?? 0); ?>">

                    <section class="project-settings-card">
                        <h3>1. Project scope</h3>
                        <p>This decides the project’s organization or department context. Department links attach to invoices for department projects; organization links only inherit when the link explicitly allows it.</p>
                        <label class="project-field">
                            <span>Project Name</span>
                            <input name="name" value="<?php echo htmlspecialchars($project['name']); ?>">
                        </label>
                        <label class="project-field">
                            <span>Department <span class="project-help-icon" title="Leave this blank for organization-level work. Choose a department only when this project belongs to a specific group within the organization.">?</span></span>
                            <select name="department_id">
                                <option value="">No department / org-level project</option>
                                <?php foreach ($projectDepartments as $department): ?>
                                    <?php $departmentId = (int)$department['id']; ?>
                                    <option value="<?php echo $departmentId; ?>" <?php echo (int)($project['department_id'] ?? 0) === $departmentId ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($department['name'] . (!empty($department['folder_name']) ? ' — ' . $department['folder_name'] : '')); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small>Leave blank if the organization has no departments or this project should use organization-level settings.</small>
                        </label>
                    </section>

                    <section class="project-settings-card">
                        <h3>2. Project contacts</h3>
                        <p>This is a project-only contact list. It does not automatically include every contact in the organization or department.</p>
                        <div class="project-info-box">
                            Uncheck a current contact to remove them from this project only. Choose the primary invoice receiver separately. Invoice email recipients and content-link viewers are also separate checkboxes.
                        </div>
                        <div style="display:grid;gap:9px">
                            <?php if (empty($projectClients)): ?>
                                <div class="project-muted" style="padding:10px;border:1px dashed #d1d5db;border-radius:8px">No project contacts attached yet.</div>
                            <?php else: ?>
                                <?php foreach ($projectClients as $client): ?>
                                    <?php
                                        $clientId = (int)$client['id'];
                                        $isPrimaryClient = (int)($project['client_id'] ?? 0) === $clientId;
                                    ?>
                                    <div class="project-contact-card <?php echo $isPrimaryClient ? 'is-primary' : ''; ?>">
                                        <input type="checkbox" name="project_client_ids[]" value="<?php echo $clientId; ?>" checked style="margin-top:3px" title="Uncheck to remove from this project">
                                        <div>
                                            <div class="project-contact-heading">
                                                <div>
                                                    <div style="font-weight:700"><?php echo htmlspecialchars($client['name']); ?></div>
                                                    <div style="font-size:12px;color:var(--muted)"><?php echo !empty($client['email']) ? htmlspecialchars($client['email']) : 'No email address'; ?></div>
                                                </div>
                                                <?php if ($isPrimaryClient || !empty($client['is_primary_billing'])): ?>
                                                    <span class="project-pill">Primary</span>
                                                <?php endif; ?>
                                                <?php if (in_array($clientId, $projectDepartmentContactIds, true)): ?>
                                                    <span class="project-pill">Department</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="project-contact-options">
                                                <label class="project-check">
                                                    <input type="radio" name="client_id" value="<?php echo $clientId; ?>" <?php echo $isPrimaryClient ? 'checked' : ''; ?>>
                                                    <span>Primary invoice receiver</span>
                                                </label>
                                                <label class="project-check">
                                                    <input type="checkbox" name="project_invoice_email_client_ids[]" value="<?php echo $clientId; ?>" <?php echo in_array($clientId, $selectedProjectInvoiceRecipientIds, true) ? 'checked' : ''; ?> <?php echo empty($client['email']) ? 'disabled' : ''; ?>>
                                                    <span>Receives project invoice emails<?php echo empty($client['email']) ? ' (no email saved)' : ''; ?></span>
                                                </label>
                                                <label class="project-check">
                                                    <input type="checkbox" name="project_invoice_link_client_ids[]" value="<?php echo $clientId; ?>" <?php echo in_array($clientId, $selectedProjectInvoiceLinkClientIds, true) ? 'checked' : ''; ?>>
                                                    <span>Can view invoice content links</span>
                                                </label>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <label class="project-field">
                            <span>Add contacts from this project’s scope</span>
                            <?php if (empty($allClients) || count($allClients) === count($selectedProjectClientIds)): ?>
                                <div class="project-muted" style="padding:10px;border:1px dashed #d1d5db;border-radius:8px">No additional contacts are available in this scope.</div>
                            <?php else: ?>
                            <div class="project-add-contact-list">
                                <?php foreach ($allClients as $client): ?>
                                    <?php if (in_array((int)$client['id'], $selectedProjectClientIds, true)) { continue; } ?>
                                    <label class="project-check">
                                        <input type="checkbox" name="project_client_ids[]" value="<?php echo (int)$client['id']; ?>">
                                        <span>
                                            <?php echo htmlspecialchars($client['name'] . (!empty($client['email']) ? ' - ' . $client['email'] : '')); ?>
                                            <?php if (in_array((int)$client['id'], $projectDepartmentContactIds, true)): ?>
                                                <span class="project-pill" style="margin-left:4px">Department</span>
                                            <?php endif; ?>
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                            <small>Checked contacts are added when you save. After they are attached, use their row above to enable invoice emails or content-link access.</small>
                        </label>
                    </section>

                    <section class="project-settings-card">
                        <h3>3. Invoice defaults</h3>
                        <p>Generated project invoices use the primary invoice receiver as the billed client. Emails only go to attached project contacts checked above as invoice recipients.</p>
                        <label class="project-field">
                            <span>Billing Period</span>
                            <select name="invoice_billing_period">
                                <option value="monthly" <?php echo ($project['invoice_billing_period'] ?? 'monthly') === 'monthly' ? 'selected' : ''; ?>>Monthly project billing</option>
                                <option value="per_invoice" <?php echo ($project['invoice_billing_period'] ?? '') === 'per_invoice' ? 'selected' : ''; ?>>Each invoice on its own</option>
                            </select>
                        </label>
                        <label class="project-check" style="padding:10px;border:1px solid #dfe3e8;border-radius:8px;background:#fff">
                            <input type="checkbox" name="project_invoice_auto_email" value="1" <?php echo $autoEmailEnabled ? 'checked' : ''; ?>>
                            <span>
                                Automatically email monthly project invoices
                                <small style="display:block">Uses the selected project invoice email recipients after the monthly invoice is generated.</small>
                            </span>
                        </label>
                        <label class="project-field">
                            <span>Project NET Days</span>
                            <input type="number" min="0" step="1" name="invoice_net_terms_days" value="<?php echo htmlspecialchars((string)($project['invoice_net_terms_days'] ?? '')); ?>" placeholder="System default">
                            <small>Leave blank to use the system default due-date terms.</small>
                        </label>
                    </section>

                    <section class="project-settings-card">
                        <h3>4. Schedule &amp; notes</h3>
                        <div class="project-settings-grid">
                            <label class="project-field">
                                <span>Start</span>
                                <input type="date" name="estimated_start" value="<?php echo htmlspecialchars((string)($project['estimated_start'] ?? '')); ?>">
                            </label>
                            <label class="project-field">
                                <span>End</span>
                                <input type="date" name="estimated_end" value="<?php echo htmlspecialchars((string)($project['estimated_end'] ?? '')); ?>">
                            </label>
                        </div>
                        <label class="project-field">
                            <span>Notes</span>
                            <textarea name="notes" rows="3"><?php echo htmlspecialchars((string)($project['notes'] ?? '')); ?></textarea>
                        </label>
                    </section>

                    <button type="submit" class="btn btn-sm project-settings-save">Save Project Settings</button>
                </form>
            </div>

            <!-- Attach Existing Documents -->
            <div class="project-panel">
                <div class="project-sidebar-title" style="margin-bottom:6px">Add Existing Document</div>
                <div style="font-size:13px;color:var(--muted);margin-bottom:12px">Available unassigned documents for this client or organization.</div>
                <div class="grid">
                    <?php $renderDocumentAttachForm('quote', 'Quote', $availableQuotes); ?>
                    <?php $renderDocumentAttachForm('contract', 'Contract', $availableContracts); ?>
                    <?php $renderDocumentAttachForm('invoice', 'Invoice', $availableInvoices); ?>
                </div>
            </div>

            <!-- Danger Zone -->
            <div class="project-panel project-danger">
                <div class="project-sidebar-title">Danger Zone</div>
                <form method="post" action="/?page=project/projects-delete" onsubmit="return confirm('Are you sure you want to delete this project?');" style="margin:0">
                    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                    <input type="hidden" name="id" value="<?php echo $projectId; ?>">
                    <input type="hidden" name="redirect" value="/?page=project/projects-list">
                    <button type="submit" style="width:100%;padding:10px;border-radius:6px;background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;font-weight:600;cursor:pointer">
                        🗑️ Delete Project
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
