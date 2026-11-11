/* Admin-only Local selector. Fluent Forms values are synchronized in the
   DOM; no submission, order, server-side Fluent storage, or commercial quote. */
(() => {
 'use strict';
 const config = window.CartLabConfig;
 const root = document.querySelector('[data-cart-lab]');
 if (!config || !root) return;
 const $ = (s, parent = root) => parent.querySelector(s);
 const $$ = (s, parent = root) => [...parent.querySelectorAll(s)];
 const el = (tag, className, txt) => {
   const node = document.createElement(tag);
   if (className) node.className = className;
   if (txt !== undefined) node.textContent = txt;
   return node;
 };
 const money = n => new Intl.NumberFormat(document.documentElement.lang || 'en', {
   style: 'currency', currency: 'USD', maximumFractionDigits: 2
 }).format(n);
 const state = {rate: config.rateId, date: '', adults:2,children:0,childAges:[],
   stays:{}, extras:[],transports:[], catalog:null, serial:0};
 const storageKey = 'cart-lab:qa:' + config.tourId;
 const endpoint = (path) => config.api + path;
 const msg = text => { $('[data-cartlab-message]').textContent = text; };
 const dateInside = (rate) => state.date && rate && state.date >= rate.from && state.date <= rate.to;
 const readRate = () => state.catalog?.rates?.find(x => x.id === state.rate);
 const api = async (path, payload) => {
   const response = await fetch(endpoint(path), {
     method:payload ? 'POST':'GET',credentials:'same-origin',
     headers:{'X-WP-Nonce':config.nonce,
       ...(payload?{'Content-Type':'application/json'}:{})},
     ...(payload?{body:JSON.stringify(payload)}:{})
   });
   const data = await response.json();
   if(!response.ok) throw Error(data.message || data.code || 'Selection unavailable');
   return data;
 };
 function card(title, small, selected, handle, radio, disabled=false) {
   const b = el('button','cartlab-option'+(selected?' is-selected':'')+(disabled?' is-disabled':''));b.type='button';
   b.disabled=disabled;b.setAttribute('aria-pressed',String(!!selected));
   b.setAttribute('role',radio?'radio':'checkbox');b.setAttribute('aria-checked',String(!!selected));
   const head=el('strong','',title);const sub=el('small','',small);
   b.append(head,sub);b.addEventListener('click',handle);
   return b;
 }
 function selectForm() {
   return document.querySelector('form.fluent_form_'+config.formId)
      || document.querySelector('#fluentform_'+config.formId)
      || document.querySelector('form[data-form_id="'+config.formId+'"]');
 }
 function syncForm() {
   const form=selectForm();
   if(!form)return false;
   if(!form.dataset.cartlabQaSubmitGuard){
     form.dataset.cartlabQaSubmitGuard='1';
     const blockQA=(event)=>{
       event.preventDefault();event.stopImmediatePropagation();
       msg('QA demo only: Fluent submission is disabled until authorized source persistence is implemented.');
     };
     form.addEventListener('submit',blockQA,true);
     form.addEventListener('click',(event)=>{
       const submit=event.target.closest('button[type="submit"],input[type="submit"]');
       if(submit && form.contains(submit))blockQA(event);
     },true);
   }
   // Respect existing Fluent field names. Never fabricate consent or price fields.
   const fields = {'no_of_adults':String(state.adults),
     'no_of_children':String(state.children)};
   // Form #6 datetime means Arrival Date, while this selector is Tour Departure.
   // Do not overwrite semantically different Fluent fields.
   // Requested Departure remains in the QA selection payload, not a Form #6 date.
   for (const [name,value] of Object.entries(fields)){
     const input = $$('[name]',form).find(n=>n.name===name);
     if(!input)continue;
     if(input.value!==value){
       input.value=value;input.dispatchEvent(new Event('input',{bubbles:true}));
       input.dispatchEvent(new Event('change',{bubbles:true}));
     }
   }
   // Provisional browser-field bridge. An unregistered Fluent field may not
   // persist in a submission. Do not claim server storage or authorization.
   let hidden=form.querySelector('[data-cartlab-provisional]');
   if(!hidden){hidden=el('input');hidden.type='hidden';hidden.name='cart_lab_configuration_json';
     hidden.dataset.cartlabProvisional='1';form.append(hidden);}
   hidden.value=JSON.stringify({
     schema:'cart-lab-intent-preview.v1',tour_id:config.tourId,rate_id:state.rate,
     requested_date:state.date,adults:state.adults,children:state.children,
     child_ages:state.childAges,stays:state.stays,extra_tour_ids:state.extras,
     transport_ids:state.transports,
     price_authorized:false,booking_authorized:false,form_persistence_verified:false
   });
   return true;
 }
 function persist() {
   try{sessionStorage.setItem(storageKey,JSON.stringify({...state,
     catalog:null,savedAt:Date.now()}));}catch(e){}
   syncForm();
 }
 function emit() {
   root.dispatchEvent(new CustomEvent('booking-cart:selection-changed',{
     bubbles:true,detail:{tour_id:config.tourId,rate_id:state.rate,
       stays:state.stays,extras:state.extras,booking_authorized:false}}));
 }
 function renderRates(){
   const target=$('[data-cartlab-rates]');target.replaceChildren();
   for(const rate of state.catalog?.rates || []){
     target.append(card(rate.package+' · '+rate.season,
       rate.from+' — '+rate.to+' · From '+money(rate.indicative_base)+' / adult',
       rate.id===state.rate,async()=>{
         if(rate.id===state.rate)return;
         state.rate=rate.id;state.stays={};
         if(!dateInside(rate))state.date=rate.from;
         $('[data-cartlab-date]').value=state.date;
         persist();await load();
       },true));
   }
 }
 function renderChildAges() {
   const target=$('[data-cartlab-child-ages]');target.replaceChildren();
   state.childAges=state.childAges.slice(0,state.children);
   while(state.childAges.length<state.children)state.childAges.push(null);
   for(let i=0;i<state.children;i++){
     const label=el('label','cartlab-age-label','Child '+(i+1)+' age');
     const input=el('select','cartlab-select');
     input.append(new Option('Select age',''));
     for(let age=0;age<18;age++)input.add(new Option(String(age),String(age)));
     input.value=state.childAges[i]===null?'':String(state.childAges[i]);
     input.addEventListener('change',()=>{state.childAges[i]=input.value===''?null:Number(input.value);changed();});
     label.append(input);target.append(label);
   }
 }
 function stepper(value,onChange,minimum=1,maximum=8){
   const wrap=el('span','cartlab-stepper');
   const less=el('button','','−');less.type='button';less.setAttribute('aria-label','Decrease quantity');
   const count=el('output','',String(value));const more=el('button','','+');
   more.type='button';more.setAttribute('aria-label','Increase quantity');
   less.addEventListener('click',()=>onChange(Math.max(minimum,Number(count.textContent)-1)));
   more.addEventListener('click',()=>onChange(Math.min(maximum,Number(count.textContent)+1)));
   wrap.append(less,count,more);return {wrap,count};
 }
 function stageChoice(stage){
   const original=state.stays[stage.key] || {};
   return {property_id:original.property_id || 0,unit_id:original.unit_id || 0,
     unit_rate_id:original.unit_rate_id || 0,rooms:original.rooms || 1,
     meal_term_id:original.meal_term_id||0};
 }
 function renderStays(){
   const target=$('[data-cartlab-stages]');target.replaceChildren();
   const data=state.catalog?.stay_catalog;
   if(!data)return;
   for(const stage of data.plan.stages||[]){
     let choice=stageChoice(stage);
     state.stays[stage.key]=choice;
     const section=el('section','cartlab-stage');
     const stageTitle=el('div','cartlab-stage-title');
     stageTitle.append(el('h4','',stage.label),el('small','','Days '+(stage.day_offset+1)+'–'+(stage.day_offset+stage.nights)+' · '+stage.price_policy));
     section.append(stageTitle);
     const propertyHeading=el('p','cartlab-label','Choose hotel');
     const propertyList=el('div','cartlab-grid');
     const roomHeading=el('p','cartlab-label','Room / unit');
     const roomList=el('div','cartlab-grid');
     const rateHeading=el('p','cartlab-label','Room rate & meal plan');
     const rateList=el('div','cartlab-grid');
     const roomsLine=el('div','cartlab-rooms-line');
     roomsLine.append(el('span','','Rooms'));
     const roomsControl=stepper(choice.rooms, val=>{
       choice.rooms=val;renderStays();changed();
     });
     roomsLine.append(roomsControl.wrap);
     section.append(propertyHeading,propertyList,roomHeading,roomList,rateHeading,rateList,roomsLine);
     target.append(section);
     function fill(){
       const available=stage.properties.map(id=>data.properties[String(id)]).filter(Boolean);
       propertyList.replaceChildren();
       for(const p of available){
         propertyList.append(card(p.name,(p.units||[]).length+' room types',
           p.id===choice.property_id,()=>{
             choice.property_id=p.id;choice.unit_id=0;
             choice.unit_rate_id=0;choice.meal_term_id=0;fill();changed();
           },true));
       }
       const prop=data.properties[String(choice.property_id)];
       roomList.replaceChildren();
       for(const unit of prop?.units||[]){
         roomList.append(card(unit.name,(unit.rates||[]).length+' rates · Availability on request',
           unit.unit_id===choice.unit_id,()=>{
             choice.unit_id=unit.unit_id;choice.unit_rate_id=0;
             choice.meal_term_id=0;fill();changed();
           },true));
       }
       const unit=(prop?.units||[]).find(u=>u.unit_id===choice.unit_id);
       rateList.replaceChildren();
       for(const rate of unit?.rates||[]){
         rateList.append(card(rate.title,'Meals: '+(rate.meal_options||[]).length+' · Price by verified Unit Rate',
           rate.rate_id===choice.unit_rate_id,()=>{
             choice.unit_rate_id=rate.rate_id;choice.meal_term_id=rate.included_board_id||0;
             fill();changed();
           },true));
       }
       const selectedRate=(unit?.rates||[]).find(r=>r.rate_id===choice.unit_rate_id);
       if(selectedRate){
         const mealNames=new Map((selectedRate.meal_options||[]).map(m=>[Number(m.term_id),m.label||'Meal plan']));
         const mealIds=[...mealNames.keys()].filter(Boolean);
         if(mealIds.length){
           const label=el('label','cartlab-meal-label','Meal plan');
           const select=el('select','cartlab-select');
           select.append(new Option('Included / standard','0'));
           for(const id of mealIds)select.add(new Option(mealNames.get(id)+' · upgrade on inquiry',String(id)));
           select.value=String(choice.meal_term_id);
           select.addEventListener('change',()=>{choice.meal_term_id=Number(select.value);changed();});
           label.append(select);rateList.append(label);
         }
       }
     }
     fill();
   }
 }
 function renderExtras() {
   const target=$('[data-cartlab-extras]');target.replaceChildren();
   for(const e of state.catalog?.extras||[]){
     target.append(card(e.label,'+'+money(e.indicative_price)+' / person · QA reference',
       state.extras.includes(e.id),()=>{
         state.extras=state.extras.includes(e.id)
           ?state.extras.filter(id=>id!==e.id):[...state.extras,e.id];
         renderExtras();changed();
       },false));
   }
 }
 function renderTransports() {
   const target=$('[data-cartlab-transports]');target.replaceChildren();
   for (const t of state.catalog?.stay_catalog?.internal_transportations || []) {
     const name=t.name||('Transfer #'+t.id);
     target.append(card(name,'+'+(t.mode||'transfer')+' · supplier quote needed',
       state.transports.includes(t.id),()=>{
         state.transports=state.transports.includes(t.id)
            ?state.transports.filter(id=>id!==t.id):[...state.transports,t.id];
         renderTransports();changed();
       },false));
   }
   if(!target.children.length)target.append(el('p','','No linked transfers for this Tour.'));
 }
 function summary(text,total){
   const target=$('[data-cartlab-summary-items]');target.replaceChildren();
   const show=(label,value)=>{
     const r=el('div','cartlab-summary-row');
     r.append(el('span','',label),el('strong','',value));target.append(r);
   };
   const rate=readRate();
   show('Tour rate',rate?rate.package+' · '+rate.season:'Not selected');
   show('Departure',state.date||'Select date');
   show('Guests',state.adults+' adults · '+state.children+' children');
   show('Accommodation',Object.values(state.stays).filter(x=>x.unit_rate_id).length+
     ' of '+(state.catalog?.stay_catalog?.plan?.stages||[]).length+' stages selected');
   show('Extras',state.extras.length+' selected');
   show('Transfers',state.transports.length+' requested');
   $('[data-cartlab-total]').textContent=typeof total==='number'?money(total):'—';
   if(text)msg(text);
 }
 let tick;
 function changed(){
   persist();emit();
   summary('Calculating QA reference…',null);
   clearTimeout(tick);
   tick=setTimeout(()=>requestPreview(false),350);
 }
 function payload(){
   return {tour_id:config.tourId,rate_id:state.rate,date:state.date,
     adults:state.adults,children:state.children,child_ages:state.childAges,
     stays:state.stays,extras:state.extras,transport_ids:state.transports};
 }
 async function requestPreview(showErrors=true){
   if(!state.date){summary('Choose a departure date.',null);return;}
   if(state.childAges.some(x=>x===null)){summary('Choose the age of each child.',null);return;}
   const seq=++state.serial;
   try{
     const result=await api('preview',payload());
     if(seq!==state.serial)return;
     summary('Reference only: included stays '+money(result.stay_included_reference)+
       ', inquiry stays '+money(result.stay_inquiry_reference)+
       '. Final pricing and availability require approval.',result.indicative_subtotal);
   }catch(e){if(seq!==state.serial)return;summary(showErrors?e.message:'Complete every accommodation stage to see an estimate.',null);}
 }
 async function load(){
   msg('Loading real accommodation options…');$('[data-cartlab-stages]').replaceChildren();
   const version=++state.serial;
   try{
     const catalog=await api('catalog?rate_id='+encodeURIComponent(state.rate));
     if(version!==state.serial)return;
     state.catalog=catalog;
     renderRates();renderStays();renderExtras();renderTransports();renderChildAges();
     persist();summary('Select options to preview an estimate.',null);
   }catch(e){msg(e.message);}
 }
 $$('[data-step]').forEach(btn=>btn.addEventListener('click',()=>{
   const [field,step]=btn.dataset.step.split(':');
   const min=field==='adults'?1:0;
   state[field]=Math.max(min,Math.min(20,state[field]+Number(step)));
   $('[data-count="'+field+'"]').textContent=String(state[field]);
   if(field==='children')renderChildAges();
   changed();
 }));
 $('[data-cartlab-date]').addEventListener('change',e=>{state.date=e.target.value;changed();});
 $('[data-cartlab-preview]').addEventListener('click',()=>requestPreview(true));
 const stored=(()=>{try{const x=JSON.parse(sessionStorage.getItem(storageKey)||'null');
     return x?.savedAt && Date.now()-x.savedAt<86400000?x:null;}catch(e){return null;}})();
 if(stored&&typeof stored.rate==='number')state.rate=stored.rate;
 if(stored&&Number.isInteger(stored.adults))state.adults=stored.adults;
 if(stored&&Number.isInteger(stored.children))state.children=stored.children;
 if(stored&&Array.isArray(stored.childAges))state.childAges=stored.childAges;
 if(stored&&Array.isArray(stored.extras))state.extras=stored.extras;
 if(stored&&Array.isArray(stored.transports))state.transports=stored.transports;
 if(stored&&stored.stays&&typeof stored.stays==='object')state.stays=stored.stays;
 if(stored&&typeof stored.date==='string')state.date=stored.date;
 $('[data-count="adults"]').textContent=String(state.adults);
 $('[data-count="children"]').textContent=String(state.children);
 $('[data-cartlab-date]').value=state.date;
 load();
})();
