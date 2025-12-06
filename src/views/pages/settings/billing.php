<?php
// src/views/pages/settings/billing.php
?>
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

    // ensure initial sync
    sync();
  })();
</script>

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
</fieldset>
