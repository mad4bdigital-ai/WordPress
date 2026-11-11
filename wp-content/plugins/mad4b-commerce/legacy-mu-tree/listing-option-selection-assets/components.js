/** Native Elementor component choices. View state only; never booking/price authority. */
(()=>{'use strict';
 const selector='.booking-component-list',states=new Map(),plans=new WeakMap();let currentRate='';
 const integer=v=>/^[1-9][0-9]*$/.test(String(v));
 const key=(p,g)=>[p.contextTourId,p.contextRateId||0,g.type,g.key].join(':');
 function plan(list){
  if(!plans.has(list)){let p;try{p=JSON.parse(list.dataset.bookingComponentPlan||'null');}catch{}plans.set(list,p);}
  const base=plans.get(list);if(!base||!integer(base.contextTourId))return null;
  if(base.requiresRate){return {...base,contextRateId:currentRate,groups:base.groupsByRate[currentRate]||[]};}
  return base;
 }
 function rows(list){return [...list.querySelectorAll('.jet-listing-grid__item[data-post-id]')].filter(row=>row.closest(selector)===list);}
 function container(row){const wrapper=[...row.children].find(n=>n.matches('.elementor,.e-con'));return wrapper?.matches('.elementor')?([...wrapper.children].find(n=>n.matches('.e-con,.elementor-section'))||wrapper):(wrapper||row);}
 function info(row,list){
  const p=plan(list);if(!p||!Array.isArray(p.groups))return null;
  const id=String(row.dataset.postId);if(!integer(id))return null;
  const g=p.groups.find(g=>['extra','transport'].includes(g.type)&&['single','multiple'].includes(g.mode)&&Array.isArray(g.ids)&&g.ids.map(String).includes(id));
  if(!g||!g.key)return null;
  const target=container(row);
  return {p,g,id,target,key:key(p,g),locked:(g.lockedIds||[]).map(String).includes(id)};
 }
 function setFor(i){if(!states.has(i.key))states.set(i.key,new Set());return states.get(i.key);}
 function renderList(list){
  const p=plan(list);list.dataset.bookingComponentStatus=p?.groups?.length?'ready':(plans.get(list)?.requiresRate?'choose_rate':'unavailable');
  for(const row of rows(list)){
   const i=info(row,list);const target=i?.target||container(row);
   target.classList.add('booking-component-item');
   if(i?.g.type==='extra'){
    const detail=window.NativeExtraCardMediaV1?.[i.id];
    // Restore the old media column when JetEngine's image_vertical
    // visibility rule hides it, reading only the approved CPT Featured Image.
    let preview=[...target.children].find(n=>n.classList.contains('booking-extra-preview-media'));
    const legacy=target.querySelector('.elementor-element-1bc9269');
    if(detail?.image&&!legacy){
     if(!preview){
      preview=document.createElement('figure');
      preview.className='booking-extra-preview-media';
      preview.setAttribute('aria-hidden','true');
      const image=document.createElement('img');
      image.alt='';image.loading='lazy';image.decoding='async';
      preview.append(image);target.insertBefore(preview,target.firstChild);
     }
     const photo=preview.querySelector('img');
     if(photo.getAttribute('src')!==detail.image)photo.src=detail.image;
    }else if(preview)preview.remove();
   }
   let status=[...target.children].find(n=>n.classList.contains('booking-component-status'));
   if(!status){status=document.createElement('span');status.className='booking-component-status';target.append(status);}
   const message=i?(i.locked?'Included':(i.g.type==='transport'?(i.g.policies?.[i.id]==='additional'?'Additional':'On request'):'')):'';
   if(status.textContent!==message)status.textContent=message;status.hidden=!message;
   if(!i){target.setAttribute('aria-disabled','true');target.removeAttribute('role');target.removeAttribute('aria-checked');target.setAttribute('tabindex','-1');target.classList.remove('is-selected','is-included');delete target.dataset.bookingComponentType;delete target.dataset.bookingComponentPolicy;continue;}
   const active=i.locked||setFor(i).has(i.id);
   target.dataset.bookingComponentType=i.g.type;target.dataset.bookingComponentPolicy=i.g.policies?.[i.id]||'inquiry';
   target.setAttribute('role',i.g.mode==='single'?'radio':'checkbox');target.setAttribute('aria-checked',String(active));target.setAttribute('aria-disabled',String(i.locked));target.setAttribute('tabindex',i.locked?'-1':'0');target.classList.toggle('is-selected',active);target.classList.toggle('is-included',i.locked);
   // Existing explicit Extra controls and clickable containers share the same visible state.
   row.querySelectorAll('[data-extra-actions]').forEach(actions=>{if(String(actions.dataset.extraActions)!==i.id)return;actions.dataset.selected=String(active);const add=actions.querySelector('[data-extra-select]'),remove=actions.querySelector('[data-extra-remove]');if(add)add.hidden=active;if(remove)remove.hidden=!active;});
  }
 }
 function renderAll(){document.querySelectorAll(selector).forEach(renderList);}
 function snapshot(){
  const out=[],seen=new Set();document.querySelectorAll(selector).forEach(list=>{for(const row of rows(list)){const i=info(row,list);if(!i||(!i.locked&&!setFor(i).has(i.id)))continue;const id=i.key+':'+i.id;if(seen.has(id))continue;seen.add(id);out.push({tourId:Number(i.p.contextTourId),rateId:Number(i.p.contextRateId||0),componentType:i.g.type,groupKey:i.g.key,optionId:Number(i.id),policy:i.g.policies?.[i.id]||'inquiry',included:i.locked});}});return out;
 }
 function notify(i,active,source='container'){
  document.dispatchEvent(new CustomEvent('booking-component-selection-change',{detail:{schemaVersion:1,tourId:Number(i.p.contextTourId),rateId:Number(i.p.contextRateId||0),componentType:i.g.type,groupKey:i.g.key,optionId:Number(i.id),selected:active,policy:i.g.policies?.[i.id]||'inquiry',source,selections:snapshot(),status:'selection_only_not_booking_authority'}}));
 }
 function choose(i){
  if(i.locked)return;const set=setFor(i),active=!set.has(i.id);if(i.g.mode==='single')set.clear();active?set.add(i.id):set.delete(i.id);renderAll();notify(i,active);
  if(i.g.type==='extra')document.dispatchEvent(new CustomEvent('shared-extra-selection-change',{detail:{tourId:Number(i.p.contextTourId),extraTourId:Number(i.id),selected:active,source:'native-component-selection'}}));
 }
 function target(event){
  if(event.target.closest?.('a,button,input,select,textarea,summary,[contenteditable="true"],.swiper-button-next,.swiper-button-prev,.jet-listing-grid__slider-icon'))return null;
  const target=event.target.closest?.('.booking-component-item');if(!target)return null;const list=target.closest(selector),row=target.closest('.jet-listing-grid__item[data-post-id]');if(!list||!row)return null;return info(row,list);
 }
 document.addEventListener('click',e=>{const i=target(e);if(i)choose(i);});
 document.addEventListener('keydown',e=>{if(!['Enter',' '].includes(e.key)||e.repeat)return;const i=target(e);if(!i||i.locked)return;e.preventDefault();choose(i);});
 document.addEventListener('shared-extra-selection-change',e=>{
  const d=e.detail;if(!d||d.source==='native-component-selection'||!integer(d.extraTourId)||typeof d.selected!=='boolean')return;
  const changed=new Map();document.querySelectorAll(selector).forEach(list=>{for(const row of rows(list)){const i=info(row,list);if(!i||i.g.type!=='extra'||i.id!==String(d.extraTourId)||(d.tourId&&String(d.tourId)!==String(i.p.contextTourId)))continue;const set=setFor(i);if(d.selected)set.add(i.id);else set.delete(i.id);changed.set(i.key,i);}});renderAll();changed.forEach(i=>notify(i,d.selected,'existing-extra-control'));
 });
 document.addEventListener('rate-selection-request',e=>{
  const d=e.detail||{};if(!integer(d.rateId))return;
  // Transport choice context only follows a server-listed Rate. A forged
  // DOM event or a Rate from another Tour cannot enable a component group.
  const contexts=[...document.querySelectorAll(selector)].map(list=>plan(list)).filter(Boolean);
  if(d.tourId!==undefined && (!integer(d.tourId)
     ||!contexts.some(ctx=>String(ctx.contextTourId)===String(d.tourId))))return;
  const isNativeRate=contexts.some(ctx=>ctx.requiresRate
   &&Object.prototype.hasOwnProperty.call(ctx.groupsByRate||{},String(d.rateId)));
  if(!isNativeRate)return;
  if(d.remove&&currentRate!==String(d.rateId))return;
  const next=d.remove?'':String(d.rateId);
  if(next===currentRate)return;currentRate=next;
  // Transport preferences belong to the chosen Rate and are invalidated on context change.
  for(const k of states.keys())if(k.split(':')[2]==='transport')states.delete(k);
  renderAll();document.dispatchEvent(new CustomEvent('booking-component-context-change',{detail:{schemaVersion:1,rateId:Number(currentRate||0),selections:snapshot(),reason:'rate_changed'}}));
 });
 let scheduled=false;function schedule(){if(scheduled)return;scheduled=true;queueMicrotask(()=>{scheduled=false;renderAll();});}
 renderAll();new MutationObserver(records=>{if(records.some(r=>[...r.addedNodes].some(n=>n.nodeType===1)))schedule();}).observe(document.body,{childList:true,subtree:true});
 document.addEventListener('jet-filter-content-rendered',schedule);
 window.BookingComponentSelection=Object.freeze({getSelections:snapshot});
})();
