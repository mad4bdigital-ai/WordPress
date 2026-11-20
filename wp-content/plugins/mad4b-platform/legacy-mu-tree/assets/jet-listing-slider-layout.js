(function(){
 'use strict';
 var queued=false;
 function alignExistingSliders(){
  queued=false;
  document.querySelectorAll('.jet-listing-grid__slider.swiper-initialized').forEach(function(node){
   var slider=node.swiper;
   if(!slider || slider.destroyed || !slider.initialized || !slider.slides || slider.slides.length<2)return;
   // JetEngine/Swiper may initialize before Elementor finishes final
   // container sizing. Re-align at the existing index without navigation.
   var index=slider.activeIndex;
   slider.update();
   if(slider.activeIndex===index)slider.slideTo(index,0,false);
  });
 }
 function queue(){
  if(queued)return;
  queued=true;
  requestAnimationFrame(alignExistingSliders);
 }
 if(document.readyState==='loading'){
  document.addEventListener('DOMContentLoaded',queue,{once:true});
 }else queue();
 window.addEventListener('load',queue,{once:true});
 window.addEventListener('resize',queue,{passive:true});
 // Widgets added by Elementor/JetsmartFilters after initial page load.
 document.addEventListener('jet-smart-filters/inited',queue);
})();
