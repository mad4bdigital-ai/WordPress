/**
 * MAD4B Contextual Help Registry — presentation only.
 * No network until a user opens a Pricing Lab dimension. No writes, no
 * business-rule inference, no mutation of JetEngine values or native forms.
 */
(()=>{
'use strict';
const config=window.MAD4BPricingHelpData;
if(!config||!config.messages)return;
const messages=config.messages;
const limits={mobile:460,panel:370};
const cache=new Map();
const inFlight=new Map();
const decorated=new WeakSet();
let active=null;
let nextId=0;
const text=(value)=>String(value??'').trim();
const create=(tag,cls,content)=>{
 const el=document.createElement(tag);
 if(cls)el.className=cls;
 if(content!==undefined)el.textContent=text(content);
 return el;
};
const getMessage=(key)=>text(messages[key]||messages.source||'');
const ellipsis=(value,max=700)=>text(value).slice(0,max);
const close=()=>{
 if(!active)return;
 active.panel.hidden=true;
 active.button.setAttribute('aria-expanded','false');
 active.pinned=false;
 active=null;
};
function position(item){
 if(!item||item.panel.hidden)return;
 const button=item.button.getBoundingClientRect();
 const narrow=window.innerWidth<=limits.mobile;
 const width=Math.min(limits.panel,window.innerWidth-24);
 item.panel.style.width=width+'px';
 item.panel.style.left=Math.max(12,
   Math.min(window.innerWidth-width-12,
     narrow?(window.innerWidth-width)/2:button.left-12))+'px';
 item.panel.style.top='12px';
 item.panel.style.maxHeight=Math.max(110,window.innerHeight-24)+'px';
 const height=item.panel.getBoundingClientRect().height;
 const below=button.bottom+8;
 const above=button.top-height-8;
 const y=below+height<=window.innerHeight-12?below:
   above>=12?above:Math.max(12,Math.min(below,window.innerHeight-height-12));
 item.panel.style.top=y+'px';
}
function show(item,pin=false){
 if(active&&active!==item)close();
 active=item;
 item.pinned=item.pinned||pin;
 item.panel.hidden=false;
 item.button.setAttribute('aria-expanded','true');
 position(item);
}
function attach(host,key,extra=null){
 if(!(host instanceof Element))return null;
 const note=extra&&typeof extra==='object'?extra:{};
 const fallback=getMessage(key);
 const help=ellipsis(note.text||fallback);
 if(!help)return null;
 const title=ellipsis(note.title||'Help',160);
 // The host keeps its existing native DOM. No reparenting and no extra
 // inputs/forms. Help has no money-related action.
 const box=create('span','mad4b-help-anchor');
 const button=create('button','mad4b-help-trigger','i');
 button.type='button';
 button.setAttribute('aria-label','Explain '+title);
 button.title='Explain '+title;
 button.setAttribute('aria-expanded','false');
 const panel=create('span','mad4b-help-panel');
 panel.id='mad4b-help-note-'+(++nextId);
 panel.setAttribute('role','tooltip');
 panel.hidden=true;
 const name=create('strong','mad4b-help-title',title);
 const body=create('span','mad4b-help-text',help);
 panel.append(name,body);
 if(note.source==='generic_fallback'){
   panel.append(create('small','mad4b-help-source',
     getMessage('no_description')));
 }
 button.setAttribute('aria-describedby',panel.id);
 box.append(button,panel);
 host.append(box);
 const item={button,panel,pinned:false};
 button.addEventListener('click',event=>{
   event.preventDefault();event.stopPropagation();
   if(active===item&&item.pinned){close();return;}
   show(item,true);
 });
 button.addEventListener('mouseenter',()=>show(item,false));
 button.addEventListener('mouseleave',()=>{
   if(active===item&&!item.pinned&&!button.matches(':focus'))close();
 });
 button.addEventListener('focus',()=>show(item,false));
 button.addEventListener('blur',()=>{
   if(active===item&&!item.pinned)close();
 });
 return item;
}
document.addEventListener('pointerdown',event=>{
 if(active&&!active.button.contains(event.target)&&
   !active.panel.contains(event.target))close();
});
document.addEventListener('keydown',event=>{
 if(event.key==='Escape'&&active){
   const item=active;close();item.button.focus();event.preventDefault();
 }
});
window.addEventListener('resize',()=>{if(active)position(active)},{passive:true});
window.addEventListener('scroll',()=>{if(active)position(active)},{
 capture:true,passive:true
});

function attachField(label,key,note=null){
 if(!(label instanceof Element)||decorated.has(label))return null;
 const result=attach(label,key,note);
 if(result){
   decorated.add(label);
   label.classList.add('mad4b-help-has-trigger');
 }
 return result;
}
function fieldWrapper(control){
 return control?.closest('.mad4b-ws-policy-control,.mad4b-pl-control')||null;
}
function attachControl(control,key,note=null){
 const label=fieldWrapper(control);
 if(!label)return null;
 return attachField(label,key,note);
}
function choiceHint(node,source,options){
 if(!(node instanceof HTMLSelectElement)||decorated.has(node))return;
 const map=options||{};
 const wrapper=fieldWrapper(node)||node.parentElement;
 if(!(wrapper instanceof Element))return;
 const detail=create('small','mad4b-help-selected');
 detail.setAttribute('role','status');
 detail.setAttribute('aria-live','polite');
 wrapper.append(detail);
 function render(){
  const selected=[...node.selectedOptions];
  const descriptions=selected.slice(0,3).map(o=>{
    const hint=map[o.value];
    if(hint?.text)return ellipsis(hint.text,280);
    if(source==='templates'){
      const code=o.value.toLowerCase();
      const strategy=['percent','fixed','delta','floor','cap','unchanged']
        .find(name=>code==='base-'+name||code.endsWith('-'+name));
      if(strategy&&messages['template.'+strategy])
        return messages['template.'+strategy];
    }
    return o.textContent?getMessage(source):'';
  }).filter(Boolean);
  detail.textContent=descriptions.length
    ?descriptions.join(' · ')+(selected.length>3
      ?' · +'+(selected.length-3)+' more selected':''):'';
 }
 node.addEventListener('change',render);
 // Pricing Lab populates native templates asynchronously after this
 // wrapper is first built. Refresh the selected-option explanation without
 // changing the select or creating a second control.
 const observer=new MutationObserver(render);
 observer.observe(node,{childList:true,subtree:true});
 decorated.add(node);
 render();
 return {refresh:render,element:detail};
}
function decorateNative(root,fields=[]){
 if(!(root instanceof Element))return;
 const lookup=new Map((fields||[]).map(f=>[text(f.name),f]));
 for(const el of root.querySelectorAll('.cx-control[data-control-name]')){
   const id=text(el.dataset.controlName);
   if(!id||decorated.has(el))continue;
   const title=el.querySelector('.cx-control__title');
   if(!title)continue;
   const f=lookup.get(id)||{};
   const native=ellipsis(f.guidance?.text||f.guidance?.context||'',400);
   const mapped=config.fields?.[id]||id;
   const explanation=getMessage(mapped);
   if(!native&&!explanation)continue;
   const value=native&&explanation&&!native.includes(explanation)
     ?explanation+' '+native:native||explanation;
   attachField(title,mapped,{
     title:text(f.label||title.textContent||id),
     text:value,
     source:native?'jetengine_guidance':'shared_help'
   });
   decorated.add(el);
 }
}
async function dimension(id){
 if(!/^(taxonomy|meta|metabox):[a-z0-9_-]{2,80}$/.test(id))
   throw Error('Invalid JetEngine dimension');
 if(cache.has(id))return cache.get(id);
 if(inFlight.has(id))return inFlight.get(id);
 const task=(async()=>{
  const params=new URLSearchParams({
    action:config.action,nonce:config.nonce,dimension_id:id
  });
  const response=await fetch(config.ajax_url,{
    method:'POST',credentials:'same-origin',
    headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},
    body:params.toString()
  });
  const json=await response.json();
  if(!response.ok||!json.success)throw Error(
    json?.data?.message||'Help was not available');
  const help=json.data;
  cache.set(id,help);
  return help;
 })();
 inFlight.set(id,task);
 try{return await task;}finally{inFlight.delete(id);}
}
const fieldKey=(raw)=>{
 const n=text(raw).toLowerCase();
 const alias={
   kind:'rate_kind',parent_id:'parent_id',
   amount:'amount',currency:'currency',
   price_unit_tour:'price_unit',price_unit_accommodation:'price_unit',
   price_unit_transport:'price_unit',
   pricing_rule_kind:'pricing_rule_kind',
   valid_from:'valid_from',valid_to:'valid_to',
   default_currency:'default_currency',
   pricing_engine_enabled:'pricing_engine_enabled'
 };
 return alias[n]||n;
};
function attachRateGenerator(){
 const root=document.getElementById('shared-rate-generation-fields');
 if(!root)return;
 const h2=[...document.querySelectorAll('h2')]
   .find(n=>/rate generation/i.test(n.textContent));
 if(h2&&!decorated.has(h2))attachField(h2,'editor',
   {title:'Rate Generator',text:getMessage('editor')});
 for(const tr of root.querySelectorAll('tr')){
   const heading=tr.querySelector('th[scope=row]');
   const control=tr.querySelector('td input[name],td select[name]');
   if(!heading||!control)continue;
   const key=fieldKey(control.getAttribute('name'));
   const description=tr.querySelector('td p.description');
   const value=getMessage(key);
   if(!value&&!description?.textContent)continue;
   attachField(heading,key,{
     title:heading.textContent,
     text:value+(description?.textContent?' '+description.textContent:''),
     source:'native_rate_generator'
   });
   if(control.tagName==='SELECT' && (
       key==='currency'||key==='rate_kind'||key==='price_unit' ||
       key==='pricing_rule_kind')){
     const entries={};
     for(const option of control.options){
       const ref=key+'.'+option.value;
       if(messages[ref])entries[option.value]={text:messages[ref]};
     }
     choiceHint(control,key,entries);
   }
 }
}
function attachPricingLab(root){
 if(!(root instanceof Element))return;
 const anchors=[
   ['.mad4b-pl-panel h2', 'editor'],
   ['.mad4b-pl-rule-head','targeting'],
 ];
 for(const [selector,key] of anchors)
  for(const el of root.querySelectorAll(selector)){
    if(decorated.has(el))continue;
    attachField(el,key,{title:el.textContent,text:getMessage(key)});
  }
 const labelMap=[
  [/preset name|business justification/i,'editor'],
  [/native.*template/i,'templates'],
  [/price policy value|price multiplier/i,'multiplier'],
  [/currency/i,'currency'],
  [/audience/i,'audience'],[/occupancy/i,'occupancy'],
  [/group matching|combine rules/i,'match'],
  [/target display languages|market countries|group name/i,'groups'],
  [/existing field values|rule values/i,'targeting'],
  [/within this condition/i,'match'],
 ];
 for(const label of root.querySelectorAll('.mad4b-pl-control')){
   if(decorated.has(label))continue;
   const title=label.querySelector(':scope > span')?.textContent||'';
   const matched=labelMap.find(([regex])=>regex.test(title));
   if(!matched)continue;
   const key=matched[1];
   attachField(label,key,{title,text:getMessage(key)});
   const input=label.querySelector('select');
   if(input && key==='templates'){
     const variants={};
     for(const option of input.options){
       const code=option.value.toLowerCase();
       const strategy=['percent','fixed','delta','floor','cap','unchanged']
         .find(name=>code==='base-'+name||code.endsWith('-'+name));
       if(strategy && messages['template.'+strategy])
         variants[option.value]={text:messages['template.'+strategy]};
     }
     choiceHint(input,'templates',variants);
   }
 }
 for(const row of root.querySelectorAll('.mad4b-pl-rule')){
   const field=row.querySelector('.mad4b-pl-rule-head select');
   const values=row.querySelector('.mad4b-pl-rule-body select[multiple]');
   if(!field||!values||decorated.has(values))continue;
   const id=field.value;
   if(!id)continue;
   // Read the descriptions only for the currently opened rule. Never
   // fetch 18 dimensions or 100+ terms during admin page load.
   decorated.add(values);
   dimension(id).then(result=>{
     if(!row.isConnected||field.value!==id)return;
     decorated.delete(values);
     const selected=choiceHint(values,'term',result.options);
     const label=values.closest('label');
     if(label)attachField(label,'targeting',result.dimension);
     if(selected)selected.refresh();
   }).catch(()=>{
     // Error does not interrupt targeting or saving; generic guidance
     // remains available for this field.
     decorated.delete(values);choiceHint(values,'term',{});
   });
 }
}
function decorateCurrencyConfig(){
 const root=document.querySelector('.mad4b-currency-config');
 if(!root)return;
 for(const section of root.querySelectorAll('.mad4b-currency-panel')){
   const heading=section.querySelector('h2');
   if(!heading)continue;
   const name=heading.textContent.toLowerCase();
   const key=name.includes('choice')?'currency_picker':
     name.includes('eligibility')?'currency_policy':'currency';
   attachField(heading,key,{title:heading.textContent,text:getMessage(key)});
 }
 for(const label of root.querySelectorAll('label:has(input[name="picker"]),label:has(input[name="mode"])')){
   const radio=label.querySelector('input[type=radio]');
   if(!radio)continue;
   const key=radio.name==='picker'
     ?'currency_picker.'+radio.value:'currency_policy.'+radio.value;
   attachField(label,key,{title:label.textContent,text:getMessage(key)});
 }
 const currencySearchLabel=root.querySelector('.mad4b-currency-filter > label');
 if(currencySearchLabel && !decorated.has(currencySearchLabel))
   attachField(currencySearchLabel,'currency',{
     title:'Available currency values',text:getMessage('currency')
   });
 for(const label of root.querySelectorAll('.mad4b-currency-option')){
   if(decorated.has(label))continue;
   const code=label.querySelector('input[data-currency-code]')?.value;
   if(!code)continue;
   // Keep 148 checkboxes visually clean, while making individual values
   // discoverable on hover and keyboard focus without dozens of extra i's.
   label.title=code+': '+getMessage('currency');
   decorated.add(label);
 }
}
function decorateWorkspace(){
 const root=document.querySelector('.mad4b-pricing-workspace-page');
 if(!root)return;
 const main=root.querySelector('h1');
 if(main)attachField(main,'editor',{title:'Pricing Workspace',text:getMessage('editor')});
 for(const card of root.querySelectorAll('.mad4b-pricing-unified-card')){
   const heading=card.querySelector('h2');
   const label=heading?.textContent||'';
   const key=/policy bindings/i.test(label)?'bindings':
     /currencies/i.test(label)?'currency':
     /pricing lab/i.test(label)?'impact':
     /rate generator/i.test(label)?'rate_kind':'editor';
   if(heading)attachField(heading,key,{title:label,text:getMessage(key)});
 }
 const policy=document.querySelector('#mad4b-policy-binding-root');
 if(policy){
   const heading=policy.closest('.wrap')?.querySelector('h1');
   if(heading)attachField(heading,'bindings',{
     title:'Policy Bindings',text:getMessage('bindings')
   });
 }
}
function bootstrap(){
 decorateCurrencyConfig();decorateWorkspace();
 const rate=document.getElementById('shared-rate-generation-fields');
 if(rate)attachRateGenerator();
 const bindings=document.getElementById('mad4b-policy-binding-root');
 if(bindings){
   const heading=bindings.closest('.wrap')?.querySelector('h1');
   if(heading)attachField(heading,'bindings',{
     title:'Policy Bindings',text:getMessage('bindings')
   });
 }
 const lab=document.getElementById('mad4b-pricing-lab-root');
 if(lab){
   attachPricingLab(lab);
   const observer=new MutationObserver(records=>{
     if(records.some(r=>[...r.addedNodes].some(n=>n.nodeType===1
       &&!(n.classList?.contains('mad4b-help-anchor')||
         n.closest?.('.mad4b-help-anchor')))))
       attachPricingLab(lab);
   });
   observer.observe(lab,{childList:true,subtree:true});
 }
}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',bootstrap,{once:true});
else bootstrap();
window.MAD4BPricingHelp=Object.freeze({
  attach,attachField,attachControl,choiceHint,decorateNative,
  dimension,attachPricingLab,message:getMessage,config
});
})();
