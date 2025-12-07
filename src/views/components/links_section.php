<?php
// src/views/components/links_section.php
// This component displays links for a client or organization
// Required variables: $entityType ('client' or 'organization'), $entityId

if (!isset($entityType) || !isset($entityId)) {
    return;
}

// Check if link resolver is enabled
$linkResolverEnabled = !empty($appConfig['link_resolver_enabled']);
if (!$linkResolverEnabled) {
    return; // Don't show section if disabled
}

// Check if client belongs to organization (for org-level link management)
$belongsToOrg = false;
$isReadOnly = false;
if ($entityType === 'client') {
    try {
        $stmt = $pdo->prepare("SELECT org_id FROM client WHERE client_id = ?");
        $stmt->execute([$entityId]);
        $orgId = $stmt->fetchColumn();
        if ($orgId) {
            $belongsToOrg = true;
            $isReadOnly = true;
        }
    } catch (Throwable $e) {
        @error_log('[LinksSection] Error checking org: ' . $e->getMessage());
    }
}

// Fetch links for this entity
try {
    $stmt = $pdo->prepare("
        SELECT link_id, type, url, expiration_date, is_expired, ignore_auto_generation, last_verified
        FROM link
        WHERE entity_type = ? AND entity_id = ?
        ORDER BY type ASC
    ");
    $stmt->execute([$entityType, $entityId]);
    $links = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $links = [];
    @error_log('[LinksSection] Error fetching links: ' . $e->getMessage());
}

// Check if any link is marked as ignored
$isIgnored = false;
foreach ($links as $link) {
    if ($link['ignore_auto_generation']) {
        $isIgnored = true;
        break;
    }
}
?>

<div style="margin-top:32px;padding-top:24px;border-top:2px solid #eee">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
        <div>
            <h3 style="margin:0 0 4px 0;font-size:18px">File Storage Links</h3>
            <p style="margin:0;font-size:13px;color:var(--muted)">Auto-generated and manual links to cloud storage folders</p>
        </div>
        <?php if (!$isReadOnly): ?>
        <div style="display:flex;gap:8px">
            <button type="button" onclick="showAddManualLinkModal('<?php echo $entityType; ?>', <?php echo $entityId; ?>)"
                    style="padding:8px 12px;border-radius:6px;border:1px solid #3b82f6;background:#eff6ff;color:#1e40af;font-size:13px;cursor:pointer;font-weight:600">
                + Add Manual Link
            </button>
            <?php if ($isIgnored): ?>
                <button type="button" onclick="unignoreLinks('<?php echo $entityType; ?>', <?php echo $entityId; ?>)"
                        style="padding:8px 12px;border-radius:6px;border:1px solid #ddd;background:#fff;font-size:13px;cursor:pointer">
                    🔔 Enable Auto-Generation
                </button>
            <?php else: ?>
                <button type="button" onclick="generateLinks('<?php echo $entityType; ?>', <?php echo $entityId; ?>)"
                        style="padding:8px 12px;border-radius:6px;border:1px solid #10b981;background:#ecfdf5;color:#065f46;font-size:13px;cursor:pointer;font-weight:600">
                    + Generate Links
                </button>
                <button type="button" onclick="refreshLinks('<?php echo $entityType; ?>', <?php echo $entityId; ?>)"
                        style="padding:8px 12px;border-radius:6px;border:1px solid #ddd;background:#fff;font-size:13px;cursor:pointer">
                    🔄 Refresh
                </button>
                <button type="button" onclick="ignoreLinks('<?php echo $entityType; ?>', <?php echo $entityId; ?>)"
                        style="padding:8px 12px;border-radius:6px;border:1px solid #ddd;background:#fff;font-size:13px;cursor:pointer">
                    🔕 Ignore
                </button>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($isReadOnly): ?>
        <div style="padding:12px 16px;background:#e0e7ff;border:1px solid #a5b4fc;border-radius:8px;margin-bottom:16px">
            <strong>ℹ️ Organization Management</strong> — This client is part of an organization. Please manage links on the organization page.
        </div>
    <?php endif; ?>

    <?php if ($isIgnored && !$isReadOnly): ?>
        <div style="padding:12px 16px;background:#fef3c7;border:1px solid #fde68a;border-radius:8px;margin-bottom:16px">
            <strong>⚠️ Auto-generation disabled</strong> — This <?php echo $entityType; ?> is marked to ignore automatic link generation.
        </div>
    <?php endif; ?>

    <div id="linksContainer_<?php echo $entityType; ?>_<?php echo $entityId; ?>" style="display:grid;gap:12px">
        <?php if (empty($links)): ?>
            <div style="padding:24px;text-align:center;background:#f9fafb;border:1px dashed #d1d5db;border-radius:8px;color:var(--muted)">
                No links generated yet. Click "Generate Links" to create them.
            </div>
        <?php else: ?>
            <?php foreach ($links as $link): 
                $typeLabel = str_replace(['auto_', '_'], ['', ' '], $link['type']);
                $typeLabel = ucwords($typeLabel);
                
                $statusClass = '';
                $statusText = '';
                if ($link['is_expired']) {
                    $statusClass = 'background:#fee2e2;color:#991b1b;border-color:#fca5a5';
                    $statusText = '⚠️ Expired';
                } elseif ($link['expiration_date'] && strtotime($link['expiration_date']) < strtotime('+7 days')) {
                    $statusClass = 'background:#fef3c7;color:#92400e;border-color:#fde68a';
                    $statusText = '⏰ Expires Soon';
                } else {
                    $statusClass = 'background:#ecfdf5;color:#065f46;border-color:#a7f3d0';
                    $statusText = '✓ Active';
                }
            ?>
                <div style="padding:16px;border:1px solid #e5e7eb;border-radius:8px;background:#fff">
                    <div style="display:flex;justify-content:space-between;align-items:start;margin-bottom:8px">
                        <div>
                            <div style="font-weight:600;font-size:14px;margin-bottom:4px"><?php echo htmlspecialchars($typeLabel); ?></div>
                            <a href="<?php echo htmlspecialchars($link['url']); ?>" target="_blank" 
                               style="font-size:13px;color:#0369a1;text-decoration:none;word-break:break-all">
                                <?php echo htmlspecialchars($link['url']); ?> ↗
                            </a>
                        </div>
                        <div style="padding:4px 8px;border-radius:6px;font-size:12px;font-weight:600;white-space:nowrap;<?php echo $statusClass; ?>">
                            <?php echo $statusText; ?>
                        </div>
                    </div>
                    <div style="display:flex;gap:16px;font-size:12px;color:var(--muted);margin-top:8px">
                        <?php if ($link['expiration_date']): ?>
                            <span>Expires: <?php echo date('M j, Y', strtotime($link['expiration_date'])); ?></span>
                        <?php endif; ?>
                        <?php if ($link['last_verified']): ?>
                            <span>Last verified: <?php echo date('M j, Y', strtotime($link['last_verified'])); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Manual Link Modal -->
<div id="manualLinkModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center">
    <div style="background:#fff;border-radius:12px;padding:24px;max-width:500px;width:90%">
        <h3 style="margin:0 0 16px 0">Add Manual Link</h3>
        <form id="manualLinkForm" style="display:grid;gap:16px">
            <input type="hidden" id="manualLinkEntityType" name="entity_type">
            <input type="hidden" id="manualLinkEntityId" name="entity_id">
            <label>
                <div style="margin-bottom:4px;font-weight:600">Link Title *</div>
                <input type="text" id="manualLinkTitle" name="title" required 
                       placeholder="e.g., Project Files, Shared Folder"
                       style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
            </label>
            <label>
                <div style="margin-bottom:4px;font-weight:600">URL *</div>
                <input type="url" id="manualLinkUrl" name="url" required 
                       placeholder="https://..."
                       style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
            </label>
            <label>
                <div style="margin-bottom:4px;font-weight:600">Expiration Date (Optional)</div>
                <input type="date" id="manualLinkExpiration" name="expiration_date"
                       style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
            </label>
            <div style="display:flex;gap:12px;margin-top:8px">
                <button type="submit" 
                        style="flex:1;padding:10px;border-radius:8px;border:0;background:var(--nav-accent);color:#fff;font-weight:600;cursor:pointer">
                    Add Link
                </button>
                <button type="button" onclick="closeManualLinkModal()" 
                        style="flex:1;padding:10px;border-radius:8px;border:1px solid #ddd;background:#fff;cursor:pointer">
                    Cancel
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function generateLinks(entityType, entityId) {
    if (!confirm('Generate storage links for this ' + entityType + '?')) return;
    
    const btn = event.target;
    btn.disabled = true;
    btn.textContent = 'Generating...';
    
    fetch('/?page=links/link-management', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({
            action: 'generate',
            entity_type: entityType,
            entity_id: entityId
        })
    })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        btn.textContent = '+ Generate Links';
        if (data.success) {
            alert('✅ Links generated successfully!');
            location.reload();
        } else {
            alert('❌ Error: ' + (data.message || 'Failed to generate links'));
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.textContent = '+ Generate Links';
        alert('❌ Network error: ' + err.message);
    });
}

function refreshLinks(entityType, entityId) {
    if (!confirm('Refresh all links for this ' + entityType + '?')) return;
    
    const btn = event.target;
    btn.disabled = true;
    btn.textContent = 'Refreshing...';
    
    fetch('/?page=links/link-management', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({
            action: 'refresh',
            entity_type: entityType,
            entity_id: entityId
        })
    })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        btn.textContent = '🔄 Refresh';
        if (data.success) {
            alert('✅ Links refreshed successfully!');
            location.reload();
        } else {
            alert('❌ Error: ' + (data.message || 'Failed to refresh links'));
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.textContent = '🔄 Refresh';
        alert('❌ Network error: ' + err.message);
    });
}

function ignoreLinks(entityType, entityId) {
    if (!confirm('Disable auto-generation for this ' + entityType + '? Existing links will remain but won\'t be refreshed automatically.')) return;
    
    fetch('/?page=links/link-management', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({
            action: 'ignore',
            entity_type: entityType,
            entity_id: entityId
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            alert('✅ Auto-generation disabled');
            location.reload();
        } else {
            alert('❌ Error: ' + (data.message || 'Failed to update setting'));
        }
    })
    .catch(err => {
        alert('❌ Network error: ' + err.message);
    });
}

function unignoreLinks(entityType, entityId) {
    if (!confirm('Re-enable auto-generation for this ' + entityType + '?')) return;
    
    fetch('/?page=links/link-management', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({
            action: 'unignore',
            entity_type: entityType,
            entity_id: entityId
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            alert('✅ Auto-generation enabled');
            location.reload();
        } else {
            alert('❌ Error: ' + (data.message || 'Failed to update setting'));
        }
    })
    .catch(err => {
        alert('❌ Network error: ' + err.message);
    });
}

function showAddManualLinkModal(entityType, entityId) {
    document.getElementById('manualLinkEntityType').value = entityType;
    document.getElementById('manualLinkEntityId').value = entityId;
    document.getElementById('manualLinkTitle').value = '';
    document.getElementById('manualLinkUrl').value = '';
    document.getElementById('manualLinkExpiration').value = '';
    document.getElementById('manualLinkModal').style.display = 'flex';
}

function closeManualLinkModal() {
    document.getElementById('manualLinkModal').style.display = 'none';
}

// Handle manual link form submission
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('manualLinkForm');
    if (form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(form);
            const submitBtn = form.querySelector('button[type="submit"]');
            submitBtn.disabled = true;
            submitBtn.textContent = 'Adding...';
            
            fetch('/?page=links/manual-link-handler', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                submitBtn.disabled = false;
                submitBtn.textContent = 'Add Link';
                
                if (data.success) {
                    alert('✅ Manual link added successfully!');
                    closeManualLinkModal();
                    location.reload();
                } else {
                    alert('❌ Error: ' + (data.message || 'Failed to add link'));
                }
            })
            .catch(err => {
                submitBtn.disabled = false;
                submitBtn.textContent = 'Add Link';
                alert('❌ Network error: ' + err.message);
            });
        });
    }
});
</script>
