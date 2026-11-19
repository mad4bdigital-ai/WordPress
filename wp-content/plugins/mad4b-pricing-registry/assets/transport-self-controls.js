/* Company/self transport controls on the existing JetEngine Pricing page. */
(() => {
'use strict';
const cfg=window.MAD4BTransportSelfPricing;
if(!cfg?.url||!cfg?.nonce||!cfg?.action||!cfg?.template)return;
function init(){
 const root=document.querySelector('.jet-engine-options-page-wrap')||
  document.querySelector('#wpbody-content .jet-engine-options-page')||
  document.querySelector('#wpbody-content .wrap');
 if(!root||document.getElementById('mad4b-transport-self-controls'))return;
 const panel=document.createElement('section');
 panel.className='mad4b-ts-panel';
 panel.id='mad4b-transport-self-controls';
 panel.setAttribute('aria-label','Transportation / Company Self Pricing');
 panel.innerHTML=cfg.template;
 root.append(panel);
 const q=k=>panel.querySelector('[data-ts="'+k+'"]');
 const state={policy:null,rows:[],revision:0,selected:null};
 const make=(tag,content)=>{const el=document.createElement(tag);
    el.textContent=String(content);return el;};
 const asNum=v=>Number.isFinite(Number(v))?new Intl.NumberFormat('en',{maximumFractionDigits:2}).format(Number(v)):'—';
 const display=k=>String(k).replaceAll('_',' ');
 async function api(mode,args={}){
    const body=new URLSearchParams({action:cfg.action,nonce:cfg.nonce,mode});
    Object.entries(args).forEach(([k,v])=>body.set(k,String(v??'')));
    const r=await fetch(cfg.url,{method:'POST',credentials:'same-origin',body,
       headers:{Accept:'application/json'}});
    const v=await r.json();
    if(!r.ok||v?.success!==true)throw new Error(v?.data?.code||'request_failed');
    return v.data;
 }
 function options(el,rows,first,selected){
    el.replaceChildren(new Option(first,''));
    rows.forEach(([value,name])=>el.add(new Option(name,value)));
    if(selected && rows.some(x=>x[0]===selected))el.value=selected;
 }
 function safeLink(target,raw){
    try{const url=new URL(raw,window.location.origin);
        if(url.origin===window.location.origin && /^https?:$/.test(url.protocol))
            target.href=url.href;
    }catch{}
 }
 function markupInputs(target,keys,values,kind){
    target.replaceChildren();
    keys.forEach(key=>{
        const label=make('label',display(key));
        label.dataset.group=kind;label.dataset.key=key;
        const inp=document.createElement('input');
        inp.type='number';inp.step='0.25';inp.min='0';inp.max='200';
        inp.value=String(values?.[key]??0);label.append(inp);target.append(label);
    });
 }
 function policyView(policy){
    state.policy=policy;state.revision=Number(policy.revision||0);
    q('currency').value=policy.currency||'';
    q('role').value=policy.price_role||'reference_only';
    q('discount').checked=policy.apply_upper_egypt_five_pct===true;
    q('global').value=String(policy.global_markup_pct??0);
    markupInputs(q('city-markups'),['Cairo','Aswan','Luxor'],policy.city_markup_pct,'city');
    markupInputs(q('vehicle-markups'),
        ['limousine','luxury_7_seats','h1','hiace','coaster','bus'],
        policy.vehicle_markup_pct,'vehicle');
    const configured=Boolean(policy.currency) && policy.price_role!=='reference_only';
    q('readiness').textContent=configured?'Internal policy configured · checkout not approved':
        'PDF trusted · currency/role not fully configured';
 }
 function currentForm(){
    const values=selector=>{const r={};
        panel.querySelectorAll(selector).forEach(label=>{
            r[label.dataset.key]=Number(label.querySelector('input').value);
        });return r;};
    return {currency:q('currency').value,price_role:q('role').value,
        apply_upper_egypt_five_pct:q('discount').checked,
        global_markup_pct:Number(q('global').value),
        city_markup_pct:values('[data-group="city"]'),
        vehicle_markup_pct:values('[data-group="vehicle"]')};
 }
 function operationChanged(){
    const r=state.rows.find(x=>x.key===q('operation').value);
    state.selected=r||null;
    q('source-ar').textContent=r?.original_ar||'';
    options(q('vehicle'),r?Object.keys(r.prices).map(k=>[k,display(k)]):[],
        'Choose vehicle');
    vehicleChanged();
 }
 function vehicleChanged(){
    const vehicle=q('vehicle').value;
    const v=state.selected?.prices?.[vehicle];
    const image=q('photo');
    if(v?.image_url){
        image.src=v.image_url;image.alt='Illustrative '+display(vehicle)+' vehicle';
        image.hidden=false;
    }else{image.removeAttribute('src');image.hidden=true;}
    q('raw').textContent=v?.amount===null?'No price in PDF (not zero)':
        v?.amount===undefined?'Choose a vehicle':
        'PDF amount '+asNum(v.amount)+' · source currency not printed';
    q('source-flags').textContent=(state.selected?.review_flags||[]).join(' · ');
    q('preview-result').textContent='Use Calculate preview to apply the saved company policy.';
 }
 async function load(){
    q('status').textContent='Loading original source and company policy…';
    try{
        const r=await api('catalog',{city:q('city').value,lang:q('lang').value||cfg.lang||'en'});
        if(r.error)throw new Error(r.error);
        state.rows=r.items;
        policyView(r.policy);
        if(q('lang').options.length<=1)
            options(q('lang'),r.languages.map(x=>[x,x.toUpperCase()]),'Language',r.language);
        options(q('operation'),r.items.map(x=>[x.key,x.key+' · '+x.title]),'Choose operation',
            q('operation').value);
        safeLink(q('drafts'),r.edit_drafts_url);
        safeLink(q('rates'),r.native_rates_url);
        safeLink(q('markets'),r.market_url);
        q('provider').textContent=r.provider.name+' / self';
        q('markets-status').textContent=Number(r.approved_market_profiles||0)+
            ' approved · native policy owner';
        operationChanged();
        q('status').textContent='Verified PDF · '+r.items.length+
            ' operations · saved revision '+state.revision;
    }catch(e){q('status').textContent='Unable to load: '+e.message;}
 }
 function quoteResult(r){
    const out=q('preview-result');out.replaceChildren();
    if(r.status==='no_price_in_pdf'){
        out.append(make('strong','No value in the PDF for this category. It is not zero.'));
        return;
    }
    for(const [title,value] of [
        ['Original PDF',asNum(r.source_amount)],
        ['Document discount',String(r.source_discount_pct)+'%'],
        ['After discount',asNum(r.after_discount)],
        ['Combined markup',String(r.markup_total_pct)+'%'],
        ['Per vehicle',asNum(r.unit_reference_amount)],
        ['All selected vehicles',asNum(r.extended_reference_amount)]
    ]){
        const line=document.createElement('div');line.className='mad4b-ts-result-row';
        line.append(make('span',title),make('strong',value));out.append(line);
    }
    out.append(make('p',r.currency?
        'Reference result: '+asNum(r.extended_reference_amount)+' '+r.currency:
        'Reference result: '+asNum(r.extended_reference_amount)+' (currency not configured)'));
    out.append(make('small','Company self provider · not an approved sale price, Market Profile quote or checkout authorization.'));
 }
 q('city').addEventListener('change',load);
 q('lang').addEventListener('change',load);
 q('operation').addEventListener('change',operationChanged);
 q('vehicle').addEventListener('change',vehicleChanged);
 q('preview').addEventListener('click',async()=>{
    if(!state.selected||!q('vehicle').value){
        q('preview-result').textContent='Select an operation and vehicle first.';return;
    }
    q('preview').disabled=true;
    try{
        const r=await api('preview',{key:state.selected.key,
            vehicle:q('vehicle').value,quantity:q('qty').value});
        quoteResult(r);
    }catch(e){q('preview-result').textContent='Preview failed: '+e.message;}
    finally{q('preview').disabled=false;}
 });
 q('save').addEventListener('click',async()=>{
    q('save').disabled=true;q('status').textContent='Saving controls…';
    try{
        const r=await api('save',{policy:JSON.stringify(currentForm()),
            revision:state.revision});
        policyView(r.policy);
        q('status').textContent='Saved revision '+state.revision+
            ' · PDF and company self source unchanged · no checkout activation.';
    }catch(e){q('status').textContent='Not saved: '+e.message+
        '. If the revision changed, reload the policy.';}
    finally{q('save').disabled=false;}
 });
 q('reload').addEventListener('click',load);
 load();
}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);
else init();
})();
