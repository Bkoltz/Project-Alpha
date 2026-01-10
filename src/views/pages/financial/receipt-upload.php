<?php
// src/views/pages/financial/receipt-upload.php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/csrf.php';

$orgId = 1;

// Get all existing stores for autocomplete
$stmt = $pdo->prepare('SELECT DISTINCT store_name FROM receipt_stores WHERE org_id = ? ORDER BY store_name');
$stmt->execute([$orgId]);
$stores = $stmt->fetchAll(PDO::FETCH_COLUMN);
?>

<div style="max-width:800px;margin:0 auto;padding:24px">
    <div style="margin-bottom:24px">
        <a href="/?page=financial/receipts-list" style="color:var(--nav-accent);text-decoration:none;font-size:14px">
            ← Back to Receipts
        </a>
    </div>

    <h1 style="margin:0 0 8px 0">Upload Receipt</h1>
    <p style="margin:0 0 24px 0;color:var(--muted)">Upload an image or PDF of your business expense receipt</p>

    <form id="receiptUploadForm" enctype="multipart/form-data" style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:24px">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
        <input type="hidden" name="action" value="create">

        <div style="display:grid;gap:20px">
            <!-- File Upload -->
            <div>
                <label style="display:block;margin-bottom:8px;font-weight:600">
                    Receipt File *
                </label>
                <input type="file" 
                       name="receipt_file" 
                       id="receiptFile"
                       accept="image/*,.pdf" 
                       required
                       style="display:block;width:100%;padding:10px;border:2px dashed #ddd;border-radius:8px;cursor:pointer"
                       onchange="previewFile(this)">
                <div style="margin-top:4px;font-size:13px;color:var(--muted)">
                    Accepts: JPEG, PNG, GIF, PDF (Max 10MB)
                </div>
            </div>

            <!-- Preview Area -->
            <div id="preview Area" style="display:none;margin-top:8px">
                <div style="font-weight:600;margin-bottom:8px">Preview:</div>
                <div id="imagePreview" style="max-width:400px;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden"></div>
            </div>

            <!-- Store Name -->
            <div>
                <label style="display:block;margin-bottom:8px;font-weight:600">
                    Store Name
                </label>
                <input type="text" 
                       name="store_name" 
                       list="storesList"
                       placeholder="e.g., Home Depot, Walmart"
                       style="width:100%;padding:10px;border:1px solid #ddd;border-radius:8px">
                <datalist id="storesList">
                    <?php foreach ($stores as $store): ?>
                        <option value="<?php echo htmlspecialchars($store); ?>">
                    <?php endforeach; ?>
                </datalist>
                <div style="margin-top:4px;font-size:13px;color:var(--muted)">
                    Optional - Start typing to see existing stores
                </div>
            </div>

            <!-- Title -->
            <div>
                <label style="display:block;margin-bottom:8px;font-weight:600">
                    Receipt Title/Description *
                </label>
                <input type="text" 
                       name="title" 
                       required 
                       placeholder="e.g., Roofing Materials, Office Supplies"
                       style="width:100%;padding:10px;border:1px solid #ddd;border-radius:8px">
                <div style="margin-top:4px;font-size:13px;color:var(--muted)">
                    Brief description of the expense
                </div>
            </div>

            <!-- Date -->
            <div>
                <label style="display:block;margin-bottom:8px;font-weight:600">
                    Receipt Date *
                </label>
                <input type="date" 
                       name="receipt_date" 
                       required
                       value="<?php echo date('Y-m-d'); ?>"
                       style="width:100%;padding:10px;border:1px solid #ddd;border-radius:8px">
                <div style="margin-top:4px;font-size:13px;color:var(--muted)">
                    Date shown on the receipt
                </div>
            </div>

            <!-- Amount -->
            <div>
                <label style="display:block;margin-bottom:8px;font-weight:600">
                    Amount *
                </label>
                <div style="position:relative">
                    <span style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--muted)">$</span>
                    <input type="number" 
                           name="amount" 
                           required 
                           step="0.01" 
                           min="0"
                           placeholder="0.00"
                           style="width:100%;padding:10px 10px 10px 24px;border:1px solid #ddd;border-radius:8px">
                </div>
                <div style="margin-top:4px;font-size:13px;color:var(--muted)">
                    Total amount from receipt
                </div>
            </div>

            <!-- Submit Buttons -->
            <div style="display:flex;gap:12px;margin-top:12px;padding-top:20px;border-top:1px solid #e5e7eb">
                <button type="submit" 
                        id="submitBtn"
                        style="flex:1;padding:12px;border-radius:8px;border:0;background:var(--nav-accent);color:#fff;font-weight:600;cursor:pointer">
                    Upload Receipt
                </button>
                <a href="/?page=financial/receipts-list" 
                   style="flex:1;padding:12px;border-radius:8px;border:1px solid #ddd;background:#fff;text-align:center;text-decoration:none;color:inherit;font-weight:600">
                    Cancel
                </a>
            </div>
        </div>

        <!-- Error/Success Message -->
        <div id="formMessage" style="display:none;margin-top:16px;padding:12px;border-radius:8px"></div>
    </form>
</div>

<script>
function previewFile(input) {
    const preview = document.getElementById('previewArea');
    const imagePreview = document.getElementById('imagePreview');
    
    if (input.files && input.files[0]) {
        const file = input.files[0];
        const reader = new FileReader();
        
        reader.onload = function(e) {
            if (file.type === 'application/pdf') {
                imagePreview.innerHTML = '<div style="padding:40px;text-align:center;background:#f9fafb"><div style="font-size:48px;margin-bottom:8px">📄</div><div style="font-weight:600">' + file.name + '</div></div>';
            } else {
                imagePreview.innerHTML = '<img src="' + e.target.result + '" style="width:100%;height:auto">';
            }
            preview.style.display = 'block';
        };
        
        reader.readAsDataURL(file);
    }
}

document.getElementById('receiptUploadForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const submitBtn = document.getElementById('submitBtn');
    const formMessage = document.getElementById('formMessage');
    const formData = new FormData(this);
    
    // Disable submit button
    submitBtn.disabled = true;
    submitBtn.textContent = 'Uploading...';
    formMessage.style.display = 'none';
    
    try {
        const response = await fetch('/?page=receipts-handler', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            formMessage.style.display = 'block';
            formMessage.style.background = '#ecfdf5';
            formMessage.style.border = '1px solid #a7f3d0';
            formMessage.style.color = '#065f46';
            formMessage.textContent = result.message;
            
            // Redirect after short delay
            setTimeout(() => {
                window.location.href = result.redirect;
            }, 1000);
        } else {
            throw new Error(result.message);
        }
    } catch (error) {
        formMessage.style.display = 'block';
        formMessage.style.background = '#fee2e2';
        formMessage.style.border = '1px solid #fca5a5';
        formMessage.style.color = '#991b1b';
        formMessage.textContent = error.message || 'Failed to upload receipt';
        
        submitBtn.disabled = false;
        submitBtn.textContent = 'Upload Receipt';
    }
});
</script>
