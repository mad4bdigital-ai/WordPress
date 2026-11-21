/* JetEngine native repeater progressive disclosure. Does not move or disable controls. */
(()=>{
'use strict';
const root=document.querySelector('.jet-engine-options-page-wrap');
const control=root?.querySelector('#settings_top .cx-control[data-control-name="shared_mode_policy_rows"]');
if(!control)return;
const optional=new Set([
 'tax_percent_override','service_fee_percent_override',
 'tax_price_basis','discount_tax_order','payment_fee_taxable',
 'payment_fee_bearer','discount_scope','refund_allocation','refund_payment_fee',
 'tax_price_basis_source','discount_tax_order_source',
 'payment_fee_taxable_source','payment_fee_bearer_source',
 'discount_scope_source','refund_allocation_source','refund_payment_fee_source'
]);
const make=(tag,text)=>{
 const e=document.createElement(tag);
 if(text!==undefined)e.textContent=text;
 return e;
};
const decorate=(row)=>{
 if(row.dataset.mad4bFinancialDisclosure==='1')return;
 const candidates=[];
 for(const native of row.querySelectorAll('.cx-ui-repeater-item-control')){
   const input=native.querySelector('input[name],select[name],textarea[name]');
   if(!input)continue;
   const parsed=String(input.name||'').match(/^\w+\[(?:item-)?\d+\]\[([a-z_]+)\]/);
   if(!parsed||!optional.has(parsed[1]))continue;
   candidates.push({native,input,key:parsed[1]});
 }
 if(candidates.length<9)return;
 const wrap=row.querySelector('.cheryr-ui-repeater-content-box');
 if(!wrap)return;
 row.dataset.mad4bFinancialDisclosure='1';
 const summary=make('div');
 summary.className='mad4b-financial-disclosure';
 const toggle=make('button','');
 toggle.type='button';toggle.className='button button-small';
 const explanation=make('span','Original JetEngine values and inherited policies remain unchanged.');
 explanation.className='mad4b-financial-disclosure-note';
 summary.append(toggle,explanation);
 wrap.prepend(summary);

 const meaningful=(obj)=>{
   const value=String(obj.input.value??'').trim().toLowerCase();
   if(obj.key.endsWith('_source'))
     return value!=='' && !['inherit','inherited','default','auto'].includes(value);
   return !['','0','0.0','false','no','inherit'].includes(value);
 };
 let expanded=candidates.some(meaningful);
 const update=()=>{
   // Invalid/required controls must remain visible; never disable native data.
   for(const {native,input} of candidates){
     native.classList.toggle('mad4b-pricing-financial-hidden',!expanded && !input.required);
   }
   toggle.textContent=expanded?'Hide advanced financial fields':
     'Show '+candidates.length+' advanced financial fields';
   toggle.setAttribute('aria-expanded',String(expanded));
   explanation.textContent=expanded
     ?'Edit tax, fees and payment policies in their original JetEngine fields.'
     :'Inherited defaults stay in effect unless overridden. Hidden field values still save natively.';
 };
 toggle.addEventListener('click',()=>{expanded=!expanded;update();});
 // An inherited source can change after opening another field or a reset.
 // Do not collapse automatically and hide a newly edited value.
 for(const {input} of candidates)input.addEventListener('change',()=>{
   if(meaningful(candidates.find(x=>x.input===input))&&!expanded){
     expanded=true;update();
   }
 });
 update();
};
const scan=()=>control.querySelectorAll('.cx-ui-repeater-item').forEach(decorate);
scan();
const watcher=new MutationObserver(mutations=>{
 if(mutations.some(m=>[...m.addedNodes].some(n=>
    n.nodeType===1 && (n.matches?.('.cx-ui-repeater-item')||
    n.querySelector?.('.cx-ui-repeater-item')))))scan();
});
watcher.observe(control,{childList:true,subtree:true});
})();
