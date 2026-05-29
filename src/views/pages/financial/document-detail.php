<?php
// src/views/pages/financial/document-detail.php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/csrf.php';

$documentId = (int)($_GET['id'] ?? 0);
$folderId = (int)($_GET['folder'] ?? 0);
$orgId = 1; // Should come from session/user context

if (!$documentId) {
    header('Location: /?page=financial/forms-list');
    exit;
}

// Fetch document details with folder info
$stmt = $pdo->prepare('
    SELECT 
        fd.id,
        fd.organization_id,
        fd.category_id,
        fd.name as file_name,
        fd.file_path,
        fd.created_at as uploaded_at,
        fc.name as folder_title
    FROM form_documents fd
    JOIN form_categories fc ON fd.category_id = fc.id
    WHERE fd.id = ? AND fc.organization_id = ?
');
$stmt->execute([$documentId, $orgId]);
$document = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$document) {
    header('Location: /?page=financial/forms-list');
    exit;
}

$fileExt = strtolower(pathinfo($document['file_path'], PATHINFO_EXTENSION));
$isPdf = $fileExt === 'pdf';
$fileParam = str_replace('/src/uploads/', '', $document['file_path']);
$fileUrl = '/?page=serve-upload&file=' . urlencode($fileParam);
?>

<div style="max-width:1400px;margin:0 auto;padding:24px">
    <div style="margin-bottom:24px">
        <a href="/?page=financial/folder-detail&id=<?php echo $document['category_id']; ?>" style="color:var(--nav-accent);text-decoration:none;font-size:14px">
            ← Back to <?php echo htmlspecialchars($document['folder_title']); ?>
        </a>
    </div>

    <!-- Document View -->
    <div style="display:grid;grid-template-columns:1fr 400px;gap:32px;align-items:start">
        <!-- Document Preview -->
        <div style="min-width:0">
            <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden">
                <?php if ($isPdf): ?>
                    <div style="height:900px;max-height:900px;min-height:900px">
                        <iframe src="<?php echo htmlspecialchars($fileUrl); ?>" 
                                style="width:100%;height:900px;max-height:900px;border:0;display:block"></iframe>
                    </div>
                <?php else: ?>
                    <div style="padding:24px;text-align:center;background:#f9fafb">
                        <img src="<?php echo htmlspecialchars($fileUrl); ?>" 
                             alt="<?php echo htmlspecialchars($document['file_name']); ?>" 
                             style="max-width:100%;height:auto;border-radius:8px;display:block;margin:0 auto">
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Document Info & Actions -->
        <div style="width:400px;max-width:400px;min-width:400px">
            <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:24px;margin-bottom:16px">
                <h1 style="margin:0 0 16px 0;font-size:24px"><?php echo htmlspecialchars($document['file_name']); ?></h1>
                
                <div style="display:grid;gap:16px">
                    <div>
                        <div style="font-size:12px;color:var(--muted);margin-bottom:4px">Folder</div>
                        <div style="font-weight:600">
                            <?php echo htmlspecialchars($document['folder_title']); ?>
                        </div>
                    </div>

                    <div style="padding-top:16px;border-top:1px solid #e5e7eb">
                        <div style="font-size:12px;color:var(--muted);margin-bottom:4px">File Type</div>
                        <div style="font-weight:600;text-transform:uppercase">
                            <?php echo htmlspecialchars($fileExt); ?>
                        </div>
                    </div>

                    <div>
                        <div style="font-size:12px;color:var(--muted);margin-bottom:4px">Uploaded</div>
                        <div style="font-weight:600">
                            <?php echo $document['uploaded_at'] ? date('F j, Y', strtotime($document['uploaded_at'])) : 'N/A'; ?>
                        </div>
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

                    <!-- Delete -->
                    <button onclick="confirmDelete()" 
                            style="width:100%;padding:10px;border-radius:6px;background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;font-weight:600;cursor:pointer;margin-top:8px">
                        🗑️ Delete Document
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Delete Document
async function confirmDelete() {
    if (!confirm('Are you sure you want to delete this document? This action cannot be undone.')) {
        return;
    }
    
    const formData = new FormData();
    formData.append('csrf', '<?php echo csrf_token(); ?>');
    formData.append('action', 'delete_document');
    formData.append('document_id', <?php echo $documentId; ?>);
    
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
</script>
