/** Native hotel widgets, canonical stage groups, one reversible OR choice per group. */
(()=>{
 'use strict';
 const selected=new Map(),initialized=new WeakSet();
 const listSelector='.booking-choice-list',itemSelector='.booking-choice-item';
 // A hotel is selectable only from the Rate currently chosen by the guest.
 let activeRateId=null;
 function syncRate(){
  const next=window.SharedPresentationSelectionV1?.getSnapshot?.()?.selectedRateId;
  const rate=Number.isSafeInteger(next)&&next>0?String(next):null;
  if(activeRateId===rate)return false;
  activeRateId=rate;return true;
 }
 function activeContext(id){return Boolean(id&&activeRateId&&id.contextId===activeRateId);}

 function identity(item){
  const list=item.closest(listSelector),group=item.closest('[data-booking-choice-stage]');
  const option=item.closest('.jet-listing-grid__item[data-post-id]');
  const context=list?.parentElement.closest('.jet-listing-grid__item[data-post-id]');
  if(!list||!group||!option||!list.contains(option))return null;
  return {list,group,optionId:option.dataset.postId,stageKey:group.dataset.bookingChoiceStage,contextId:context?.dataset.postId||'',key:[context?.dataset.postId||'page',list.dataset.id||'list',group.dataset.bookingChoiceStage].join(':')};
 }
 function soleProperty(id){
  let plan;try{plan=JSON.parse(id?.list?.dataset.bookingChoicePlan||'null');}catch{return false;}
  const stage=plan?.stages?.find(s=>s.key===id?.stageKey);
  return Array.isArray(stage?.properties)&&stage.properties.length===1&&
    String(stage.properties[0])===String(id.optionId);
 }
 function render(item){
  const id=identity(item);if(!id)return;
  const enabled=activeContext(id);
  const value=selected.get(id.key),active=enabled&&value===id.optionId;
  item.setAttribute('role','radio');item.removeAttribute('aria-pressed');
  // A sole server-authorized stay follows Select Rate; no second toggle.
  const fixed=enabled&&soleProperty(id);
  item.setAttribute('aria-disabled',String(!enabled||fixed));
  item.classList.toggle('is-fixed-choice',fixed);
  item.setAttribute('aria-checked',String(active));item.classList.toggle('is-selected',active);
  item.classList.toggle('is-rate-inactive',!enabled);
  const first=id.group.querySelector(itemSelector)===item;
  item.setAttribute('tabindex',enabled&&(active||(!value&&first))?'0':'-1');
 }
 function renderGroup(group){group.querySelectorAll(itemSelector).forEach(render);}
 function unavailable(list,code){
  list.dataset.bookingChoiceStatus=code||'unavailable';
  list.querySelectorAll(itemSelector).forEach(item=>{
   item.setAttribute('aria-disabled','true');item.setAttribute('tabindex','-1');item.removeAttribute('role');item.removeAttribute('aria-pressed');item.removeAttribute('aria-checked');
  });
 }
 function concealUnlinkedList(list,status){
  unavailable(list,status||'no_eligible_rate_properties');
  list.dataset.bookingChoiceEligible='0';
  list.hidden=true;
  const parent=list.parentElement;
  if(parent?.classList.contains('elementor-element-644ab04') && parent.children.length===1){
   parent.hidden=true;
  }
 }
 function initializeList(list){
  if(initialized.has(list))return;
  let plan;try{plan=JSON.parse(list.dataset.bookingChoicePlan||'null');}catch{}
  const stages=Array.isArray(plan?.stages)?plan.stages:[];
  const valid=Boolean(plan&&['simple_or','multi_stage'].includes(plan.mode)
    &&stages.some(stage=>Array.isArray(stage.properties)&&stage.properties.some(x=>Number(x)>0)));
  if(!valid||list.dataset.bookingChoiceEligible==='0'){
   initialized.add(list);concealUnlinkedList(list,plan?.reason||plan?.error);return;
  }
  const holder=list.querySelector('.jet-listing-grid__items');if(!holder)return;
  initialized.add(list);
  const rows=[...holder.children].filter(row=>row.classList.contains('jet-listing-grid__item'));
  const templates=new Map(rows.map(row=>[row.dataset.postId,row]));
  const used=new Set(),fragment=document.createDocumentFragment();let complete=true;
  for(const stage of plan.stages){
   if(!stage.key||!Array.isArray(stage.properties)){complete=false;continue;}
   const allowed=new Set(stage.properties.map(String));
   const group=document.createElement('fieldset');group.className='booking-choice-stage';group.dataset.bookingChoiceStage=stage.key;group.setAttribute('role','radiogroup');
   const legend=document.createElement('legend');legend.textContent=stage.label||stage.key;group.append(legend);
   let count=0;
   // Retain the native Listing Grid's authored within-stage order.
   for(const [id,row]of templates){
    if(!allowed.has(id))continue;
    const native=used.has(id)?row.cloneNode(true):row;used.add(id);group.append(native);count++;
   }
   if(!count){complete=false;const message=document.createElement('p');message.textContent='No eligible hotel options';group.append(message);}
   fragment.append(group);
  }
  if(!used.size){
   // A provider/query cannot expose a selector with no actual choices.
   // Keep the rate price/actions available and expose a diagnostic status.
   concealUnlinkedList(list,'no_rendered_eligible_properties');return;
  }
  holder.replaceChildren(fragment);list.dataset.bookingChoiceStatus=complete?'ready':'incomplete';
  list.querySelectorAll(itemSelector).forEach(render);
 }
 function initialize(root=document){
  syncRate();
  if(root.matches?.(listSelector))initializeList(root);
  root.querySelectorAll?.(listSelector).forEach(initializeList);
  if(root.matches?.(itemSelector))render(root);
  root.querySelectorAll?.(itemSelector).forEach(render);
  if(window.SharedPresentationSelectionV1?.getSnapshot?.()?.selectedRateId)
   queueMicrotask(()=>{if(typeof refreshRate==='function')refreshRate();});
 }
 function choose(item,toggle=true){
  const id=identity(item);
  syncRate();
  if(!activeContext(id))return;
  const clear=toggle&&selected.get(id.key)===id.optionId;
  clear?selected.delete(id.key):selected.set(id.key,id.optionId);
  renderGroup(id.group);
  const stages={};for(const group of id.list.querySelectorAll('[data-booking-choice-stage]')){
   const first=group.querySelector(itemSelector),info=first&&identity(first);if(info&&selected.has(info.key))stages[info.stageKey]=selected.get(info.key);
  }
  item.dispatchEvent(new CustomEvent('booking-option-selection-change',{bubbles:true,detail:{contextId:id.contextId,stageKey:id.stageKey,optionId:id.optionId,selected:!clear,selectedOptionIds:clear?[]:[id.optionId],stageSelections:stages,complete:[...id.list.querySelectorAll('[data-booking-choice-stage]')].every(group=>Object.hasOwn(stages,group.dataset.bookingChoiceStage))}}));
 }
 function target(event){
  const item=event.target.closest?.(`${listSelector} ${itemSelector}`);
  if(!item||item.getAttribute('aria-disabled')==='true'||!activeContext(identity(item))||event.target.closest('a,button,input,select,textarea,summary,[contenteditable="true"]'))return null;
  return item;
 }
 document.addEventListener('click',event=>{const item=target(event);if(item)choose(item);});
 document.addEventListener('keydown',event=>{
  const item=target(event);if(!item||event.repeat)return;
  if(['Enter',' '].includes(event.key)){event.preventDefault();choose(item);return;}
  if(!['ArrowUp','ArrowDown','ArrowLeft','ArrowRight','Home','End'].includes(event.key))return;
  const id=identity(item);if(!id)return;const items=[...id.group.querySelectorAll(itemSelector)];let index=items.indexOf(item);
  if(event.key==='Home')index=0;else if(event.key==='End')index=items.length-1;else index=(index+(['ArrowDown','ArrowRight'].includes(event.key)?1:-1)+items.length)%items.length;
  event.preventDefault();choose(items[index],false);items[index].focus();
 });
 initialize();
 // The shared broker is the single source for accommodation picks, including
 // the deterministic one-property selections made by the primary Rate button.
 function reconcileFromBroker(){
  const snap=window.SharedPresentationSelectionV1?.getSnapshot?.();
  if(!snap)return;
  const stageMap=(snap.rates||[]).find(r=>r.rateId===snap.selectedRateId)?.stages||{};
  for(const list of document.querySelectorAll(listSelector)){
   for(const group of list.querySelectorAll('[data-booking-choice-stage]')){
    const first=group.querySelector(itemSelector),id=first&&identity(first);
    if(!id||!activeContext(id))continue;
    const value=String(stageMap[id.stageKey]||'');
    if(value)selected.set(id.key,value);else selected.delete(id.key);
    renderGroup(group);
   }
  }
 }
 function autoSelectSoleStages(){
  const snap=window.SharedPresentationSelectionV1?.getSnapshot?.();
  if(!snap?.selectedRateId)return;
  const existing=(snap.rates||[]).find(r=>r.rateId===snap.selectedRateId)?.stages||{};
  for(const list of document.querySelectorAll(listSelector)){
   if(list.dataset.bookingChoiceStatus!=='ready')continue;
   for(const group of list.querySelectorAll('[data-booking-choice-stage]')){
    const items=[...group.querySelectorAll(itemSelector)];
    if(items.length!==1)continue;
    const item=items[0],id=identity(item);
    if(!activeContext(id)||!soleProperty(id)||existing[id.stageKey])continue;
    selected.set(id.key,id.optionId);
    renderGroup(group);
    item.dispatchEvent(new CustomEvent('booking-option-selection-change',{
     bubbles:true,detail:{contextId:id.contextId,stageKey:id.stageKey,
      optionId:id.optionId,selected:true,source:'sole_property_rate_selection'}
    }));
   }
  }
 }
 const refreshRate=()=>{
  syncRate();
  reconcileFromBroker();
  autoSelectSoleStages();
  document.querySelectorAll(listSelector).forEach(list=>
   list.querySelectorAll('[data-booking-choice-stage]').forEach(renderGroup));
 };
 document.addEventListener('shared-presentation-selection-changed',e=>{
  if(['rate','source.context','accommodation.stage','component.context'].includes(e.detail?.kind))
   refreshRate();
 });
 document.addEventListener('rate-selection-request',()=>queueMicrotask(refreshRate));
 // New JetEngine listing rows can arrive after a Rate was selected.
 document.addEventListener('jet-filter-content-rendered',()=>queueMicrotask(refreshRate));
 document.addEventListener('booking-component-context-change',()=>queueMicrotask(refreshRate));
 new MutationObserver(records=>{for(const record of records)for(const node of record.addedNodes)if(node.nodeType===1)initialize(node);}).observe(document.body,{childList:true,subtree:true});
})();
