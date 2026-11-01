(function(){'use strict';
 function update(){
  if(!window.travelBookingNationalityAjax)return;
  fetch(window.travelBookingNationalityAjax,{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}})
    .then(function(res){if(!res.ok)throw Error('nationality read failed');return res.json();})
    .then(function(payload){if(!payload||!payload.success||!payload.data)return;
      var state=payload.data;
      document.querySelectorAll('.travel-booking-nationality-switcher').forEach(function(form){
        var select=form.querySelector('select[name="nationality"]');if(select)select.value=state.country||'';
        var flag=form.querySelector('img.travel-booking-country-flag');if(flag){if(state.flag_url){flag.src=state.flag_url;flag.alt=state.country||'';flag.hidden=false;}else flag.hidden=true;}
      });
    }).catch(function(){/* Native form continues to work. */});
 }
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',update);else update();
})();
