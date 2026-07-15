(function () {
  'use strict';
  function loadGoogle(key) {
    if (window.google && window.google.maps) return Promise.resolve();
    if (window.__paGooglePlacesPromise) return window.__paGooglePlacesPromise;
    window.__paGooglePlacesPromise = new Promise(function (resolve, reject) {
      window.__paPlacesReady = resolve;
      var script = document.createElement('script');
      script.async = true; script.onerror = reject;
      script.src = 'https://maps.googleapis.com/maps/api/js?key=' + encodeURIComponent(key) + '&libraries=places&loading=async&callback=__paPlacesReady';
      document.head.appendChild(script);
    });
    return window.__paGooglePlacesPromise;
  }
  function setValue(form, names, value) {
    for (var i=0;i<names.length;i++) { var field=form.querySelector('[name="'+names[i]+'"]'); if(field){field.value=value||'';field.dispatchEvent(new Event('change',{bubbles:true}));return;} }
  }
  function populate(form, place) {
    var parts={}; (place.addressComponents||[]).forEach(function(component){(component.types||[]).forEach(function(type){parts[type]=component;});});
    setValue(form,['address_line1'],[parts.street_number&&parts.street_number.longText,parts.route&&parts.route.longText].filter(Boolean).join(' '));
    var locality=parts.locality||parts.postal_town||parts.sublocality;
    setValue(form,['city'],locality&&locality.longText);
    setValue(form,['state'],parts.administrative_area_level_1&&(parts.administrative_area_level_1.shortText||parts.administrative_area_level_1.longText));
    setValue(form,['postal_code','postal'],parts.postal_code&&parts.postal_code.longText);
    setValue(form,['country'],parts.country&&(parts.country.shortText||parts.country.longText));
    setValue(form,['google_place_id'],place.id||'');
  }
  function enhance(form,key){
    if(form.dataset.addressAssistanceReady==='1'||!form.querySelector('[name="address_line1"]'))return;form.dataset.addressAssistanceReady='1';
    var street=form.querySelector('[name="address_line1"]'),hidden=form.querySelector('[name="google_place_id"]');if(!hidden){hidden=document.createElement('input');hidden.type='hidden';hidden.name='google_place_id';form.appendChild(hidden);}
    loadGoogle(key).then(function(){if(!google.maps.places||!google.maps.places.PlaceAutocompleteElement)return;var picker=new google.maps.places.PlaceAutocompleteElement({});picker.setAttribute('aria-label','Search for an address (optional)');picker.style.display='block';picker.style.marginBottom='8px';picker.style.width='100%';street.parentNode.insertBefore(picker,street);picker.addEventListener('gmp-select',function(event){var place=event.placePrediction.toPlace();place.fetchFields({fields:['id','formattedAddress','addressComponents']}).then(function(){populate(form,place);});});}).catch(function(){});
  }
  function init(ctx){var root=(ctx&&ctx.root)||document,config=document.getElementById('paAddressAssistanceConfig');if(!config||config.dataset.enabled!=='1'||!config.dataset.key)return;root.querySelectorAll('form').forEach(function(form){enhance(form,config.dataset.key);});}
  init.pageInitializerId='address-assistance';if(window.ProjectAlpha&&window.ProjectAlpha.registerPage)window.ProjectAlpha.registerPage('*',init);if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',function(){init({root:document});},{once:true});else init({root:document});
})();
