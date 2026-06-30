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
$helpIcon = static function (string $text): string {
    return '<span class="pa-help" tabindex="0" aria-label="' . e($text) . '" title="' . e($text) . '">?</span>';
};
$dropboxAppKey = trim((string)($appConfig['dropbox_app_key'] ?? ''));
$dropboxHasAppSecret = trim((string)($appConfig['dropbox_app_secret'] ?? '')) !== '';
$dropboxCanConnect = $dropboxAppKey !== '' && $dropboxHasAppSecret;
$configuredAppHost = trim((string)($appConfig['app_host'] ?? ''));
$dropboxCallbackBase = $configuredAppHost !== '' ? $configuredAppHost : ($_SERVER['HTTP_HOST'] ?? '');
if ($dropboxCallbackBase !== '' && !preg_match('#^https?://#i', $dropboxCallbackBase)) {
    $dropboxCallbackBase = 'https://' . preg_replace('#/.*$#', '', $dropboxCallbackBase);
}
$dropboxCallbackUri = rtrim($dropboxCallbackBase, '/') . '/?page=settings/dropbox-oauth&action=callback';
?>
<div style="max-width:1000px">
    <!-- CSRF token for JavaScript -->
    <script nonce="<?php echo e($csrfToken); ?>">
    (function() {
        window.csrfToken = "<?php echo e($csrfToken); ?>";
    })();
    </script>

    <style>
    .pa-help{display:inline-flex;align-items:center;justify-content:center;width:18px;height:18px;border-radius:999px;background:#eef2ff;color:#3730a3;font-size:12px;font-weight:800;margin-left:6px;cursor:help}
    .pa-help:focus{outline:2px solid #93c5fd;outline-offset:2px}
    .pa-setting-note{font-size:12px;color:var(--muted);line-height:1.4;margin-top:4px}
    .pa-setup-card{padding:14px;border:1px solid #dbeafe;background:#eff6ff;border-radius:10px;margin-bottom:20px}
    .pa-setup-card h3{margin:0 0 8px;font-size:15px;color:#1e3a8a}
    .pa-setup-card ol{margin:0;padding-left:20px;color:#1f2937;font-size:13px;line-height:1.55}
    .pa-provider-note{padding:10px 12px;border:1px solid #e5e7eb;background:#f9fafb;border-radius:8px;font-size:12px;color:#4b5563;line-height:1.45}
    </style>

    <h2 style="margin:0 0 8px 0">Link Resolver</h2>
    <p style="margin:0 0 24px 0;color:var(--muted)">Connect cloud storage, create manual content links, and optionally let PA find exact organization or department folders.</p>

    <div class="pa-setup-card">
        <h3>Recommended setup flow</h3>
        <ol>
            <li>Keep the resolver disabled unless your business delivers files or external links with invoices.</li>
            <li>Create organization and department records first, including folder names or aliases where needed.</li>
            <li>Connect one provider and test it before enabling automatic generation.</li>
            <li>Use manual links for custom URLs, maps, models, or providers that cannot be scanned automatically.</li>
            <li>PA auto-attaches only one exact safe match. Ambiguous matches require review.</li>
        </ol>
    </div>

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
                <span class="font-600">Enable Link Resolver System<?php echo $helpIcon('Disabled by default. Turn this on only if PA should look for cloud folders automatically.'); ?></span>
            </label>

            <label style="display:flex;align-items:center;gap:10px">
                <input type="checkbox" name="org_level_links_only" value="1" <?php echo !empty($appConfig['org_level_links_only']) ? 'checked' : ''; ?>>
                <span class="font-600">Prefer organization and department links<?php echo $helpIcon('Clients inside an organization should normally use organization or department links instead of direct client-level automatic links.'); ?></span>
                <span style="font-size:13px;color:var(--muted);margin-left:4px">Safer default for grouped clients</span>
            </label>

            <label style="display:flex;align-items:flex-start;gap:10px">
                <input type="checkbox" name="link_resolver_daily_scan_enabled" value="1" <?php echo !empty($appConfig['link_resolver_daily_scan_enabled']) ? 'checked' : ''; ?> style="margin-top:3px">
                <span>
                    <span class="font-600">Daily folder scan<?php echo $helpIcon('Optional background scan. It stays off unless your business wants PA to refresh resolver candidates automatically.'); ?></span>
                    <span class="pa-setting-note">Runs only when the resolver and a provider are enabled.</span>
                </span>
            </label>

            <label style="display:flex;align-items:flex-start;gap:10px">
                <input type="checkbox" name="link_resolver_invoice_auto_attach_enabled" value="1" <?php echo !empty($appConfig['link_resolver_invoice_auto_attach_enabled']) ? 'checked' : ''; ?> style="margin-top:3px">
                <span>
                    <span class="font-600">Just-in-time invoice link scan<?php echo $helpIcon('Before emailing an invoice, PA may check only the relevant org/department if the invoice has no content links.'); ?></span>
                    <span class="pa-setting-note">Ambiguous matches must be reviewed instead of auto-attached.</span>
                </span>
            </label>

            <label style="display:flex;align-items:flex-start;gap:10px">
                <input type="checkbox" name="project_specific_links_enabled" value="1" <?php echo !empty($appConfig['project_specific_links_enabled']) ? 'checked' : ''; ?> style="margin-top:3px">
                <span>
                    <span class="font-600">Allow project-specific invoice links<?php echo $helpIcon('Disabled by default. Most projects should use the higher-level org or department folder link.'); ?></span>
                    <span class="pa-setting-note">Manual project links can override the normal org/department link flow when this is enabled.</span>
                </span>
            </label>

            <label style="display:flex;align-items:flex-start;gap:10px">
                <input type="checkbox" name="invoice_content_links_enabled" value="1" <?php echo !empty($appConfig['invoice_content_links_enabled']) ? 'checked' : ''; ?> style="margin-top:3px">
                <span>
                    <span class="font-600">Show content links on invoices<?php echo $helpIcon('When enabled, invoices can show a “View your content here” section using links marked for invoices.'); ?></span>
                    <span class="pa-setting-note">This is off by default so businesses that do not deliver files are not affected.</span>
                </span>
            </label>

            <label>
                <div style="font-weight:600;margin-bottom:4px">If invoice content links are missing<?php echo $helpIcon('Controls email behavior when a project invoice has no eligible content links.'); ?></div>
                <?php $missingBehavior = (string)($appConfig['invoice_missing_content_links_behavior'] ?? 'warn'); ?>
                <select name="invoice_missing_content_links_behavior" style="max-width:280px;width:100%;padding:8px;border-radius:6px;border:1px solid #ddd">
                    <option value="send" <?php echo $missingBehavior === 'send' ? 'selected' : ''; ?>>Send anyway</option>
                    <option value="warn" <?php echo $missingBehavior === 'warn' ? 'selected' : ''; ?>>Warn before sending</option>
                    <option value="block" <?php echo $missingBehavior === 'block' ? 'selected' : ''; ?>>Block sending</option>
                </select>
            </label>
            
            <div style="padding:12px;background:#fffbeb;border:1px solid #fde68a;border-radius:6px;font-size:13px">
                <strong>ℹ️ Link Expiration:</strong> Set expiration dates per-client or per-organization in their respective storage sections, not globally.
            </div>
            <div class="pa-provider-note">
                Folder matching is intentionally strict: organization folders match by organization name, department folders match by department folder name or alias under the parent organization folder, and project-specific links stay disabled unless explicitly enabled.
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
                <span class="font-600">Enable <?php echo e($providerName); ?> auto-generation</span>
            </label>
            <div class="pa-provider-note">
                Manual links do not require auto-generation. Enable this provider only when PA should scan for exact organization or department folders and create share links automatically.
            </div>

            <div id="fields_<?php echo e($provider); ?>" style="<?php echo !$isEnabled ? 'display:none' : ''; ?>">
                <?php if ($provider === 'dropbox'): ?>
                    <div class="grid">
                        <fieldset style="border:1px solid #dbeafe;background:#eff6ff;border-radius:8px;padding:12px">
                            <legend style="padding:0 6px;font-weight:600;color:#1e3a8a">Dropbox App</legend>
                            <div style="display:grid;gap:12px">
                                <label>
                                    <div style="margin-bottom:4px;font-weight:600">App Key<?php echo $helpIcon('Create or open your Dropbox app in the Dropbox App Console, then paste the App key here.'); ?></div>
                                    <input type="text" name="dropbox_app_key"
                                           value="<?php echo e($dropboxAppKey); ?>"
                                           placeholder="Dropbox app key"
                                           autocomplete="off"
                                           style="width:100%;padding:8px;border-radius:6px;border:1px solid #ddd;font-family:monospace;font-size:13px">
                                </label>
                                <label>
                                    <div style="margin-bottom:4px;font-weight:600">App Secret<?php echo $helpIcon('Paste the Dropbox app secret. For safety, a saved secret is not shown again; leave blank to keep it.'); ?></div>
                                    <input type="password" name="dropbox_app_secret"
                                           value=""
                                           placeholder="<?php echo $dropboxHasAppSecret ? 'Saved - leave blank to keep existing secret' : 'Dropbox app secret'; ?>"
                                           autocomplete="new-password"
                                           style="width:100%;padding:8px;border-radius:6px;border:1px solid #ddd;font-family:monospace;font-size:13px">
                                </label>
                                <div class="pa-setting-note">
                                    Add this exact redirect URI in the Dropbox app console:
                                    <code style="display:block;margin-top:4px;padding:6px;background:#fff;border:1px solid #dbeafe;border-radius:6px;white-space:normal;word-break:break-all"><?php echo e($dropboxCallbackUri); ?></code>
                                </div>
                            </div>
                        </fieldset>

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
                                    <div style="font-size:12px;color:#6b7280"><?php echo $dropboxCanConnect ? 'Connect via OAuth for a secure, permanent connection.' : 'Enter and save the Dropbox app key and secret first.'; ?></div>
                                </div>
                                <?php if ($dropboxCanConnect): ?>
                                    <a href="/?page=settings/dropbox-oauth&action=start"
                                       style="padding:8px 16px;border-radius:6px;border:0;background:#2563eb;color:#fff;font-size:13px;text-decoration:none;font-weight:600">Connect Dropbox</a>
                                <?php else: ?>
                                    <span style="padding:8px 16px;border-radius:6px;border:1px solid #d1d5db;background:#f3f4f6;color:#6b7280;font-size:13px;font-weight:600">Save credentials first</span>
                                <?php endif; ?>
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
	                            Get credentials from <a href="https://console.cloud.google.com/" target="_blank" style="color:var(--nav-accent)">Google Cloud Console</a>. Share the target folders with this service account.
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
	                    <div style="margin-bottom:4px;font-weight:600"><?php echo $provider === 'gdrive' ? 'Root Folder ID' : 'Root Folder Path'; ?></div>
	                    <input type="text" name="<?php echo e($provider); ?>_root_path"
	                           value="<?php echo e($credentials['root_path'] ?? '/'); ?>"
	                           placeholder="<?php echo $provider === 'gdrive' ? 'Optional Google Drive folder ID' : '/'; ?>"
	                           style="width:100%;padding:8px;border-radius:6px;border:1px solid #ddd">
	                    <div style="margin-top:4px;font-size:12px;color:var(--muted)">
	                        <?php if ($provider === 'gdrive'): ?>
	                            Optional parent folder ID used to limit searches. Leave blank to search all folders visible to the service account.
	                        <?php else: ?>
	                            Base path where organization, department, or standalone client folders are located.
	                        <?php endif; ?>
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
        const tokenField = document.querySelector(`input[name="${provider}_access_token"]`);
        formData.append('access_token', tokenField ? tokenField.value : '');
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
