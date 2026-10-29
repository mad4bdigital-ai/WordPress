/** Local Lab: server-calculated read-only quote beside existing Fluent Form #6. */
(() => {
 'use strict';
 const cfg=window.SharedPresentationFluentConfigV1;
 const broker=window.SharedPresentationSelectionV1;
 if(!cfg?.quoteEndpoint||!broker||window.SharedPresentationFluentQuoteV1||
    cfg.scope!=='tour_single_booking'||
    !document.body.classList.contains('single-tours-and-activities')||
    !cfg.formScopeSelector)return;
 const allowedForm=form=>!!(form&&form.matches(cfg.formScopeSelector));
 const bound=new WeakSet(),forms=new Set(),states=new WeakMap();
 const asInt=v=>/^(0|[1-9][0-9]{0,2})$/.test(String(v??''))?Number(v):null;
 // Original Tour Rate post IDs are not traveler counts (many exceed 999).
 const asRateId=v=>/^[1-9][0-9]{0,11}$/.test(String(v??''))&&
   Number.isSafeInteger(Number(v))?Number(v):null;
 // Accommodation comes from MU Presentation source policy, not an
 // unconditional Fluent Form field or the presence of a Tour Rate.
 function hasAccommodation(rate){
  return !!(rate&&cfg.accommodationPolicy?.rates_with_stay?.[String(rate)]===true);
 }
 function syncAccommodationContext(state,snapshot,adults){
  const rate=asRateId(snapshot.selectedRateId);
  const realStay=hasAccommodation(rate);
  state.occupancyLabel.hidden=!realStay;
  if(realStay){
   if(!state.roomContext||state.lastRoomRate!==rate)state.occupancy.value='';
  }else{
   // An internal price tier is NOT a room assignment. Direct per-person
   // pricing uses its neutral "single" basis. When a Rate has sharing
   // tiers but no stay, derive a provisional tier from adult count.
   const n=Math.max(1,adults||1);
   state.occupancy.value=rate?(n===1?'single':n%2===0?'double':n%3===0?'triple':'single'):'single';
  }
  state.roomContext=realStay;
  state.lastRoomRate=rate;
  state.panel.dataset.accommodation=realStay?'linked':'none';
 }
 function selectedStays(snapshot,rate,state){
   const row=(snapshot.rates||[]).find(x=>x.rateId===rate);
   if(!row||!hasAccommodation(rate))return [];
   return Object.entries(row.stages||{})
     .filter(([key,id])=>/^[a-z0-9_-]{2,45}$/.test(key)&&asRateId(id))
     .sort(([a],[b])=>a.localeCompare(b))
     .map(([stage_key,property_id])=>{
       const obj={stage_key,property_id:Number(property_id)};
       const choice=state?.stayChoices.get(rate+'|'+stage_key+'|'+property_id);
       if(choice?.unit_id)obj.unit_id=choice.unit_id;
       if(choice?.board_id)obj.board_id=choice.board_id;
       if(choice?.rooms)obj.rooms=choice.rooms;
       return obj;
     });
 }
 const iso=v=>{
  const x=String(v??'').trim();
  let m=/^(\d{4})-(\d{2})-(\d{2})$/.exec(x);
  if(!m)m=/^(\d{1,2})-([A-Za-z]{3})-(\d{2,4})$/.exec(x);
  if(!m)return null;
  if(x.includes('-')&&/^[0-9]{4}/.test(x)){
   const d=new Date(x+'T00:00:00Z');return !Number.isNaN(d.getTime())&&d.toISOString().slice(0,10)===x?x:null;
  }
  const months={jan:1,feb:2,mar:3,apr:4,may:5,jun:6,jul:7,aug:8,sep:9,oct:10,nov:11,dec:12};
  const mo=months[m[2].toLowerCase()];if(!mo)return null;
  const yy=Number(m[3]);const y=yy<100?2000+yy:yy;
  const value=[y,String(mo).padStart(2,'0'),String(m[1]).padStart(2,'0')].join('-');
  const d=new Date(value+'T00:00:00Z');
  return !Number.isNaN(d.getTime())&&d.toISOString().slice(0,10)===value?value:null;
 };
 function intentPayload(form){
  const n=name=>form.querySelector('[name="'+name+'"]')?.value;
  const adults=asInt(n('no_of_adults')),children=asInt(n('no_of_children')),infants=asInt(n('no_of_infants'));
  const snap=broker.getSnapshot(),rate=asRateId(snap.selectedRateId);
  const st=states.get(form);
  if(!st||!st.occupancy.value)return null;
  if(adults===null||adults<1||adults>100||children===null||infants===null)return null;
  const travelDate=iso(n('datetime'));if(!travelDate)return null;
  const extras=[...snap.extras].map(id=>{
   const item=st.extras.get(String(id));
   const persons=item?asInt(item.value):adults;
   return {tour_id:Number(id),persons:persons===null?0:persons};
  });
  // Optional activity attendance may include children; their retail
  // age pricing stays review-only until the selected activity is eligible.
  if(extras.some(x=>!x.tour_id||x.persons<1||
      x.persons>Math.min(100,adults+children)))return null;
  return {tour_id:Number(cfg.tourId),source:rate?'rate':'direct',rate_id:rate||null,
    travel_date:travelDate,adults,children,infants,occupancy:st.occupancy.value,
    extras,stay_stages:rate?selectedStays(snap,rate,st):[]};
 }
 function selected(form){
  const payload=intentPayload(form);
  if(!payload||payload.children!==0||payload.infants!==0)return null;
  // Stay preferences are saved for human review, not accepted as money.
  // Existing quote endpoints retain their exact pricing contract.
  const {stay_stages,...priceOnly}=payload;
  return priceOnly;
 }
 function amount(value,currency){
  try{return new Intl.NumberFormat(undefined,{style:'currency',currency,maximumFractionDigits:2}).format(value);}
  catch(_){return String(value)+' '+currency;}
 }

 function childrenOrInfants(form){
   return Number(form.querySelector('[name="no_of_children"]')?.value||0)>0||
     Number(form.querySelector('[name="no_of_infants"]')?.value||0)>0;
 }
 function indicativeExtraUnit(id){
  const source=cfg.extraDetails?.[String(id)]?.preview_price;
  if(!source||!Number.isFinite(Number(source.amount))||
     Number(source.amount)<=0||!/^[A-Z]{3}$/.test(source.currency||''))return null;
  return 'From '+amount(Number(source.amount),source.currency)
    +(source.unit==='per_person'?' / person':'');
 }
 function resetExtraPrices(state){
  for(const row of state.extraWrap.querySelectorAll('.shared-extra-item')){
   const text=row.querySelector('.shared-extra-unit-price');
   if(!text)continue;
   const possible=indicativeExtraUnit(row.dataset.extraTourId);
   text.textContent=possible||'Price on request';
   text.dataset.priceStatus=possible?'indicative':'unverified';
  }
 }
 function selectedTransportOptions(snapshot){
  const rate=asRateId(snapshot.selectedRateId);
  if(!rate)return [];
  const current=(snapshot.rates||[]).find(x=>x.rateId===rate);
  const groups=current?.components?.transport||{};
  const selected=Object.entries(groups).flatMap(([group,ids])=>
    (Array.isArray(ids)?ids:[]).map(id=>({id:String(id),group})));
  const native=window.BookingComponentSelection?.getSelections?.()||[];
  return selected.filter(x=>cfg.transportDetails?.[x.id])
    .slice(0,60).map(x=>{
     const detail=cfg.transportDetails[x.id];
     const chosen=native.find(n=>n.componentType==='transport'&&
       n.rateId===rate&&String(n.optionId)===x.id&&n.groupKey===x.group);
     return {id:Number(x.id),group:x.group,mode:detail.mode,
       name:detail.name,policy:chosen?.included?'included':
         ['included','additional','inquiry'].includes(chosen?.policy)?chosen.policy:'inquiry'};
    });
 }
 function renderTransports(state,snapshot){
  const options=selectedTransportOptions(snapshot);
  state.transportPicker.hidden=options.length===0;
  const fingerprint=JSON.stringify(options);
  if(state.transportFingerprint!==fingerprint){
   state.transportWrap.replaceChildren();
   for(const option of options){
    const row=document.createElement('div');
    row.className='shared-transport-option';
    row.dataset.selected='true';row.dataset.pricingPolicy=option.policy;
    const name=document.createElement('span');name.className='shared-transport-name';
    name.textContent=option.name;
    const badge=document.createElement('span');badge.className='shared-transport-status';
    const added=document.createElement('span');added.className='shared-transport-added';
    added.textContent=option.policy==='included'?'✓ Included':'✓ Added';
    const policy=document.createElement('small');policy.className='shared-transport-policy';
    policy.textContent=option.policy==='included'?'In package':
      option.policy==='additional'?'Additional':'To confirm';
    badge.append(added,policy);
    row.append(name,badge);state.transportWrap.append(row);
   }
   state.transportFingerprint=fingerprint;
  }
  state.transportIntent.value=JSON.stringify({
   tour_id:Number(cfg.tourId),
   rate_id:asRateId(snapshot.selectedRateId)||0,
   options:options.map(o=>({transport_id:o.id,group:o.group}))
  });
 }
 function showPrice(state,data,partial){
   state.output.replaceChildren();
   const label=document.createElement('span');
   label.className='shared-price-amount';
   label.textContent=amount(data.total,data.currency);
   state.output.append(label);
   const note=document.createElement('small');
   note.className='shared-price-scope';
   note.textContent=partial&&!state.roomContext
     ?(data.extras_pending_count>0?'Extras to confirm':'Tour estimate · subject to confirmation')
     :partial?
     (data.stay_additional_included?'Tour + priced stays · more to confirm':
       data.stay_pending_count>0?'Stay price on request':
       data.extras_included?'Tour + extras · stays to confirm':
       data.extras_pending_count>0?'Extras to confirm':
       'Tour estimate · stays to confirm'):
     'Estimate · subject to availability';
   state.output.append(note);
   for(const row of state.extraWrap.querySelectorAll('.shared-extra-item')){
    const id=row.dataset.extraTourId;
    const target=row.querySelector('.shared-extra-unit-price');
    const source=data.extra_prices?.[id];
    if(!target)continue;
    if(source&&Number.isFinite(Number(source.amount))&&
       /^[A-Z]{3}$/.test(source.currency||'')){
     target.textContent=amount(source.amount,source.currency);
     if(source.unit==='per_person')target.textContent+=' / person';
     target.dataset.priceStatus='verified';
    }else{
     const value=indicativeExtraUnit(id);
     target.textContent=value||'Price on request';
     target.dataset.priceStatus=value?'indicative':'unverified';
    }
   }
 }
 async function indicativePreview(state,payload,sequence,signal){
   if(!cfg.indicativeEndpoint)return false;
   try{
     const url=new URL(cfg.indicativeEndpoint,location.href);
     for(const key of ['tour_id','source','rate_id','travel_date',
       'adults','children','infants','occupancy'])
       url.searchParams.set(key,String(payload[key]??''));
     url.searchParams.set('extras',JSON.stringify(payload.extras||[]));
     url.searchParams.set('stay_stages',JSON.stringify(payload.stay_stages||[]));
     const reply=await fetch(url.toString(),{
       method:'GET',credentials:'same-origin',signal});
     const estimate=await reply.json();
     if(sequence!==state.seq)return true;
     if(reply.ok&&estimate.status==='indicative_preview_only'&&
       Number.isFinite(Number(estimate.total))&&/^[A-Z]{3}$/.test(estimate.currency||'')){
       showPrice(state,estimate,true);
       state.lastSettledPriceSignature=state.activePriceSignature;
       return true;
     }
   }catch(error){if(error.name==='AbortError')return true;}
   return false;
 }
 function syncStayOptions(form,state,snap,adults){
  const rate=asRateId(snap.selectedRateId);
  const stages=rate?selectedStays(snap,rate,state):[];
  state.stayPicker.hidden=stages.length===0;
  const keys=new Set(stages.map(x=>rate+'|'+x.stage_key+'|'+x.property_id));
  for(const row of [...state.stayWrap.querySelectorAll('.shared-stay-row')])
   if(!keys.has(row.dataset.stayToken))row.remove();
  const option=(id,name)=>{const x=document.createElement('option');x.value=String(id);x.textContent=name;return x;};
  const fill=(select,items,prompt)=>{select.replaceChildren(option('',prompt));
   for(const x of items)select.append(option(x.id,x.name));};
  for(const stage of stages){
   const token=rate+'|'+stage.stage_key+'|'+stage.property_id;
   if([...state.stayWrap.children].some(x=>x.dataset.stayToken===token))continue;
   const row=document.createElement('section');row.className='shared-stay-row';row.dataset.stayToken=token;
   const title=document.createElement('strong');title.className='shared-stay-heading';
   title.textContent=stage.stage_key.replace(/_stay$/,'').replace(/_/g,' ')+' stay';
   const field=(name,tag)=>{
    const label=document.createElement('label');label.className='shared-stay-field';
    const text=document.createElement('span');text.textContent=name;
    const input=document.createElement(tag);label.append(text,input);return [label,input];
   };
   const [unitLabel,unit]=field('Room type','select');
   const [boardLabel,board]=field('Meals','select');
   const [roomsLabel,rooms]=field('Rooms','input');
   unit.className='shared-stay-unit';board.className='shared-stay-board';
   rooms.type='number';rooms.className='shared-stay-rooms';rooms.min='1';rooms.max='50';rooms.step='1';
   rooms.value='1';rooms.inputMode='numeric';board.disabled=true;rooms.disabled=true;
   fill(unit,[],'Loading rooms…');fill(board,[],'Choose meals');
   const hint=document.createElement('small');hint.className='shared-stay-hint';
   hint.textContent='Room price to confirm';
   row.append(title,unitLabel,boardLabel,roomsLabel,hint);
   state.stayWrap.append(row);
   const current=()=>state.stayChoices.get(token)||{};
   const save=obj=>{state.stayChoices.set(token,{...current(),...obj});schedule(form);};
   const updateBoard=()=>{
    const entry=state.stayCatalog.get(token)?.units?.find(x=>x.id===Number(unit.value));
    fill(board,(entry?.boards||[]).map(x=>({id:x.id,name:x.name})),'Choose meals');
    board.disabled=!entry;rooms.disabled=!entry;
    const saved=current();
    if((entry?.boards||[]).some(x=>x.id===saved.board_id))board.value=String(saved.board_id);
    if(entry){
     const min=Math.max(1,Math.ceil((adults||1)/Math.max(1,entry.capacity)));
     rooms.min=String(min);rooms.value=String(Math.max(min,Math.min(50,saved.rooms||min)));
    }
    const policy=state.stayCatalog.get(token)?.price_policy;
    hint.textContent=policy==='included'?'Included · selection to confirm':
     policy==='inquiry'?'Price on request':'Price subject to availability';
   };
   unit.addEventListener('change',()=>{
    save({unit_id:Number(unit.value)||null,board_id:null,rooms:null});
    updateBoard();
   });
   board.addEventListener('change',()=>save({
    board_id:Number(board.value)||null,rooms:Number(rooms.value)||1
   }));
   rooms.addEventListener('change',()=>{
    const entry=state.stayCatalog.get(token)?.units?.find(x=>x.id===Number(unit.value));
    const min=Math.max(1,Math.ceil((adults||1)/Math.max(1,entry?.capacity||1)));
    const count=Math.max(min,Math.min(50,asInt(rooms.value)||min));
    rooms.value=String(count);save({rooms:count});
   });
   const load=async()=>{
    try{
     const url=new URL(cfg.stayOptionsEndpoint,location.href);
     for(const [key,value] of Object.entries({tour_id:Number(cfg.tourId),
      rate_id:rate,stage_key:stage.stage_key,property_id:stage.property_id}))
      url.searchParams.set(key,String(value));
     const response=await fetch(url.toString(),{credentials:'same-origin',cache:'no-store'});
     const data=await response.json();
     if(!response.ok||!Array.isArray(data.units)||!row.isConnected)return;
     state.stayCatalog.set(token,data);
     fill(unit,data.units.map(x=>({id:x.id,
       name:String(x.name).replace(/^QA ONLY\s*[·:-]\s*/i,'')})),'Choose room type');
     if(data.units.some(x=>x.id===current().unit_id))unit.value=String(current().unit_id);
     updateBoard();schedule(form);
    }catch(e){if(row.isConnected){fill(unit,[],'Room quote required');
      hint.textContent='Continue for a quote';}}
   };
   if(cfg.stayOptionsEndpoint)load();else{fill(unit,[],'Room quote required');}
  }
 }

 async function refresh(form){
  const state=states.get(form);if(!state)return;
  const adults=asInt(form.querySelector('[name="no_of_adults"]')?.value);
  const snapshot=broker.getSnapshot();
  syncAccommodationContext(state,snapshot,adults);
  const selectedIds=new Set([...snapshot.extras].map(String));
  // Only optional activities picked in the native Elementor/JetEngine section.
  // The source registry validates the selection; allowed-but-unpicked items
  // are not customer order lines and must not appear in Fluent Form #6.
  const allowed=new Set((window.SharedPresentationSelectionConfigV1?.allowedExtraTourIds||[]).map(String));
  const options=[...selectedIds].filter(id=>allowed.has(id)&&
    (cfg.extraDetails?.[id]||cfg.extraNames?.[id])).slice(0,30);
  syncStayOptions(form,state,snapshot,adults);
  renderTransports(state,snapshot);
  state.picker.hidden=options.length===0;
  state.picker.dataset.selectedCount=String(options.length);
  state.picker.classList.toggle('has-many-extras',options.length>=4);
  state.picker.classList.toggle('has-one-extra',options.length===1);
  for(const [id,field] of state.extras){
   if(options.includes(id))continue;
   field.closest('.shared-extra-item')?.remove();
   state.extras.delete(id);
  }
  const children=asInt(form.querySelector('[name="no_of_children"]')?.value)??0;
  const maximum=adults!==null&&adults>0?
    Math.min(adults+children,100):1;
  options.forEach((key,index)=>{
   const detail=cfg.extraDetails?.[key]||{};
   const name=detail.name||cfg.extraNames?.[key]||'Activity '+(index+1);
   let field=state.extras.get(key);
   if(!field){
    const row=document.createElement('div');row.className='shared-extra-item';
    row.dataset.extraTourId=key;
    const media=document.createElement('span');media.className='shared-extra-media';
    if(detail.image&&/^(https?:\/\/|\/wp-content\/uploads\/)/.test(detail.image)){
     const img=document.createElement('img');img.src=detail.image;img.alt='';
     img.loading='lazy';img.decoding='async';media.append(img);
    }else{
     media.classList.add('shared-extra-media-placeholder');
     media.textContent='✦';media.setAttribute('aria-hidden','true');
    }
    const content=document.createElement('div');content.className='shared-extra-content';
    const title=document.createElement('span');title.className='shared-extra-name';
    title.textContent=name;
    const price=document.createElement('span');price.className='shared-extra-unit-price';
    // No client-rendered CPT raw price: the effective unit amount may
    // depend on dates, package, FX and Rate source arbitration.
    price.textContent=indicativeExtraUnit(key)||'Price on request';
    price.dataset.priceStatus=indicativeExtraUnit(key)?'indicative':'unverified';
    const controls=document.createElement('div');controls.className='shared-extra-counter';
    const decrease=document.createElement('button');decrease.type='button';
    decrease.className='shared-extra-minus';decrease.textContent='−';
    decrease.setAttribute('aria-label','Decrease guests for '+name);
    field=document.createElement('input');field.type='number';
    field.className='shared-extra-quantity';field.min='1';field.step='1';
    field.inputMode='numeric';field.value=String(maximum);
    field.setAttribute('aria-label','Guests for '+name);
    const increase=document.createElement('button');increase.type='button';
    increase.className='shared-extra-plus';increase.textContent='+';
    increase.setAttribute('aria-label','Increase guests for '+name);
    const change=delta=>{
     const current=asInt(field.value)??1;
     const next=Math.max(1,Math.min(Number(field.max)||1,current+delta));
     if(next===current)return;
     field.value=String(next);
     field.dispatchEvent(new Event('input',{bubbles:true}));
    };
    // Counts customize existing native selections. Deselect an activity from
    // the tour's Extra Activities section, not by silently changing a zero row.
    decrease.addEventListener('click',()=>change(-1));
    increase.addEventListener('click',()=>change(1));
    field.addEventListener('change',()=>{
     const value=asInt(field.value);
     field.value=String(Math.max(1,Math.min(Number(field.max)||1,value??1)));
     schedule(form);
    });
    field.addEventListener('input',()=>schedule(form));
    controls.append(decrease,field,increase);
    content.append(title,controls);
    row.append(media,content,price);
    state.extraWrap.append(row);state.extras.set(key,field);
   }
   const row=field.closest('.shared-extra-item');
   if(row&&state.extraWrap.lastElementChild!==row)state.extraWrap.append(row);
   field.max=String(maximum);
   const active=selectedIds.has(key);
   const current=asInt(field.value);
   const value=Math.max(1,Math.min(maximum,current&&current>0?current:maximum));
   if(field.value!==String(value))field.value=String(value);
   if(row){
    row.classList.toggle('is-selected',active);
    const buttons=row.querySelectorAll('.shared-extra-counter button');
    if(buttons[0])buttons[0].disabled=value<=1;
    if(buttons[1])buttons[1].disabled=value>=maximum;
   }
  });
  const payload=selected(form);
  const intent=intentPayload(form);
  // A known Direct Tour retail price can still be shown as an adult-only
  // lower-bound when child/infant pricing requires a human review.
  // Never turn that subtotal into the form's commercial total.
  const partialDirect=(!payload&&intent&&intent.source==='direct'&&
    (intent.children>0||intent.infants>0))?
    {...intent,children:0,infants:0,extras:[],stay_stages:[]}:null;
  state.intent.value=intent?JSON.stringify(intent):'';
  // A transport-only preference does not change the price inputs.
  // Keep the last source-validated amount instead of blanking it to
  // Calculating and re-fetching an identical quote.
  const signature=(payload||partialDirect)?JSON.stringify({
    price:payload||partialDirect,stays:intent?.stay_stages||[],
    scope:partialDirect?'adults_only_unpriced_minors':'full_estimate'
  }):null;
  if(signature&&state.lastSettledPriceSignature===signature&&
     state.output.querySelector('.shared-price-amount'))return;
  const seq=++state.seq;
  state.activePriceSignature=signature;
  state.controller?.abort();state.controller=null;
  if(!payload&&!partialDirect){
   state.lastSettledPriceSignature=null;
   state.output.textContent=state.roomContext?
     'Select date & occupancy':'Select travel date';
   resetExtraPrices(state);
   return;
  }
  if(partialDirect){
   state.output.textContent='Calculating adult base…';
   resetExtraPrices(state);
   const controller=new AbortController();state.controller=controller;
   try{
    const {stay_stages,...adultPrice}=partialDirect;
    const response=await fetch(cfg.quoteEndpoint,{
      method:'POST',headers:{'Content-Type':'application/json'},
      credentials:'same-origin',body:JSON.stringify(adultPrice),signal:controller.signal
    });
    const quote=await response.json();
    if(seq!==state.seq)return;
    if(response.ok&&quote.status==='price_preview_only'&&
       Number.isFinite(Number(quote.total))&&quote.total>0&&
       /^[A-Z]{3}$/.test(quote.currency||'')){
      const value=document.createElement('span');
      value.className='shared-price-amount';
      value.textContent='From '+amount(quote.total,quote.currency);
      const scope=document.createElement('small');
      scope.className='shared-price-scope';
      scope.textContent='Adults only · child/infant pricing and extras to confirm';
      state.output.replaceChildren(value,scope);
      state.panel.dataset.priceScope='adults_only_unpriced_minors';
      state.lastSettledPriceSignature=signature;
    }else{
      state.output.textContent='Final price to confirm';
      state.lastSettledPriceSignature=null;
    }
   }catch(error){
    if(seq===state.seq&&error.name!=='AbortError')
      state.output.textContent='Final price to confirm';
   }
   return;
  }
  state.output.textContent='Calculating…';
  // Avoid keeping a stale confirmed per-person price after changing dates,
  // occupancy, activity count, or Rate selection.
  resetExtraPrices(state);
  const controller=new AbortController();state.controller=controller;
  // Pure read-only estimate is allowed through the Local QA preview gateway;
  // POST authorization for booking/Fluent Forms remains deliberately closed.
  if(await indicativePreview(state,{...payload,stay_stages:intent?.stay_stages||[]},seq,controller.signal))return;
  try{
   const response=await fetch(cfg.quoteEndpoint,{method:'POST',headers:{'Content-Type':'application/json'},
     credentials:'same-origin',body:JSON.stringify(payload),signal:controller.signal});
   const data=await response.json();if(seq!==state.seq)return;
   if(!response.ok||data.status!=='price_preview_only'){

     if(seq===state.seq)state.output.textContent='Price on request';
     return;
   }
   showPrice(state,data,Boolean(data.component_adjustments_certified===false));
   state.lastSettledPriceSignature=state.activePriceSignature;
  }catch(err){
   if(seq!==state.seq||err.name==='AbortError')return;
   state.output.textContent='Price on request';
  }
 }
 function schedule(form){
  const state=states.get(form);if(!state)return;
  clearTimeout(state.timer);state.timer=setTimeout(()=>refresh(form),260);
 }
 function attach(form){
  if(!allowedForm(form)||bound.has(form))return;
  // Elementor Popup may clone an already-enhanced form. Throw away copied
  // pricing UI/hidden fields and attach one fresh controller to the clone.
  form.querySelectorAll('.shared-fluent-server-quote,.shared-extra-picker,.shared-transport-picker').forEach(x=>x.remove());
  form.querySelectorAll('input[name="shared_tour_selection_v2"],input[name="shared_transport_preferences_v1"]').forEach(x=>x.remove());
  bound.add(form);forms.add(form);
  const panel=document.createElement('div');panel.className='shared-fluent-server-quote shared-price-first-preview';
  panel.style.cssText='padding:12px;margin-block:12px;border:1px solid var(--e-global-color-secondary,#ddd);border-radius:10px';
  const title=document.createElement('strong');title.className='shared-price-title';
  title.textContent='Estimated price';
  const label=document.createElement('label');label.textContent='Room occupancy ';
  label.className='shared-room-occupancy-field';label.hidden=true;
  const occupancy=document.createElement('select');
  [['','Choose occupancy'],['single','Single'],['double','Double'],['triple','Triple']].forEach(([value,name])=>{
   const el=document.createElement('option');el.value=value;el.textContent=name;occupancy.append(el);
  });
  occupancy.addEventListener('change',()=>schedule(form));label.append(occupancy);
  const picker=document.createElement('section');picker.className='shared-extra-picker';
  picker.hidden=true;picker.dataset.selectedCount='0';
  picker.setAttribute('aria-label','Selected extra activities');
  const pickerTitle=document.createElement('h3');pickerTitle.className='shared-extra-picker-title';
  pickerTitle.textContent='Extra activities';
  const extraWrap=document.createElement('div');extraWrap.className='shared-fluent-extras-quantities';
  picker.append(pickerTitle,extraWrap);
  const transportPicker=document.createElement('section');
  transportPicker.className='shared-transport-picker';transportPicker.hidden=true;
  transportPicker.setAttribute('aria-label','Selected transport options');
  const transportTitle=document.createElement('h3');
  transportTitle.className='shared-transport-heading';
  transportTitle.textContent='Transport options';
  const transportWrap=document.createElement('div');
  transportWrap.className='shared-transport-list';
  transportPicker.append(transportTitle,transportWrap);
  const transportIntent=document.createElement('input');
  transportIntent.type='hidden';transportIntent.name='shared_transport_preferences_v1';
  transportIntent.autocomplete='off';form.append(transportIntent);
  const stayPicker=document.createElement('section');stayPicker.className='shared-stay-picker';
  stayPicker.hidden=true;stayPicker.setAttribute('aria-label','Stay preferences');
  const stayWrap=document.createElement('div');stayWrap.className='shared-stay-options';
  stayPicker.append(stayWrap);
  const output=document.createElement('output');output.setAttribute('aria-live','polite');
  output.style.cssText='display:block;margin-block:10px;font-weight:600';
  const note=document.createElement('small');note.className='shared-price-note';
  note.textContent='No payment required';
  const intent=document.createElement('input');
  intent.type='hidden';intent.name='shared_tour_selection_v2';intent.autocomplete='off';
  form.append(intent);
  form.addEventListener('submit',()=>{const payload=intentPayload(form);intent.value=payload?JSON.stringify(payload):'';},true);
  panel.append(title,label,output,note);
  const description=form.querySelector('textarea[name="description"]')?.closest('.ff-el-group');
  if(description){description.insertAdjacentElement('beforebegin',picker);
   picker.insertAdjacentElement('afterend',transportPicker);
   transportPicker.insertAdjacentElement('afterend',stayPicker);
  }else{form.append(picker,transportPicker,stayPicker);}
  const sibling=form.querySelector('[data-presentation-form-projection-v1]');
  if(sibling)sibling.insertAdjacentElement('afterend',panel);else form.append(panel);
  states.set(form,{occupancy,occupancyLabel:label,roomContext:false,lastRoomRate:null,
    panel,picker,extraWrap,extras:new Map(),
    transportPicker,transportWrap,transportIntent,transportFingerprint:'',
    stayPicker,stayWrap,stayChoices:new Map(),stayCatalog:new Map(),
    output,intent,seq:0,controller:null,timer:null});
  form.addEventListener('change',()=>schedule(form));
  form.addEventListener('input',()=>schedule(form));
  schedule(form);
 }
 function initialize(){
  document.querySelectorAll(cfg.formScopeSelector).forEach((form,index)=>{
   // Elementor sticky creates an invisible clone as its spacer. It must not
   // become another live form, quote controller or duplicate HTML ID.
   if(form.closest('.elementor-sticky__spacer')){
    form.inert=true;form.setAttribute('aria-hidden','true');
    form.id='fluentform_6_sticky_spacer_'+index;
    return;
   }
   attach(form);
  });
 }
 document.addEventListener('shared-presentation-form-projected',e=>{if(e.target?.matches?.('form.fluent_form_6'))schedule(e.target);});
 document.addEventListener('shared-presentation-selection-changed',()=>{for(const form of forms)if(form.isConnected)schedule(form);});
 initialize();
 new MutationObserver(()=>initialize()).observe(document.body,{subtree:true,childList:true});
 window.SharedPresentationFluentQuoteV1=Object.freeze({version:'1.0.0',readOnly:true,getPayload:selected});
})();
