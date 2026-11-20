(() => {
 'use strict';
 const enable=()=>{
  if(document.querySelector('form.jet-form,.jet-form form,form.jet-form-builder')) {
   const already=document.body.classList.contains('mad4b-jetengine-form-recipe');
   document.body.classList.add('mad4b-jetengine-form-recipe');
   if(!already)document.dispatchEvent(new CustomEvent('mad4b:jetengine:mode-changed'));
  }
 };
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',enable,{once:true});
 else enable();
 // Elementor's dynamically rendered native JetEngine forms.
 document.addEventListener('jet-engine/forms/loaded',enable);
})();
