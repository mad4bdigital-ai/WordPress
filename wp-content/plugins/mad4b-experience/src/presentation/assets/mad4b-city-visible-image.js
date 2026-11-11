(function(){
 'use strict';
 var root=document.documentElement;
 var visibleClass='mad4b-city-geometry-visible';
 var selector='.elementor-element-95d1c9c';
 if(!root || !root.getBoundingClientRect || !window.requestAnimationFrame) {
  if(root)root.classList.remove('mad4b-city-geometry-lazy');
  return;
 }
 var queued=false,mutationObserver=null,intersectionObserver=null;
 var observedCards=new WeakSet(),observedSwipers=new WeakSet();
 var stats={version:'2.0.0',revealed:0,swipers:0,swiperEvents:0,ajaxCards:0,fallbacks:0};
 window.mad4bCityImageStats=stats;
 function cards(){return document.querySelectorAll(selector);}
 function near(card){
  var r=card.getBoundingClientRect();
  if(r.width<1 || r.height<1)return false;
  var sw=window.innerWidth||root.clientWidth,sh=window.innerHeight||root.clientHeight;
  if(r.bottom < -650 || r.top>sh+800)return false;
  var slider=card.closest('.swiper,.jet-listing-grid__slider');
  var left=-260,right=sw+260;
  if(slider){
   var s=slider.getBoundingClientRect();
   if(s.bottom < -650 || s.top>sh+800)return false;
   left=Math.max(left,s.left-260);
   right=Math.min(right,s.right+260);
  }
  return r.right>left && r.left<right;
 }
 function reveal(card){
  if(!card.classList.contains(visibleClass)){
   card.classList.add(visibleClass);stats.revealed++;
  }
  // The separate card-background gate must not hide a card that has
  // already become visible in a moving Swiper or an AJAX-inserted grid.
  var item=card.closest('.jet-listing-grid__item');
  if(item && !item.hasAttribute('data-mad4b-card-bg-ready'))
   item.setAttribute('data-mad4b-card-bg-ready','1');
  if(intersectionObserver)intersectionObserver.unobserve(card);
 }
 function eventScan(){stats.swiperEvents++;schedule();window.setTimeout(schedule,90);window.setTimeout(schedule,310);}
 function bindSwipers(){
  var list=document.querySelectorAll('.swiper,.jet-listing-grid__slider');
  for(var i=0;i<list.length;i++){
   var slider=list[i];
   if(!slider.querySelector(selector))continue;
   var sw=slider.swiper;
   if(!sw || !sw.on || observedSwipers.has(sw))continue;
   observedSwipers.add(sw);stats.swipers++;
   ['slideChange','slideChangeTransitionStart','slideChangeTransitionEnd',
    'transitionStart','transitionEnd','resize','observerUpdate','update','loopFix']
    .forEach(function(name){sw.on(name,eventScan);});
  }
 }
 function scan(){
  queued=false;
  bindSwipers();
  var list=cards();
  for(var i=0;i<list.length;i++){
   var card=list[i];
   if(!observedCards.has(card)){
    observedCards.add(card);stats.ajaxCards++;
    if(intersectionObserver)intersectionObserver.observe(card);
   }
   if(near(card))reveal(card);
  }
 }
 function schedule(){if(queued)return;queued=true;window.requestAnimationFrame(scan);}
 function fallback(){
  if(document.hidden)return;
  // Observe slide movement even if a theme replaces the Swiper instance or
  // fails to dispatch events.  No network work is performed by this check.
  var list=cards();
  for(var i=0;i<list.length;i++){
   if(!list[i].classList.contains(visibleClass) && near(list[i])){
    stats.fallbacks++;schedule();return;
   }
  }
  bindSwipers();
 }
 try{
  if('IntersectionObserver' in window){
   intersectionObserver=new IntersectionObserver(function(entries){
    for(var i=0;i<entries.length;i++)
     if(entries[i].isIntersecting && near(entries[i].target))reveal(entries[i].target);
   },{rootMargin:'650px 260px 800px 260px',threshold:0.01});
  }
  window.addEventListener('scroll',schedule,{passive:true});
  window.addEventListener('resize',schedule,{passive:true});
  window.addEventListener('orientationchange',schedule,{passive:true});
  if(window.visualViewport){
   window.visualViewport.addEventListener('resize',schedule,{passive:true});
   window.visualViewport.addEventListener('scroll',schedule,{passive:true});
  }
  if('MutationObserver' in window){
   mutationObserver=new MutationObserver(schedule);
   mutationObserver.observe(document.body||root,{childList:true,subtree:true});
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',schedule,{once:true});
  window.addEventListener('load',schedule,{once:true});
  window.mad4bCityVisibleReady=true;
  schedule();
  window.setTimeout(schedule,250);
  window.setTimeout(schedule,1000);
  window.setTimeout(schedule,2300);
  window.setInterval(fallback,1100);
 }catch(e){
  root.classList.remove('mad4b-city-geometry-lazy');
  if(mutationObserver)mutationObserver.disconnect();
  if(intersectionObserver)intersectionObserver.disconnect();
 }
})();
