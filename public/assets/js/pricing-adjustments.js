(function(){
  'use strict';

  function initializePricingAdjustments(context){
    var root=context&&context.root?context.root:document;
    root.querySelectorAll('[data-pricing-create-form]').forEach(function(form){
      if(form.dataset.pricingScopeInitialized==='1')return;
      form.dataset.pricingScopeInitialized='1';
      var scope=form.querySelector('[data-pricing-scope]');
      var customer=form.querySelector('[data-pricing-customer]');
      if(!scope||!customer)return;
      var select=customer.querySelector('select');
      function sync(){var visible=scope.value==='customer';customer.hidden=!visible;if(select)select.required=visible;}
      scope.addEventListener('change',sync);sync();
    });

    root.querySelectorAll('[data-pricing-override]').forEach(function(panel){
      if(panel.dataset.pricingOverrideInitialized==='1')return;
      panel.dataset.pricingOverrideInitialized='1';
      var mode=panel.querySelector('[data-pricing-override-mode]');
      var definitionWrap=panel.querySelector('[data-pricing-override-definition]');
      var reasonWrap=panel.querySelector('[data-pricing-override-reason]');
      var warning=panel.querySelector('[data-pricing-override-warning]');
      var definition=definitionWrap&&definitionWrap.querySelector('select');
      var reason=reasonWrap&&reasonWrap.querySelector('textarea');
      var form=panel.closest('form');
      if(!mode)return;
      var initialMode=mode.value;
      var initialDefinition=definition?definition.value:'';
      function syncOverride(){
        var usesDefinition=mode.value==='adjustment';
        var isOverride=mode.value!=='inherit';
        if(definitionWrap)definitionWrap.hidden=!usesDefinition;
        if(reasonWrap)reasonWrap.hidden=!isOverride;
        if(warning)warning.hidden=!isOverride;
        if(definition){definition.disabled=!usesDefinition;definition.required=usesDefinition;}
        if(reason){reason.disabled=!isOverride;reason.required=isOverride;}
      }
      if(form&&form.dataset.pricingOverrideConfirmBound!=='1'){
        form.dataset.pricingOverrideConfirmBound='1';
        form.addEventListener('submit',function(event){
          var changed=mode.value!==initialMode||(mode.value==='adjustment'&&definition&&definition.value!==initialDefinition);
          if(mode.value!=='inherit'&&changed&&!window.confirm('This pricing override can change the document total. Save this new revision?'))event.preventDefault();
        });
      }
      mode.addEventListener('change',syncOverride);syncOverride();
    });
  }

  initializePricingAdjustments.pageInitializerId='pricing-adjustments';
  if(window.ProjectAlpha&&typeof window.ProjectAlpha.registerPage==='function'){
    window.ProjectAlpha.registerPage(['settings','quote/quotes-edit','contract/contracts-edit','invoice/invoices-edit'],initializePricingAdjustments);
  }else if(document.readyState==='loading'){
    document.addEventListener('DOMContentLoaded',function(){initializePricingAdjustments({root:document});},{once:true});
  }else{
    initializePricingAdjustments({root:document});
  }
})();
