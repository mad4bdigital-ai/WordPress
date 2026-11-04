(function(){
 'use strict';
 var root=document.documentElement;
 var cls='mad4b-city-geometry-visible';
 var selector='.elementor-element-95d1c9c';
 if(!root || !('getBoundingClientRect' in document.documentElement)){return;}
 var ticking=false,observer;
 function cards(){return document.querySelectorAll(selector);}
 function reveal(card){
  if(!card.classList.contains(cls))card.classList.add(cls);
 }
 function scan(){
  ticking=false;
  var el=cards();
  if(!el.length)return;
  var screenW=window.innerWidth||document.documentElement.clientWidth;
  var screenH=window.innerHeight||document.documentElement.clientHeight;
  for(var i=0;i<el.length;i++){
   var card=el[i];
   if(card.classList.contains(cls))continue;
   var r=card.getBoundingClientRect();
   if(!r.width||!r.height)continue;
   var slider=card.closest('.swiper,.jet-listing-grid__slider');
   var left=-300,right=screenW+300;
   if(slider){
    var s=slider.getBoundingClientRect();
    left=Math.max(left,s.left-260);
    right=Math.min(right,s.right+260);
   }
   // Cards inside the currently visible *horizontal* slider are always warm,
   // including the rightmost slide that Swiper may not label active/next.
   if(r.right>left && r.left<right && r.bottom>-700 && r.top<screenH+900)
    reveal(card);
  }
 }
 function schedule(){if(ticking)return;ticking=true;requestAnimationFrame(scan);}
 try{
  window.addEventListener('scroll',schedule,{passive:true});
  window.addEventListener('resize',schedule,{passive:true});
  window.addEventListener('orientationchange',schedule,{passive:true});
  if(window.visualViewport){
   window.visualViewport.addEventListener('resize',schedule,{passive:true});
   window.visualViewport.addEventListener('scroll',schedule,{passive:true});
  }
  if('MutationObserver' in window){
   observer=new MutationObserver(schedule);
   observer.observe(document.body||document.documentElement,{subtree:true,childList:true});
  }
  document.addEventListener('DOMContentLoaded',schedule,{once:true});
  window.addEventListener('load',schedule,{once:true});
  schedule();
  window.setTimeout(schedule,400);
  window.setTimeout(schedule,1500);
  // Fail open on unsupported layouts / stalled initialization rather than
  // leaving permanent blank city cards.
  window.setTimeout(function(){
   if(!cards().length || !document.querySelector(selector+'.'+cls))
    root.classList.remove('mad4b-city-geometry-lazy');
  },6500);
 }catch(e){
  root.classList.remove('mad4b-city-geometry-lazy');
  if(observer)observer.disconnect();
 }
})();
