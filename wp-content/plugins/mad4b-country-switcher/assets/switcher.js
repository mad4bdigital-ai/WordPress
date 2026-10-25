(function(){'use strict';
 function update(){
  var url=window.mad4bCountryAjax;
  if(!url)return;
  fetch(url,{cache:'no-store',credentials:'same-origin',headers:{Accept:'application/json'}})
    .then(function(res){if(!res.ok)throw Error('market read failed');return res.json();})
    .then(function(data){if(!data||!data.success||!data.data)return;
      var state=data.data;
      document.querySelectorAll('.mad4b-country-switcher').forEach(function(form){
        var select=form.querySelector('select[name="market_country"]');if(!select)return;
        select.value=state.country||'';
        var flag=form.querySelector('img.mad4b-country-flag');
        if(flag){if(state.flag_url){flag.src=state.flag_url;flag.alt=state.country||'';flag.hidden=false;}else flag.hidden=true;}
        var label=form.querySelector('.mad4b-country-market-status');
        if(label&&state.explicit&&!state.market_profile_id)label.textContent='Please choose an available language for this market.';
      });
    }).catch(function(){/* Semantic no-JS form remains functional. */});
 }
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',update);else update();
})();
