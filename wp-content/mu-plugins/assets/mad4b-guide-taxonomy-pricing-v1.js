/* Native Dynamic Pricing Controls: term-source Guide Cost admin, no checkout authority. */
(() => {
 'use strict';
 const mount=()=>{
 const cfg=window.MAD4BGuideTaxPricing;
 const panel=document.getElementById('mad4b-guide-taxonomy-cost-panel');
 if(!panel||!cfg)return;
 const root=document.querySelector('.jet-engine-options-page-wrap');
 const workspace=root?.querySelector('.mad4b-workspace');
 const sidebar=workspace?.querySelector('.mad4b-ws-sidebar .mad4b-ws-nav');
 const content=workspace?.querySelector('.mad4b-ws-content');

 // The older module put this panel at the END of the entire native form,
 // outside the six-section workspace. Mount a proper seventh navigation
 // section instead; keep the existing JetEngine form and save untouched.
 if(workspace && sidebar && content) {
   content.appendChild(panel);
   panel.classList.add('mad4b-guide-cost-tab-panel');
   panel.style.margin='0';
   panel.style.maxWidth='100%';
   panel.hidden=true;

   const tab=document.createElement('button');
   tab.type='button';
   tab.className='mad4b-ws-nav-btn mad4b-guide-cost-nav';
   tab.dataset.section='guide-costs';
   tab.setAttribute('aria-controls',panel.id);
   tab.setAttribute('aria-expanded','false');
   const name=document.createElement('span');
   const icon=document.createElement('span');
   icon.className='mad4b-ws-nav-icon';
   icon.textContent='◈';
   name.appendChild(icon);
   name.appendChild(document.createTextNode('Guide costs'));
   const count=document.createElement('small');
   count.textContent=String(panel.querySelectorAll('.mad4b-guide-term-row').length);
   tab.append(name,count);
   sidebar.appendChild(tab);
   // Count the visible Guide-cost section alongside native functional groups.
   for(const item of workspace.querySelectorAll('.mad4b-ws-summary .mad4b-ws-metric')){
     if(item.textContent.includes('Functional groups')){
       const value=item.querySelector('strong');
       if(value)value.textContent='7';
     }
   }

   const style=document.createElement('style');
   style.id='mad4b-guide-cost-tab-style';
   style.textContent=[
     '.jet-engine-options-page-wrap.mad4b-guide-cost-tab-active .mad4b-ws-original {display:none!important}',
     '.jet-engine-options-page-wrap.mad4b-guide-cost-tab-active .mad4b-ws-content > :not(#mad4b-guide-taxonomy-cost-panel) {display:none!important}',
     '.jet-engine-options-page-wrap.mad4b-guide-cost-tab-active #mad4b-guide-taxonomy-cost-panel {display:block!important;box-sizing:border-box}',
     '.mad4b-guide-cost-tab-panel[hidden] {display:none!important}',
     '.mad4b-guide-cost-tab-panel table {min-width:990px}',
     '.mad4b-guide-cost-tab-panel section {margin:16px 0}',
     '.mad4b-guide-cost-tab-panel h2 {margin:5px 0 10px}',
     '.mad4b-guide-cost-tab-panel #mad4b-guide-cost-result:not(:empty) {padding:9px 12px;margin:10px 0;border-left:3px solid #2271b1;background:#f6f7f7}'
   ].join('\n');
   document.head.appendChild(style);

   const leave=()=>{
     root.classList.remove('mad4b-guide-cost-tab-active');
     panel.hidden=true;
     tab.classList.remove('is-active');
     tab.setAttribute('aria-expanded','false');
     tab.setAttribute('aria-current','false');
   };
   const open=()=>{
     // Native controls remain on the page and retain their values.
     root.classList.add('mad4b-guide-cost-tab-active');
     panel.hidden=false;
     for(const btn of sidebar.querySelectorAll('.mad4b-ws-nav-btn')){
       const active=btn===tab;
       btn.classList.toggle('is-active',active);
       btn.setAttribute('aria-current',active?'true':'false');
     }
     tab.setAttribute('aria-expanded','true');
     if(history.replaceState)history.replaceState(null,'','#mad4b-guide-taxonomy-cost-panel');
     panel.scrollIntoView({block:'start',behavior:'auto'});
   };
   tab.addEventListener('click',open);
   sidebar.addEventListener('click',event=>{
     const other=event.target.closest('.mad4b-ws-nav-btn:not(.mad4b-guide-cost-nav)');
     if(other)leave();
   },true);
   workspace.addEventListener('click',event=>{
     if(event.target.closest('.mad4b-ws-secondary'))leave();
   },true);
   window.addEventListener('hashchange',()=>{
     if(location.hash==='#mad4b-guide-taxonomy-cost-panel')open();
   });
   if(location.hash==='#mad4b-guide-taxonomy-cost-panel')open();
 } else {
   // Don't lose the cost editor if vendor markup changes. Place the panel
   // near the page heading with an obvious title, never at the long footer.
   const fallback=root || document.querySelector('#wpbody-content .wrap');
   if(fallback)fallback.prepend(panel);
 }
 const status=panel.querySelector('#mad4b-guide-cost-result');
 const progress=(msg,error=false)=>{
   status.textContent=String(msg||'');
   status.style.color=error?'#b32d2e':'#2271b1';
 };
 async function send(payload){
   const body=new URLSearchParams({
     action:cfg.action,nonce:cfg.nonce,payload:JSON.stringify(payload)
   });
   const response=await fetch(cfg.url,{
     method:'POST',credentials:'same-origin',cache:'no-store',
     headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},
     body
   });
   const data=await response.json();
   if(!response.ok||!data?.success)throw new Error(data?.data?.reason||('HTTP '+response.status));
   return data.data;
 }
 const global=panel.querySelector('#mad4b-guide-default-form');
 global?.addEventListener('submit',async(e)=>{
   e.preventDefault();
   const btn=global.querySelector('button[type=submit]');
   const read=(name)=>global.querySelector('[name='+name+']')?.value||'';
   const values={ar:read('ar'),en:read('en'),other:read('other')};
   if(!window.confirm('Update company Guide fallback costs in EGP? This changes internal calculations but does not publish Tour Rates.'))return;
   btn.disabled=true;progress('Saving EGP fallback policy…');
   try {
     const result=await send({scope:'global',expected_hash:read('expected_hash'),amounts:values});
     global.querySelector('[name=expected_hash]').value=result.policy_hash;
     progress('Updated Global Defaults. Term overrides and Rate prices remain unchanged.');
   }catch(e){progress('Global defaults not saved: '+e.message,true);}
   finally{btn.disabled=false;}
 });
 for(const row of panel.querySelectorAll('.mad4b-guide-term-row')){
   row.querySelector('.mad4b-guide-approve-term')?.addEventListener('click',async()=>{
     const btn=row.querySelector('.mad4b-guide-approve-term');
     const fields={};
     for(const el of row.querySelectorAll('[data-field]'))
       fields[el.dataset.field]=el.value;
     fields.mad4b_guide_cost_unit='per_guide_day';
     fields.mad4b_guide_cost_status='approved';
     const chosen=row.querySelector('[data-field=mad4b_guide_cost_mode]')?.value;
     if(chosen==='inherit'){
       fields.mad4b_guide_base_cost='';
       fields.mad4b_guide_cost_currency='EGP';
       fields.mad4b_guide_supplier='self';
       fields.mad4b_guide_cost_valid_from='';
       fields.mad4b_guide_cost_valid_to='';
     }
     if(!window.confirm('Approve Guide cost for this language? Currency conversion must be verified. Existing selling Rates are not changed.'))return;
     btn.disabled=true;progress('Checking source revision and saving taxonomy cost…');
     try{
       const result=await send({
         scope:'term',term_id:Number(row.dataset.id),
         revision:Number(row.dataset.revision),raw_hash:row.dataset.hash,
         fields
       });
       progress('Approved '+result.rate.language_code+
         ' · '+result.rate.source_unit_cost+' '+result.rate.source_currency+
         ' = '+result.rate.unit_cost.toFixed(2)+' EGP per guide/day. No Rate was published.');
       window.location.reload();
     }catch(e){progress('Cost was not approved: '+e.message,true);}
     finally{btn.disabled=false;}
   });
 }
};
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',mount,{once:true});
 else mount();
})();
