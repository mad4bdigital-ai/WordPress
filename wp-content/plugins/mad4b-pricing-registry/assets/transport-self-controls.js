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
 const state={policy:null,rows:[],revision:0,selected:null,requestSeq:0};
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
 function isDirty(){
    if(!state.policy)return false;
    const p=state.policy;
    const expected={
        currency:p.currency||'',price_role:p.price_role||'reference_only',
        apply_upper_egypt_five_pct:p.apply_upper_egypt_five_pct===true,
        global_markup_pct:Number(p.global_markup_pct??0),
        city_markup_pct:p.city_markup_pct,vehicle_markup_pct:p.vehicle_markup_pct
    };
    return JSON.stringify(currentForm())!==JSON.stringify(expected);
 }
 function markDirty(){
    const changed=isDirty();
    q('dirty').textContent=changed
        ?'Draft changes not saved — price preview still uses the saved policy.'
        :'Saved settings are active in preview.';
    q('dirty').classList.toggle('is-unsaved',changed);
 }
 function refreshOperationOptions(){
    const search=q('search').value.trim().toLocaleLowerCase();
    const previous=q('operation').value;
    const componentFilter=q('component-filter').value;
    const available=state.rows.filter(r=>{
        const qualified=r.component?.qualifies_as_tour_component===true;
        const statusMatch=componentFilter==='all' ||
          (componentFilter==='qualified'?qualified:!qualified);
        const queryMatch=!search||
          (r.key+' '+r.title+' '+r.original_ar+' '+r.service_type)
            .toLocaleLowerCase().includes(search);
        return statusMatch && queryMatch;
    });
    options(q('operation'),available.map(r=>[r.key,r.key+' · '+r.title]),
       available.length?'Choose operation ('+available.length+')':'No matching operations',previous);
    operationChanged();
 }
 function componentReadiness(entry){
    const c=entry?.component;
    const action=q('component-edit');
    if(!entry){
        q('component-state').textContent='Select a PDF operation';
        q('component-reasons').textContent='Status is edited on the Transportation post below Description.';
        action.hidden=true;action.removeAttribute('href');return;
    }
    const reasons={
      'tour_component_status_no':'Tour Component Status is No',
      'transport_not_published':'Transportation is still a Draft',
      'transport_not_available':'Is Available? is not Yes',
      'company_tour_component_channel_disabled':'Company Tour Component channel is disabled',
      'unsupported_or_unclassified_transport_mode':'Transport mode needs classification',
      'TRANSPORT_SUBTYPE_MISMATCH':'Transport subtype is not aligned with taxonomy',
      'TRANSPORT_TAXONOMY_MODE_MISMATCH':'Transport mode taxonomy is not aligned',
      'native_transport_identity_not_loaded':'Transport identity policy is not available',
      'missing_or_duplicate_translation_draft':'The localized source Draft is missing or duplicated'
    };
    const yes=c?.qualifies_as_tour_component===true;
    q('component-state').textContent=yes?'Qualified for Tour Component selection':
      c?.opted_in?'Yes selected · still requires checks':'Not qualified · Status No';
    const issues=c?.reason_codes||[];
    q('component-reasons').textContent=yes
      ?'Ready for Tour Component selection, not automatically sellable or stocked.'
      :issues.map(x=>reasons[x]||String(x).replaceAll('_',' ')).join(' · ');
    action.hidden=!entry?.post_id;
    action.removeAttribute('href');
    if(entry?.edit_url)safeLink(action,entry.edit_url);
    if(!action.getAttribute('href'))action.hidden=true;
 }
 function operationsReadiness(entry){
    const stateBox=q('operations-state'),details=q('operations-details');
    const o=entry?.operations;
    if(!o){
      stateBox.textContent='Select a PDF operation';
      details.textContent='Source city, exact points and price approval will appear here.';
      return;
    }
    stateBox.textContent=o.booking_ready?'Approved for operations':
        'Request and supplier review only — not payable';
    const origin=o.origin_city_known?'Origin city recorded':'Origin city missing';
    const destination=o.destination_city_known?
        'Destination city recorded':'Destination / route details pending';
    details.textContent=[
      origin,destination,
      'Exact pickup '+(o.pickup_point_verified?'verified':'unconfirmed'),
      'Exact drop-off '+(o.dropoff_point_verified?'verified':'unconfirmed'),
      'Availability: '+o.supplier_availability.replaceAll('_',' '),
      'Native approved Rate: '+(o.approved_native_rate_present?'yes':'no'),
      'Source currency / role: '+(o.source_currency||'missing')+' / '+o.source_price_role,
      'Fleet capacity: supplier confirmation required'
    ].join(' · ');
 }
 function operationChanged(){
    const r=state.rows.find(x=>x.key===q('operation').value);
    state.selected=r||null;
    q('source-ar').textContent=r?.original_ar||'';
    componentReadiness(r);
    operationsReadiness(r);
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
 async function load(forceReload=false){
    const preserveDraft=!forceReload && state.policy!==null && isDirty();
    const sequence=++state.requestSeq;
    q('status').textContent='Loading original source and company policy…';
    try{
        const r=await api('catalog',{city:q('city').value,lang:q('lang').value||cfg.lang||'en'});
        if(r.error)throw new Error(r.error);
        if(sequence!==state.requestSeq)return;
        state.rows=r.items;
        if(!preserveDraft){
            policyView(r.policy);
        }else{
            q('status').textContent='Catalog updated. Your unsaved policy edits were preserved.';
        }
        markDirty();
        if(q('lang').options.length<=1)
            options(q('lang'),r.languages.map(x=>[x,x.toUpperCase()]),'Language',r.language);
        refreshOperationOptions();
        safeLink(q('drafts'),r.edit_drafts_url);
        safeLink(q('transportations'),r.transportations_url);
        safeLink(q('rates'),r.native_rates_url);
        q('components-count').textContent=
          Number(r.component_qualified_count||0)+' qualified / '+r.items.length+' shown';
        safeLink(q('markets'),r.market_url);
        q('provider').textContent=r.provider.name+' / self';
        q('markets-status').textContent=Number(r.approved_market_profiles||0)+
            ' approved · native policy owner';
        operationChanged();
        q('status').textContent='Verified PDF · '+r.items.length+
            ' operations · saved revision '+state.revision;
    }catch(e){
        if(sequence===state.requestSeq)
           q('status').textContent='Unable to load: '+e.message;
    }
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
 q('city').addEventListener('change',()=>load(false));
 q('lang').addEventListener('change',()=>load(false));
 q('search').addEventListener('input',refreshOperationOptions);
 q('component-filter').addEventListener('change',refreshOperationOptions);
 panel.querySelectorAll('[data-ts="currency"],[data-ts="role"],[data-ts="discount"],[data-ts="global"]')
   .forEach(element=>{
     element.addEventListener('input',markDirty);
     element.addEventListener('change',markDirty);
   });
 for(const region of [q('city-markups'),q('vehicle-markups')]){
   region.addEventListener('input',markDirty);
   region.addEventListener('change',markDirty);
 }
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
        markDirty();
        q('status').textContent='Saved revision '+state.revision+
            ' · PDF and company self source unchanged · no checkout activation.';
    }catch(e){q('status').textContent='Not saved: '+e.message+
        '. If the revision changed, reload the policy.';}
    finally{q('save').disabled=false;}
 });
 q('reload').addEventListener('click',()=>load(true));
 load(true);
}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);
else init();
})();
