(function(){'use strict';
 function update(){
  // Public HTML cannot determine per-visitor country after MaxMind was activated.
  var url=window.mad4bCountryAjax;
  if(!url)return;
  function originalRead(){return fetch(url,{cache:'no-store',credentials:'same-origin',headers:{Accept:'application/json'}})
    .then(function(res){if(!res.ok)throw Error('market read failed');return res.json();})
    .then(function(data){if(!data||!data.success||!data.data)throw Error('market state contract invalid');return data.data;});}
  var bridge=window.MAD4BVisitorPreferenceBatch;
  var result=bridge && typeof bridge.read==='function'
    ? bridge.read().then(function(state){if(!state.country || state.country.contract!=='mad4b.country-switcher.v1')throw Error('market batch invalid');return state.country;}).catch(function(){return null;})
    : originalRead();
  result.then(function(state){
    if(!state)return;
      document.querySelectorAll('.mad4b-country-switcher').forEach(function(form){
        var select=form.querySelector('select[name="market_country"]');if(!select)return;
        if(form.dataset.mad4bCountrySearchUserChoice==='1')return; // user-selected ISO2 outranks stale public state
        select.value=state.country||'';
        var flag=form.querySelector('img.mad4b-country-flag');
        if(flag){if(state.flag_url){flag.src=state.flag_url;flag.alt=state.country||'';flag.hidden=false;}else flag.hidden=true;}
        var label=form.querySelector('.mad4b-country-market-status');
        if(label&&state.explicit&&!state.market_profile_id)label.textContent='Choose a supported language for this country.';
        select.dataset.saved=select.value;
      });
      if(bridge && typeof bridge.notify==='function')bridge.notify();else document.dispatchEvent(new CustomEvent('header-preferences:state'));
    }).catch(function(){/* Semantic no-JS form remains functional. */});
 }
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',update);else update();
})();
