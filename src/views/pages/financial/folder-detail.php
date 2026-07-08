<?php
// src/views/pages/financial/folder-detail.php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/csrf.php';
require_once __DIR__ . '/../../../utils/acl.php';

$folderId = (int)($_GET['id'] ?? 0);
$orgId = request_client_org_id();

if (!$folderId) {
    header('Location: /?page=financial/forms-list');
    exit;
}

// Fetch folder details
$stmt = $pdo->prepare('
    SELECT id, organization_id, title, description, created_at
    FROM form_categories 
    WHERE id = ? AND type = "folder"
');
$stmt->execute([$folderId]);
$folder = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$folder) {
    header('Location: /?page=financial/forms-list');
    exit;
}

// Fetch all documents in this folder
$stmt = $pdo->prepare('
    SELECT 
        fd.id,
        fd.organization_id,
        fd.category_id,
        fd.file_name,
        fd.file_path,
        fd.uploaded_at
    FROM form_documents fd
    WHERE fd.category_id = ? AND (fd.project_id IS NULL OR fd.project_id = 0)
    ORDER BY fd.created_at DESC
');
$stmt->execute([$folderId]);
$documents = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>

<div style="max-width:1400px;margin:0 auto;padding:24px">
    <!-- Back Button -->
    <div style="margin-bottom:24px">
        <a href="/?page=financial/forms-list" style="color:var(--nav-accent);text-decoration:none;font-size:14px">
            ← Back to Forms & Docs
        </a>
    </div>

    <!-- Folder Header -->
    <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:24px;margin-bottom:24px">
        <div style="display:flex;justify-content:space-between;align-items:start;margin-bottom:16px">
            <div style="flex:1">
                <div style="display:flex;align-items:center;gap:12px;margin-bottom:8px">
                    <h1 style="margin:0;font-size:28px"><?php echo htmlspecialchars($folder['title']); ?></h1>
                    <span style="padding:4px 12px;background:#dbeafe;color:#1e40af;border-radius:6px;font-size:13px;font-weight:600">
                        📁 FOLDER
                    </span>
                </div>
                <p style="margin:0;color:var(--muted)">
                    Created <?php echo date('F j, Y', strtotime($folder['created_at'])); ?>
                    • <?php echo count($documents); ?> document<?php echo count($documents) !== 1 ? 's' : ''; ?>
                </p>
            </div>
        </div>

        <!-- Folder Actions -->
        <div style="display:flex;gap:8px;padding-top:16px;border-top:1px solid #e5e7eb">
            <button onclick="showUploadModal()" 
                    style="padding:10px 16px;border-radius:8px;background:var(--nav-accent);color:#fff;border:0;font-weight:600;cursor:pointer">
                📤 Upload Document
            </button>
            <button id="emailSelectedBtn" onclick="showEmailModal()" 
                    style="padding:10px 16px;border-radius:8px;background:#10b981;color:#fff;border:0;font-weight:600;cursor:pointer">
                ✉️ Email Documents (<span id="selectedCount">0</span>)
            </button>
            <button onclick="editFolder(<?php echo $folder['id']; ?>, '<?php echo htmlspecialchars(addslashes($folder['title'])); ?>')" 
                    style="padding:10px 16px;border-radius:8px;border:1px solid #ddd;background:#fff;font-weight:600;cursor:pointer">
                ✏️ Rename Folder
            </button>
            <button onclick="deleteFolder()" 
                    style="padding:10px 16px;border-radius:8px;border:1px solid #fca5a5;background:#fee2e2;color:#991b1b;font-weight:600;cursor:pointer">
                🗑️ Delete Folder
            </button>
        </div>
    </div>

    <!-- Documents Grid -->
    <?php if (empty($documents)): ?>
        <div style="text-align:center;padding:64px 24px;border:2px dashed #e5e7eb;border-radius:12px;background:#fff">
            <div style="font-size:48px;margin-bottom:16px">📂</div>
            <h2 style="margin:0 0 8px 0;font-size:20px">No documents in this folder yet</h2>
            <p style="margin:0 0 24px 0;color:var(--muted)">Upload your first document to get started</p>
            <button onclick="showUploadModal()" 
                    style="padding:12px 24px;border-radius:8px;background:var(--nav-accent);color:#fff;border:0;font-weight:600;cursor:pointer">
                📤 Upload Document
            </button>
        </div>
    <?php else: ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:20px">
            <?php foreach ($documents as $doc): 
                $fileExt = strtolower(pathinfo($doc['file_path'], PATHINFO_EXTENSION));
                $isPdf = $fileExt === 'pdf';
                $fileParam = str_replace('/src/uploads/', '', $doc['file_path']);
            ?>
                <div style="border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;background:#fff;display:flex;flex-direction:column;position:relative">
                    <!-- Checkbox -->
                    <div style="position:absolute;top:8px;left:8px;z-index:10">
                        <input type="checkbox" 
                               class="doc-checkbox" 
                               data-doc-id="<?php echo $doc['id']; ?>" 
                               data-file-path="<?php echo htmlspecialchars($doc['file_path']); ?>"
                               data-file-name="<?php echo htmlspecialchars($doc['file_name']); ?>"
                               onchange="updateSelectedCount()"
                               style="width:20px;height:20px;cursor:pointer">
                    </div>
                    
                    <!-- Document Preview -->
                    <div style="height:200px;background:#f9fafb;display:flex;align-items:center;justify-content:center;overflow:hidden">
                        <?php if ($isPdf): ?>
                            <div style="text-align:center;color:var(--muted)">
                                <div style="font-size:48px;margin-bottom:8px">📄</div>
                                <div style="font-size:12px;font-weight:600">PDF Document</div>
                            </div>
                        <?php else: ?>
                            <img src="/?page=serve-upload&file=<?php echo urlencode($fileParam); ?>" 
                                 alt="<?php echo htmlspecialchars($doc['file_name']); ?>" 
                                 style="width:100%;height:100%;object-fit:cover"
                                 loading="lazy">
                        <?php endif; ?>
                    </div>
                    
                    <!-- Document Info -->
                    <div style="padding:16px;flex:1;display:flex;flex-direction:column">
                        <div style="font-weight:600;margin-bottom:4px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?php echo htmlspecialchars($doc['file_name']); ?>">
                            <?php echo htmlspecialchars($doc['file_name']); ?>
                        </div>
                        <div style="font-size:13px;color:var(--muted);margin-bottom:8px">
                            Uploaded <?php echo date('M j, Y', strtotime($doc['uploaded_at'])); ?>
                        </div>
                        
                        <!-- Action Buttons -->
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:auto;padding-top:12px;border-top:1px solid #e5e7eb">
                            <a href="/?page=serve-upload&file=<?php echo urlencode($fileParam); ?>&download=1" download
                               style="padding:8px;border-radius:6px;background:#f9fafb;border:1px solid #e5e7eb;text-align:center;text-decoration:none;color:inherit;font-size:13px;font-weight:600">
                                📥 Download
                            </a>
                            <a href="/?page=financial/document-detail&id=<?php echo $doc['id']; ?>&folder=<?php echo $folderId; ?>"
                               style="padding:8px;border-radius:6px;background:var(--nav-accent);color:#fff;text-align:center;text-decoration:none;font-size:13px;font-weight:600">
                                👁️ View
                            </a>
                        </div>
                        <button onclick="deleteDocument(<?php echo $doc['id']; ?>)"
                                style="width:100%;padding:8px;border-radius:6px;border:1px solid #fca5a5;background:#fee2e2;color:#991b1b;font-size:13px;font-weight:600;cursor:pointer;margin-top:8px">
                            🗑️ Delete
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Upload Document Modal -->
<div id="uploadModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center">
    <div style="background:#fff;border-radius:12px;padding:24px;max-width:500px;width:90%">
        <h3 style="margin:0 0 16px 0">Upload Document to Folder</h3>
        
        <form id="uploadForm" enctype="multipart/form-data">
            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
            <input type="hidden" name="action" value="upload_document">
            <input type="hidden" name="category_id" value="<?php echo $folderId; ?>">
            
            <label style="display:block;margin-bottom:16px">
                <div style="margin-bottom:4px;font-weight:600">Document File *</div>
                <input type="file" name="document_file" required accept="image/*,.pdf"
                       style="width:100%;padding:10px;border:2px dashed #ddd;border-radius:8px;cursor:pointer">
                <div style="margin-top:4px;font-size:13px;color:var(--muted)">
                    Accepts: JPEG, PNG, GIF, PDF (Max 20MB)
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

<!-- Email Modal -->
<div id="emailModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center">
    <div style="background:#fff;border-radius:12px;padding:24px;max-width:500px;width:90%;max-height:90vh;overflow-y:auto">
        <h3 style="margin:0 0 16px 0">Email Documents to Client</h3>
        
        <form id="emailForm">
            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
            <input type="hidden" name="action" value="email_bulk_documents">
            <input type="hidden" name="folder_id" value="<?php echo $folderId; ?>">
            <input type="hidden" name="document_ids" id="documentIds">
            
            <div style="margin-bottom:16px">
                <label style="display:block;margin-bottom:8px;font-weight:600">Send To:</label>
                <div style="display:flex;gap:8px;margin-bottom:12px">
                    <button type="button" data-recipient-type="clients" id="clientTypeBtn"
                            style="flex:1;padding:8px;border-radius:6px;border:1px solid #ddd;background:#fff;cursor:pointer">
                        Clients
                    </button>
                    <button type="button" data-recipient-type="organization" id="orgTypeBtn"
                            style="flex:1;padding:8px;border-radius:6px;border:1px solid #ddd;background:#fff;cursor:pointer">
                        Organization / Departments
                    </button>
                </div>
            </div>

            <input type="hidden" name="recipient_type" id="recipientType">

            <style>
                .forms-email-search{position:relative}.forms-email-suggestions{display:none;position:absolute;left:0;right:0;top:100%;z-index:20;background:#fff;border:1px solid #dfe3e8;border-radius:8px;box-shadow:0 12px 24px rgba(15,23,42,.12);max-height:220px;overflow:auto}.forms-email-suggestion{display:block;width:100%;text-align:left;padding:9px 10px;border:0;border-bottom:1px solid #eef2f7;background:#fff;cursor:pointer}.forms-email-suggestion:hover{background:#f8fafc}.forms-email-suggestion strong,.forms-email-chip strong{display:block}.forms-email-suggestion small,.forms-email-chip small{display:block;color:var(--muted);font-size:12px;margin-top:2px}.forms-email-selected{display:grid;gap:8px;margin-top:10px}.forms-email-chip{display:flex;align-items:center;justify-content:space-between;gap:10px;border:1px solid #dfe3e8;border-radius:8px;background:#fbfcfd;padding:9px 10px}.forms-email-chip button{width:26px;height:26px;border:0;border-radius:999px;background:#f3f4f6;font-weight:800;cursor:pointer}.forms-email-empty{padding:10px;border:1px dashed #d1d5db;border-radius:8px;color:var(--muted);font-size:13px;background:#fff}.forms-email-check{display:flex;gap:8px;align-items:flex-start;padding:8px;border:1px solid #e5e7eb;border-radius:8px;background:#fff;font-size:13px}.forms-email-check input{margin-top:2px}
            </style>

            <div id="clientSelector" data-forms-email-panel="clients" style="display:none;margin-bottom:16px">
                <label style="display:block;margin-bottom:4px;font-weight:600">Find clients *</label>
                <div class="forms-email-search">
                    <input type="text" data-forms-email-client-search autocomplete="off" placeholder="Type a client name or email..."
                           style="width:100%;padding:10px;border:1px solid #ddd;border-radius:8px">
                    <div class="forms-email-suggestions" data-forms-email-client-suggestions></div>
                </div>
                <div class="forms-email-selected" data-forms-email-selected-clients></div>
                <div data-forms-email-client-hidden></div>
                <div style="margin-top:4px;font-size:13px;color:var(--muted)">Each selected client receives a separate email.</div>
            </div>

            <div id="orgSelector" data-forms-email-panel="organization" style="display:none;margin-bottom:16px">
                <label style="display:block;margin-bottom:4px;font-weight:600">Find organization *</label>
                <div class="forms-email-search">
                    <input type="text" data-forms-email-org-search autocomplete="off" placeholder="Type an organization name..."
                           style="width:100%;padding:10px;border:1px solid #ddd;border-radius:8px">
                    <div class="forms-email-suggestions" data-forms-email-org-suggestions></div>
                </div>
                <input type="hidden" name="recipient_id" data-forms-email-org-id>
                <div class="forms-email-empty" data-forms-email-selected-org style="margin-top:10px">No organization selected.</div>
                <div style="margin-top:12px;font-weight:600;font-size:13px">Departments</div>
                <div data-forms-email-departments style="display:grid;gap:8px;margin-top:8px">
                    <div class="forms-email-empty">Select an organization to choose departments.</div>
                </div>
                <div style="margin-top:4px;font-size:13px;color:var(--muted)">Leave departments blank to email every client in the organization.</div>
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

<!-- Edit Folder Modal -->
<div id="editModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center">
    <div style="background:#fff;border-radius:12px;padding:24px;max-width:500px;width:90%">
        <h3 style="margin:0 0 16px 0">Rename Folder</h3>
        
        <form id="editForm">
            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
            <input type="hidden" name="action" value="update_category">
            <input type="hidden" name="category_id" id="editFolderId">
            
            <label style="display:block;margin-bottom:16px">
                <div style="margin-bottom:4px;font-weight:600">Folder Name *</div>
                <input type="text" name="title" id="editTitle" required 
                       style="width:100%;padding:10px;border:1px solid #ddd;border-radius:8px">
            </label>

            <div style="display:flex;gap:12px">
                <button type="submit" id="updateBtn"
                        style="flex:1;padding:10px;border-radius:8px;border:0;background:var(--nav-accent);color:#fff;font-weight:600;cursor:pointer">
                    Update
                </button>
                <button type="button" onclick="closeEditModal()"
                        style="flex:1;padding:10px;border-radius:8px;border:1px solid #ddd;background:#fff;font-weight:600;cursor:pointer">
                    Cancel
                </button>
            </div>

            <div id="editMessage" style="display:none;margin-top:16px;padding:12px;border-radius:8px"></div>
        </form>
    </div>
</div>

<script>
    window.formCsrfToken = <?php echo json_encode(csrf_token()); ?>;
    window.formFolderId = <?php echo (int)$folderId; ?>;
</script>
<script src="<?php echo htmlspecialchars(asset_url('/assets/js/forms-email-recipient-picker.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
<script src="<?php echo htmlspecialchars(asset_url('/assets/js/folder-detail-logic.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
