<?php
// src/views/pages/project/projects-details.php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/csrf.php';

$projectId = (int)($_GET['id'] ?? 0);

if (!$projectId) {
    header('Location: /?page=project/projects-list');
    exit;
}

// Fetch project details
$stmt = $pdo->prepare('
    SELECT p.*, c.name AS client_name, o.name AS organization_name
    FROM projects p
    LEFT JOIN clients c ON c.id = p.client_id
    LEFT JOIN organizations o ON o.id = p.organization_id
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
$stmt = $pdo->prepare('SELECT id, doc_number, status, total, created_at FROM invoices WHERE project_id = ? ORDER BY created_at DESC');
$stmt->execute([$projectId]);
$invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

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

// Status colors
$statusColors = [
    'not_started' => ['bg' => '#fef3c7', 'color' => '#92400e', 'text' => 'Not Started'],
    'active' => ['bg' => '#d1fae5', 'color' => '#065f46', 'text' => 'Active'],
    'overdue' => ['bg' => '#fee2e2', 'color' => '#dc2626', 'text' => 'Overdue'],
    'completed' => ['bg' => '#e0e7ff', 'color' => '#3730a3', 'text' => 'Completed'],
    'cancelled' => ['bg' => '#f3f4f6', 'color' => '#6b7280', 'text' => 'Cancelled']
];

$currentStatus = $statusColors[$project['status']] ?? $statusColors['not_started'];

?>

<div style="max-width:1400px;margin:0 auto;padding:24px">
    <div style="margin-bottom:24px">
        <a href="/?page=project/projects-list" style="color:var(--nav-accent);text-decoration:none;font-size:14px">
            ← Back to Projects
        </a>
    </div>

    <div style="display:grid;grid-template-columns:1fr 350px;gap:24px;align-items:start">
        <!-- Main Content -->
        <div>
            <!-- Project Header -->
            <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:24px;margin-bottom:24px">
                <div style="display:flex;justify-content:space-between;align-items:start;margin-bottom:16px">
                    <div>
                        <h1 style="margin:0 0 8px 0;font-size:28px"><?php echo htmlspecialchars($project['name']); ?></h1>
                        <div style="font-size:14px;color:var(--muted)">
                            Created <?php echo date('F j, Y', strtotime($project['created_at'])); ?>
                        </div>
                    </div>
                    <div style="padding:8px 16px;border-radius:8px;background:<?php echo $currentStatus['bg']; ?>;color:<?php echo $currentStatus['color']; ?>;font-weight:600">
                        <?php echo $currentStatus['text']; ?>
                    </div>
                </div>

                <div style="display:grid;gap:16px;padding-top:16px;border-top:1px solid #e5e7eb">
                    <?php if ($project['client_name']): ?>
                    <div>
                        <div style="font-size:12px;color:var(--muted);margin-bottom:4px">Client</div>
                        <div class="font-600"><?php echo htmlspecialchars($project['client_name']); ?></div>
                    </div>
                    <?php endif; ?>

                    <?php if ($project['organization_name']): ?>
                    <div>
                        <div style="font-size:12px;color:var(--muted);margin-bottom:4px">Organization</div>
                        <div class="font-600"><?php echo htmlspecialchars($project['organization_name']); ?></div>
                    </div>
                    <?php endif; ?>

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

                    <?php if ($project['notes']): ?>
                    <div>
                        <div style="font-size:12px;color:var(--muted);margin-bottom:4px">Notes</div>
                        <div style="white-space:pre-wrap"><?php echo htmlspecialchars($project['notes']); ?></div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Associated Documents Section -->
            <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:24px">
                <h2 style="margin:0 0 16px 0;font-size:20px">Associated Documents</h2>

                <!-- Quotes -->
                <?php if (!empty($quotes)): ?>
                <div style="margin-bottom:24px">
                    <h3 style="margin:0 0 12px 0;font-size:16px;color:#374151">Quotes (<?php echo count($quotes); ?>)</h3>
                    <div class="grid">
                        <?php foreach ($quotes as $quote): ?>
                        <a href="/?page=quote/quote-details&id=<?php echo $quote['id']; ?>" 
                           style="display:flex;justify-content:space-between;align-items:center;padding:12px;border:1px solid #e5e7eb;border-radius:8px;text-decoration:none;color:inherit;background:#f9fafb">
                            <div>
                                <div class="font-600">Quote #<?php echo $quote['doc_number'] ?? $quote['id']; ?></div>
                                <div style="font-size:13px;color:var(--muted)">
                                    <?php echo ucfirst($quote['status']); ?> · 
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
                        <a href="/?page=contract/contract-details&id=<?php echo $contract['id']; ?>" 
                           style="display:flex;justify-content:space-between;align-items:center;padding:12px;border:1px solid #e5e7eb;border-radius:8px;text-decoration:none;color:inherit;background:#f9fafb">
                            <div>
                                <div class="font-600">Contract #<?php echo $contract['doc_number'] ?? $contract['id']; ?></div>
                                <div style="font-size:13px;color:var(--muted)">
                                    <?php echo ucfirst($contract['status']); ?> · 
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
                        <a href="/?page=invoice/invoice-details&id=<?php echo $invoice['id']; ?>" 
                           style="display:flex;justify-content:space-between;align-items:center;padding:12px;border:1px solid #e5e7eb;border-radius:8px;text-decoration:none;color:inherit;background:#f9fafb">
                            <div>
                                <div class="font-600">Invoice #<?php echo $invoice['doc_number'] ?? $invoice['id']; ?></div>
                                <div style="font-size:13px;color:var(--muted)">
                                    <?php echo ucfirst($invoice['status']); ?> · 
                                    <?php echo date('M j, Y', strtotime($invoice['created_at'])); ?>
                                </div>
                            </div>
                            <div style="font-weight:600;color:var(--nav-accent)">
                                $<?php echo number_format($invoice['total'], 2); ?>
                            </div>
                        </a>
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
                        <a href="/?page=financial/form-detail&id=<?php echo $doc['category_id']; ?>" 
                           style="display:flex;justify-content:space-between;align-items:center;padding:12px;border:1px solid #e5e7eb;border-radius:8px;text-decoration:none;color:inherit;background:#f9fafb">
                            <div>
                                <div class="font-600"><?php echo htmlspecialchars($doc['file_name']); ?></div>
                                <div style="font-size:13px;color:var(--muted)">
                                    <?php echo htmlspecialchars($doc['category_title'] ?? 'Uncategorized'); ?> · 
                                    <?php echo date('M j, Y', strtotime($doc['uploaded_at'])); ?>
                                </div>
                            </div>
                            <div style="font-size:13px;color:var(--muted)">
                                <?php echo strtoupper(pathinfo($doc['file_path'], PATHINFO_EXTENSION)); ?>
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
        <div>
            <!-- Status Management -->
            <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:16px;margin-bottom:16px">
                <div style="font-weight:600;margin-bottom:12px">Change Status</div>
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
            <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:16px;margin-bottom:16px">
                <div style="font-weight:600;margin-bottom:12px">Quick Actions</div>
                <div class="grid">
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

            <!-- Danger Zone -->
            <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:16px">
                <div style="font-weight:600;margin-bottom:12px;color:#991b1b">Danger Zone</div>
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
