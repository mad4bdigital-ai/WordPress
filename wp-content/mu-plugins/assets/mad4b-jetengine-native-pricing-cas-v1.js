/* Signed source revision inside the ONE vendor JetEngine options form. */
(()=>{
 'use strict';
 const cfg=window.MAD4BJetEngineNativePricingCAS;
 if(!cfg||!cfg.revision||!cfg.nonce)return;
 const form=[...document.querySelectorAll('.jet-engine-options-page-wrap form')].find(f=>
   f.querySelector('#settings_top')&&f.querySelector('button[type="submit"]'));
 if(!form)return;
 const hidden=(name,value)=>{
   let input=form.querySelector('input[name="'+name+'"]');
   if(!input){input=document.createElement('input');input.type='hidden';input.name=name;form.append(input);}
   input.value=value;
 };
 hidden('mad4b_native_cas_nonce',cfg.nonce);
 hidden('mad4b_native_cas_revision',cfg.revision);
})();
