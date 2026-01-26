<?php
// src/views/pages/financial/form-detail.php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/csrf.php';

$categoryId = (int)($_GET['id'] ?? 0);
$orgId = 1; // Should come from session/user context

if (!$categoryId) {
    header('Location: /?page=financial/forms-list');
    exit;
}

// Fetch category and document details
$stmt = $pdo->prepare('
    SELECT 
        fc.*,
        fd.id as doc_id,
        fd.file_path,
        fd.file_name,
        fd.file_size,
        fd.mime_type,
        fd.uploaded_at,
        u.username as uploaded_by_name
    FROM form_categories fc
    LEFT JOIN form_documents fd ON fc.id = fd.category_id
    LEFT JOIN users u ON fd.uploaded_by = u.id
    WHERE fc.id = ? AND fc.org_id = ?
');
$stmt->execute([$categoryId, $orgId]);
$category = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$category) {
    header('Location: /?page=financial/forms-list');
    exit;
}

$hasDocument = !empty($category['file_path']);
$fileExt = $hasDocument ? strtolower(pathinfo($category['file_path'], PATHINFO_EXTENSION)) : '';
$isPdf = $fileExt === 'pdf';

// Fetch clients for email modal
$stmt = $pdo->prepare('SELECT id, name, email FROM clients WHERE archived = 0 ORDER BY name');
$stmt->execute();
$clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch organizations for email modal
$stmt = $pdo->prepare('SELECT id, name FROM organizations ORDER BY name');
$stmt->execute();
$organizations = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div style="max-width:1400px;margin:0 auto;padding:24px">
    <div style="margin-bottom:24px">
        <a href="/?page=financial/forms-list" style="color:var(--nav-accent);text-decoration:none;font-size:14px">
            ← Back to Forms & Docs
        </a>
    </div>

    <?php if (!$hasDocument): ?>
        <!-- No Document Uploaded State -->
        <div style="max-width:800px;margin:0 auto">
            <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:32px;text-align:center">
                <h1 style="margin:0 0 8px 0;font-size:24px"><?php echo htmlspecialchars($category['title']); ?></h1>
                <p style="margin:0 0 24px 0;color:var(--muted)">No document has been uploaded to this category yet</p>
                
                <div style="font-size:64px;margin-bottom:24px">📂</div>
                
                <button onclick="showUploadModal()" 
                        style="padding:12px 24px;border-radius:8px;background:var(--nav-accent);color:#fff;border:0;font-weight:600;cursor:pointer">
                    Upload Document
                </button>
            </div>
        </div>
    <?php else: ?>
        <!-- Document View -->
        <div style="display:grid;grid-template-columns:1fr 400px;gap:32px;align-items:start">
            <!-- Document Preview -->
            <div style="min-width:0">
                <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden">
                    <?php 
                    $fileParam = str_replace('/src/uploads/', '', $category['file_path']);
                    $fileUrl = '/?page=serve-upload&file=' . urlencode($fileParam);
                    ?>
                    <?php if ($isPdf): ?>
                        <div style="height:900px;max-height:900px;min-height:900px">
                            <iframe src="<?php echo htmlspecialchars($fileUrl); ?>" 
                                    style="width:100%;height:900px;max-height:900px;border:0;display:block"></iframe>
                        </div>
                    <?php else: ?>
                        <div style="padding:24px;text-align:center;background:#f9fafb">
                            <img src="<?php echo htmlspecialchars($fileUrl); ?>" 
                                 alt="<?php echo htmlspecialchars($category['title']); ?>" 
                                 style="max-width:100%;height:auto;border-radius:8px;display:block;margin:0 auto">
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Document Info & Actions -->
            <div style="width:400px;max-width:400px;min-width:400px">
                <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:24px;margin-bottom:16px">
                    <h1 style="margin:0 0 16px 0;font-size:24px"><?php echo htmlspecialchars($category['title']); ?></h1>
                    
                    <div style="display:grid;gap:16px">
                        <div>
                            <div style="font-size:12px;color:var(--muted);margin-bottom:4px">File Name</div>
                            <div style="font-weight:600;word-break:break-word">
                                <?php echo htmlspecialchars($category['file_name']); ?>
                            </div>
                        </div>

                        <div style="padding-top:16px;border-top:1px solid #e5e7eb">
                            <div style="font-size:12px;color:var(--muted);margin-bottom:4px">File Type</div>
                            <div style="font-weight:600;text-transform:uppercase">
                                <?php echo htmlspecialchars($fileExt); ?>
                            </div>
                        </div>

                        <?php if ($category['file_size']): ?>
                        <div>
                            <div style="font-size:12px;color:var(--muted);margin-bottom:4px">File Size</div>
                            <div style="font-weight:600">
                                <?php echo number_format($category['file_size'] / 1024 / 1024, 2); ?> MB
                            </div>
                        </div>
                        <?php endif; ?>

                        <div>
                            <div style="font-size:12px;color:var(--muted);margin-bottom:4px">Uploaded</div>
                            <div style="font-weight:600">
                                <?php echo date('F j, Y', strtotime($category['uploaded_at'])); ?>
                            </div>
                            <?php if ($category['uploaded_by_name']): ?>
                                <div style="font-size:13px;color:var(--muted);margin-top:2px">
                                    by <?php echo htmlspecialchars($category['uploaded_by_name']); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Actions -->
                <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:16px">
                    <div style="font-weight:600;margin-bottom:12px">Actions</div>
                    
                    <div style="display:grid;gap:8px">
                        <!-- Download -->
                        <a href="<?php echo htmlspecialchars($fileUrl . '&download=1'); ?>" 
                           download
                           style="display:block;padding:10px;border-radius:6px;background:#f9fafb;border:1px solid #e5e7eb;text-align:center;text-decoration:none;color:inherit;font-weight:600">
                            📥 Download
                        </a>

                        <!-- View in New Tab -->
                        <a href="<?php echo htmlspecialchars($fileUrl); ?>" 
                           target="_blank"
                           style="display:block;padding:10px;border-radius:6px;background:#f9fafb;border:1px solid #e5e7eb;text-align:center;text-decoration:none;color:inherit;font-weight:600">
                            🔗 View in New Tab
                        </a>

                        <!-- Email -->
                        <button onclick="showEmailModal()" 
                                style="width:100%;padding:10px;border-radius:6px;background:#f9fafb;border:1px solid #e5e7eb;font-weight:600;cursor:pointer">
                            ✉️ Email
                        </button>

                        <!-- Replace File -->
                        <button onclick="showUploadModal()" 
                                style="width:100%;padding:10px;border-radius:6px;background:#f9fafb;border:1px solid #e5e7eb;font-weight:600;cursor:pointer;margin-top:8px">
                            🔄 Replace File
                        </button>

                        <!-- Delete -->
                        <button onclick="confirmDelete()" 
                                style="width:100%;padding:10px;border-radius:6px;background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;font-weight:600;cursor:pointer">
                            🗑️ Delete Document
                        </button>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Upload/Replace Modal -->
<div id="uploadModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center">
    <div style="background:#fff;border-radius:12px;padding:24px;max-width:500px;width:90%;max-height:90vh;overflow-y:auto">
        <h3 style="margin:0 0 16px 0"><?php echo $hasDocument ? 'Replace' : 'Upload'; ?> Document</h3>
        
        <form id="uploadForm" enctype="multipart/form-data">
            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
            <input type="hidden" name="action" value="upload_document">
            <input type="hidden" name="category_id" value="<?php echo $categoryId; ?>">
            
            <label style="display:block;margin-bottom:16px">
                <div style="margin-bottom:4px;font-weight:600">Document File *</div>
                <input type="file" name="document_file" required accept="image/*,.pdf"
                       style="width:100%;padding:10px;border:2px dashed #ddd;border-radius:8px;cursor:pointer">
                <div style="margin-top:4px;font-size:13px;color:var(--muted)">
                    Accepts: JPEG, PNG, GIF, PDF (Max 20MB)
                </div>
            </label>
            
            <label style="display:block;margin-bottom:16px">
                <div style="margin-bottom:4px;font-weight:600">Project (Optional)</div>
                <select name="project_id" id="projectSelectFormDetail" style="width:100%;padding:10px;border:1px solid #ddd;border-radius:8px">
                    <option value="">-- No Project --</option>
                    <?php
                    $projectsStmt = $pdo->query('SELECT id, name, status FROM projects ORDER BY name ASC');
                    $allProjects = $projectsStmt->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($allProjects as $proj):
                    ?>
                        <option value="<?php echo $proj['id']; ?>">
                            <?php echo htmlspecialchars($proj['name']); ?> (<?php echo ucwords(str_replace('_', ' ', $proj['status'])); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <div style="margin-top:4px;font-size:13px;color:var(--muted)">
                    Associate this document with a project
                </div>
            </label>

            <div style="display:flex;gap:12px">
                <button type="submit" id="uploadBtn"
                        style="flex:1;padding:10px;border-radius:8px;border:0;background:var(--nav-accent);color:#fff;font-weight:600;cursor:pointer">
                    Upload
                </button>
                <button type="button" onclick="closeUploadModal()"
                        style="flex:1;padding:10px;border-radius:8px;border:1px solid #ddd;background:#fff;font-weight:600;cursor:pointer">
                    Cancel
                </button>
            </div>

            <div id="uploadMessage" style="display:none;margin-top:16px;padding:12px;border-radius:8px"></div>
        </form>
    </div>
</div>

<?php if ($hasDocument): ?>
<!-- Email Modal -->
<div id="emailModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center">
    <div style="background:#fff;border-radius:12px;padding:24px;max-width:500px;width:90%;max-height:90vh;overflow-y:auto">
        <h3 style="margin:0 0 16px 0">Email Form to Client</h3>
        
        <form id="emailForm">
            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
            <input type="hidden" name="action" value="email_form">
            <input type="hidden" name="category_id" value="<?php echo $categoryId; ?>">
            
            <div style="margin-bottom:16px">
                <label style="display:block;margin-bottom:8px;font-weight:600">Send To:</label>
                <div style="display:flex;gap:8px;margin-bottom:12px">
                    <button type="button" onclick="selectRecipientType('client')" id="clientTypeBtn"
                            style="flex:1;padding:8px;border-radius:6px;border:1px solid #ddd;background:#fff;cursor:pointer">
                        Individual Client
                    </button>
                    <button type="button" onclick="selectRecipientType('clients')" id="clientsTypeBtn"
                            style="flex:1;padding:8px;border-radius:6px;border:1px solid #ddd;background:#fff;cursor:pointer">
                        Multiple Clients
                    </button>
                    <button type="button" onclick="selectRecipientType('organization')" id="orgTypeBtn"
                            style="flex:1;padding:8px;border-radius:6px;border:1px solid #ddd;background:#fff;cursor:pointer">
                        Organization
                    </button>
                </div>
            </div>

            <input type="hidden" name="recipient_type" id="recipientType">
            
            <div id="clientSelector" style="display:none;margin-bottom:16px">
                <label style="display:block;margin-bottom:4px;font-weight:600">Select Client *</label>
                <select name="recipient_id" id="clientSelect" 
                        style="width:100%;padding:10px;border:1px solid #ddd;border-radius:8px">
                    <option value="">-- Select a client --</option>
                    <?php foreach ($clients as $client): ?>
                        <option value="<?php echo $client['id']; ?>">
                            <?php echo htmlspecialchars($client['name']); ?>
                            <?php if ($client['email']): ?>
                                (<?php echo htmlspecialchars($client['email']); ?>)
                            <?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div id="clientsSelector" style="display:none;margin-bottom:16px">
                <label style="display:block;margin-bottom:4px;font-weight:600">Select Clients *</label>
                <div style="max-height:200px;overflow-y:auto;border:1px solid #ddd;border-radius:8px;padding:8px">
                    <?php foreach ($clients as $client): ?>
                        <label style="display:block;padding:8px;cursor:pointer;border-radius:4px" 
                               onmouseover="this.style.background='#f9fafb'" 
                               onmouseout="this.style.background='transparent'">
                            <input type="checkbox" name="client_ids[]" value="<?php echo $client['id']; ?>" 
                                   style="margin-right:8px">
                            <?php echo htmlspecialchars($client['name']); ?>
                            <?php if ($client['email']): ?>
                                <span style="color:var(--muted);font-size:13px">
                                    (<?php echo htmlspecialchars($client['email']); ?>)
                                </span>
                            <?php endif; ?>
                        </label>
                    <?php endforeach; ?>
                </div>
                <div style="margin-top:4px;font-size:13px;color:var(--muted)">
                    Each client will receive a separate email
                </div>
            </div>

            <div id="orgSelector" style="display:none;margin-bottom:16px">
                <label style="display:block;margin-bottom:4px;font-weight:600">Select Organization *</label>
                <select name="recipient_id" id="orgSelect" 
                        style="width:100%;padding:10px;border:1px solid #ddd;border-radius:8px">
                    <option value="">-- Select an organization --</option>
                    <?php foreach ($organizations as $org): ?>
                        <option value="<?php echo $org['id']; ?>">
                            <?php echo htmlspecialchars($org['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div style="margin-top:4px;font-size:13px;color:var(--muted)">
                    Will email all clients in this organization
                </div>
            </div>

            <div style="display:flex;gap:12px">
                <button type="submit" id="emailBtn"
                        style="flex:1;padding:10px;border-radius:8px;border:0;background:var(--nav-accent);color:#fff;font-weight:600;cursor:pointer">
                    Send Email
                </button>
                <button type="button" onclick="closeEmailModal()"
                        style="flex:1;padding:10px;border-radius:8px;border:1px solid #ddd;background:#fff;font-weight:600;cursor:pointer">
                    Cancel
                </button>
            </div>

            <div id="emailMessage" style="display:none;margin-top:16px;padding:12px;border-radius:8px"></div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
// Upload Modal
function showUploadModal() {
    document.getElementById('uploadModal').style.display = 'flex';
}

function closeUploadModal() {
    document.getElementById('uploadModal').style.display = 'none';
    document.getElementById('uploadForm').reset();
}

document.getElementById('uploadForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const btn = document.getElementById('uploadBtn');
    const msg = document.getElementById('uploadMessage');
    const formData = new FormData(this);
    
    btn.disabled = true;
    btn.textContent = 'Uploading...';
    msg.style.display = 'none';
    
    try {
        const response = await fetch('/?page=forms-handler', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            window.location.reload();
        } else {
            throw new Error(result.message);
        }
    } catch (error) {
        msg.style.display = 'block';
        msg.style.background = '#fee2e2';
        msg.style.border = '1px solid #fca5a5';
        msg.style.color = '#991b1b';
        msg.textContent = error.message || 'Failed to upload document';
        
        btn.disabled = false;
        btn.textContent = 'Upload';
    }
});

<?php if ($hasDocument): ?>
// Email Modal
function showEmailModal() {
    document.getElementById('emailModal').style.display = 'flex';
}

function closeEmailModal() {
    document.getElementById('emailModal').style.display = 'none';
    document.getElementById('emailForm').reset();
    document.getElementById('clientSelector').style.display = 'none';
    document.getElementById('clientsSelector').style.display = 'none';
    document.getElementById('orgSelector').style.display = 'none';
    document.getElementById('clientTypeBtn').style.background = '#fff';
    document.getElementById('clientsTypeBtn').style.background = '#fff';
    document.getElementById('orgTypeBtn').style.background = '#fff';
}

function selectRecipientType(type) {
    if (type === 'organization') {
        // Show confirmation for organization email
        if (!confirm('This will email the document to ALL clients in the selected organization. Do you want to continue?')) {
            return;
        }
    }
    
    document.getElementById('recipientType').value = type;
    
    // Reset all buttons
    document.getElementById('clientTypeBtn').style.background = '#fff';
    document.getElementById('clientTypeBtn').style.color = 'inherit';
    document.getElementById('clientsTypeBtn').style.background = '#fff';
    document.getElementById('clientsTypeBtn').style.color = 'inherit';
    document.getElementById('orgTypeBtn').style.background = '#fff';
    document.getElementById('orgTypeBtn').style.color = 'inherit';
    
    // Hide all selectors
    document.getElementById('clientSelector').style.display = 'none';
    document.getElementById('clientsSelector').style.display = 'none';
    document.getElementById('orgSelector').style.display = 'none';
    
    // Reset required attributes
    document.getElementById('clientSelect').required = false;
    document.getElementById('orgSelect').required = false;
    
    if (type === 'client') {
        document.getElementById('clientSelector').style.display = 'block';
        document.getElementById('clientSelect').required = true;
        document.getElementById('clientTypeBtn').style.background = 'var(--nav-accent)';
        document.getElementById('clientTypeBtn').style.color = '#fff';
    } else if (type === 'clients') {
        document.getElementById('clientsSelector').style.display = 'block';
        document.getElementById('clientsTypeBtn').style.background = 'var(--nav-accent)';
        document.getElementById('clientsTypeBtn').style.color = '#fff';
    } else if (type === 'organization') {
        document.getElementById('orgSelector').style.display = 'block';
        document.getElementById('orgSelect').required = true;
        document.getElementById('orgTypeBtn').style.background = 'var(--nav-accent)';
        document.getElementById('orgTypeBtn').style.color = '#fff';
    }
}

document.getElementById('emailForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const btn = document.getElementById('emailBtn');
    const msg = document.getElementById('emailMessage');
    const formData = new FormData(this);
    
    const recipientType = document.getElementById('recipientType').value;
    if (!recipientType) {
        msg.style.display = 'block';
        msg.style.background = '#fee2e2';
        msg.style.border = '1px solid #fca5a5';
        msg.style.color = '#991b1b';
        msg.textContent = 'Please select a recipient type';
        return;
    }
    
    // Validate multiple clients selection
    if (recipientType === 'clients') {
        const checkedClients = document.querySelectorAll('input[name="client_ids[]"]:checked');
        if (checkedClients.length === 0) {
            msg.style.display = 'block';
            msg.style.background = '#fee2e2';
            msg.style.border = '1px solid #fca5a5';
            msg.style.color = '#991b1b';
            msg.textContent = 'Please select at least one client';
            return;
        }
    }
    
    btn.disabled = true;
    btn.textContent = 'Sending...';
    msg.style.display = 'none';
    
    try {
        const response = await fetch('/?page=forms-handler', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            msg.style.display = 'block';
            msg.style.background = '#ecfdf5';
            msg.style.border = '1px solid #a7f3d0';
            msg.style.color = '#065f46';
            msg.textContent = result.message;
            
            setTimeout(() => {
                closeEmailModal();
            }, 2000);
        } else {
            throw new Error(result.message);
        }
    } catch (error) {
        msg.style.display = 'block';
        msg.style.background = '#fee2e2';
        msg.style.border = '1px solid #fca5a5';
        msg.style.color = '#991b1b';
        msg.textContent = error.message || 'Failed to send email';
        
        btn.disabled = false;
        btn.textContent = 'Send Email';
    }
});

// Delete Document
async function confirmDelete() {
    if (!confirm('Are you sure you want to delete this document? This action cannot be undone.')) {
        return;
    }
    
    const formData = new FormData();
    formData.append('csrf', '<?php echo csrf_token(); ?>');
    formData.append('action', 'delete_document');
    formData.append('document_id', '<?php echo $category['doc_id']; ?>');
    
    try {
        const response = await fetch('/?page=forms-handler', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            window.location.href = result.redirect;
        } else {
            alert(result.message || 'Failed to delete document');
        }
    } catch (error) {
        alert('Failed to delete document');
    }
}
<?php endif; ?>
</script>
