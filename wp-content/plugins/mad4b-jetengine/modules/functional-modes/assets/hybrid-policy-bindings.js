(()=>{
'use strict';
const cfg=window.MAD4BPolicyBindings,root=document.getElementById('mad4b-policy-binding-root');
if(!cfg||!root)return;
const E=(tag,text='',cls='')=>{
 const el=document.createElement(tag);
 if(cls)el.className=cls;
 if(text)el.textContent=text;
 return el;
};
const wrap=E('div','','mad4b-hybrid-layout');
const controls=E('section','','mad4b-hybrid-panel');
const impact=E('section','','mad4b-hybrid-panel');
const resolution=E('section','','mad4b-hybrid-panel mad4b-hybrid-resolution');
root.replaceChildren(wrap);wrap.append(controls,impact,resolution);
controls.append(E('h2','Bind central policies to native metadata'));
impact.append(E('h2','Policy inheritance preview'));
const info=E('p','No live prices, checkout rules or campaigns are changed by these references.','mad4b-hybrid-note');
controls.append(info);
const line=(parent,label,control)=>{
 const row=E('label','','mad4b-hybrid-field');
 row.append(E('span',label),control);parent.append(row);
 return control;
};
const choose=(opts)=>{
 const select=E('select');
 for(const [value,text] of opts){
   const option=E('option',text);option.value=value;
   select.append(option);
 }
 return select;
};
const scope=line(controls,'Binding scope',choose([
 ['global','Company global policy'],
 ['term','Taxonomy term default'],
 ['tour','Tour-specific override'],
 ['rate','Tour Rate override']
]));
const taxonomy=line(controls,'Taxonomy',choose([]));
const target=line(controls,'Native Term / Tour / Rate ID',E('input'));
target.type='number';target.min='1';target.step='1';
target.placeholder='Choose target or enter ID';
const lookup=E('div','','mad4b-hybrid-lookup');
const search=E('input');search.type='search';search.placeholder='Search names';
const searchBtn=E('button','Find target','button');searchBtn.type='button';
const choices=choose([['','Search available records']]);
lookup.append(search,searchBtn,choices);controls.append(lookup);
const load=E('button','Load references','button button-secondary');load.type='button';
const policies=E('fieldset','','mad4b-hybrid-policies');
policies.append(E('legend','Versioned Pricing Lab policies'));
const actions=E('div','','mad4b-hybrid-actions');
const save=E('button','Save reference assignment','button button-primary');
const preview=E('button','Preview inheritance','button');
save.type=preview.type='button';actions.append(load,save,preview);
const notice=E('p','','mad4b-hybrid-status');notice.setAttribute('role','status');
controls.append(policies,actions,notice);
const summary=E('div','Choose a Tour or Rate for the policy resolution chain.','mad4b-hybrid-preview');
impact.append(summary);
let catalog=null,loaded=null,busy=false;
const announce=(message,error=false)=>{
 notice.textContent=message;notice.classList.toggle('is-error',error);
};
const request=async(op,args={})=>{
 const params=new URLSearchParams({action:cfg.action,nonce:cfg.nonce,operation:op});
 for(const [name,value] of Object.entries(args))
   params.set(name,typeof value==='string'?value:String(value));
 const response=await fetch(cfg.ajax,{method:'POST',credentials:'same-origin',
   headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},
   body:params.toString()});
 const json=await response.json();
 if(!response.ok||!json.success)
   throw new Error(json?.data?.message||'Server rejected policy request');
 return json.data;
};
const current=()=>({scope:scope.value,id:scope.value==='global'?0:Number(target.value)||0});
const updateScope=()=>{
 const isTerm=scope.value==='term',global=scope.value==='global';
 taxonomy.closest('label').hidden=!isTerm;
 target.closest('label').hidden=global;
 lookup.hidden=global;
 save.disabled=true;preview.disabled=true;
 loaded=null;policies.querySelectorAll('input[type=checkbox]').forEach(x=>x.checked=false);
 summary.replaceChildren(E('p','Load a Tour or Rate to see inherited policy references.'));
 announce('Select a target and load its existing policy references.');
};
const renderPolicies=ids=>{
 policies.querySelectorAll('label').forEach(x=>x.remove());
 const presetList=catalog?.policies||[];
 if(!presetList.length){
  policies.append(E('p','No Pricing Lab Drafts yet. Create one first, then return here.'));
 }
 for(const p of presetList){
  const label=E('label','','mad4b-hybrid-policy');
  const cb=E('input');cb.type='checkbox';cb.value=p.id;
  cb.checked=ids.includes(p.id);
  label.append(cb,E('strong',p.title||p.id),
    E('small','Revision '+p.revision+' · '+p.status));
  policies.append(label);
 }
};
const renderResolution=data=>{
 summary.replaceChildren();
 if(!data){summary.append(E('p','No inheritance result available.'));return;}
 summary.append(E('h3',data.status.replaceAll('_',' ')));
 if(data.effective){
  summary.append(E('p','Highest-priority policy: '+data.effective.title+
    ' · '+data.effective.source));
 }else{
  summary.append(E('p',data.status==='existing_pricing_default'
   ?'No applicable reference: existing pricing defaults remain authoritative.'
   :'No single policy can be chosen; review conflicts before using this scope.'));
 }
 for(const level of data.levels||[]){
  const section=E('details','','mad4b-hybrid-tier');
  section.open=level.policies?.length>0;
  const title=E('summary',level.source.replaceAll('_',' ')+
    ' · '+(level.policies?.length||0)+' policies');
  section.append(title);
  const list=E('div','','mad4b-hybrid-tier-content');
  if(!level.policies?.length)list.append(E('p','No attached active references.'));
  for(const item of level.policies||[])
    list.append(E('p',item.title+' · '+item.status+' · revision '+item.revision));
  section.append(list);summary.append(section);
 }
 if(data.conflicts?.length){
  const warning=E('div','','mad4b-hybrid-warning');
  warning.append(E('strong',data.conflicts.length+' conflict(s) require review'));
  for(const c of data.conflicts.slice(0,8))
    warning.append(E('p',c.type.replaceAll('_',' ')+
      (c.source?' · '+c.source:'')));
  summary.append(warning);
 }
 summary.append(E('small','Read-only: no checkout or campaign activation.'));
};
// Resolution Settings: all settings are company scoped and affect
// Policy Bindings *preview* only. Native prices, rate overrides and stop-sale
// are never changed by this order.
const levelLabels={
 rate_override:'Rate-specific policy reference',
 tour_override:'Tour-specific policy reference',
 taxonomy_term_policy:'Taxonomy inherited policy',
 global_policy:'Company global fallback'
};
resolution.append(E('h2','Inheritance order & conflict rules'));
resolution.append(E('p','Move a source up/down to change precedence in the hybrid preview. Native Rate prices and Stop Sale always keep their own protections.','mad4b-hybrid-note'));
const orderList=E('ol','','mad4b-hybrid-order');
const taxList=E('ol','','mad4b-hybrid-order mad4b-hybrid-tax-order');
const taxSection=E('details','','mad4b-hybrid-tax-section');
taxSection.append(E('summary','Taxonomy priority when multiple categories match'),taxList);
const collision=line(resolution,'Multiple Taxonomies',choose([
 ['strict','Require one unambiguous matching policy (default)'],
 ['ranked','Prefer the highest-ranked matching taxonomy; ties still conflict']
]));
const saveResolution=E('button','Save inheritance rules','button button-secondary');
saveResolution.type='button';
const resetResolution=E('button','Restore safe defaults','button');
resetResolution.type='button';
const resActions=E('div','','mad4b-hybrid-actions');
resActions.append(saveResolution,resetResolution);
const resStatus=E('p','','mad4b-hybrid-status');
resStatus.setAttribute('role','status');
resolution.insertBefore(orderList,collision.closest('label'));
resolution.insertBefore(taxSection,collision.closest('label'));
resolution.append(resActions,resStatus);
let resolverConfig=null;
let priorityDirty=false;
const renderOrdered=(items,container,labels)=>{
 container.replaceChildren();
 for(let i=0;i<items.length;i++){
  const name=items[i];
  const row=E('li','','mad4b-hybrid-priority-row');
  row.append(E('strong',labels[name]||name));
  const commands=E('span','','mad4b-hybrid-priority-buttons');
  for(const [step,text] of [[-1,'Up'],[1,'Down']]){
   const btn=E('button',text,'button');
   btn.type='button';
   btn.disabled=(step===-1&&i===0)||(step===1&&i===items.length-1);
   btn.setAttribute('aria-label','Move '+(labels[name]||name)+' '+text.toLowerCase());
   btn.addEventListener('click',()=>{
     [items[i],items[i+step]]=[items[i+step],items[i]];
     priorityDirty=true;renderResolver();
   });
   commands.append(btn);
  }
  row.append(commands);container.append(row);
 }
};
const renderResolver=()=>{
 if(!resolverConfig)return;
 renderOrdered(resolverConfig.order,orderList,levelLabels);
 renderOrdered(resolverConfig.taxonomy_order,taxList,{});
 collision.value=resolverConfig.taxonomy_collision;
 resStatus.textContent=(priorityDirty?'Changes not saved · ':'')+
   'Version '+resolverConfig.revision+
   ' · '+(resolverConfig.saved?'company setting':'inherited safe default')+
   ' · preview only';
};
collision.addEventListener('change',()=>{
 if(!resolverConfig)return;
 resolverConfig.taxonomy_collision=collision.value;
 priorityDirty=true;renderResolver();
});
resetResolution.addEventListener('click',()=>{
 if(!resolverConfig)return;
 resolverConfig.order=['rate_override','tour_override','taxonomy_term_policy','global_policy'];
 resolverConfig.taxonomy_order=[...(catalog?.taxonomies||[])];
 resolverConfig.taxonomy_collision='strict';
 priorityDirty=true;renderResolver();
});
saveResolution.addEventListener('click',async()=>{
 if(!resolverConfig||!priorityDirty)return;
 saveResolution.disabled=true;
 try{
  const value=await request('save_resolution',{
   config:JSON.stringify(resolverConfig),revision:resolverConfig.revision,
   confirm:'save_preview_resolution_only'
  });
  resolverConfig=value;priorityDirty=false;renderResolver();
  if(loaded?.resolution)
    renderResolution(await request('resolve',current()));
 }catch(error){resStatus.textContent='Could not save: '+error.message;
  resStatus.classList.add('is-error');
 }finally{saveResolution.disabled=false;}
});

const run=async(op)=>{
 if(busy)return;
 busy=true;
 try{
  const src=current();
  if(src.scope!=='global'&&!src.id)throw Error('Choose a valid native ID.');
  if(op==='read'){
   const value=await request('read',src);
   loaded=value;renderPolicies(value.ids||[]);
   save.disabled=false;preview.disabled=!['tour','rate'].includes(src.scope);
   announce('Loaded '+value.ids.length+' policy reference(s) · revision '+value.revision);
   if(value.resolution)renderResolution(value.resolution);
  }else if(op==='save'){
   if(!loaded||loaded.scope!==src.scope||loaded.id!==src.id)
     throw Error('Load the target before saving.');
   const ids=[...policies.querySelectorAll('input[type=checkbox]:checked')].map(x=>x.value);
   const saved=await request('save',{...src,
      revision:loaded.revision,ids:JSON.stringify(ids),
      confirm:'bind_policy_references_only'});
   loaded=saved;
   announce('Saved '+saved.ids.length+' reference(s) · revision '+saved.revision+
      ' · commercial prices unchanged');
   if(['tour','rate'].includes(src.scope))
     renderResolution(await request('resolve',src));
  }else if(op==='resolve'){
   if(!loaded)throw Error('Load references first.');
   renderResolution(await request('resolve',src));
  }else if(op==='search'){
   const found=await request('search',{
      scope:src.scope,q:search.value,taxonomy:taxonomy.value});
   choices.replaceChildren(E('option','Select a matching record'));
   choices.firstChild.value='';
   for(const item of found.items){
     const opt=E('option',item.label);opt.value=item.id;choices.append(opt);
   }
   announce('Found '+found.items.length+' matching records.');
  }
 }catch(error){announce(String(error.message),true);}
 finally{busy=false;}
};
scope.addEventListener('change',()=>{target.value='';updateScope();});
taxonomy.addEventListener('change',()=>{target.value='';loaded=null;save.disabled=true;});
target.addEventListener('input',()=>{loaded=null;save.disabled=true;preview.disabled=true;});
searchBtn.addEventListener('click',()=>run('search'));
choices.addEventListener('change',()=>{target.value=choices.value;loaded=null;save.disabled=true;});
load.addEventListener('click',()=>run('read'));
save.addEventListener('click',()=>run('save'));
preview.addEventListener('click',()=>run('resolve'));
(async()=>{
 try{
  catalog=await request('catalog');
  resolverConfig=catalog.resolution_config||null;
  if(resolverConfig)renderResolver();
  for(const name of catalog.taxonomies||[]){
   const opt=E('option',name);opt.value=name;taxonomy.append(opt);
  }
  scope.value=cfg.initial_scope;
  target.value=cfg.initial_id||'';
  updateScope();
  if(scope.value==='global'||cfg.initial_id)await run('read');
 }catch(error){announce('Failed to load the policy registry: '+error.message,true);}
})();
})();
