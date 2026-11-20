/* Native Dynamic Pricing Controls: term-source Guide Cost admin, no checkout authority. */
(() => {
 'use strict';
 document.addEventListener('DOMContentLoaded', () => {
 const cfg=window.MAD4BGuideTaxPricing;
 const panel=document.getElementById('mad4b-guide-taxonomy-cost-panel');
 if(!panel||!cfg)return;
 const wrap=document.querySelector('#wpbody-content .wrap');
 if(wrap)wrap.appendChild(panel);
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
} });
})();
