<?php
// src/views/components/links_section.php
// Displays manual and resolver-managed links for an entity.
// Required variables: $entityType, $entityId

require_once __DIR__ . '/../../utils/invoice_content_links.php';

if (!isset($entityType, $entityId)) {
    return;
}

$linkResolverEnabled = pa_config_bool($appConfig ?? [], 'link_resolver_enabled', false);
$belongsToOrg = false;
$isReadOnly = false;
$linkDepartmentOptions = [];

if ($entityType === 'client') {
    try {
        $stmt = $pdo->prepare('SELECT organization_id FROM clients WHERE id = ?');
        $stmt->execute([(int)$entityId]);
        $orgId = $stmt->fetchColumn();
        if ($orgId) {
            $belongsToOrg = true;
            $isReadOnly = true;
        }
    } catch (Throwable $e) {
        @error_log('[LinksSection] Error checking org: ' . $e->getMessage());
    }
}

if ($entityType === 'organization') {
    try {
        $deptStmt = $pdo->prepare('SELECT id, name FROM organization_departments WHERE organization_id = ? ORDER BY name ASC');
        $deptStmt->execute([(int)$entityId]);
        $linkDepartmentOptions = $deptStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $linkDepartmentOptions = [];
        @error_log('[LinksSection] Error loading departments: ' . $e->getMessage());
    }
}

try {
    $sourceSelect = invoice_content_links_table_has_column($pdo, 'entity_links', 'link_source') ? 'link_source' : 'link_type AS link_source';
    $includeSelect = invoice_content_links_table_has_column($pdo, 'entity_links', 'include_on_invoices') ? 'include_on_invoices' : '0 AS include_on_invoices';
    $stmt = $pdo->prepare("
        SELECT id, title, link_type, {$sourceSelect}, {$includeSelect}, url, expiration_date, is_expired, ignore_auto_generation, last_verified
        FROM entity_links
        WHERE entity_type = ? AND entity_id = ?
        ORDER BY include_on_invoices DESC, link_type ASC, title ASC
    ");
    $stmt->execute([(string)$entityType, (int)$entityId]);
    $links = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $links = [];
    @error_log('[LinksSection] Error fetching links: ' . $e->getMessage());
}

$isIgnored = false;
foreach ($links as $link) {
    if (!empty($link['ignore_auto_generation'])) {
        $isIgnored = true;
        break;
    }
}
?>

<div style="margin-top:32px;padding-top:24px;border-top:2px solid #eee">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:16px">
        <div>
            <h3 style="margin:0 0 4px 0;font-size:18px">File & Content Links</h3>
            <p style="margin:0;font-size:13px;color:var(--muted)">Manual links are available even when automatic link resolver is disabled.</p>
        </div>
        <?php if (!$isReadOnly): ?>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
            <button type="button" onclick="showAddManualLinkModal('<?php echo e((string)$entityType); ?>', <?php echo (int)$entityId; ?>)"
                    style="padding:8px 12px;border-radius:6px;border:1px solid #3b82f6;background:#eff6ff;color:#1e40af;font-size:13px;cursor:pointer;font-weight:600">
                + Add Manual Link
            </button>
            <?php if ($linkResolverEnabled && !$isReadOnly): ?>
                <?php if ($isIgnored): ?>
                    <button type="button" onclick="unignoreLinks('<?php echo e((string)$entityType); ?>', <?php echo (int)$entityId; ?>)"
                            style="padding:8px 12px;border-radius:6px;border:1px solid #ddd;background:#fff;font-size:13px;cursor:pointer">
                        Enable resolver
                    </button>
                <?php else: ?>
                    <button type="button" onclick="generateLinks('<?php echo e((string)$entityType); ?>', <?php echo (int)$entityId; ?>)"
                            style="padding:8px 12px;border-radius:6px;border:1px solid #10b981;background:#ecfdf5;color:#065f46;font-size:13px;cursor:pointer;font-weight:600">
                        Generate Links
                    </button>
                    <button type="button" onclick="refreshLinks('<?php echo e((string)$entityType); ?>', <?php echo (int)$entityId; ?>)"
                            style="padding:8px 12px;border-radius:6px;border:1px solid #ddd;background:#fff;font-size:13px;cursor:pointer">
                        Refresh
                    </button>
                    <button type="button" onclick="ignoreLinks('<?php echo e((string)$entityType); ?>', <?php echo (int)$entityId; ?>)"
                            style="padding:8px 12px;border-radius:6px;border:1px solid #ddd;background:#fff;font-size:13px;cursor:pointer">
                        Manual only
                    </button>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <?php if (!$linkResolverEnabled): ?>
        <div style="padding:12px 16px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;margin-bottom:16px;color:#374151;font-size:13px">
            Link resolver automation is disabled. Add manual Dropbox, WebODM, or external links when you want PA to store content links.
        </div>
    <?php endif; ?>

    <?php if ($isReadOnly): ?>
        <div style="padding:12px 16px;background:#e0e7ff;border:1px solid #a5b4fc;border-radius:8px;margin-bottom:16px">
            <strong>Organization-managed links</strong> — this client is part of an organization, so manage shared links on the organization page.
        </div>
    <?php endif; ?>

    <?php if ($isIgnored && !$isReadOnly): ?>
        <div style="padding:12px 16px;background:#fef3c7;border:1px solid #fde68a;border-radius:8px;margin-bottom:16px">
            <strong>Manual-only mode</strong> — automatic link generation is disabled for this <?php echo e((string)$entityType); ?>.
        </div>
    <?php endif; ?>

    <div id="linksContainer_<?php echo e((string)$entityType); ?>_<?php echo (int)$entityId; ?>" style="display:grid;gap:12px">
        <?php if (empty($links)): ?>
            <div style="padding:24px;text-align:center;background:#f9fafb;border:1px dashed #d1d5db;border-radius:8px;color:var(--muted)">
                No links yet. Add a manual link when this client or organization needs a content URL.
            </div>
        <?php else: ?>
            <?php foreach ($links as $link):
                $typeLabel = ucwords(str_replace(['manual_', 'auto_', '_'], ['', '', ' '], (string)$link['link_type']));
                $displayTitle = trim((string)($link['title'] ?? '')) !== '' ? (string)$link['title'] : ($typeLabel ?: 'Content link');
                if (!empty($link['is_expired'])) {
                    $statusStyle = 'background:#fee2e2;color:#991b1b;border-color:#fca5a5';
                    $statusText = 'Expired';
                } elseif (!empty($link['expiration_date']) && strtotime((string)$link['expiration_date']) < strtotime('+7 days')) {
                    $statusStyle = 'background:#fef3c7;color:#92400e;border-color:#fde68a';
                    $statusText = 'Expires soon';
                } else {
                    $statusStyle = 'background:#ecfdf5;color:#065f46;border-color:#a7f3d0';
                    $statusText = 'Active';
                }
            ?>
                <div style="padding:16px;border:1px solid #e5e7eb;border-radius:8px;background:#fff">
                    <div style="display:flex;justify-content:space-between;align-items:start;gap:12px;margin-bottom:8px">
                        <div>
                            <div style="font-weight:600;font-size:14px;margin-bottom:4px"><?php echo e($displayTitle); ?></div>
                            <div style="font-size:12px;color:var(--muted);margin-bottom:4px">
                                <?php echo e($typeLabel); ?><?php echo !empty($link['include_on_invoices']) ? ' · Included on invoices' : ''; ?>
                            </div>
                            <a href="<?php echo e((string)$link['url']); ?>" target="_blank" rel="noopener"
                               style="font-size:13px;color:#0369a1;text-decoration:none;word-break:break-all">
                                <?php echo e((string)$link['url']); ?> ↗
                            </a>
                        </div>
                        <div style="padding:4px 8px;border-radius:6px;font-size:12px;font-weight:600;white-space:nowrap;<?php echo $statusStyle; ?>">
                            <?php echo e($statusText); ?>
                        </div>
                    </div>
                    <div style="display:flex;gap:16px;flex-wrap:wrap;font-size:12px;color:var(--muted);margin-top:8px">
                        <?php if (!empty($link['expiration_date'])): ?>
                            <span>Expires: <?php echo e(date('M j, Y', strtotime((string)$link['expiration_date']))); ?></span>
                        <?php endif; ?>
                        <?php if (!empty($link['last_verified'])): ?>
                            <span>Last verified: <?php echo e(date('M j, Y', strtotime((string)$link['last_verified']))); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<div id="manualLinkModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center">
    <div style="background:#fff;border-radius:12px;padding:24px;max-width:520px;width:90%">
        <h3 style="margin:0 0 16px 0">Add Manual Link</h3>
        <form id="manualLinkForm" style="display:grid;gap:16px">
            <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
            <input type="hidden" id="manualLinkEntityType" name="entity_type">
            <input type="hidden" id="manualLinkEntityId" name="entity_id">
            <label>
                <div style="margin-bottom:4px;font-weight:600">Link Title *</div>
                <input type="text" id="manualLinkTitle" name="title" required placeholder="e.g., Football Dropbox Folder, WebODM Map"
                       style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
            </label>
            <label>
                <div style="margin-bottom:4px;font-weight:600">URL *</div>
                <input type="url" id="manualLinkUrl" name="url" required placeholder="https://..."
                       style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
            </label>
            <label>
                <div style="margin-bottom:4px;font-weight:600">Link Type</div>
                <select id="manualLinkType" name="link_type" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
                    <option value="manual">General manual link</option>
                    <option value="manual_dropbox">Dropbox folder</option>
                    <option value="manual_gdrive">Google Drive folder</option>
                    <option value="manual_onedrive">OneDrive folder</option>
                    <option value="manual_webodm_map">WebODM map</option>
                    <option value="manual_webodm_model">WebODM model</option>
                    <option value="manual_external">External URL</option>
                    <option value="manual_other">Other</option>
                </select>
            </label>
            <label>
                <div style="margin-bottom:4px;font-weight:600">Expiration Date (Optional)</div>
                <input type="date" id="manualLinkExpiration" name="expiration_date"
                       style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
            </label>
            <label style="display:flex;gap:8px;align-items:flex-start">
                <input type="checkbox" id="manualLinkIncludeOnInvoices" name="include_on_invoices" value="1" style="margin-top:3px">
                <span>
                    <span style="font-weight:600">Include on invoices</span>
                    <span style="display:block;font-size:12px;color:var(--muted)">Shown in "View your content here" when invoice link rules allow this entity's links.</span>
                </span>
            </label>
            <div id="manualLinkVisibilityFields" style="display:none;border:1px solid #e5e7eb;border-radius:8px;padding:12px;background:#f9fafb">
                <label>
                    <div style="margin-bottom:4px;font-weight:600">Organization Link Visibility</div>
                    <select id="manualLinkVisibilityScope" name="visibility_scope" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
                        <option value="entity_only">Organization only</option>
                        <option value="all_departments">All departments</option>
                        <option value="selected_departments">Selected departments</option>
                        <option value="org_contacts">Organization contacts without department</option>
                    </select>
                </label>
                <div id="manualLinkDepartmentPicker" style="display:none;margin-top:10px">
                    <div style="font-size:13px;font-weight:600;margin-bottom:6px">Allowed Departments</div>
                    <?php if (empty($linkDepartmentOptions)): ?>
                        <div style="font-size:12px;color:var(--muted)">No departments exist yet.</div>
                    <?php else: ?>
                        <div style="display:grid;gap:6px;max-height:140px;overflow:auto">
                            <?php foreach ($linkDepartmentOptions as $department): ?>
                                <label style="display:flex;gap:8px;align-items:center;font-size:13px">
                                    <input type="checkbox" name="selected_department_ids[]" value="<?php echo (int)$department['id']; ?>">
                                    <span><?php echo e((string)$department['name']); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <div style="display:flex;gap:12px;margin-top:8px">
                <button type="submit" style="flex:1;padding:10px;border-radius:8px;border:0;background:var(--nav-accent);color:#fff;font-weight:600;cursor:pointer">
                    Add Link
                </button>
                <button type="button" onclick="closeManualLinkModal()" style="flex:1;padding:10px;border-radius:8px;border:1px solid #ddd;background:#fff;cursor:pointer">
                    Cancel
                </button>
            </div>
        </form>
    </div>
</div>

<script>
var linksSectionCsrf = '<?php echo e(csrf_token()); ?>';

function linkManagementRequest(action, entityType, entityId) {
    return fetch('/?page=links/link-management', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({action, entity_type: entityType, entity_id: entityId, csrf: linksSectionCsrf})
    }).then(r => r.json());
}

function generateLinks(entityType, entityId) {
    if (!confirm('Generate storage links for this ' + entityType + '?')) return;
    linkManagementRequest('generate', entityType, entityId).then(data => {
        if (data.success) location.reload();
        else alert('Error: ' + (data.message || 'Failed to generate links'));
    }).catch(err => alert('Network error: ' + err.message));
}

function refreshLinks(entityType, entityId) {
    if (!confirm('Refresh all links for this ' + entityType + '?')) return;
    linkManagementRequest('refresh', entityType, entityId).then(data => {
        if (data.success) location.reload();
        else alert('Error: ' + (data.message || 'Failed to refresh links'));
    }).catch(err => alert('Network error: ' + err.message));
}

function ignoreLinks(entityType, entityId) {
    if (!confirm('Switch this ' + entityType + ' to manual-only links?')) return;
    linkManagementRequest('ignore', entityType, entityId).then(data => {
        if (data.success) location.reload();
        else alert('Error: ' + (data.message || 'Failed to update setting'));
    }).catch(err => alert('Network error: ' + err.message));
}

function unignoreLinks(entityType, entityId) {
    if (!confirm('Allow automatic link generation for this ' + entityType + '?')) return;
    linkManagementRequest('unignore', entityType, entityId).then(data => {
        if (data.success) location.reload();
        else alert('Error: ' + (data.message || 'Failed to update setting'));
    }).catch(err => alert('Network error: ' + err.message));
}

function showAddManualLinkModal(entityType, entityId) {
    document.getElementById('manualLinkEntityType').value = entityType;
    document.getElementById('manualLinkEntityId').value = entityId;
    document.getElementById('manualLinkTitle').value = '';
    document.getElementById('manualLinkUrl').value = '';
    document.getElementById('manualLinkType').value = 'manual';
    document.getElementById('manualLinkExpiration').value = '';
    document.getElementById('manualLinkIncludeOnInvoices').checked = false;
    const visibilityFields = document.getElementById('manualLinkVisibilityFields');
    const visibilityScope = document.getElementById('manualLinkVisibilityScope');
    const departmentPicker = document.getElementById('manualLinkDepartmentPicker');
    if (visibilityFields && visibilityScope && departmentPicker) {
        visibilityFields.style.display = entityType === 'organization' ? 'block' : 'none';
        visibilityScope.value = 'entity_only';
        departmentPicker.style.display = 'none';
        document.querySelectorAll('#manualLinkDepartmentPicker input[type="checkbox"]').forEach(cb => cb.checked = false);
    }
    document.getElementById('manualLinkModal').style.display = 'flex';
}

function closeManualLinkModal() {
    document.getElementById('manualLinkModal').style.display = 'none';
}

document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('manualLinkForm');
    const visibilityScope = document.getElementById('manualLinkVisibilityScope');
    const departmentPicker = document.getElementById('manualLinkDepartmentPicker');
    if (visibilityScope && departmentPicker) {
        visibilityScope.addEventListener('change', function() {
            departmentPicker.style.display = visibilityScope.value === 'selected_departments' ? 'block' : 'none';
        });
    }
    if (!form) return;
    form.addEventListener('submit', function(e) {
        e.preventDefault();
        const formData = new FormData(form);
        const submitBtn = form.querySelector('button[type="submit"]');
        submitBtn.disabled = true;
        submitBtn.textContent = 'Adding...';
        fetch('/?page=links/manual-link-handler', {method: 'POST', body: formData})
            .then(r => r.json())
            .then(data => {
                submitBtn.disabled = false;
                submitBtn.textContent = 'Add Link';
                if (data.success) {
                    closeManualLinkModal();
                    location.reload();
                } else {
                    alert('Error: ' + (data.message || 'Failed to add link'));
                }
            })
            .catch(err => {
                submitBtn.disabled = false;
                submitBtn.textContent = 'Add Link';
                alert('Network error: ' + err.message);
            });
    });
});
</script>
