// Invoice edit page - client-side functionality
// This file is loaded once in the main layout and persists across AJAX page loads

// Extra charges management
function addExtraCharge() {
  console.log('addExtraCharge() called');
  try {
    var container = document.getElementById('extraChargesContainer');
    console.log('Container found:', container !== null);
    
    var anchorBtn = document.getElementById('addExtraChargeBtn');
    console.log('Button found:', anchorBtn !== null);
    
    // If container doesn't exist, create it and insert it right before the button
    if (!container) {
      console.log('Creating new container...');
      container = document.createElement('div');
      container.id = 'extraChargesContainer';
      container.style.display = 'grid';
      container.style.gap = '8px';
      // Insert before the button in the DOM
      if (anchorBtn && anchorBtn.parentNode) {
        anchorBtn.parentNode.insertBefore(container, anchorBtn);
        console.log('Container inserted before button');
      } else {
        console.error('Could not find button or its parent');
        return;
      }
    }
    
    // Create a new row for the extra charge
    console.log('Creating new row...');
    var wrap = document.createElement('div');
    wrap.style.display = 'grid';
    wrap.style.gridTemplateColumns = '2fr 1fr 1fr auto';
    wrap.style.gap = '8px';
    wrap.style.padding = '8px';
    wrap.style.background = '#fff';
    wrap.style.borderRadius = '4px';
    wrap.style.border = '1px solid #fcd34d';
    wrap.innerHTML = `
    <input type="text" name="extra_desc[]" placeholder="Description" style="padding:8px;border-radius:4px;border:1px solid #ddd" oninput="recalcInv()">
    <input type="number" step="0.01" min="0" name="extra_qty[]" value="1" style="padding:8px;border-radius:4px;border:1px solid #ddd" oninput="recalcInv()">
    <input type="number" step="0.01" min="0" name="extra_price[]" value="0" style="padding:8px;border-radius:4px;border:1px solid #ddd" oninput="recalcInv()">
    <input type="hidden" name="extra_id[]" value="">
    <button type="button" onclick="this.parentElement.remove();recalcInv()" style="border:0;background:#fee2e2;color:#991b1b;border-radius:4px;padding:8px 10px;cursor:pointer">Remove</button>
  `;
    container.appendChild(wrap);
    console.log('Row added to container');
    recalcInv();
  } catch (e) {
    console.error('Error in addExtraCharge():', e.message, e.stack);
  }
}

function recalcInv() {
  // Recalculate totals from extra charges if visible
  var extraQtys = Array.from(document.querySelectorAll('[name="extra_qty[]"]')).map(e => parseFloat(e.value) || 0);
  var extraPrices = Array.from(document.querySelectorAll('[name="extra_price[]"]')).map(e => parseFloat(e.value) || 0);
  var extraSubtotal = 0;
  for (var i = 0; i < extraQtys.length; i++) {
    extraSubtotal += extraQtys[i] * extraPrices[i];
  }
  // Note: Contract items are read-only and calculated on server. Add extra charges to visible form values.
  // Display update not needed here since it's recalc for extra charges only.
}

// Initialize invoice edit page event listeners
function initInvoiceEdit() {
  // Client typeahead for invoice edit
  var ciI = document.getElementById('clientInputInv');
  if (!ciI) return; // Not on invoice edit page
  
  var cidI = document.getElementById('clientIdInv');
  var sugI = document.getElementById('clientSuggestInv');
  
  ciI.addEventListener('input', function(){
    cidI.value='';
    var t = this.value.trim();
    if(!t){sugI.style.display='none';sugI.innerHTML='';return;}
    fetch('/?page=clients-search&term='+encodeURIComponent(t))
      .then(r=>r.json())
      .then(list=>{
        if(!Array.isArray(list)||list.length===0){sugI.style.display='none';sugI.innerHTML='';return;}
        sugI.innerHTML = list.map(x=>`<div data-id="${x.id}" data-name="${x.name}" style="padding:8px 10px;cursor:pointer">${x.name}</div>`).join('');
        Array.from(sugI.children).forEach(el=>{
          el.addEventListener('click', function(){
            ciI.value = this.dataset.name; cidI.value = this.dataset.id; sugI.style.display='none';
          });
        });
        sugI.style.display='block';
      }).catch(()=>{sugI.style.display='none'});
  });
  document.addEventListener('click', function(e){ if(!sugI.contains(e.target) && e.target!==ciI){ sugI.style.display='none'; } });
  
  var form = document.getElementById('invEditForm');
  if (form) {
    form.addEventListener('submit', function(e){ 
      if(!cidI.value){ 
        e.preventDefault(); 
        alert('Please select a client from suggestions.'); 
      } 
    });
  }

  // Listen for discount/tax changes
  ['discountTypeInv', 'discountValueInv', 'taxPercentInv'].forEach(id => {
    var elem = document.getElementById(id);
    if (elem) elem.addEventListener('input', recalcInv);
  });
}

// Initialize on page load
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initInvoiceEdit);
} else {
  initInvoiceEdit();
}

// Re-initialize after AJAX page load (for client-side navigation)
document.addEventListener('pageLoaded', function() {
  initInvoiceEdit();
});
