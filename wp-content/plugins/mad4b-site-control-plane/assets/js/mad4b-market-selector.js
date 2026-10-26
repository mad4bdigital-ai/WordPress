(function(){
 'use strict';
 function ready(){
  var url=(window.mad4bMarketSelector&&window.mad4bMarketSelector.ajaxUrl)||'';
  if(!url)return;
  document.querySelectorAll('.mad4b-market-picker').forEach(function(form){
   var select=form.querySelector('select[name="market_profile_id"]');
   var note=form.querySelector('.mad4b-market-picker__hint');
   if(!select||!note)return;
   fetch(url+'?action=mad4b_market_context_read',{credentials:'same-origin',cache:'no-store',headers:{'Accept':'application/json'}})
    .then(function(r){if(!r.ok)throw new Error('Country lookup unavailable');return r.json();})
    .then(function(payload){
     var data=payload&&payload.success&&payload.data;if(!data)return;
     if(data.selected_market&&data.selected_market.profile_id){
      select.value=data.selected_market.profile_id;
      note.textContent='Selected country: '+data.selected_market.country;
      return;
     }
     if(data.suggested_market&&data.suggested_market.profile_id){
      note.textContent='Suggested country: '+data.suggested_market.country+'. Confirm your market to customize offers.';
      return;
     }
     if(data.country_hint)note.textContent='Detected country: '+data.country_hint+'. Select your market to continue.';
    }).catch(function(){note.textContent='Choose your market to customize offers.';});
  });
 }
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',ready);else ready();
})();
