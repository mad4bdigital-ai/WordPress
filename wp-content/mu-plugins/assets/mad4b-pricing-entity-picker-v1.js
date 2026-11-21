/* Read-only AJAX search. Selection updates ONLY JetEngine's original input. */
(()=>{
'use strict';
const cfg=window.MAD4BPricingEntityPicker;
const root=document.querySelector('.jet-engine-options-page-wrap');
const top=root?.querySelector('#settings_top');
if(!cfg||!root||!top)return;
const fields={
  shared_package_pricing_rows:{
    package_term_id:['package','Package level'],
    tour_id:['tour','Tour'],
    rate_id:['rate','Tour Rate'],
  },
  shared_group_tier_pricing_rows:{
    tier_id:['tier','Group size tier'],
    rate_id:['rate','Tour Rate'],
  }
};
const create=(tag,text,cls)=>{
  const e=document.createElement(tag);
  if(text!==undefined)e.textContent=String(text);
  if(cls)e.className=cls;
  return e;
};
const lookup=async(kind,query)=>{
  const form=new URLSearchParams({
    action:cfg.action,nonce:cfg.nonce,kind,q:query
  });
  const res=await fetch(cfg.url,{
    method:'POST',credentials:'same-origin',cache:'no-store',
    headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},
    body:form
  });
  const json=await res.json();
  if(!res.ok||json?.success!==true||!Array.isArray(json.data?.results))
    throw Error(json?.data?.reason||'Search unavailable');
  return json.data.results;
};
const attach=(native,kind,label)=>{
  if(native.dataset.mad4bPricingPicker==='1')return;
  native.dataset.mad4bPricingPicker='1';
  const host=native.closest('.cx-ui-repeater-item-control')||native.parentElement;
  if(!host)return;
  const details=create('details',undefined,'mad4b-native-entity-picker');
  const summary=create('summary','Find '+label+' by name');
  const inner=create('div',undefined,'mad4b-native-entity-picker-panel');
  const search=create('input');
  search.type='search';
  search.placeholder='Name or WordPress ID';
  search.setAttribute('aria-label','Search '+label);
  search.autocomplete='off';
  const go=create('button','Search','button button-small');
  go.type='button';
  const status=create('p','Enter at least 2 characters','mad4b-native-picker-status');
  status.setAttribute('role','status');status.setAttribute('aria-live','polite');
  const results=create('div',undefined,'mad4b-native-picker-results');
  const run=async()=>{
    const query=search.value.trim();
    if(query.length<2){status.textContent='Enter at least 2 characters';return;}
    go.disabled=true;status.textContent='Searching '+label+'…';
    results.replaceChildren();
    try{
      const choices=await lookup(kind,query);
      status.textContent=choices.length?choices.length+' result(s)':
        'No matching records — original JetEngine value remains unchanged.';
      for(const item of choices){
        const choice=create('button',undefined,'mad4b-native-picker-choice');
        choice.type='button';
        const main=create('strong',item.name||('ID '+item.id));
        const suffix=create('small','#'+item.id+' · '+
          (item.status||'')+
          (item.language?' · '+item.language.toUpperCase():'')+
          (item.canonical_id && item.canonical_id!==item.id
             ?' · Canonical #'+item.canonical_id:''));
        choice.append(main,suffix);
        choice.addEventListener('click',()=>{
          if(native.disabled||native.readOnly)return;
          // Native name, row controls and JetEngine Save remain untouched.
          native.value=String(item.id);
          native.dispatchEvent(new Event('input',{bubbles:true}));
          native.dispatchEvent(new Event('change',{bubbles:true}));
          status.textContent='Selected '+item.name+' (#'+item.id+
            '). Save with the original JetEngine button.';
          details.open=false;
          native.focus({preventScroll:true});
        });
        results.append(choice);
      }
    }catch(e){
      status.textContent='Search error: '+String(e.message)+
        '. No value has been changed.';
    }finally{go.disabled=false;}
  };
  go.addEventListener('click',run);
  search.addEventListener('keydown',event=>{
    if(event.key==='Enter'){event.preventDefault();run();}
    if(event.key==='Escape'){event.preventDefault();details.open=false;native.focus();}
  });
  inner.append(search,go,status,results);
  details.append(summary,inner);
  host.append(details);
};
const scan=()=>{
  for(const [name,columns] of Object.entries(fields)){
    const control=top.querySelector('.cx-control[data-control-name="'+name+'"]');
    if(!control)continue;
    for(const input of control.querySelectorAll('input[name]')){
      if(input.dataset.mad4bPricingPicker==='1' ||
         (input.type!=='number'&&input.type!=='text') || input.disabled)continue;
      const match=String(input.name||'').match(/^\w+\[(?:item-)?\d+\]\[([a-z_]+)\]$/);
      if(!match||!Object.prototype.hasOwnProperty.call(columns,match[1]))continue;
      const [kind,label]=columns[match[1]];
      attach(input,kind,label);
    }
  }
};
scan();
const observer=new MutationObserver(mutations=>{
  if(mutations.some(m=>[...m.addedNodes].some(n=>
    n.nodeType===1 && (n.matches?.('.cx-ui-repeater-item')||
       n.querySelector?.('.cx-ui-repeater-item')))))scan();
});
observer.observe(top,{subtree:true,childList:true});
})();
