(function(){
 'use strict';
 var uid=0;
 function update(){
  if(!window.travelBookingNationalityAjax)return;
  fetch(window.travelBookingNationalityAjax,{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}})
   .then(function(res){if(!res.ok)throw Error('nationality read failed');return res.json();})
   .then(function(payload){
    if(!payload||!payload.success||!payload.data)return;
    var state=payload.data;
    document.querySelectorAll('.travel-booking-nationality-switcher').forEach(function(form){
     var select=form.querySelector('select[name="nationality"]');
     if(select){
      select.value=state.country||'';
      form.dispatchEvent(new CustomEvent('passport-search:sync'));
      form.dispatchEvent(new CustomEvent('passport-preference:mode',{detail:{mode:state.preference_mode||'auto'}}));
     }
     var flag=form.querySelector('img.travel-booking-country-flag');
     if(flag){
      if(state.flag_url){flag.src=state.flag_url;flag.alt=state.country||'';flag.hidden=false;}
      else flag.hidden=true;
     }
    });
    document.dispatchEvent(new CustomEvent('header-preferences:state'));
   }).catch(function(){/* Native form remains available. */});
 }
 function enhance(form){
  var select=form.querySelector('select[name="nationality"]');
  var cfg=form.querySelector('.travel-booking-passport-search-config');
  if(!select || !cfg || !cfg.dataset.endpoint || form.classList.contains('is-search-enabled'))return;
  var label=form.querySelector('label');
  if(!label)return;
  uid+=1;
  var listId='passport-options-'+uid, active=-1, options=[], page=0, more=false, busy=false;
  var requestNumber=0, controller=null, debounce=null, blur=null, selected=false;
  var box=document.createElement('div');
  box.className='travel-booking-passport-search';
  var input=document.createElement('input');
  input.type='search';
  input.className='travel-booking-passport-search__input';
  input.placeholder=cfg.dataset.placeholder||'Search passport country';
  input.setAttribute('aria-label',form.querySelector('.travel-booking-nationality-label')?.textContent||'Passport nationality');
  input.setAttribute('role','combobox');
  input.setAttribute('aria-haspopup','listbox');
  input.setAttribute('aria-autocomplete','list');
  input.setAttribute('aria-controls',listId);
  input.setAttribute('aria-expanded','false');
  input.autocomplete='off';
  input.spellcheck=false;
  var list=document.createElement('ul');
  list.id=listId;list.className='travel-booking-passport-search__results';
  list.setAttribute('role','listbox');list.hidden=true;
  var feedback=document.createElement('div');
  feedback.className='travel-booking-passport-search__feedback';
  feedback.setAttribute('role','status');
  feedback.setAttribute('aria-live','polite');
  feedback.hidden=true;
  box.append(input,list,feedback);
  label.appendChild(box);
  form.classList.add('is-search-enabled');
  var mode=document.createElement('div');
  mode.className='travel-booking-passport-mode';
  mode.setAttribute('aria-live','polite');
  var currentMode=cfg.dataset.mode==='manual'?'manual':'auto';
  var modeLabel=document.createElement('span');
  modeLabel.className='travel-booking-passport-mode__label';
  var reset=document.createElement('button');
  reset.type='button';
  reset.className='travel-booking-passport-mode__reset';
  reset.textContent=cfg.dataset.resetLabel||'Use selected country';
  reset.addEventListener('click',function(){
   form.dispatchEvent(new CustomEvent('passport-preference:reset',{bubbles:true}));
  });
  mode.append(modeLabel,reset);
  box.insertAdjacentElement('afterend',mode);
  function setMode(value){
   currentMode=value==='manual'?'manual':'auto';
   modeLabel.textContent=currentMode==='manual'?(cfg.dataset.modeManual||'Manual'):(cfg.dataset.modeAuto||'Auto · follows country');
   reset.hidden=currentMode!=='manual';
  }
  form.addEventListener('passport-preference:mode',function(event){
   setMode(event.detail?.mode||'auto');
  });
  setMode(currentMode);
  select.tabIndex=-1;
  select.setAttribute('aria-hidden','true');
  function countryName(){
   var index=select.selectedIndex;
   return index>0?select.options[index].textContent.trim():'';
  }
  function sync(){
   if(document.activeElement===input && list.hidden===false)return;
   input.value=countryName();
   selected=!!select.value;
  }
  function status(text){
   feedback.textContent=text||'';
   feedback.hidden=!text;
  }
  function close(){
   list.hidden=true;input.setAttribute('aria-expanded','false');
   input.removeAttribute('aria-activedescendant');
   active=-1;status('');
   if(controller){controller.abort();controller=null;}
   ++requestNumber;busy=false;
  }
  function draw(){
   list.replaceChildren();
   if(options.length===0){
    var empty=document.createElement('li');
    empty.className='travel-booking-passport-search__empty';
    empty.textContent=busy?(cfg.dataset.loading||'Searching…'):(cfg.dataset.empty||'No countries found');
    empty.setAttribute('role','presentation');
    list.appendChild(empty);
   }else options.forEach(function(opt,index){
    var item=document.createElement('li');
    item.id=listId+'-'+index;
    item.className='travel-booking-passport-search__option';
    item.setAttribute('role','option');
    item.setAttribute('aria-selected',String(index===active));
    item.textContent=opt.text;
    item.dataset.code=opt.id;
    item.addEventListener('mousedown',function(e){e.preventDefault();});
    item.addEventListener('click',function(){choose(index);});
    list.appendChild(item);
   });
   list.hidden=false;
   input.setAttribute('aria-expanded','true');
   highlight();
  }
  function highlight(){
   var children=list.querySelectorAll('[role="option"]');
   children.forEach(function(el,i){el.setAttribute('aria-selected',String(i===active));});
   var el=children[active];
   if(el){input.setAttribute('aria-activedescendant',el.id);el.scrollIntoView({block:'nearest'});}
   else input.removeAttribute('aria-activedescendant');
  }
  function choose(index){
   var record=options[index];if(!record||busy)return;
   var existing=[].find.call(select.options,function(o){return o.value===record.id;});
   if(!existing)return; // never accept a result not in the original catalogue
   select.value=record.id;
   input.value=existing.textContent.trim();
   selected=true;
   close();
   select.dispatchEvent(new Event('change',{bubbles:true}));
  }
  function load(append){
   if(busy && append)return;
   if(controller)controller.abort();
   controller=new AbortController();
   var number=++requestNumber;
   if(!append){options=[];page=0;active=-1;list.scrollTop=0;}
   var nextPage=page+1;
   var url=new URL(cfg.dataset.endpoint,location.href);
   var searchTerm=input.value.trim();
   // Only the compact mobile header uses localized country labels. The API's
   // canonical search is English, so map an unambiguous localized name back
   // to English without changing the visible query or selected country code.
   if(form.closest('.header-context-controls-mobile') && searchTerm &&
      typeof Intl!=='undefined' && typeof Intl.DisplayNames==='function'){
    try {
     var locale=document.documentElement.lang||'en';
     var needle=searchTerm.toLocaleLowerCase(locale);
     var matches=[].filter.call(select.options,function(opt){
       return /^[A-Z]{2}$/.test(opt.value) &&
         opt.textContent.trim().toLocaleLowerCase(locale).includes(needle);
     });
     if(matches.length===1){
      var english=new Intl.DisplayNames(['en'],{type:'region'}).of(matches[0].value);
      if(english && english!==matches[0].value) searchTerm=english;
     }
    }catch(e){/* retain native query when locale data is unavailable */}
   }
   url.searchParams.set('q',searchTerm);
   url.searchParams.set('page',String(nextPage));
   busy=true;
   if(!append){draw();status('');}
   fetch(url.href,{method:'GET',credentials:'same-origin',cache:'no-store',
     headers:{Accept:'application/json'},signal:controller.signal})
    .then(function(res){if(!res.ok)throw new Error('Passport search failed');return res.json();})
    .then(function(data){
     if(number!==requestNumber)return;
     if(!data||data.contract!=='travel_booking.passport-country-search.v1'||!Array.isArray(data.options))
      throw new Error('Unexpected country list');
     var safe=data.options.filter(function(x){return x&&typeof x.id==='string'&&
      typeof x.text==='string'&&/^[A-Z]{2}$/.test(x.id)&&
      [].some.call(select.options,function(o){return o.value===x.id;});});
     options=options.concat(safe);page=nextPage;more=!!data.has_more;busy=false;
     draw();status('');
    })
    .catch(function(err){
     if(number!==requestNumber || err.name==='AbortError')return;
     busy=false;list.replaceChildren();list.hidden=true;
     input.setAttribute('aria-expanded','false');
     status(cfg.dataset.error||'Unable to load countries. Try again.');
    });
  }
  input.addEventListener('focus',function(){
   if(blur){clearTimeout(blur);blur=null;}
   load(false);
  });
  input.addEventListener('input',function(){
   selected=false;
   if(debounce)clearTimeout(debounce);
   debounce=setTimeout(function(){load(false);},190);
  });
  input.addEventListener('keydown',function(e){
   if(e.key==='Escape'){e.preventDefault();close();input.value=countryName();return;}
   if(e.key==='ArrowDown'||e.key==='ArrowUp'){
    e.preventDefault();
    if(list.hidden){load(false);return;}
    if(!options.length)return;
    active=e.key==='ArrowDown'?Math.min(active+1,options.length-1):Math.max(0,active-1);
    highlight();return;
   }
   if(e.key==='Enter' && !list.hidden){
    e.preventDefault();
    if(active>=0)choose(active);else if(options.length===1)choose(0);
   }
  });
  input.addEventListener('blur',function(){
   blur=setTimeout(function(){
    close();
    if(!selected)input.value=countryName();
   },150);
  });
  list.addEventListener('scroll',function(){
   if(!busy&&more&&!list.hidden&&list.scrollTop+list.clientHeight>=list.scrollHeight-35)
    load(true);
  });
  document.addEventListener('pointerdown',function(event){
   if(!box.contains(event.target))close();
  });
  form.addEventListener('passport-search:sync',sync);
  sync();
 }
 function boot(){
  var forms=document.querySelectorAll('.travel-booking-nationality-switcher');
  forms.forEach(enhance);
  // Anonymous, unfiltered Staging first paint is already server-authoritative.
  // Keep the native fetch for sessions, currencies, filters, and changed sources.
  if(window.travelBookingNationalityServerFirstPaint===true && forms.length){
   document.dispatchEvent(new CustomEvent('header-preferences:state'));
  }else update();
 }
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',boot);
 else boot();
})();
