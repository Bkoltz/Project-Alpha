<?php
// src/views/pages/settings.php
require_once __DIR__ . '/../../config/app.php';
$tab = isset($_GET['tab']) ? preg_replace('/[^a-z0-9\-]/i', '', $_GET['tab']) : 'system';
?>
<section>
  <h2>Settings</h2>
  <?php if (isset($_GET['saved']) && $_GET['saved'] === '1'): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#e6fffa;color:#065f46;border:1px solid #99f6e4">Saved.</div>
  <?php elseif (isset($_GET['saved']) && $_GET['saved'] === '0'): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#fff1f2;color:#881337;border:1px solid #fca5a5">Failed to save settings. <?php if (!empty($_GET['error'])) {
                                                                                                                                                        echo htmlspecialchars($_GET['error']);
                                                                                                                                                      } ?></div>
  <?php elseif (!empty($_GET['error'])): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#fff1f2;color:#881337;border:1px solid #fca5a5"><?php echo htmlspecialchars($_GET['error']); ?></div>
  <?php endif; ?>
  <?php if (!empty($_GET['fallback']) && $_GET['fallback'] === '1' && empty($appConfig['suppress_assets_warning'])): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#fff7ed;color:#78350f;border:1px solid #ffd8a8">Settings saved to internal config (fallback) because public/assets wasn't writable.</div>
  <?php endif; ?>

  <div style="display:grid;grid-template-columns:220px 1fr;gap:16px;margin-top:12px">
    <aside style="border:1px solid #eee;border-radius:8px;overflow:hidden;background:#fff">
      <a href="/?page=settings&tab=system" style="display:block;padding:10px 12px;border-bottom:1px solid #eee;<?php echo $tab === 'system' ? 'background:#f8fafc;font-weight:600' : ''; ?>">System</a>
      <a href="/?page=settings&tab=terms" style="display:block;padding:10px 12px;border-bottom:1px solid #eee;<?php echo $tab === 'terms' ? 'background:#f8fafc;font-weight:600' : ''; ?>">Terms & Conditions</a>
      <a href="/?page=settings&tab=billing" style="display:block;padding:10px 12px;border-bottom:1px solid #eee;<?php echo $tab === 'billing' ? 'background:#f8fafc;font-weight:600' : ''; ?>">Billing</a>
      <a href="/?page=settings&tab=account" style="display:block;padding:10px 12px;border-bottom:1px solid #eee;<?php echo $tab === 'account' ? 'background:#f8fafc;font-weight:600' : ''; ?>">Account</a>
      <a href="/?page=settings&tab=quotes" style="display:block;padding:10px 12px;border-bottom:1px solid #eee;<?php echo $tab === 'quotes' ? 'background:#f8fafc;font-weight:600' : ''; ?>">Quotes</a>
      <a href="/?page=settings&tab=contracts" style="display:block;padding:10px 12px;border-bottom:1px solid #eee;<?php echo $tab === 'contracts' ? 'background:#f8fafc;font-weight:600' : ''; ?>">Contracts</a>
      <a href="/?page=settings&tab=invoices" style="display:block;padding:10px 12px;border-bottom:1px solid #eee;<?php echo $tab === 'invoices' ? 'background:#f8fafc;font-weight:600' : ''; ?>">Invoices</a>
      <a href="/?page=api-keys" style="display:block;padding:10px 12px;<?php echo $tab === 'API Keys' ? 'background:#f8fafc;font-weight:600' : ''; ?>">API Keys</a>

    </aside>

    <div>
      <form method="post" action="/?page=settings&tab=<?php echo $tab; ?>" enctype="multipart/form-data" style="display:grid;gap:16px;max-width:800px">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
        <input type="hidden" name="tab" value="<?php echo $tab; ?>">

        <?php if ($tab === 'system'): ?>
          <fieldset style="border:1px solid #eee;border-radius:8px;padding:12px">
            <legend style="padding:0 6px;color:var(--muted)">Brand</legend>
            <label>
              <div>Brand Name</div>
              <input type="text" name="brand_name" value="<?php echo htmlspecialchars(($appConfig['brand_name'] ?? 'Project Alpha')); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
            </label>

            <div>
              <div>Logo (PNG, JPG, WEBP)</div>
              <?php if (!empty($appConfig['logo_path'])): ?>
                <div style="margin:8px 0"><img alt="Current logo" src="<?php echo htmlspecialchars($appConfig['logo_path']); ?>" style="max-width:240px;max-height:120px;object-fit:contain;border-radius:6px;background:#fff;padding:8px"></div>
              <?php endif; ?>
              <input type="file" name="logo" accept="image/png,image/jpeg,image/svg+xml,image/webp">
            </div>
          </fieldset>
        <?php endif; ?>
        <?php if ($tab === 'billing'): ?>
          <script>
            (function() {
              var pmList = document.getElementById('pmList');
              var pmAdd = document.getElementById('pmAdd');
              var pmSelect = document.getElementById('pmSelect');
              var pmCustom = document.getElementById('pmCustom');
              var hiddenJson = document.getElementById('paymentMethodsJson');

              function sync() {
                var items = [];
                Array.from(pmList.querySelectorAll('.pm-item')).forEach(function(el) {
                  var name = el.querySelector('input[type="hidden"]').value || el.querySelector('span').textContent.trim();
                  items.push({
                    name: name
                  });
                });
                hiddenJson.value = JSON.stringify(items);
                var fallback = document.querySelector('textarea[name="payment_methods"]');
                if (fallback) {
                  fallback.value = items.map(function(i) {
                    return i.name;
                  }).join('\n');
                }
              }

              function removeHandler(e) {
                var btn = e.currentTarget;
                var row = btn.closest('.pm-item');
                if (row) {
                  row.remove();
                  sync();
                }
              }

              function addMethod(name) {
                if (!name) return;
                // prevent duplicates (case-insensitive)
                var existing = Array.from(pmList.querySelectorAll('input[type="hidden"]')).some(function(h) {
                  return h.value.toLowerCase() === name.toLowerCase();
                });
                if (existing) return;

                var div = document.createElement('div');
                div.className = 'pm-item';
                div.style.display = 'flex';
                div.style.alignItems = 'center';
                div.style.gap = '8px';
                div.innerHTML = '<input type="hidden" name="payment_methods_backup[]" value="' + htmlEscape(name) + '">' +
                  '<span style="padding:8px 10px;border:1px solid #ddd;border-radius:6px;background:#fafafa">' + escapeHtml(name) + '</span>' +
                  '<button type="button" class="pm-remove" style="margin-left:auto;padding:6px 8px;border-radius:6px;border:1px solid #ddd;background:#fff">Remove</button>';

                pmList.appendChild(div);
                var btn = div.querySelector('.pm-remove');
                btn.addEventListener('click', removeHandler);
                sync();
              }

              function escapeHtml(s) {
                return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
              }

              function htmlEscape(s) {
                return s.replace(/"/g, '&quot;');
              }

              // wire existing remove buttons
              Array.from(document.querySelectorAll('.pm-remove')).forEach(function(b) {
                b.addEventListener('click', removeHandler);
              });

              pmSelect.addEventListener('change', function() {
                if (pmSelect.value === 'other') {
                  pmCustom.style.display = '';
                  pmCustom.focus();
                } else {
                  pmCustom.style.display = 'none';
                }
              });

              pmAdd.addEventListener('click', function() {
                var name = pmSelect.value === 'other' ? pmCustom.value.trim() : pmSelect.value;
                if (!name) return;
                addMethod(name);
                pmCustom.value = '';
                pmSelect.value = 'card';
              });

              // helper to safely set innerText for display
              function escapeHtmlForDisplay(s) {
                return s.replace(/&/g, '&amp;').replace(/</g, '&lt;');
              }

              // ensure initial sync
              sync();
            })();
          </script>
        <?php endif; ?>

        <?php if ($tab === 'system'): ?>
          <fieldset style="border:1px solid #eee;border-radius:8px;padding:12px">
            <legend style="padding:0 6px;color:var(--muted)">User Info (From)</legend>
            <label>
              <div>Name</div><input name="from_name" value="<?php echo htmlspecialchars($appConfig['from_name'] ?? ''); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
            </label>
            <label>
              <div>Address line 1</div><input name="from_address_line1" value="<?php echo htmlspecialchars($appConfig['from_address_line1'] ?? ''); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
            </label>
            <label>
              <div>Address line 2</div><input name="from_address_line2" value="<?php echo htmlspecialchars($appConfig['from_address_line2'] ?? ''); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
            </label>
            <div style="display:grid;gap:8px;grid-template-columns:1fr 1fr 1fr">
              <label>
                <div>City</div><input name="from_city" value="<?php echo htmlspecialchars($appConfig['from_city'] ?? ''); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
              </label>
              <label>
                <div>State</div><input name="from_state" value="<?php echo htmlspecialchars($appConfig['from_state'] ?? ''); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
              </label>
              <label>
                <div>Postal</div><input name="from_postal" value="<?php echo htmlspecialchars($appConfig['from_postal'] ?? ''); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
              </label>
              <!-- <label><div>Country</div><input name="from_country" value="<?php echo htmlspecialchars($appConfig['from_country'] ?? ''); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd"></label> -->
            </div>
            <div style="margin-top:12px">
              <label>
                <div>Primary State (default for new clients)</div>
                <input name="primary_state" value="<?php echo htmlspecialchars($appConfig['primary_state'] ?? ''); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd" placeholder="WI">
              </label>
            </div>
            <div style="display:grid;gap:8px;grid-template-columns:1fr 1fr">
              <label>
                <div>Email</div><input name="from_email" value="<?php echo htmlspecialchars($appConfig['from_email'] ?? ''); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
              </label>
              <label>
                <div>Phone</div><input name="from_phone" value="<?php echo htmlspecialchars($appConfig['from_phone'] ?? ''); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd" placeholder="(123) 456-7890">
              </label>
            </div>
          </fieldset>
        <?php endif; ?>

        <?php if ($tab === 'system'): ?>
          <fieldset style="border:1px solid #eee;border-radius:8px;padding:12px">
            <legend style="padding:0 6px;color:var(--muted)">Timezone</legend>
            <?php $tzCurrent = $appConfig['timezone'] ?? date_default_timezone_get();
            $zones = function_exists('timezone_identifiers_list') ? timezone_identifiers_list() : []; ?>
            <label>
              <div>Select Timezone</div>
              <select name="timezone" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
                <?php foreach ($zones as $z): ?>
                  <option value="<?php echo htmlspecialchars($z); ?>" <?php echo ($tzCurrent === $z) ? 'selected' : ''; ?>><?php echo htmlspecialchars($z); ?></option>
                <?php endforeach; ?>
              </select>
            </label>
          </fieldset>
        <?php endif; ?>

        <?php if ($tab === 'account'): ?>
          <fieldset style="border:1px solid #eee;border-radius:8px;padding:12px;max-width:600px">
            <legend style="padding:0 6px;color:var(--muted)">Account</legend>
            <?php if (!empty($_GET['pwd']) && $_GET['pwd'] === '1'): ?>
              <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0">Password updated.</div>
            <?php elseif (!empty($_GET['pwd_error'])): ?>
              <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#fff1f2;color:#881337;border:1px solid #fca5a5"><?php echo htmlspecialchars($_GET['pwd_error']); ?></div>
            <?php endif; ?>
            <input type="hidden" name="change_password" value="1">
            <div style="display:grid;gap:12px">
              <label>
                <div>Current Password</div>
                <input required type="password" name="current_password" autocomplete="current-password" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
              </label>
              <div style="display:grid;gap:8px;grid-template-columns:1fr 1fr">
                <label>
                  <div>New Password</div>
                  <input required minlength="8" type="password" name="new_password" autocomplete="new-password" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
                </label>
                <label>
                  <div>Confirm New Password</div>
                  <input required minlength="8" type="password" name="confirm_password" autocomplete="new-password" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
                </label>
              </div>
              <div style="color:var(--muted);font-size:12px">Click Save below to update your password.</div>
            </div>
          </fieldset>
        <?php endif; ?>

        <?php if ($tab === 'terms'): ?>
          <fieldset style="border:1px solid #eee;border-radius:8px;padding:12px">
            <legend style="padding:0 6px;color:var(--muted)">General Settings</legend>
            <label style="margin-bottom:8px">
              <div>Documents Valid for (days)</div>
              <input type="number" min="0" name="documents_valid_days" value="<?php echo htmlspecialchars((string)($appConfig['documents_valid_days'] ?? 14)); ?>" style="width:120px;padding:10px;border-radius:8px;border:1px solid #ddd">
            </label>
            <label style="display:block;margin-bottom:8px"><input type="checkbox" name="quotes_show_terms" value="1" <?php echo (!isset($appConfig['quotes_show_terms']) || (int)($appConfig['quotes_show_terms']) === 1) ? 'checked' : ''; ?>> Show terms on Quotes</label>
          </fieldset>
          
          <fieldset style="border:1px solid #eee;border-radius:8px;padding:12px;margin-top:16px">
            <legend style="padding:0 6px;color:var(--muted)">Standard Terms & Conditions</legend>
            <p style="margin:0 0 8px;color:var(--muted);font-size:13px">Default terms for quotes and regular contracts</p>
            <textarea name="terms" rows="12" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd" placeholder="Enter your standard terms..."><?php echo htmlspecialchars($appConfig['terms'] ?? ''); ?></textarea>
          </fieldset>
          
          <fieldset style="border:1px solid #eee;border-radius:8px;padding:12px;margin-top:16px">
            <legend style="padding:0 6px;color:var(--muted)">Long-term Contract Terms & Conditions</legend>
            <p style="margin:0 0 8px;color:var(--muted);font-size:13px">Specific terms for recurring/long-term service contracts (leave blank to use standard terms)</p>
            <textarea name="long_term_terms" rows="12" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd" placeholder="Enter your long-term contract terms..."><?php echo htmlspecialchars($appConfig['long_term_terms'] ?? ''); ?></textarea>
          </fieldset>
        <?php endif; ?>

        <?php if ($tab === 'billing'): ?>
          <fieldset style="border:1px solid #eee;border-radius:8px;padding:12px">
            <legend style="padding:0 6px;color:var(--muted)">Billing Defaults</legend>
            <label>
              <div>Net Terms (days)</div>
              <input type="number" min="0" name="net_terms" value="<?php echo htmlspecialchars((string)($appConfig['net_terms'] ?? 30)); ?>" style="width:120px;padding:10px;border-radius:8px;border:1px solid #ddd">
            </label>
            <div style="margin-top:12px"></div>
            <?php $paymentMethods = (array)($appConfig['payment_methods'] ?? ['card', 'cash', 'bank_transfer']); ?>
            <div style="margin-bottom:8px">
              <div style="margin-bottom:6px">Payment Methods</div>
              <div id="pmList" style="display:flex;flex-direction:column;gap:6px">
                <?php foreach ($paymentMethods as $pm): ?>
                  <div class="pm-item" style="display:flex;align-items:center;gap:8px">
                    <input type="hidden" name="payment_methods_backup[]" value="<?php echo htmlspecialchars($pm); ?>">
                    <span style="padding:8px 10px;border:1px solid #ddd;border-radius:6px;background:#fafafa"><?php echo htmlspecialchars($pm); ?></span>
                    <button type="button" class="pm-remove" style="margin-left:auto;padding:6px 8px;border-radius:6px;border:1px solid #ddd;background:#fff">Remove</button>
                  </div>
                <?php endforeach; ?>
              </div>

              <div style="display:flex;gap:8px;margin-top:8px">
                <select id="pmSelect" style="padding:8px;border-radius:8px;border:1px solid #ddd">
                  <option value="card">Card</option>
                  <option value="bank_transfer">Bank Transfer</option>
                  <option value="cash">Cash</option>
                  <option value="Check">Check</option>
                  <option value="other">Other...</option>
                </select>
                <input id="pmCustom" placeholder="Custom method" style="padding:8px;border-radius:8px;border:1px solid #ddd;flex:1;display:none">
                <button type="button" id="pmAdd" style="padding:8px 10px;border-radius:8px;border:0;background:var(--nav-accent);color:#fff">Add</button>
              </div>

              <!-- Hidden JSON payload for server processing; fallback backup textarea kept for backward compatibility -->
              <input type="hidden" name="payment_methods_json" id="paymentMethodsJson" value="<?php echo htmlspecialchars(json_encode($paymentMethods)); ?>">
              <textarea name="payment_methods" rows="3" style="width:100%;padding:8px;border-radius:6px;border:1px solid #eee;margin-top:8px;display:none"><?php echo htmlspecialchars(implode("\n", $paymentMethods)); ?></textarea>
            </div>
            <!-- <div style="margin-top:10px">
              <label><input type="checkbox" name="suppress_assets_warning" value="1" <?php echo !empty($appConfig['suppress_assets_warning']) ? 'checked' : ''; ?>> Don't show warning about public/assets not being writable</label>
            </div> -->
          </fieldset>
        <?php endif; ?>

        <?php if ($tab === 'system'): ?>
          <?php if (!empty($_GET['email_test']) && $_GET['email_test'] === '1'): ?>
            <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0">Test email sent.</div>
          <?php elseif (!empty($_GET['email_err'])): ?>
            <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#fff1f2;color:#881337;border:1px solid #fca5a5">Test email failed: <?php echo htmlspecialchars($_GET['email_err']); ?></div>
          <?php endif; ?>
          <fieldset style="border:1px solid #eee;border-radius:8px;padding:12px">
            <legend style="padding:0 6px;color:var(--muted)">Outgoing Email (SMTP)</legend>
            <p style="margin:0 0 8px;color:var(--muted)">Configure SMTP to send emails from your own account. For Gmail, enable 2-Step Verification and create an App Password.</p>
            <div style="display:grid;gap:8px;grid-template-columns:1fr 1fr 1fr">
              <label>
                <div>SMTP Host</div><input name="smtp_host" value="<?php echo htmlspecialchars($appConfig['smtp_host'] ?? ''); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd" placeholder="smtp.gmail.com">
              </label>
              <label>
                <div>Port</div><input type="number" name="smtp_port" value="<?php echo htmlspecialchars((string)($appConfig['smtp_port'] ?? 587)); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
              </label>
              <label>
                <div>Security</div>
                <select name="smtp_secure" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
                  <?php $sec = strtolower((string)($appConfig['smtp_secure'] ?? 'tls')); ?>
                  <option value="tls" <?php echo $sec === 'tls' ? 'selected' : ''; ?>>TLS</option>
                  <option value="ssl" <?php echo $sec === 'ssl' ? 'selected' : ''; ?>>SSL</option>
                  <option value="none" <?php echo $sec === 'none' ? 'selected' : ''; ?>>None</option>
                </select>
              </label>
            </div>
            <div style="display:grid;gap:8px;grid-template-columns:1fr 1fr">
              <label>
                <div>Username (email)</div><input name="smtp_username" value="<?php echo htmlspecialchars($appConfig['smtp_username'] ?? ''); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd" placeholder="you@gmail.com">
              </label>
              <label>
                <div>App Password</div><input type="password" name="smtp_password" value="" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd" placeholder="Enter to update (leave blank to keep)">
              </label>
            </div>
            <p style="margin:6px 0 0;color:var(--muted);font-size:12px">For Gmail: host smtp.gmail.com, port 587 (TLS) or 465 (SSL); use an App Password (not your normal password).</p>
            <div style="margin-top:12px">
              <button type="button" id="btnEmailTest" style="padding:8px 12px;border-radius:8px;border:1px solid #ddd;background:#fff">Send Test Email</button>
              <div id="emailTestResult" style="margin-top:8px;font-size:13px"></div>
            </div>
          </fieldset>
        <?php endif; ?>
        <?php if ($tab === 'quotes'): ?>
          <fieldset style="border:1px solid #eee;border-radius:8px;padding:12px">
            <legend style="padding:0 6px;color:var(--muted)">Quote Options</legend>
            <div style="display:grid;gap:12px">
              <label>
                <input type="checkbox" name="quote_scope_enabled" value="1" <?php echo !empty($appConfig['quote_scope_enabled']) ? 'checked' : ''; ?>>
                <span style="font-weight:600">Enable "Scope of Project" field on quotes</span>
                <div style="margin-top:4px;color:var(--muted);font-size:12px">If enabled, quotes will have a scope field. If left blank, it will be excluded from PDF.</div>
              </label>
            </div>
          </fieldset>
          
          <fieldset style="border:1px solid #eee;border-radius:8px;padding:12px;margin-top:16px">
            <legend style="padding:0 6px;color:var(--muted)">Auto-generation on Quote Approval</legend>
            <p style="margin:0 0 12px;color:var(--muted);font-size:13px">Configure what gets automatically created when a quote is approved</p>
            <div style="display:grid;gap:12px">
              <label>
                <input type="checkbox" name="quote_auto_create_contract" value="1" <?php echo !empty($appConfig['quote_auto_create_contract']) || !isset($appConfig['quote_auto_create_contract']) ? 'checked' : ''; ?>>
                <span style="font-weight:600">Auto-create Contract on approval</span>
              </label>
              <label>
                <input type="checkbox" name="quote_auto_create_invoice" value="1" <?php echo !empty($appConfig['quote_auto_create_invoice']) || !isset($appConfig['quote_auto_create_invoice']) ? 'checked' : ''; ?>>
                <span style="font-weight:600">Auto-create Invoice on approval</span>
              </label>
            </div>
          </fieldset>
        <?php endif; ?>

        <?php if ($tab === 'contracts'): ?>
          <fieldset style="border:1px solid #eee;border-radius:8px;padding:12px">
            <legend style="padding:0 6px;color:var(--muted)">Contract Options</legend>
            <div style="display:grid;gap:12px">
              <label>
                <input type="checkbox" name="contract_scope_enabled" value="1" <?php echo !empty($appConfig['contract_scope_enabled']) || !isset($appConfig['contract_scope_enabled']) ? 'checked' : ''; ?>>
                <span style="font-weight:600">Enable "Scope of Contract" field</span>
                <div style="margin-top:4px;color:var(--muted);font-size:12px">Available for both regular and long-term contracts. If left blank, excluded from PDF.</div>
              </label>
              <label>
                <input type="checkbox" name="contract_memo_enabled" value="1" <?php echo !empty($appConfig['contract_memo_enabled']) ? 'checked' : ''; ?>>
                <span style="font-weight:600">Enable "Memo" field</span>
                <div style="margin-top:4px;color:var(--muted);font-size:12px">Add a memo/notes section to contracts for additional context.</div>
              </label>
            </div>
          </fieldset>
          
          <fieldset style="border:1px solid #eee;border-radius:8px;padding:12px;margin-top:16px">
            <legend style="padding:0 6px;color:var(--muted)">Signature Agreement Text</legend>
            <p style="margin:0 0 8px;color:var(--muted);font-size:13px">This text appears above the signature line on all contracts</p>
            <textarea name="signature_agreement" rows="3" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd" placeholder="Enter signature agreement text..."><?php echo htmlspecialchars($appConfig['signature_agreement'] ?? 'By signing below, I acknowledge that this is a multi-page contract and that I have read and agree to the terms and conditions.'); ?></textarea>
          </fieldset>
          
          <fieldset style="border:1px solid #eee;border-radius:8px;padding:12px;margin-top:16px">
            <legend style="padding:0 6px;color:var(--muted)">Advanced: Custom Contract Sections</legend>
            <p style="margin:0 0 12px;color:var(--muted);font-size:13px">Define custom sections that appear on all contracts. Drag to reorder. Leave blank to exclude from PDF.</p>
            <div style="padding:12px;background:#fffbeb;border:1px solid #fde68a;border-radius:6px;font-size:13px;margin-bottom:12px">
              ⚠️ <strong>Coming Soon:</strong> Custom section builder with drag-and-drop ordering will be available in a future update.
            </div>
            <div style="opacity:0.5;pointer-events:none">
              <div style="padding:10px;border:1px solid #e5e7eb;border-radius:6px;background:#f9fafb;margin-bottom:8px">
                <div style="display:flex;align-items:center;gap:8px">
                  <span style="cursor:move">⋮⋮</span>
                  <strong>Scope of Work</strong>
                  <span style="margin-left:auto;font-size:12px;color:var(--muted)">Built-in</span>
                </div>
              </div>
              <div style="padding:10px;border:1px solid #e5e7eb;border-radius:6px;background:#f9fafb;margin-bottom:8px">
                <div style="display:flex;align-items:center;gap:8px">
                  <span style="cursor:move">⋮⋮</span>
                  <strong>Terms & Conditions</strong>
                  <span style="margin-left:auto;font-size:12px;color:var(--muted)">Built-in</span>
                </div>
              </div>
            </div>
          </fieldset>
        <?php endif; ?>

        <?php if ($tab === 'invoices'): ?>
          <fieldset style="border:1px solid #eee;border-radius:8px;padding:12px">
            <legend style="padding:0 6px;color:var(--muted)">Recurring Invoice Generation</legend>
            <p style="margin:0 0 12px;color:var(--muted);font-size:13px">Configure when the system automatically generates recurring invoices for active long-term contracts.</p>
            
            <div style="display:grid;gap:12px;max-width:600px">
              <label>
                <input type="checkbox" name="cron_enabled" value="1" <?php echo !empty($appConfig['cron_enabled']) ? 'checked' : ''; ?>>
                <span style="font-weight:600">Enable Automatic Invoice Generation</span>
              </label>
        
        <div id="cronScheduleSection" style="<?php echo empty($appConfig['cron_enabled']) ? 'display:none' : ''; ?>">
          <label>
            <div style="margin-bottom:4px">Schedule</div>
            <select name="cron_schedule" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
              <?php $cronSched = $appConfig['cron_schedule'] ?? 'daily_2am'; ?>
              <option value="hourly" <?php echo $cronSched === 'hourly' ? 'selected' : ''; ?>>Every Hour</option>
              <option value="every_6hours" <?php echo $cronSched === 'every_6hours' ? 'selected' : ''; ?>>Every 6 Hours</option>
              <option value="daily_midnight" <?php echo $cronSched === 'daily_midnight' ? 'selected' : ''; ?>>Daily at Midnight</option>
              <option value="daily_2am" <?php echo $cronSched === 'daily_2am' ? 'selected' : ''; ?>>Daily at 2:00 AM (Recommended)</option>
              <option value="daily_6am" <?php echo $cronSched === 'daily_6am' ? 'selected' : ''; ?>>Daily at 6:00 AM</option>
              <option value="daily_noon" <?php echo $cronSched === 'daily_noon' ? 'selected' : ''; ?>>Daily at Noon</option>
              <option value="custom" <?php echo $cronSched === 'custom' ? 'selected' : ''; ?>>Custom Cron Expression</option>
            </select>
          </label>
          
          <div id="customCronSection" style="<?php echo $cronSched !== 'custom' ? 'display:none' : ''; ?>">
            <label>
              <div style="margin-bottom:4px">Custom Cron Expression</div>
              <input type="text" name="cron_custom" value="<?php echo htmlspecialchars($appConfig['cron_custom'] ?? '0 2 * * *'); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd;font-family:monospace" placeholder="0 2 * * *">
              <div style="margin-top:4px;color:var(--muted);font-size:12px">Format: minute hour day month weekday (e.g., "0 2 * * *" = 2:00 AM daily)</div>
            </label>
          </div>
          
          <div style="padding:10px;background:#f0f9ff;border:1px solid #bae6fd;border-radius:6px;font-size:13px">
            <strong>Current Status:</strong>
            <div style="margin-top:4px" id="cronStatusDisplay">
              <?php 
                $lastRun = $appConfig['cron_last_run'] ?? null;
                if ($lastRun) {
                  echo 'Last run: ' . date('M j, Y g:i A', strtotime($lastRun));
                } else {
                  echo 'Never run';
                }
              ?>
            </div>
          </div>
          
          <div style="padding:10px;background:#ecfdf5;border:1px solid #a7f3d0;border-radius:6px;font-size:12px;line-height:1.5">
            <strong>✅ Automatically Configured:</strong> The cron job runs automatically in the Docker container using the schedule above. No manual setup needed!
          </div>
          
          <details style="margin-top:8px">
            <summary style="cursor:pointer;color:var(--muted);font-size:12px">Advanced: Manual Cron Setup</summary>
            <div style="padding:10px;margin-top:8px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;font-size:12px;line-height:1.5">
              If running outside Docker, add this to your crontab:<br>
              <code style="display:block;margin-top:6px;padding:6px;background:#fff;border-radius:4px;font-family:monospace">
                <?php 
                  $cronExpr = '0 2 * * *'; // default
                  switch($cronSched) {
                    case 'hourly': $cronExpr = '0 * * * *'; break;
                    case 'every_6hours': $cronExpr = '0 */6 * * *'; break;
                    case 'daily_midnight': $cronExpr = '0 0 * * *'; break;
                    case 'daily_2am': $cronExpr = '0 2 * * *'; break;
                    case 'daily_6am': $cronExpr = '0 6 * * *'; break;
                    case 'daily_noon': $cronExpr = '0 12 * * *'; break;
                    case 'custom': $cronExpr = $appConfig['cron_custom'] ?? '0 2 * * *'; break;
                  }
                  echo htmlspecialchars($cronExpr);
                ?> php /var/www/src/cron/generate_recurring_invoices.php &gt;&gt; /var/log/recurring-invoices.log 2&gt;&amp;1
              </code>
            </div>
          </details>
            </div>
          </div>
          </fieldset>
          
          <script>
      (function() {
        var cronEnabled = document.querySelector('input[name="cron_enabled"]');
        var schedSection = document.getElementById('cronScheduleSection');
        var schedSelect = document.querySelector('select[name="cron_schedule"]');
        var customSection = document.getElementById('customCronSection');
        
        if (cronEnabled && schedSection) {
          cronEnabled.addEventListener('change', function() {
            schedSection.style.display = cronEnabled.checked ? '' : 'none';
          });
        }
        
        if (schedSelect && customSection) {
          schedSelect.addEventListener('change', function() {
            customSection.style.display = schedSelect.value === 'custom' ? '' : 'none';
          });
        }
      })();
          </script>
          <fieldset style="margin-top:18px;padding:12px;border-radius:8px;border:1px solid #eee">
          <legend style="padding:0 6px;color:var(--muted)">Automatic Invoice Emails</legend>
          <div style="display:flex;flex-direction:column;gap:8px">
            <label style="display:flex;align-items:center;gap:10px">
              <input type="checkbox" name="invoice_auto_send_due_7days" value="1" <?php echo !empty($appConfig['invoice_auto_send_due_7days']) ? 'checked' : ''; ?>>
              <div>
                <div style="font-weight:600">Send reminder 7 days before due</div>
                <div style="font-size:13px;color:var(--muted)">When enabled, the system will email clients one week before an invoice is due.</div>
              </div>
            </label>

            <label style="display:flex;align-items:center;gap:10px">
              <input type="checkbox" name="invoice_auto_send_overdue_weekly" value="1" <?php echo !empty($appConfig['invoice_auto_send_overdue_weekly']) ? 'checked' : ''; ?>>
              <div>
                <div style="font-weight:600">Send weekly reminders for overdue invoices</div>
                <div style="font-size:13px;color:var(--muted)">When enabled, the system will email clients for overdue invoices at most once every 7 days.</div>
              </div>
            </label>
          </div>
        </fieldset>
        <?php endif; ?>

        <div>
          <button type="submit" style="padding:10px 14px;border-radius:8px;border:0;background:var(--nav-accent);color:#fff;font-weight:600">Save</button>
        </div>
      </form>
    </div>
  </div>