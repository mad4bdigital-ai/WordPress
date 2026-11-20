/**
 * Generic JetEngine Functional Mode: source-cards.
 * Never creates or mirrors an editable field. Only styles original
 * JetEngine repeater controls and reads their unsaved values for preview.
 */
(()=>{
'use strict';
const settings=window.MAD4BProviderPriceSourcesConfig;
if(!settings)return;
const make=(tag,text='',className='')=>{
  const el=document.createElement(tag);
  if(text)el.textContent=text;
  if(className)el.className=className;
  return el;
};
const press=(title,handler)=>{
  const el=make('button',title,'button');
  el.type='button';el.addEventListener('click',handler);return el;
};
const input=(type,attrs={})=>{
  const el=document.createElement('input');el.type=type;
  Object.entries(attrs).forEach(([k,v])=>el.setAttribute(k,String(v)));
  return el;
};
const labeled=(label,control)=>{
  const wrap=make('label','','mad4b-source-preview-field');
  wrap.append(make('span',label),control);return wrap;
};
const select=(items)=>{
  const el=document.createElement('select');
  for(const [value,title] of items){
    const option=make('option',title);option.value=String(value);el.append(option);
  }
  return el;
};
const ids=(value,key)=>{
  if(value===null||value===undefined||value==='')return [];
  const chunks=String(value).trim().split(/[\s,;]+/).filter(Boolean);
  if(chunks.length>120||chunks.some(v=>!/^[1-9]\d*$/.test(v)
      ||!Number.isSafeInteger(Number(v))))
    throw Error(key+' requires valid WordPress numeric IDs.');
  return [...new Set(chunks.map(Number))];
};
const fieldNames={
  providers:'mad4b_provider_catalog_rows',
  rules:'mad4b_provider_source_policy_rows'
};
function nativeRoot(field){
  return document.querySelector('.jet-engine-options-page-wrap .cx-control[data-control-name="'+field+'"]');
}
function rows(field){
  const control=nativeRoot(field);
  if(!control)throw Error('Native JetEngine '+field+' is not available. Reload the Pricing Controls page.');
  const grouped=new Map();
  // Native repeater inputs belong to the existing JetEngine save form.
  // Do not clone, re-parent or attach new input names.
  const selector='input[name],select[name],textarea[name]';
  for(const el of control.querySelectorAll(selector)){
    if(el.disabled||el.type==='button'||el.type==='submit')continue;
    const name=el.name||'';
    if(!name.startsWith(field+'['))continue;
    const rest=name.slice(field.length);
    // CX native repeater rows are named field[item-0][subfield]
    // (not field[0][subfield]); support both JetEngine generations.
    const match=rest.match(/^\[(?:item-)?(\d+)\]\[([a-zA-Z0-9_-]+)\](?:\[\])?$/);
    if(!match)continue;
    const index=Number(match[1]),key=match[2];
    if(!grouped.has(index))grouped.set(index,{});
    const value=grouped.get(index);
    if((el.type==='checkbox'||el.type==='radio')){
      if(el.checked)value[key]=el.value===''?'true':el.value;
      else if(!(key in value))value[key]='false';
    }else if(el.multiple){
      value[key]=[...el.selectedOptions].map(o=>o.value).join(',');
    }else if(!(key in value)||el.type!=='hidden'){
      value[key]=el.value;
    }
  }
  return [...grouped.entries()].sort((a,b)=>a[0]-b[0]).map(([,value])=>value)
    .filter(value=>Object.entries(value).some(([k,v])=>
      k!=='preview_enabled'&&String(v).trim()!==''));
}
function parseNative(){
  return {
    providers:rows(fieldNames.providers).map(row=>({
      id:String(row.provider_id||'').trim(),
      name:String(row.provider_name||'').trim(),
      linked_rate_ids:ids(row.linked_rate_ids||'','Provider Rate IDs')
    })),
    rules:rows(fieldNames.rules).map(row=>({
      id:String(row.rule_id||'').trim(),
      provider_id:String(row.provider_id||'').trim(),
      scope:String(row.scope||'').trim(),
      priority:row.priority===''?null:Number(row.priority),
      source:String(row.source||'').trim(),
      fallback:String(row.fallback||'next_valid').trim(),
      taxonomy:String(row.taxonomy||'').trim(),
      term_ids:ids(row.term_ids||'','Group term IDs'),
      tour_ids:ids(row.tour_ids||'','Tour IDs'),
      rate_ids:ids(row.rate_ids||'','Rate IDs'),
      preview_enabled:['true','1','yes','on'].includes(String(row.preview_enabled||'false').toLowerCase())
    }))
  };
}
const genericMode=({nativeControl,field})=>{
  if(!nativeControl||nativeControl.dataset.mad4bSourceCards)return;
  nativeControl.dataset.mad4bSourceCards='true';
  nativeControl.classList.add('mad4b-source-cards-mode');
  nativeControl.setAttribute('data-mad4b-native-owner','JetEngine');
  const title=nativeControl.querySelector('.cx-control__title');
  if(title){
    const hint=make('small','Original JetEngine fields · native Save changes','','');
    // Keep title untouched. No added input or duplicate controller.
    if(!title.parentNode.querySelector('.mad4b-source-native-hint')){
      hint.className='mad4b-source-native-hint';
      title.insertAdjacentElement('afterend',hint);
    }
  }
};
window.MAD4BJetModeEnhancers=Object.assign(
  {},window.MAD4BJetModeEnhancers||{}, {'source-cards':genericMode}
);
function mount(container){
  if(!container||container.dataset.mad4bProviderNativeMounted)return;
  container.dataset.mad4bProviderNativeMounted='true';
  const status=make('p','Loading original JetEngine Provider Catalog…','mad4b-provider-status');
  status.setAttribute('role','status');status.setAttribute('aria-live','polite');
  const self=make('div','','mad4b-provider-company');
  self.append(make('strong','Company / Self Provider'),make('span',
    'Owns Direct Tour Price and all unassigned published Tour Rates.'));
  const overview=make('p','Edit providers and rules using the original JetEngine Repeaters below. Save with the page-level Save changes button. No second form or separate Draft save.');
  const goto=(name,title)=>{
    const button=press(title,()=>{
      const target=nativeRoot(name);
      if(!target){status.textContent='Native JetEngine field unavailable. Reload the page.';return;}
      target.scrollIntoView({behavior:'smooth',block:'center'});
      target.querySelector('input,select,textarea')?.focus({preventScroll:true});
    });
    return button;
  };
  const links=make('div','','mad4b-provider-native-jumps');
  links.append(goto(fieldNames.providers,'Edit native Provider Catalog'),
    goto(fieldNames.rules,'Edit native Source Rules'));
  const grid=make('div','','mad4b-provider-grid');
  const tour=select([['','Choose a published Tour']]);
  const rate=input('number',{min:1,step:1,placeholder:'Optional Rate ID'});
  const audience=select([['general','International / General'],['egyptians','Egyptians']]);
  const occupancy=select([['double','Double'],['single','Single'],['triple','Triple']]);
  grid.append(labeled('Published Tour',tour),
    labeled('Specific Rate (optional)',rate),
    labeled('Audience',audience),
    labeled('Occupancy',occupancy));
  const results=make('div','','mad4b-provider-preview-result');
  const request=async(operation,values={})=>{
    const body=new URLSearchParams(Object.assign({
      action:settings.action,nonce:settings.nonce,operation
    },values));
    const response=await fetch(settings.url,{
      method:'POST',
      credentials:'same-origin',
      headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},
      body
    });
    const json=await response.json();
    if(!response.ok||!json.success)
      throw Error(json?.data?.message||'Provider source preview refused');
    return json.data;
  };
  const test=press('Evaluate native source rules · AJAX',async()=>{
    test.disabled=true;
    try{
      if(!tour.value)throw Error('Choose a published Tour first.');
      const draft=parseNative();
      const data=await request('preview',{
        tour_id:tour.value,rate_id:rate.value||'0',
        audience:audience.value,occupancy:occupancy.value,
        payload:JSON.stringify(draft)
      });
      results.replaceChildren();
      const winner=data.winner;
      results.append(make('strong',winner?'Proposed source (Draft preview)':
        'No unambiguous source available'));
      if(winner)results.append(make('p',
        winner.provider+' · '+(winner.rate_id?'Rate #'+winner.rate_id:'Direct Tour')+
        ' · '+winner.amount+' '+winner.currency+
        ' · scope '+winner.scope+' · priority '+winner.priority));
      for(const x of data.conflicts||[])
        results.append(make('p','Conflict: '+x,'mad4b-provider-error'));
      const list=make('div','','mad4b-provider-candidates');
      for(const row of data.rows||[]){
        const entry=make('div','','mad4b-provider-candidate');
        entry.append(make('strong',row.provider+' · '+
          (row.rate_id?'Rate #'+row.rate_id:'Direct Tour')),
          make('span',row.amount+' '+row.currency),
          make('small',row.scope+' · priority '+row.priority+
            ' · '+row.evidence+' · '+(row.eligible?'eligible':'excluded')));
        list.append(entry);
      }
      results.append(list,make('small',
        'Preview only · no provider source rule is commercially active.'));
      status.textContent='Previewed '+draft.providers.length+' provider(s), '+
        draft.rules.length+' rule(s) from the actual unsaved JetEngine inputs. '+
        'Save changes in the original JetEngine form to persist.';
    }catch(error){
      results.replaceChildren(make('p',error.message,'mad4b-provider-error'));
      status.textContent='Preview failed: '+error.message;
    }finally{test.disabled=false;}
  });
  const sourceNote=make('p','Scope precedence: Rate → Tour → Taxonomy Group → Provider → Company. Provider ID and Rate ownership are validated at native save; this feature does not activate checkout pricing.');
  const footer=make('small','Native JetEngine data owner. The former separate MAD4B Draft writer is disabled.');
  container.append(make('h4','Provider Source Policies · JetEngine Native Mode'),
    overview,self,status,links,sourceNote,grid,test,results,footer);
  (async()=>{
    try{
      const data=await request('catalog');
      self.querySelector('strong').textContent=
        String(data.company_name||'Company')+' · Self Provider';
      for(const row of data.tour_choices||[]){
        const option=make('option',row.title+' (#'+row.id+')');
        option.value=String(row.id);tour.append(option);
      }
      if(data.validation_error){
        status.textContent='Native source data requires repair: '+data.validation_error;
      }else if(data.config_storage!=='jetengine_native'){
        status.textContent='Native JetEngine definition not yet ready. Reload the page.';
      }else{
        status.textContent='Ready · Original JetEngine Repeaters are active. '+
          (data.providers?.length||0)+' external provider(s), '+
          (data.rules?.length||0)+' saved inactive rule(s).';
      }
    }catch(error){
      status.textContent='Provider catalog error: '+error.message;
    }
  })();
}
window.MAD4BProviderPriceSources=Object.freeze({mount,parseNative});
})();
