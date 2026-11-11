(()=>{
'use strict';
const source=window.MAD4BJetCurrencyChoices;
if(!source||!source.options||!Object.keys(source.options).length)return;
const id='mad4b-jetengine-currencies-datalist';
let datalist=document.getElementById(id);
if(!datalist){
 datalist=document.createElement('datalist');
 datalist.id=id;
 for(const [code,label] of Object.entries(source.options)){
  const option=document.createElement('option');
  option.value=code;option.label=label;
  datalist.append(option);
 }
 document.body.append(datalist);
}
const isCurrencyField=field=>{
 const raw=(field.name||'').split('[').pop().replace(/]$/,'');
 // Only ISO currency code fields: never numeric FX rates or currency
 // multipliers. JetEngine and its Vue model remain the input owner.
 return /^(?:[a-z0-9_]*_)?currency$/i.test(raw);
};
const attach=(root)=>{
 if(!(root instanceof Element))return;
 const inputs=root.matches('input')?[root]:[...root.querySelectorAll('input')];
 for(const field of inputs){
  if(!['text','search'].includes(field.type)
     ||!isCurrencyField(field)
     ||field.getAttribute('list')===id
     ||field.hasAttribute('readonly'))continue;
  // Enhance only inside JetEngine's own native form/Meta Box.
  if(!field.closest('.jet-engine-options-page-wrap, #post, .jet-engine-meta-wrap, .jet-engine-meta-boxes'))continue;
  field.setAttribute('list',id);
  field.setAttribute('data-mad4b-currency-source',source.source);
  // No field replacement, no value assignment, no event dispatch,
  // and no separate save listener. Empty still means Inherit.
 }
};
const init=()=>{
 const roots=[...document.querySelectorAll('.jet-engine-options-page-wrap, #post, .jet-engine-meta-wrap, .jet-engine-meta-boxes')];
 for(const root of roots){
  attach(root);
  const observer=new MutationObserver(changes=>{
   for(const change of changes)
    for(const node of change.addedNodes)if(node.nodeType===1)attach(node);
  });
  observer.observe(root,{childList:true,subtree:true});
 }
};
if(document.readyState==='loading')
 document.addEventListener('DOMContentLoaded',init,{once:true});
else init();
})();
