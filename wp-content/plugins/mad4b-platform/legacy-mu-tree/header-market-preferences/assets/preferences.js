(function(){
 'use strict';
 var currentNonces=null;
 var nonceRequest=null;
 function refreshNonces(details,force){
  if(window.MAD4BBrowserJourney?.getNonces && details?.dataset?.preferenceState)
   return window.MAD4BBrowserJourney.getNonces(details.dataset.preferenceState,!!force)
    .then(function(v){currentNonces=v;return v;}).catch(function(){return null;});
  if(!force&&currentNonces)return Promise.resolve(currentNonces);
  if(!force&&nonceRequest)return nonceRequest;
  if(!details?.dataset?.preferenceState)return Promise.resolve(null);
  nonceRequest=fetch(details.dataset.preferenceState,{
   credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}
  }).then(function(r){if(!r.ok)throw Error('nonce_state_http');return r.json();})
   .then(function(data){
    if(data?.contract!=='visitor-preferences.state.v1'||
       !data.nonces?.country||!data.nonces?.passport)throw Error('nonce_state_invalid');
    currentNonces=data.nonces;
    return currentNonces;
   }).catch(function(){return null;}).finally(function(){nonceRequest=null;});
  return nonceRequest;
 }
 function updateLayer(details){
  var header=details.closest('.ekit-template-content-header');
  if(header)header.classList.toggle('market-preferences-layer-open',details.open);
 }
 document.addEventListener('toggle',function(event){
  var d=event.target;
  if(d instanceof HTMLDetailsElement&&d.matches('[data-native-preference-switchers]'))updateLayer(d);
 },true);
 function sync(details){
  var country=details.querySelector('select[name="market_country"]');
  var label=details.querySelector('.header-market-preferences__current');
  if(!label)return;
  var value=country&&country.value?country.value:'';
  label.textContent=value||((document.documentElement.lang||'').toLowerCase().startsWith('ar')?'الدولة':'Country');
  var icon=details.querySelector('.header-market-preferences__icon');
  if(icon){
   if(/^[A-Z]{2}$/.test(value)){
    icon.textContent=String.fromCodePoint(value.charCodeAt(0)-65+0x1f1e6,
       value.charCodeAt(1)-65+0x1f1e6);
   }else icon.textContent='🌐';
  }
 }
 function status(details,message){
  var item=details.querySelector('.header-market-preferences__message');
  if(item)item.textContent=message||'';
 }
 function setAuto(details,passport,mode,flag){
  details.querySelectorAll('.travel-booking-nationality-switcher').forEach(function(form){
   var sel=form.querySelector('select[name="nationality"]');
   if(sel){
    sel.value=passport||'';
    form.dispatchEvent(new CustomEvent('passport-search:sync'));
   }
   var image=form.querySelector('img.travel-booking-country-flag');
   if(image){
    image.hidden=!flag;
    if(flag){image.src=flag;image.alt=passport||'';}
   }
   form.dispatchEvent(new CustomEvent('passport-preference:mode',{detail:{mode:mode}}));
  });
 }
 async function save(details,action,code,form,oldValue,oldPassport,oldMode){
  if(details.classList.contains('is-applying'))return;
  var nonceInput=form.querySelector('[name="_mad4b_country_nonce"],[name="_travel_booking_nationality_nonce"]');
  if(!nonceInput || !details.dataset.preferenceApi){
   form.requestSubmit();return;
  }
  details.classList.add('is-applying');
  details.setAttribute('aria-busy','true');
  var language=(document.documentElement.lang||'').toLowerCase().startsWith('ar');
  status(details,language?'جارٍ الحفظ…':'Saving…');
  try{
   await refreshNonces(details,false);
   var requestKind=action==='country'?'country':'passport';
   async function postWithNonce(nonce){
    var controller=new AbortController();
    var timer=setTimeout(function(){controller.abort();},12000);
    try{
     var reply=await fetch(details.dataset.preferenceApi,{
      method:'POST',credentials:'same-origin',cache:'no-store',
      headers:{'Content-Type':'application/json',Accept:'application/json'},
      body:JSON.stringify({action:action,country:code||'',nonce:nonce}),
      signal:controller.signal
     });
     return {response:reply,data:await reply.json()};
    }finally{clearTimeout(timer);}
   }
   var saved=await postWithNonce(currentNonces?.[requestKind]||nonceInput.value);
   if(saved.response.status===403 && saved.data?.code==='invalid_nonce'){
    var renewed=await refreshNonces(details,true);
    if(renewed?.[requestKind])saved=await postWithNonce(renewed[requestKind]);
   }
   var response=saved.response,data=saved.data;
   if(!response.ok||data.contract!=='visitor-preferences.v1')
    throw Error(data.message||'Could not save');
   var market=details.querySelector('select[name="market_country"]');
   if(market && data.country)market.value=data.country;
   setAuto(details,data.passport,data.mode,data.passport_flag);
   var marketStatus=details.querySelector('.mad4b-country-market-status');
   if(marketStatus && action==='country'){
    marketStatus.textContent=data.market_profile_id?'':(language?
      'هذه الدولة غير متاحة باللغة الحالية.':'Choose a supported language for this country.');
   }
   var marketFlag=details.querySelector('img.mad4b-country-flag');
   if(marketFlag && action==='country'){
    marketFlag.hidden=!data.country_flag;
    if(data.country_flag){marketFlag.src=data.country_flag;marketFlag.alt=data.country;}
   }
   sync(details);
   document.dispatchEvent(new CustomEvent('visitor-preferences:saved',{detail:data}));
   if(action==='country'){
    // Pricing/availability owners must revalidate their data when the market
    // changes. Client must never synthesize a new price.
    window.dispatchEvent(new CustomEvent('visitor-market:changed',{detail:data}));
    document.querySelectorAll('[data-market-price],[data-market-quote]').forEach(function(e){
     e.dispatchEvent(new CustomEvent('visitor-market:revalidate',{bubbles:true,detail:data}));
    });
   }
   status(details,language?'تم الحفظ':'Saved');
   setTimeout(function(){if(!details.classList.contains('is-applying'))status(details,'');},1800);
  }catch(error){
   if(oldValue!==undefined){
    var field=action==='country'?'market_country':'nationality';
    var input=form.querySelector('select[name="'+field+'"]');
    if(input)input.value=oldValue;
   }
   if(action==='country' && oldMode==='auto')
    setAuto(details,oldPassport||'','auto','');
   sync(details);
   status(details,language?'لم يتم الحفظ. حاول مجددًا.':'Could not save. Try again.');
  }finally{
   details.classList.remove('is-applying');
   details.removeAttribute('aria-busy');
  }
 }
 document.addEventListener('DOMContentLoaded',function(){
  var details=document.querySelectorAll('[data-native-preference-switchers]');
  details.forEach(function(d){sync(d);updateLayer(d);});
  // Nonces are fetched on demand only, not on every page visit.
 });
 document.addEventListener('header-preferences:state',function(){
  document.querySelectorAll('[data-native-preference-switchers]').forEach(sync);
 });
 document.addEventListener('change',function(event){
  var target=event.target;
  if(!(target instanceof HTMLSelectElement)||
    !['market_country','nationality'].includes(target.name)||!target.value)return;
  var details=target.closest('[data-native-preference-switchers]');
  if(!details)return;
  var form=target.form;if(!form)return;
  var oldValue=target.dataset.saved||'';
  if(target.name==='market_country'){
   // Country UI responds immediately, then persists without a page reload.
   var code=target.value;
   var passport=details.querySelector('select[name="nationality"]');
   var oldPassport=passport?passport.value:'';
   var modeLabel=details.querySelector('.travel-booking-passport-mode__reset');
   var autoMode=!!modeLabel&&modeLabel.hidden;
   if(autoMode)setAuto(details,code,'auto','');
   sync(details);
   save(details,'country',code,form,oldValue,oldPassport,autoMode?'auto':'manual')
    .then(function(){target.dataset.saved=target.value;});
  }else{
   save(details,'manual_passport',target.value,form,oldValue).then(function(){target.dataset.saved=target.value;});
  }
 });
 document.addEventListener('passport-preference:reset',function(e){
  var form=e.target.closest('form');
  var d=e.target.closest('[data-native-preference-switchers]');
  if(form&&d)save(d,'auto_passport','',form);
 });
 document.addEventListener('pointerdown',function(event){
  document.querySelectorAll('[data-native-preference-switchers][open]').forEach(function(d){
   if(!d.contains(event.target))d.open=false;
  });
 });
 document.addEventListener('keydown',function(event){
  if(event.key!=='Escape')return;
  // The autocomplete owns Escape when its option list is open.
  if(event.target.closest('.travel-booking-passport-search') &&
    event.target.getAttribute('aria-expanded')==='true')return;
  document.querySelectorAll('[data-native-preference-switchers][open]').forEach(function(d){
   d.open=false;
   var s=d.querySelector('summary');if(s)s.focus();
  });
 });
})();
