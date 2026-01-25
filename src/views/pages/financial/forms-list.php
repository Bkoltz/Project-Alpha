<?php
// src/views/pages/financial/forms-list.php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/csrf.php';

$orgId = 1; // Should come from session/user context

// Fetch all form categories with their documents and counts
$stmt = $pdo->prepare('
    SELECT 
        fc.*,
        fd.id as doc_id,
        fd.file_path,
        fd.file_name,
        fd.mime_type,
        fd.uploaded_at,
        u.username as uploaded_by_name,
        COUNT(DISTINCT fd2.id) as doc_count
    FROM form_categories fc
    LEFT JOIN form_documents fd ON fc.id = fd.category_id
    LEFT JOIN form_documents fd2 ON fc.id = fd2.category_id
    LEFT JOIN users u ON fd.uploaded_by = u.id
    WHERE fc.org_id = ?
    GROUP BY fc.id, fd.id, fd.file_path, fd.file_name, fd.mime_type, fd.uploaded_at, u.username
    ORDER BY fc.created_at DESC
');
$stmt->execute([$orgId]);
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div style="max-width:1400px;margin:0 auto;padding:24px">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px">
        <div>
            <h1 style="margin:0 0 8px 0;font-size:28px">Forms & Documents</h1>
            <p style="margin:0;color:var(--muted)">Manage business forms like W-9, contracts templates, and other documents</p>
        </div>
        <div style="display:flex;gap:8px">
            <button onclick="showUploadFileModal()" 
                    style="padding:10px 16px;border-radius:8px;background:#f9fafb;border:1px solid #e5e7eb;color:inherit;font-weight:600;cursor:pointer">
                📄 Upload File
            </button>
            <button onclick="showCreateCategoryModal()" 
                    style="padding:10px 16px;border-radius:8px;background:var(--nav-accent);color:#fff;border:0;font-weight:600;cursor:pointer">
                📁 Create Folder
            </button>
        </div>
    </div>

    <?php if (empty($categories)): ?>
        <div style="text-align:center;padding:64px 24px;border:2px dashed #e5e7eb;border-radius:12px">
            <div style="font-size:48px;margin-bottom:16px">📁</div>
            <h2 style="margin:0 0 8px 0;font-size:20px">No forms or folders yet</h2>
            <p style="margin:0 0 24px 0;color:var(--muted)">Upload individual files or create folders to organize your business documents</p>
            <div style="display:flex;gap:12px;justify-content:center">
                <button onclick="showUploadFileModal()" 
                        style="padding:12px 24px;border-radius:8px;background:#f9fafb;border:1px solid #e5e7eb;color:inherit;font-weight:600;cursor:pointer">
                    📄 Upload File
                </button>
                <button onclick="showCreateCategoryModal()" 
                        style="padding:12px 24px;border-radius:8px;background:var(--nav-accent);color:#fff;border:0;font-weight:600;cursor:pointer">
                    📁 Create Folder
                </button>
            </div>
        </div>
    <?php else: ?>
        <!-- Categories Grid -->
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:24px">
            <?php foreach ($categories as $category): 
                $hasDocument = !empty($category['file_path']);
                $fileExt = $hasDocument ? strtolower(pathinfo($category['file_path'], PATHINFO_EXTENSION)) : '';
                $isPdf = $fileExt === 'pdf';
            ?>
                <div style="border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;background:#fff;display:flex;flex-direction:column">
                    <!-- Document Preview -->
                    <div style="height:220px;background:#f9fafb;display:flex;align-items:center;justify-content:center;position:relative;overflow:hidden">
                        <?php if ($hasDocument): ?>
                            <?php if ($isPdf): ?>
                                <div style="text-align:center;color:var(--muted)">
                                    <div style="font-size:64px;margin-bottom:12px">📄</div>
                                    <div style="font-size:14px;font-weight:600"><?php echo htmlspecialchars($category['file_name']); ?></div>
                                </div>
                            <?php else: ?>
                                <?php 
                                $fileParam = str_replace('/src/uploads/', '', $category['file_path']);
                                ?>
                                <img src="/?page=serve-upload&file=<?php echo urlencode($fileParam); ?>" 
                                     alt="<?php echo htmlspecialchars($category['title']); ?>" 
                                     style="max-width:100%;max-height:100%;object-fit:contain"
                                     loading="lazy">
                            <?php endif; ?>
                            
                            <!-- View Badge -->
                            <?php 
                            $detailPage = $category['type'] === 'folder' ? 'folder-detail' : 'form-detail';
                            ?>
                            <a href="/?page=financial/<?php echo $detailPage; ?>&id=<?php echo $category['id']; ?>" 
                               style="position:absolute;top:12px;right:12px;padding:6px 12px;background:var(--nav-accent);color:#fff;border-radius:6px;text-decoration:none;font-size:13px;font-weight:600">
                                View
                            </a>
                        <?php else: ?>
                            <div style="text-align:center;color:var(--muted)">
                                <div style="font-size:48px;margin-bottom:8px">📂</div>
                                <div style="font-size:14px">No document uploaded</div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Category Info -->
                    <div style="padding:16px;flex:1;display:flex;flex-direction:column">
                        <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">
                            <h3 style="margin:0;font-size:18px;font-weight:600">
                                <?php echo htmlspecialchars($category['title']); ?>
                            </h3>
                            <?php if ($category['type'] === 'folder'): ?>
                                <span style="padding:2px 8px;background:#dbeafe;color:#1e40af;border-radius:4px;font-size:11px;font-weight:600">
                                    📁 FOLDER
                                </span>
                            <?php else: ?>
                                <span style="padding:2px 8px;background:#fef3c7;color:#92400e;border-radius:4px;font-size:11px;font-weight:600">
                                    📄 FILE
                                </span>
                            <?php endif; ?>
                        </div>

                        <?php if ($hasDocument): ?>
                            <div style="font-size:13px;color:var(--muted);margin-bottom:12px">
                                Uploaded <?php echo date('M j, Y', strtotime($category['uploaded_at'])); ?>
                                <?php if ($category['uploaded_by_name']): ?>
                                    <br>by <?php echo htmlspecialchars($category['uploaded_by_name']); ?>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div style="font-size:13px;color:var(--muted);margin-bottom:12px">
                                Category created <?php echo date('M j, Y', strtotime($category['created_at'])); ?>
                            </div>
                        <?php endif; ?>

                        <!-- Action Buttons -->
                        <div style="margin-top:auto;display:grid;gap:8px">
                            <?php if ($hasDocument): ?>
                                <?php 
                                $detailPage = $category['type'] === 'folder' ? 'folder-detail' : 'form-detail';
                                ?>
                                <a href="/?page=financial/<?php echo $detailPage; ?>&id=<?php echo $category['id']; ?>" 
                                   style="padding:10px;border-radius:6px;background:var(--nav-accent);color:#fff;text-align:center;text-decoration:none;font-weight:600">
                                    View Details
                                </a>
                            <?php else: ?>
                                <button onclick="uploadDocument(<?php echo $category['id']; ?>)" 
                                        style="padding:10px;border-radius:6px;background:var(--nav-accent);color:#fff;border:0;font-weight:600;cursor:pointer">
                                    Upload Document
                                </button>
                            <?php endif; ?>
                            
                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
                                <button onclick="editCategory(<?php echo $category['id']; ?>, '<?php echo htmlspecialchars(addslashes($category['title'])); ?>')" 
                                        style="padding:8px;border-radius:6px;border:1px solid #ddd;background:#fff;font-size:13px;cursor:pointer">
                                    ✏️ Edit
                                </button>
                                <button onclick="deleteCategory(<?php echo $category['id']; ?>)" 
                                        style="padding:8px;border-radius:6px;border:1px solid #fca5a5;background:#fee2e2;color:#991b1b;font-size:13px;cursor:pointer">
                                    🗑️ Delete
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Upload File Modal -->
<div id="uploadFileModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center">
    <div style="background:#fff;border-radius:12px;padding:24px;max-width:500px;width:90%;max-height:90vh;overflow-y:auto">
        <h3 style="margin:0 0 16px 0">Upload File</h3>
        
        <form id="uploadFileForm" enctype="multipart/form-data">
            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
            <input type="hidden" name="action" value="quick_upload">
            
            <label style="display:block;margin-bottom:16px">
                <div style="margin-bottom:4px;font-weight:600">File Name *</div>
                <input type="text" name="title" required 
                       placeholder="e.g., W-9 Form, Tax Document"
                       style="width:100%;padding:10px;border:1px solid #ddd;border-radius:8px">
                <div style="margin-top:4px;font-size:13px;color:var(--muted)">
                    Give this file a descriptive name
                </div>
            </label>
            
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
                <select name="project_id" id="projectSelectUpload" style="width:100%;padding:10px;border:1px solid #ddd;border-radius:8px">
                    <option value="">-- No Project --</option>
                    <?php
                    $projectsStmt = $pdo->query('SELECT id, name, status FROM projects ORDER BY name ASC');
                    $projects = $projectsStmt->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($projects as $proj):
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
                <button type="submit" id="uploadFileBtn"
                        style="flex:1;padding:10px;border-radius:8px;border:0;background:var(--nav-accent);color:#fff;font-weight:600;cursor:pointer">
                    Upload
                </button>
                <button type="button" onclick="closeUploadFileModal()"
                        style="flex:1;padding:10px;border-radius:8px;border:1px solid #ddd;background:#fff;font-weight:600;cursor:pointer">
                    Cancel
                </button>
            </div>

            <div id="uploadFileMessage" style="display:none;margin-top:16px;padding:12px;border-radius:8px"></div>
        </form>
    </div>
</div>

<!-- Create Folder Modal -->
<div id="createModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center">
    <div style="background:#fff;border-radius:12px;padding:24px;max-width:500px;width:90%">
        <h3 style="margin:0 0 16px 0">Create Folder</h3>
        
        <form id="createForm">
            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
            <input type="hidden" name="action" value="create_category">
            
            <label style="display:block;margin-bottom:16px">
                <div style="margin-bottom:4px;font-weight:600">Folder Name *</div>
                <input type="text" name="title" required 
                       placeholder="e.g., Google Forms Responses, Client Contracts"
                       style="width:100%;padding:10px;border:1px solid #ddd;border-radius:8px">
                <div style="margin-top:4px;font-size:13px;color:var(--muted)">
                    Give this folder a descriptive name
                </div>
            </label>

            <div style="display:flex;gap:12px">
                <button type="submit" id="createBtn"
                        style="flex:1;padding:10px;border-radius:8px;border:0;background:var(--nav-accent);color:#fff;font-weight:600;cursor:pointer">
                    Create
                </button>
                <button type="button" onclick="closeCreateModal()"
                        style="flex:1;padding:10px;border-radius:8px;border:1px solid #ddd;background:#fff;font-weight:600;cursor:pointer">
                    Cancel
                </button>
            </div>

            <div id="createMessage" style="display:none;margin-top:16px;padding:12px;border-radius:8px"></div>
        </form>
    </div>
</div>

<!-- Edit Category Modal -->
<div id="editModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center">
    <div style="background:#fff;border-radius:12px;padding:24px;max-width:500px;width:90%">
        <h3 style="margin:0 0 16px 0">Edit Category</h3>
        
        <form id="editForm">
            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
            <input type="hidden" name="action" value="update_category">
            <input type="hidden" name="category_id" id="editCategoryId">
            
            <label style="display:block;margin-bottom:16px">
                <div style="margin-bottom:4px;font-weight:600">Category Title *</div>
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

<!-- Upload Document Modal -->
<div id="uploadModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center">
    <div style="background:#fff;border-radius:12px;padding:24px;max-width:500px;width:90%;max-height:90vh;overflow-y:auto">
        <h3 style="margin:0 0 16px 0">Upload Document</h3>
        
        <form id="uploadForm" enctype="multipart/form-data">
            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
            <input type="hidden" name="action" value="upload_document">
            <input type="hidden" name="category_id" id="uploadCategoryId">
            
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
                <select name="project_id" id="projectSelectUploadDoc" style="width:100%;padding:10px;border:1px solid #ddd;border-radius:8px">
                    <option value="">-- No Project --</option>
                    <?php foreach ($projects as $proj): ?>
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

<script>
// Upload File Modal
function showUploadFileModal() {
    document.getElementById('uploadFileModal').style.display = 'flex';
}

function closeUploadFileModal() {
    document.getElementById('uploadFileModal').style.display = 'none';
    document.getElementById('uploadFileForm').reset();
}

document.getElementById('uploadFileForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const btn = document.getElementById('uploadFileBtn');
    const msg = document.getElementById('uploadFileMessage');
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
            window.location.href = result.redirect;
        } else {
            throw new Error(result.message);
        }
    } catch (error) {
        msg.style.display = 'block';
        msg.style.background = '#fee2e2';
        msg.style.border = '1px solid #fca5a5';
        msg.style.color = '#991b1b';
        msg.textContent = error.message || 'Failed to upload file';
        
        btn.disabled = false;
        btn.textContent = 'Upload';
    }
});

// Create Folder Modal
function showCreateCategoryModal() {
    document.getElementById('createModal').style.display = 'flex';
}

function closeCreateModal() {
    document.getElementById('createModal').style.display = 'none';
    document.getElementById('createForm').reset();
}

document.getElementById('createForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const btn = document.getElementById('createBtn');
    const msg = document.getElementById('createMessage');
    const formData = new FormData(this);
    
    btn.disabled = true;
    btn.textContent = 'Creating...';
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
        msg.textContent = error.message || 'Failed to create category';
        
        btn.disabled = false;
        btn.textContent = 'Create';
    }
});

// Edit Category Modal
function editCategory(id, title) {
    document.getElementById('editCategoryId').value = id;
    document.getElementById('editTitle').value = title;
    document.getElementById('editModal').style.display = 'flex';
}

function closeEditModal() {
    document.getElementById('editModal').style.display = 'none';
}

document.getElementById('editForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const btn = document.getElementById('updateBtn');
    const msg = document.getElementById('editMessage');
    const formData = new FormData(this);
    
    btn.disabled = true;
    btn.textContent = 'Updating...';
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
        msg.textContent = error.message || 'Failed to update category';
        
        btn.disabled = false;
        btn.textContent = 'Update';
    }
});

// Upload Document Modal
function uploadDocument(categoryId) {
    document.getElementById('uploadCategoryId').value = categoryId;
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
            window.location.href = result.redirect;
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

// Delete Category
async function deleteCategory(id) {
    if (!confirm('Are you sure you want to delete this category and all its documents? This action cannot be undone.')) {
        return;
    }
    
    const formData = new FormData();
    formData.append('csrf', '<?php echo csrf_token(); ?>');
    formData.append('action', 'delete_category');
    formData.append('category_id', id);
    
    try {
        const response = await fetch('/?page=forms-handler', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            window.location.reload();
        } else {
            alert(result.message || 'Failed to delete category');
        }
    } catch (error) {
        alert('Failed to delete category');
    }
}
</script>
