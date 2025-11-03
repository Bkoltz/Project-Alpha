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
      <a href="/?page=settings&tab=quotes" style="display:block;padding:10px 12px;border-bottom:1px solid #eee;<?php echo $tab === 'Quotes' ? 'background:#f8fafc;font-weight:600' : ''; ?>">Quotes</a>
      <a href="/?page=settings&tab=contracts" style="display:block;padding:10px 12px;border-bottom:1px solid #eee;<?php echo $tab === 'Contracts' ? 'background:#f8fafc;font-weight:600' : ''; ?>">Contracts</a>
      <a href="/?page=settings&tab=invoices" style="display:block;padding:10px 12px;border-bottom:1px solid #eee;<?php echo $tab === 'Invoices' ? 'background:#f8fafc;font-weight:600' : ''; ?>">Invoices</a>
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
            (function(){
              var pmList = document.getElementById('pmList');
              var pmAdd = document.getElementById('pmAdd');
              var pmSelect = document.getElementById('pmSelect');
              var pmCustom = document.getElementById('pmCustom');
              var hiddenJson = document.getElementById('paymentMethodsJson');

              function sync() {
                var items = [];
                Array.from(pmList.querySelectorAll('.pm-item')).forEach(function(el){
                  var name = el.querySelector('input[type="hidden"]').value || el.querySelector('span').textContent.trim();
                  items.push({name: name});
                });
                hiddenJson.value = JSON.stringify(items);
                var fallback = document.querySelector('textarea[name="payment_methods"]');
                if (fallback) {
                  fallback.value = items.map(function(i){ return i.name; }).join('\n');
                }
              }

              function removeHandler(e){
                var btn = e.currentTarget;
                var row = btn.closest('.pm-item');
                if (row) { row.remove(); sync(); }
              }

              function addMethod(name){
                if (!name) return;
                // prevent duplicates (case-insensitive)
                var existing = Array.from(pmList.querySelectorAll('input[type="hidden"]')).some(function(h){ return h.value.toLowerCase() === name.toLowerCase(); });
                if (existing) return;

                var div = document.createElement('div');
                div.className = 'pm-item';
                div.style.display = 'flex'; div.style.alignItems = 'center'; div.style.gap = '8px';
                div.innerHTML = '<input type="hidden" name="payment_methods_backup[]" value="'+htmlEscape(name)+'">'
                  + '<span style="padding:8px 10px;border:1px solid #ddd;border-radius:6px;background:#fafafa">'+escapeHtml(name)+'</span>'
                  + '<button type="button" class="pm-remove" style="margin-left:auto;padding:6px 8px;border-radius:6px;border:1px solid #ddd;background:#fff">Remove</button>';

                pmList.appendChild(div);
                var btn = div.querySelector('.pm-remove');
                btn.addEventListener('click', removeHandler);
                sync();
              }

              function escapeHtml(s){ return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
              function htmlEscape(s){ return s.replace(/"/g,'&quot;'); }

              // wire existing remove buttons
              Array.from(document.querySelectorAll('.pm-remove')).forEach(function(b){ b.addEventListener('click', removeHandler); });

              pmSelect.addEventListener('change', function(){
                if (pmSelect.value === 'other') { pmCustom.style.display = ''; pmCustom.focus(); } else { pmCustom.style.display = 'none'; }
              });

              pmAdd.addEventListener('click', function(){
                var name = pmSelect.value === 'other' ? pmCustom.value.trim() : pmSelect.value;
                if (!name) return;
                addMethod(name);
                pmCustom.value = '';
                pmSelect.value = 'card';
              });

              // helper to safely set innerText for display
              function escapeHtmlForDisplay(s){ return s.replace(/&/g,'&amp;').replace(/</g,'&lt;'); }

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
            <legend style="padding:0 6px;color:var(--muted)">Terms & Conditions</legend>
            <label style="margin-bottom:8px">
              <div>Documents Valid for (days)</div>
              <input type="number" min="0" name="documents_valid_days" value="<?php echo htmlspecialchars((string)($appConfig['documents_valid_days'] ?? 14)); ?>" style="width:120px;padding:10px;border-radius:8px;border:1px solid #ddd">
            </label>
            <label style="display:block;margin-bottom:8px"><input type="checkbox" name="quotes_show_terms" value="1" <?php echo (!isset($appConfig['quotes_show_terms']) || (int)($appConfig['quotes_show_terms']) === 1) ? 'checked' : ''; ?>> Show terms on Quotes</label>
            <textarea name="terms" rows="12" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd" placeholder="Enter your terms..."><?php echo htmlspecialchars($appConfig['terms'] ?? ''); ?></textarea>
          </fieldset>
        <?php endif; ?>

        <!--TODO: change the payment methods so that it isn't a text input, maybe a dropdown of available payment methods. We need to have credit, bank transfer, cash, check (we need to record the check number), etc. -->
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


        <div>
          <button type="submit" style="padding:10px 14px;border-radius:8px;border:0;background:var(--nav-accent);color:#fff;font-weight:600">Save</button>
        </div>
      </form>
    </div>
  </div>
  <?php if ($tab === 'system'): ?>
    <script>
      (function() {
        var btn = document.getElementById('btnEmailTest');
        if (!btn) return;
        btn.addEventListener('click', function() {
          var form = btn.closest('form');
          var out = document.getElementById('emailTestResult');
          if (out) {
            out.textContent = 'Sending test email...';
            out.style.color = '#334155';
          }
          var fd = new FormData();

          function val(name) {
            var el = form.querySelector('[name="' + name + '"]');
            return el ? el.value : '';
          }
          fd.append('ajax', '1');
          fd.append('csrf', (document.querySelector('meta[name="csrf-token"]') || {}).content || '');
          ['smtp_host', 'smtp_port', 'smtp_secure', 'smtp_username', 'smtp_password', 'from_email'].forEach(function(k) {
            fd.append(k, val(k));
          });
          fetch('/?page=email-test', {
              method: 'POST',
              body: fd,
              headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
              }
            })
            .then(function(r) {
              return r.json();
            })
            .then(function(j) {
              if (out) {
                if (j && j.ok) {
                  out.textContent = 'Test email sent.';
                  out.style.color = '#065f46';
                } else {
                  out.textContent = 'Test email failed' + (j && j.error ? (': ' + j.error) : '');
                  out.style.color = '#b91c1c';
                }
              }
            })
            .catch(function(err) {
              if (out) {
                out.textContent = 'Test email failed';
                out.style.color = '#b91c1c';
              }
            });
        });
      })();
    </script>
  <?php endif; ?>
  <!-- TODO: add settings for quotes so we can -->
  <?php if ($tab === 'quotes'): ?>
  <?php endif; ?>
  <!-- TODO: add settings for contracts so we can -->
  <?php if ($tab === 'contracts'): ?>
  <?php endif; ?>
  <!-- TODO: add settings for invoices so we can -->
  <?php if ($tab === 'invoices'): ?>
  <?php endif; ?>
</section>