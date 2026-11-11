(() => {
  'use strict';
  // Loaded only on the existing Functional Modes admin screen.
  const api=window.MAD4BJetModePage?.priceGroup;
  const root=document.querySelector('.jet-engine-options-page-wrap');
  if(!root || !api?.ajax_url || !api?.action || !api?.nonce || root.dataset.mad4bPriceGroupController) return;
  root.dataset.mad4bPriceGroupController='1';

  const el=(tag,cls,text)=>{
    const node=document.createElement(tag);
    if(cls)node.className=cls;
    if(text!==undefined)node.textContent=String(text);
    return node;
  };
  async function request(rateId,operation,data={}){
    const body=new URLSearchParams({
      action:api.action,nonce:api.nonce,
      rate_id:String(rateId),operation,...data
    });
    const result=await fetch(api.ajax_url,{
      method:'POST',credentials:'same-origin',
      headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},
      body:body.toString()
    });
    let json;
    try{json=await result.json();}catch(_){throw new Error('Invalid response from Price Group Controller');}
    if(!result.ok || json?.success!==true)throw new Error(
      json?.data?.message || 'Price Group Controller unavailable ('+result.status+')'
    );
    return json.data;
  }

  const choices=(select,values)=>{
    for(const [value,label] of values)select.add(new Option(label,value));
  };
  function render(editor,snapshot){
    const panel=editor.querySelector('.mad4b-price-group-editor__panel');
    if(!panel)return;
    panel.replaceChildren();
    const native=snapshot.native_fields||{};
    const managed=snapshot.managed||{};
    const adapter=snapshot.adapters?.['tour-rates'];
    if(!adapter?.audiences){
      panel.append(el('p','mad4b-price-group-editor__error','Rate adapter unavailable.'));
      return;
    }
    const intro=el('p','mad4b-price-group-editor__warning',
      'Explicit Save writes selected native Rate price fields. It can affect published prices; existing Stop Sale, validity and booking gates are unchanged.');
    const source=el('p','mad4b-price-group-editor__origin',
      'Rate #'+snapshot.rate_id+' · Tour #'+snapshot.tour_id+
      ' · Occupancy policy: '+(snapshot.occupancy_policy?.source||'native')+
      ' · Controller revision '+snapshot.revision);
    panel.append(intro,source);
    const form=el('div','mad4b-price-group-editor__form');
    const controls=new Map();

    function field(key,label,kind='price'){
      const entry=el('div','mad4b-price-group-editor__field');
      entry.dataset.field=key;
      const header=el('div','mad4b-price-group-editor__field-heading');
      const name=el('label','',label);
      const mode=document.createElement('select');
      mode.setAttribute('aria-label',label+' mode');
      choices(mode,[['inherit','Inherit'],['override','Override']]);
      mode.value=managed[key]?'override':'inherit';
      header.append(name,mode);
      const input=kind==='flag'?document.createElement('select'):document.createElement('input');
      input.setAttribute('aria-label',label+' value');
      if(kind==='flag') choices(input,[['0','Disabled'],['1','Enabled']]);
      else if(kind==='currency'){
        input.type='text';input.maxLength=3;input.autocomplete='off';input.placeholder='USD / EGP';
      }else{
        input.type='number';input.min=kind==='sale'?'0':'0.0001';
        input.max='99999999';input.step='0.0001';input.inputMode='decimal';
        input.placeholder=kind==='sale'?'0 = no sale':'Price';
      }
      const current=String(native[key]?.value??'');
      const original=managed[key]?.before?.value!==undefined
        ?String(managed[key].before.value):current;
      input.value=managed[key]?String(managed[key].after):current;
      const hint=el('small','mad4b-price-group-editor__inherit-value',
        'Inherited before controller: '+(original||'Not configured'));
      const update=()=>{
        input.disabled=mode.value!=='override';
        entry.classList.toggle('is-override',mode.value==='override');
      };
      mode.addEventListener('change',update);
      update();
      entry.append(header,input,hint);
      controls.set(key,{mode,input});
      return entry;
    }

    const keysForGroup={};
    for(const [audience,group] of Object.entries(adapter.audiences)){
      const box=el('section','mad4b-price-group-editor__audience');
      const h=el('h4','',audience==='egyptians'?'Egyptians':'International / General');
      const subtitle=el('p','',
        audience==='egyptians'
          ? 'Separate Egyptian prices and currency. Enable only after all three prices are complete.'
          : 'Regular International source prices and optional sale values.');
      box.append(h,subtitle);
      const keys=[];
      if(group.enabled){
        box.append(field(group.enabled,'Egyptians pricing enabled','flag'));
        keys.push(group.enabled);
      }
      box.append(field(group.currency,
        audience==='egyptians'?'Egyptians currency':'International currency','currency'));
      keys.push(group.currency);
      for(const occ of ['single','double','triple']){
        const row=el('div','mad4b-price-group-editor__occupancy');
        row.append(el('strong','',occ[0].toUpperCase()+occ.slice(1)));
        row.append(field(group.regular[occ],
          (audience==='egyptians'?'Egyptians ':'')+occ+' regular'));
        row.append(field(group.sale[occ],
          (audience==='egyptians'?'Egyptians ':'')+occ+' sale','sale'));
        keys.push(group.regular[occ],group.sale[occ]);
        box.append(row);
      }
      const quick=el('div','mad4b-price-group-editor__quick');
      const inherit=el('button','','Inherit all in group');
      inherit.type='button';
      inherit.addEventListener('click',()=>{
        for(const key of keys){
          const ctl=controls.get(key);
          if(ctl){ctl.mode.value='inherit';ctl.mode.dispatchEvent(new Event('change'));}
        }
      });
      const preserve=el('button','','Override existing regular values');
      preserve.type='button';
      preserve.addEventListener('click',()=>{
        for(const key of [group.currency,...Object.values(group.regular)]){
          const ctl=controls.get(key);
          if(!ctl || !String(ctl.input.value).trim())continue;
          ctl.mode.value='override';ctl.mode.dispatchEvent(new Event('change'));
        }
      });
      quick.append(inherit,preserve);
      box.append(quick);
      form.append(box);
      keysForGroup[audience]=keys;
    }
    const global=el('section','mad4b-price-group-editor__global');
    global.append(el('h4','','Shared Rate flags'));
    global.append(el('p','','Sale pricing flag is shared across all occupancies and audiences.'));
    global.append(field(adapter.flags.sale_enabled,'Sale prices enabled','flag'));
    form.append(global);

    const notice=el('p','mad4b-price-group-editor__notice','');
    notice.setAttribute('role','status');notice.setAttribute('aria-live','polite');
    const consent=el('label','mad4b-price-group-editor__confirm');
    const check=document.createElement('input');check.type='checkbox';
    check.setAttribute('aria-label','Confirm applying live Rate overrides');
    consent.append(check,el('span','',
      'I reviewed the changes to this native Tour Rate. Apply only the explicitly overridden fields.'));
    const actions=el('div','mad4b-price-group-editor__actions');
    const submit=el('button','mad4b-price-group-editor__save','Apply Price Group Overrides');
    submit.type='button';submit.disabled=true;
    const cancel=el('button','mad4b-price-group-editor__cancel','Discard unsaved edits');
    cancel.type='button';
    cancel.addEventListener('click',()=>render(editor,snapshot));
    check.addEventListener('change',()=>{submit.disabled=!check.checked;});
    actions.append(submit,cancel);
    form.append(consent,actions,notice);

    submit.addEventListener('click',async ()=>{
      if(!check.checked)return;
      const submitted={};
      for(const [key,ctl] of controls){
        submitted[key]=ctl.mode.value==='override'
          ?{mode:'override',value:ctl.input.value.trim()}
          :{mode:'inherit'};
      }
      submit.disabled=true;cancel.disabled=true;
      notice.textContent='Applying selected overrides…';
      try{
        const res=await request(snapshot.rate_id,'save',{
          version:snapshot.version,changes:JSON.stringify(submitted)
        });
        const fresh=res.data;
        if(!fresh || !fresh.version)throw new Error('Rate save response is missing its revision');
        if(res.changed){
          editor.closest('.mad4b-tour-inspector')?.dispatchEvent(
            new CustomEvent('mad4b:price-group-saved',{
              bubbles:false,detail:{rate_id:snapshot.rate_id,
                changed_keys:res.changed_keys||[]}
            })
          );
        }else{
          render(editor,fresh);
          const info=editor.querySelector('.mad4b-price-group-editor__notice');
          if(info) info.textContent='No native Rate fields changed.';
        }
      }catch(error){
        notice.textContent=error?.message||'Override was not applied. Reload this Rate.';
        notice.classList.add('is-error');
        cancel.disabled=false;
        check.checked=false;
      }finally{
        submit.disabled=true;
      }
    });
    panel.append(form);
  }

  // Capture the native <details> toggle to initialize only the opened Rate.
  // No observers, repeat polling or additional requests on normal catalog loads.
  root.addEventListener('toggle',async event=>{
    const details=event.target;
    if(!(details instanceof HTMLElement)
      || !details.matches('.mad4b-price-group-editor')
      || !details.open || details.dataset.loaded==='1'
      || details.dataset.loading==='1')return;
    const id=Number(details.dataset.rateId);
    if(!Number.isInteger(id)||id<1)return;
    details.dataset.loading='1';
    const panel=details.querySelector('.mad4b-price-group-editor__panel');
    if(panel)panel.textContent='Reading source Rate and current overrides…';
    try{
      const response=await request(id,'get');
      if(!response?.data||!details.isConnected)return;
      render(details,response.data);
      details.dataset.loaded='1';
    }catch(err){
      if(panel)panel.textContent=err?.message||'Price Group Controller unavailable';
    }finally{
      details.dataset.loading='0';
    }
  },true);
})();
