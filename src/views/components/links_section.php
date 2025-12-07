<?php
// src/views/components/links_section.php
// This component displays links for a client or organization
// Required variables: $entityType ('client' or 'organization'), $entityId

if (!isset($entityType) || !isset($entityId)) {
    return;
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
            <p style="margin:0;font-size:13px;color:var(--muted)">Auto-generated links to cloud storage folders</p>
        </div>
        <div style="display:flex;gap:8px">
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
    </div>

    <?php if ($isIgnored): ?>
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
</script>
