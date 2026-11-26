(function(){'use strict';
 function init(form){
  if(form.dataset.mad4bAgesBound)return;
  const widget=form.querySelector('.mad4b-age-collector');if(!widget)return;
  const MAX=Number(widget.dataset.maxGuests||50),children=form.elements.namedItem(widget.dataset.childCount),infants=form.elements.namedItem(widget.dataset.infantCount);
  if(!children||!infants)return;form.dataset.mad4bAgesBound='1';
  const anchor=infants.closest('.ff-el-group')||children.closest('.ff-el-group')||infants;anchor.insertAdjacentElement('afterend',widget);
  let policy;try{policy=JSON.parse(widget.dataset.agePolicy||'null');}catch{}let revision=0,currentRate=String(policy?.context?.rate_id||0);
  const embeddedTour=form.elements.namedItem('__fluent_form_embded_post_id')?.value||0;
  const tour=Number(widget.dataset.tourId||policy?.context?.product_id||(policy?.error?embeddedTour:0)||0),token=widget.querySelector('[name="mad4b_age_context_token"]');
  const error=widget.querySelector('.mad4b-age-error'),caption=widget.querySelector('.mad4b-age-caption');
  const groups=[{key:'children',count:children,node:widget.querySelector('.mad4b-age-child-fields'),name:'mad4b_child_ages[]',label:'Child'},
   {key:'infants',count:infants,node:widget.querySelector('.mad4b-age-infant-fields'),name:'mad4b_infant_ages[]',label:'Infant'}];
  const quantity=input=>/^(0|[1-9][0-9]*)$/.test(String(input.value).trim())&&Number(input.value)<=MAX?Number(input.value):null;
  const ranges=key=>Array.isArray(policy?.groups?.[key])?policy.groups[key]:[];
  const label=key=>ranges(key).map(r=>r.min===r.max?String(r.min):r.min+'–'+r.max).join(', ')+' years';
  const allowed=(key,value)=>/^(?:0|[1-9][0-9]{0,2})(?:\.[0-9]{1,2})?$/.test(String(value).trim())&&Number(value)<=120&&ranges(key).some(r=>Number(value)>=r.min&&Number(value)<=r.max);
  function check(){
   const total=groups.reduce((sum,g)=>sum+(quantity(g.count)??MAX+1),0);let message='';
   if(total>MAX)message='Please check passenger counts (combined maximum '+MAX+').';
   const general=policy?.mode==='collect_only';
   if(total&&(!policy?.groups?.children?.length||!policy?.groups?.infants?.length||
      (!general&&!token?.value)))
    message=general?'Please complete the children and infant ages.':
       'Age policy is temporarily unavailable. Please refresh this page or contact us.';
   for(const g of groups)for(const input of g.node.querySelectorAll('input')){
    const valid=allowed(g.key,input.value);input.setCustomValidity(valid?'':g.label+' age must match '+label(g.key)+'.');
    if(!valid&&input.value!==''&&!message)message=g.label+' age must match '+label(g.key)+'.';
   }
   if(widget.dataset.ratePolicyLoading==='1'){
    error.hidden=true;error.textContent='';return false;
   }
   error.hidden=!message;error.textContent=message;return !message;
  }
  function render(){
   for(const g of groups){
    const n=quantity(g.count),old=[...g.node.querySelectorAll('input')].map(i=>i.value),fragment=document.createDocumentFragment(),rr=ranges(g.key);
    if(n!==null)for(let i=0;i<n;i++){
     const wrap=document.createElement('label');wrap.className='mad4b-age-item';const title=document.createElement('span');title.textContent=g.label+' '+(i+1)+' age ('+label(g.key)+')';
     const input=document.createElement('input');input.type='number';input.inputMode='decimal';input.name=g.name;input.min=String(rr.length?Math.min(...rr.map(r=>r.min)):0);input.max=String(rr.length?Math.max(...rr.map(r=>r.max)):0);input.step='0.01';input.required=true;input.disabled=!rr.length;input.autocomplete='off';input.value=old[i]??'';input.addEventListener('input',check);wrap.append(title,input);fragment.append(wrap);
    }
    g.node.replaceChildren(fragment);
    const counterLabel=g.count.closest('.ff-el-group')?.querySelector('.ff-el-input--label');if(counterLabel){let hint=counterLabel.querySelector('[data-age-range-hint]');if(!hint){hint=document.createElement('small');hint.dataset.ageRangeHint='1';counterLabel.append(hint);}hint.textContent=rr.length?' ('+label(g.key)+')':'';}
   }
   const adults=form.elements.namedItem('no_of_adults');const adultLabel=adults?.closest('.ff-el-group')?.querySelector('.ff-el-input--label');if(adultLabel&&ranges('adults').length){let hint=adultLabel.querySelector('[data-age-range-hint]');if(!hint){hint=document.createElement('small');hint.dataset.ageRangeHint='1';adultLabel.append(hint);}hint.textContent=' ('+label('adults')+')';}
   caption.textContent='Enter ages in years (decimals allowed: 1.50 = 18 months) '+(policy?.age_reference==='checkin'?'at check-in':'at arrival / service start')+'. No date of birth required.';
   widget.hidden=groups.every(g=>quantity(g.count)===0);check();
  }
  async function refresh(rate,force){
   // Shared age collection is independent of Tour Rate selections.
   if(!tour||policy?.mode==='collect_only')return;
   rate=String(rate||0);if(rate===currentRate&&!force)return;currentRate=rate;const seq=++revision;
   if(token)token.value='';
   widget.dataset.ratePolicyLoading='1';
   // Keep the prior hints and field geometry until the next policy arrives.
   // The stale token is invalidated immediately, preventing stale submissions.
   if(!tour||!/^\d+$/.test(rate)){
    delete widget.dataset.ratePolicyLoading;policy={};render();return;
   }
   try{const url=new URL(widget.dataset.policyUrl,location.href);url.searchParams.set('form_id',widget.dataset.formId);url.searchParams.set('tour_id',String(tour));url.searchParams.set('rate_id',rate);const response=await fetch(url,{credentials:'same-origin',cache:'no-store',headers:{'Accept':'application/json'}});const data=await response.json();if(seq!==revision)return;if(!response.ok||!data?.groups?.children?.length||!data?.groups?.infants?.length||!data?.token)throw Error('policy unavailable');policy=data;policy.mode='tour';if(token)token.value=data.token;
    delete widget.dataset.ratePolicyLoading;render();}catch(e){if(seq===revision){
    delete widget.dataset.ratePolicyLoading;policy={};render();}}
  }
  for(const g of groups){g.count.addEventListener('input',render);g.count.addEventListener('change',render);}
  document.addEventListener('rate-selection-request',e=>{if(e.detail?.remove&&String(e.detail.rateId)!==currentRate)return;refresh(e.detail?.remove?0:e.detail?.rateId);});
  document.addEventListener('shared-presentation-selection-changed',e=>{const snapshot=e.detail?.snapshot;if(snapshot&&Number(snapshot.tourId)===tour)refresh(snapshot.selectedRateId||0);});
  if(tour&&policy?.mode!=='collect_only')widget.dataset.ratePolicyLoading='1';
  render();const snap=window.SharedPresentationSelectionV1?.getSnapshot?.();
  // Refresh the signed token every Tour page load, even if HTML came from an old cache entry.
  if(tour&&policy?.mode!=='collect_only')refresh(snap&&Number(snap.tourId)===tour?snap.selectedRateId||0:0,true);
  form.addEventListener('submit',function(event){if(!check()){event.preventDefault();event.stopImmediatePropagation();return;}for(const g of groups){if(quantity(g.count)===null){event.preventDefault();event.stopImmediatePropagation();g.count.focus();return;}for(const input of g.node.querySelectorAll('input'))if(!input.checkValidity()){event.preventDefault();event.stopImmediatePropagation();input.reportValidity();return;}}},true);
 }
 function scan(){document.querySelectorAll('form').forEach(init);}
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',scan,{once:true});else scan();
 document.addEventListener('fluentform_submission_success',scan);
})();
