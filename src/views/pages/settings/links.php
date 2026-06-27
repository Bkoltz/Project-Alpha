<?php
// src/views/pages/settings/links.php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/escaper.php';

// Fetch global app config
$appConfig = [];
try {
    $hasOrgColumn = (bool)$pdo->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='app_config' AND COLUMN_NAME='organization_id'")->fetchColumn();
    $stmt = $pdo->query($hasOrgColumn ? 'SELECT config_key, config_value FROM app_config WHERE organization_id = 0' : 'SELECT config_key, config_value FROM app_config');
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $appConfig[$row['config_key']] = $row['config_value'];
    }
} catch (Throwable $e) {
    // Table may not exist yet - will be created on first save
}

// Fetch link resolver configurations
$linkConfigs = [];
try {
    $stmt = $pdo->query('SELECT * FROM link_resolver_config ORDER BY provider');
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $linkConfigs[$row['provider']] = $row;
    }
} catch (Throwable $e) {
    // Table may not exist yet
}

// Get counts of links, expired links, and ignored clients/orgs
$linkStats = [
    'total_links' => 0,
    'expired_links' => 0,
    'ignored_clients' => 0,
    'auto_links' => 0
];

try {
    $linkStats['total_links'] = (int)$pdo->query('SELECT COUNT(*) FROM entity_links')->fetchColumn();
    $linkStats['expired_links'] = (int)$pdo->query('SELECT COUNT(*) FROM entity_links WHERE is_expired = 1')->fetchColumn();
    $linkStats['ignored_clients'] = (int)$pdo->query('SELECT COUNT(*) FROM entity_links WHERE ignore_auto_generation = 1')->fetchColumn();
    $linkStats['auto_links'] = (int)$pdo->query("SELECT COUNT(*) FROM entity_links WHERE link_type IN ('auto_dropbox','auto_gdrive','auto_s3')")->fetchColumn();
} catch (Throwable $e) {
    @error_log('[links] Error fetching link stats: ' . $e->getMessage());
}

// Make CSRF token available to JavaScript
$csrfToken = session_status() === PHP_SESSION_ACTIVE ? ($_SESSION['csrf'] ?? '') : '';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf'];

$providers = ['dropbox', 'gdrive', 's3'];
?>
<div style="max-width:1000px">
    <!-- CSRF token for JavaScript -->
    <script nonce="<?php echo e($csrfToken); ?>">
    (function() {
        window.csrfToken = "<?php echo e($csrfToken); ?>";
    })();
    </script>

    <h2 style="margin:0 0 8px 0">Link Resolver</h2>
    <p style="margin:0 0 24px 0;color:var(--muted)">Auto-generate and manage links for client/organization file storage</p>

    <!-- Stats Banner -->
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:24px">
        <div style="padding:16px;background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px">
            <div style="font-size:24px;font-weight:700;color:#0369a1"><?php echo $linkStats['total_links']; ?></div>
            <div style="font-size:13px;color:#075985">Total Links</div>
        </div>
        <div style="padding:16px;background:#ecfdf5;border:1px solid #a7f3d0;border-radius:8px">
            <div style="font-size:24px;font-weight:700;color:#065f46"><?php echo $linkStats['auto_links']; ?></div>
            <div style="font-size:13px;color:#065f46">Auto-Generated</div>
        </div>
        <div style="padding:16px;background:#fef3c7;border:1px solid #fde68a;border-radius:8px">
            <div style="font-size:24px;font-weight:700;color:#92400e"><?php echo $linkStats['expired_links']; ?></div>
            <div style="font-size:13px;color:#92400e">Expired</div>
        </div>
        <div style="padding:16px;background:#f3f4f6;border:1px solid #d1d5db;border-radius:8px">
            <div style="font-size:24px;font-weight:700;color:#374151"><?php echo $linkStats['ignored_clients']; ?></div>
            <div style="font-size:13px;color:#6b7280">Ignored</div>
        </div>
    </div>

    <form method="POST" action="/?page=settings/links-handler">
    <input type="hidden" name="csrf" value="<?php echo e($csrfToken); ?>">
    
    <!-- Global Settings -->
    <fieldset style="border:1px solid #eee;border-radius:8px;padding:16px;margin-bottom:20px">
        <legend style="padding:0 8px;font-weight:600">Global Settings</legend>
        
        <div style="display:grid;gap:16px">
            <label style="display:flex;align-items:center;gap:10px">
                <input type="checkbox" name="link_resolver_enabled" value="1" <?php echo !empty($appConfig['link_resolver_enabled']) ? 'checked' : ''; ?>>
                <span class="font-600">Enable Link Resolver System</span>
            </label>

            <label style="display:flex;align-items:center;gap:10px">
                <input type="checkbox" name="org_level_links_only" value="1" <?php echo !empty($appConfig['org_level_links_only']) ? 'checked' : ''; ?>>
                <span class="font-600">Organization-level links only</span>
                <span style="font-size:13px;color:var(--muted);margin-left:4px">(If client belongs to organization, manage links at org level)</span>
            </label>
            
            <div style="padding:12px;background:#fffbeb;border:1px solid #fde68a;border-radius:6px;font-size:13px">
                <strong>ℹ️ Link Expiration:</strong> Set expiration dates per-client or per-organization in their respective storage sections, not globally.
            </div>
        </div>
    </fieldset>

    <!-- Provider Configurations -->
    <h3 style="margin:0 0 12px 0;font-size:16px">Storage Providers</h3>
    
    <?php foreach ($providers as $provider): 
        $config = $linkConfigs[$provider] ?? null;
        $isEnabled = $config ? $config['is_enabled'] : 0;
        $credentials = $config && $config['credentials'] ? json_decode($config['credentials'], true) : [];
    ?>
    
    <fieldset style="border:1px solid #eee;border-radius:8px;padding:16px;margin-bottom:16px">
        <legend style="padding:0 8px;font-weight:600;text-transform:capitalize">
            <?php 
                $providerName = $provider;
                if ($provider === 'gdrive') $providerName = 'Google Drive';
                elseif ($provider === 's3') $providerName = 'Amazon S3';
                else $providerName = ucfirst($provider);
                echo e($providerName); 
            ?>
        </legend>
        
        <div style="display:grid;gap:16px">
            <label style="display:flex;align-items:center;gap:10px">
                <input type="checkbox" name="provider_enabled_<?php echo e($provider); ?>" value="1"
                       <?php echo $isEnabled ? 'checked' : ''; ?>
                       onchange="toggleProviderFields('<?php echo e($provider); ?>')">
                <span class="font-600">Enable <?php echo e($providerName); ?> Auto-Generation</span>
            </label>

            <div id="fields_<?php echo e($provider); ?>" style="<?php echo !$isEnabled ? 'display:none' : ''; ?>">
                <?php if ($provider === 'dropbox'): ?>
                    <div class="grid">
                        <?php if (!empty($credentials['refresh_token'])): ?>
                            <!-- OAuth Connected State -->
                            <div style="padding:12px;background:#ecfdf5;border:1px solid #a7f3d0;border-radius:8px;display:flex;align-items:center;gap:12px">
                                <span style="font-size:20px">✅</span>
                                <div style="flex:1">
                                    <div style="font-weight:600;color:#065f46">Dropbox Connected</div>
                                    <div style="font-size:12px;color:#6b7280">
                                        <?php if (!empty($credentials['token_expires_at'])): ?>
                                            Token expires: <?php echo e($credentials['token_expires_at']); ?>
                                        <?php else: ?>
                                            Connection is active
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <a href="/?page=settings/dropbox-oauth&action=disconnect" 
                                   style="padding:6px 12px;border-radius:6px;border:1px solid #dc2626;background:#fff;color:#dc2626;font-size:13px;text-decoration:none"
                                   onclick="return confirm('Disconnect Dropbox? This will revoke the token.');">Disconnect</a>
                            </div>
                        <?php else: ?>
                            <!-- OAuth Disconnected State -->
                            <div style="padding:12px;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;display:flex;align-items:center;gap:12px">
                                <span style="font-size:20px">🔗</span>
                                <div style="flex:1">
                                    <div style="font-weight:600;color:#991b1b">Dropbox Not Connected</div>
                                    <div style="font-size:12px;color:#6b7280">Connect via OAuth for a secure, permanent connection.</div>
                                </div>
                                <a href="/?page=settings/dropbox-oauth&action=start" 
                                   style="padding:8px 16px;border-radius:6px;border:0;background:#2563eb;color:#fff;font-size:13px;text-decoration:none;font-weight:600">Connect Dropbox</a>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Show legacy access token field if no OAuth, for backward compatibility -->
                        <?php if (empty($credentials['refresh_token'])): ?>
                            <div style="margin-top:8px">
                                <label style="font-size:13px;color:var(--muted);cursor:pointer;display:flex;align-items:center;gap:6px">
                                    <input type="checkbox" onchange="document.getElementById('legacy-dropbox-token').style.display=this.checked?'block':'none'">
                                    <span>Use legacy access token instead (not recommended)</span>
                                </label>
                                <div id="legacy-dropbox-token" style="display:none;margin-top:8px">
                                    <input type="text" name="<?php echo e($provider); ?>_access_token"
                                           value="<?php echo e($credentials['access_token'] ?? ''); ?>"
                                           placeholder="Enter Dropbox access token"
                                           style="width:100%;padding:8px;border-radius:6px;border:1px solid #ddd;font-family:monospace;font-size:13px">
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                <?php elseif ($provider === 'gdrive'): ?>
                    <label>
                        <div style="margin-bottom:4px;font-weight:600">Service Account JSON</div>
                        <textarea name="<?php echo e($provider); ?>_credentials" rows="4"
                                  placeholder='Paste Google Service Account JSON here'
                                  style="width:100%;padding:8px;border-radius:6px;border:1px solid #ddd;font-family:monospace;font-size:12px"><?php echo e($credentials['service_account'] ?? ''); ?></textarea>
                        <div style="margin-top:4px;font-size:12px;color:var(--muted)">
                            Get credentials from <a href="https://console.cloud.google.com/" target="_blank" style="color:var(--nav-accent)">Google Cloud Console</a>
                        </div>
                    </label>

                <?php elseif ($provider === 's3'): ?>
                    <div style="display:grid;gap:12px;grid-template-columns:1fr 1fr">
                        <label>
                            <div style="margin-bottom:4px;font-weight:600">Access Key ID</div>
                            <input type="text" name="<?php echo e($provider); ?>_access_key"
                                   value="<?php echo e($credentials['access_key'] ?? ''); ?>"
                                   placeholder="AWS Access Key ID"
                                   style="width:100%;padding:8px;border-radius:6px;border:1px solid #ddd;font-family:monospace;font-size:13px">
                        </label>
                        <label>
                            <div style="margin-bottom:4px;font-weight:600">Secret Access Key</div>
                            <input type="password" name="<?php echo e($provider); ?>_secret_key"
                                   value="<?php echo e($credentials['secret_key'] ?? ''); ?>"
                                   placeholder="AWS Secret Access Key"
                                   style="width:100%;padding:8px;border-radius:6px;border:1px solid #ddd;font-family:monospace;font-size:13px">
                        </label>
                    </div>
                    <label>
                        <div style="margin-bottom:4px;font-weight:600">Bucket Name</div>
                        <input type="text" name="<?php echo e($provider); ?>_bucket"
                               value="<?php echo e($credentials['bucket'] ?? ''); ?>"
                               placeholder="my-bucket-name"
                               style="width:100%;padding:8px;border-radius:6px;border:1px solid #ddd">
                    </label>
                    <label>
                        <div style="margin-bottom:4px;font-weight:600">Region</div>
                        <input type="text" name="<?php echo e($provider); ?>_region"
                               value="<?php echo e($credentials['region'] ?? 'us-east-1'); ?>"
                               placeholder="us-east-1"
                               style="width:200px;padding:8px;border-radius:6px;border:1px solid #ddd">
                    </label>
                <?php endif; ?>

                <label>
                    <div style="margin-bottom:4px;font-weight:600">Root Folder Path</div>
                    <input type="text" name="<?php echo e($provider); ?>_root_path"
                           value="<?php echo e($credentials['root_path'] ?? '/'); ?>"
                           placeholder="/"
                           style="width:100%;padding:8px;border-radius:6px;border:1px solid #ddd">
                    <div style="margin-top:4px;font-size:12px;color:var(--muted)">
                        Base path where client/org folders are located
                    </div>
                </label>

                <button type="button" onclick="testConnection('<?php echo e($provider); ?>')"
                        style="padding:8px 16px;border-radius:6px;border:1px solid #ddd;background:#fff;font-size:13px;cursor:pointer">
                    Test Connection
                </button>
            </div>
        </div>
    </fieldset>
    
    <?php endforeach; ?>

    <!-- Link Expiration Reference -->
    <fieldset style="border:1px solid #eee;border-radius:8px;padding:16px;margin-top:20px">
        <legend style="padding:0 8px;font-weight:600">Link Expiration Management</legend>
        <div style="padding:12px;background:#f0f9ff;border:1px solid #bae6fd;border-radius:6px;font-size:13px">
            ℹ️ <strong>Link expiration automation and warnings</strong> are managed on the <a href="/?page=settings&tab=notifications" style="color:var(--nav-accent);font-weight:600">Notifications</a> tab under "System Automation" and "Link Expiration Warnings".
        </div>
        <div style="margin-top:12px;padding:12px;background:#fffbeb;border:1px solid #fde68a;border-radius:6px;font-size:13px">
            <strong>ℹ️ Note:</strong> Expired links will be marked automatically but not deleted. You can manually refresh or regenerate them as needed.
        </div>
    </fieldset>
    
    <!-- Save Button -->
    <div style="margin-top:20px">
        <button type="submit" style="padding:10px 24px;border-radius:8px;border:0;background:var(--nav-accent);color:#fff;font-weight:600;cursor:pointer;font-size:14px">
            Save Settings
        </button>
        <?php if (isset($_GET['saved']) && $_GET['saved'] === '1'): ?>
            <span style="margin-left:12px;color:#059669;font-weight:600">✓ Settings saved!</span>
        <?php elseif (isset($_GET['saved']) && $_GET['saved'] === '0'): ?>
            <span style="margin-left:12px;color:#dc2626;font-weight:600">✗ Failed to save settings. <?php echo isset($_GET['error']) ? e($_GET['error']) : ''; ?></span>
        <?php endif; ?>
    </div>
    </form>
</div>

<script>
function toggleProviderFields(provider) {
    const checkbox = document.querySelector(`input[name="provider_enabled_${provider}"]`);
    const fields = document.getElementById(`fields_${provider}`);
    fields.style.display = checkbox.checked ? 'block' : 'none';
}

function testConnection(provider) {
    const btn = event.target;
    btn.disabled = true;
    btn.textContent = 'Testing...';
    
    // Gather credentials for this provider
    const formData = new FormData();
    formData.append('provider', provider);
    formData.append('csrf', window.csrfToken || '');  // Add CSRF token
    
    if (provider === 'dropbox') {
        formData.append('access_token', document.querySelector(`input[name="${provider}_access_token"]`).value);
    } else if (provider === 'gdrive') {
        formData.append('credentials', document.querySelector(`textarea[name="${provider}_credentials"]`).value);
    } else if (provider === 's3') {
        formData.append('access_key', document.querySelector(`input[name="${provider}_access_key"]`).value);
        formData.append('secret_key', document.querySelector(`input[name="${provider}_secret_key"]`).value);
        formData.append('bucket', document.querySelector(`input[name="${provider}_bucket"]`).value);
        formData.append('region', document.querySelector(`input[name="${provider}_region"]`).value);
    }
    
    fetch('/?page=settings/link-test-connection', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        btn.textContent = 'Test Connection';
        if (data.success) {
            alert('✅ Connection successful!');
        } else {
            alert('❌ Connection failed: ' + (data.error || 'Unknown error'));
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.textContent = 'Test Connection';
        alert('❌ Connection test failed: ' + err.message);
    });
}
</script>
