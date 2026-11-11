(function () {
 'use strict';
 const config=window.CatalogNativeFilterState;
 if(!config||!Array.isArray(config.expected))return;
 let done=false,attempts=0;
 document.addEventListener('pointerdown',event=>{if(event.target.closest('.jet-filter,.jet-remove-all-filters'))done=true;},{capture:true,passive:true});
 function hydrate(){
  if(done||++attempts>16)return;
  const group=window.JetSmartFilters?.filterGroups?.[config.provider];
  if(!group){setTimeout(hydrate,150);return;}
  const missing=[];
  for(const item of config.expected){
   const inputs=Array.from(document.querySelectorAll('input[type="checkbox"][name="'+CSS.escape(item.taxonomy)+'"][data-url-value="'+CSS.escape(item.slug)+'"]'));
   if(!inputs.length){setTimeout(hydrate,150);return;}
   if(!inputs.some(input=>input.checked))missing.push(inputs[0]);
  }
  done=true;
  // Use each native control's handler; JSF owns queries, results, URL and reset state.
  missing.forEach((input,index)=>setTimeout(()=>{if(!input.checked)input.click();},index*85));
 }
 document.addEventListener('jet-smart-filters/inited',()=>setTimeout(hydrate,80),{once:true});
 setTimeout(hydrate,180);
}());
