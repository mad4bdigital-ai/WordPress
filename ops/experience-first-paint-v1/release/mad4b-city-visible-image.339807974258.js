(function(){
 'use strict';
 var root=document.documentElement;
 var visibleClass='mad4b-city-geometry-visible';
 var selector='.elementor-element-95d1c9c';
 if(!root || !root.getBoundingClientRect || !window.requestAnimationFrame) {
  if(root)root.classList.remove('mad4b-city-geometry-lazy');
  return;
 }
 var queued=false,mutationObserver=null,intersectionObserver=null,observedHome=null;
 var observedCards=new WeakSet(),observedSwipers=new WeakSet();
 var stats={version:'2.3.0',revealed:0,swipers:0,swiperEvents:0,ajaxCards:0,fallbacks:0,intentWarm:0,idleWarm:0,navigationWarm:0};
 window.mad4bCityImageStats=stats;
 function homeScope(){
  return document.querySelector('.elementor-element-b25dbff');
 }
 function cards(){
  var home=homeScope();
  return home ? home.querySelectorAll(selector) : document.querySelectorAll(selector);
 }
 function observeHome(){
  if(!mutationObserver)return;
  var home=homeScope();
  if(!home || home===observedHome)return;
  mutationObserver.disconnect();
  // Only card/slide mutations within this owned Elementor widget may trigger
  // geometry work. Never observe every child mutation in document.body.
  mutationObserver.observe(home,{childList:true,subtree:true});
  observedHome=home;
  stats.mutationScope='city-widget';
 }
 function near(card,sliderRects){
  var r=card.getBoundingClientRect();
  if(r.width<1 || r.height<1)return false;
  var sw=window.innerWidth||root.clientWidth,sh=window.innerHeight||root.clientHeight;
  if(r.bottom < -650 || r.top>sh+800)return false;
  var slider=card.closest('.swiper,.jet-listing-grid__slider');
  var left=-260,right=sw+260;
  if(slider){
   var s=sliderRects && sliderRects.get(slider);
   if(!s){s=slider.getBoundingClientRect();if(sliderRects)sliderRects.set(slider,s);}
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
 // Explicitly warmed *only* following user intent. Nothing is preloaded in
 // <head>, and this block does not request images during initial page load.
 var finishedLoad=document.readyState==='complete';
 var cityIntent=false,idleWarmUsed=false,idleWarmQueued=false;
 var touchStartX=null,touchStartY=null,touchSlider=null;
 function homeSlider(){
  var widget=document.querySelector('.elementor-element-b25dbff');
  return widget?widget.querySelector('.jet-listing-grid__slider'):null;
 }
 function sliderNearby(slider){
  if(!slider || document.hidden)return false;
  var r=slider.getBoundingClientRect();
  var h=window.innerHeight||root.clientHeight;
  return r.width>0 && r.height>0 && r.bottom>-160 && r.top<h+180;
 }
 function fromTarget(target){
  var element=target && target.closest?target:null;
  if(!element || !element.closest('.elementor-element-b25dbff'))return null;
  return homeSlider();
 }
 function warmAdjacent(slider,direction,reason){
  if(!sliderNearby(slider))return false;
  var slides=slider.querySelectorAll('.jet-listing-grid__item.swiper-slide');
  if(!slides || slides.length<2 || slides.length>80)return false;
  var r=slider.getBoundingClientRect(),min=Math.max(0,r.left),max=Math.min(window.innerWidth||root.clientWidth,r.right);
  var first=-1,last=-1;
  for(var i=0;i<slides.length;i++){
   var b=slides[i].getBoundingClientRect();
   if(b.width && b.right>min+8 && b.left<max-8){
    if(first<0)first=i;
    last=i;
   }
  }
  if(first<0)return false;
  // Prefer a not-yet-revealed adjacent card. Already-visible cards were
  // requested by the existing geometry gate, so do not fetch them again.
  var start=direction<0?first:last;
  for(var step=1;step<=Math.min(2,slides.length-1);step++){
   var index=(start+(direction<0?-step:step)+slides.length)%slides.length;
   var card=slides[index].querySelector(selector);
   if(!card || card.classList.contains(visibleClass))continue;
   reveal(card);
   stats.intentWarm++;
   if(reason==='idle')stats.idleWarm++;
   if(reason==='navigation'){
    stats.navigationWarm++;
    // The deliberate navigation warm already used the single speculative
    // slot. Do not also request another background through idle warming.
    idleWarmUsed=true;
   }
   return true;
  }
  return false;
 }
 // Reveal at most two adjacent *already-referenced* CSS backgrounds for
 // explicit user navigation, without inserting image tags or <link preload>.
 // A slow/data-saving connection retains the one-image policy.
 function warmNavigation(slider,direction){
  if(!sliderNearby(slider))return 0;
  var warmed=warmAdjacent(slider,direction,'navigation')?1:0;
  if(warmed && networkAllowsIdle() && warmAdjacent(slider,direction,'navigation'))
   warmed++;
  return warmed;
 }
 function networkAllowsIdle(){
  var connection=navigator.connection||navigator.mozConnection||navigator.webkitConnection;
  if(!connection)return true;
  return !connection.saveData &&
    !/^(?:slow-2g|2g|3g)$/i.test(connection.effectiveType||'');
 }
 function queueIdleWarm(){
  if(!finishedLoad || !cityIntent || idleWarmUsed || idleWarmQueued ||
      !networkAllowsIdle() || !sliderNearby(homeSlider()))return;
  idleWarmQueued=true;
  var cb=function(){
   idleWarmQueued=false;
   if(finishedLoad && cityIntent && !idleWarmUsed && sliderNearby(homeSlider())){
    // At most ONE additional background is activated by the idle path.
    if(warmAdjacent(homeSlider(),1,'idle'))idleWarmUsed=true;
   }
  };
  if('requestIdleCallback' in window)requestIdleCallback(cb,{timeout:1700});
  else window.setTimeout(cb,450);
 }
 function cityUserIntent(){
  cityIntent=true;
  queueIdleWarm();
 }
 function onCityNavigation(event){
  var slider=fromTarget(event.target);
  if(!slider)return;
  var prev=event.target.closest('.prev-arrow');
  var next=event.target.closest('.next-arrow');
  if(!prev && !next)return;
  cityUserIntent();
  warmNavigation(slider,prev?-1:1);
  schedule();
  window.setTimeout(schedule,90);
 }
 function onCityKey(event){
  if(!event || !/^(ArrowLeft|ArrowRight|Enter| )$/.test(event.key))return;
  var slider=fromTarget(event.target);
  if(!slider)return;
  var prev=event.target.closest('.prev-arrow'),next=event.target.closest('.next-arrow');
  if(!prev && !next && !/^(ArrowLeft|ArrowRight)$/.test(event.key))return;
  cityUserIntent();
  warmNavigation(slider,prev?-1:(next?1:(event.key==='ArrowLeft'?-1:1)));
 }
 function eventScan(sw,isNavigationEvent){
  stats.swiperEvents++;
  schedule();
  window.setTimeout(schedule,90);
  window.setTimeout(schedule,310);
  // Resize/update/loopFix must not speculate about additional images.
  if(isNavigationEvent && cityIntent && sw && sw.el && fromTarget(sw.el)){
   warmAdjacent(homeSlider(),1,'navigation');
   queueIdleWarm();
  }
 }
 function bindSwipers(){
  var home=homeScope();if(!home)return;
  var list=home.querySelectorAll('.swiper,.jet-listing-grid__slider');
  for(var i=0;i<list.length;i++){
   var slider=list[i];
   if(!slider.querySelector(selector))continue;
   var sw=slider.swiper;
   if(!sw || !sw.on || observedSwipers.has(sw))continue;
   observedSwipers.add(sw);stats.swipers++;
   ['slideChange','slideChangeTransitionStart','slideChangeTransitionEnd',
    'transitionStart','transitionEnd']
    .forEach(function(name){sw.on(name,function(){eventScan(sw,true);});});
   ['resize','observerUpdate','update','loopFix']
    .forEach(function(name){sw.on(name,function(){eventScan(sw,false);});});
  }
 }
 function scan(){
  queued=false;
  observeHome();
  bindSwipers();
  var list=cards(),toReveal=[],sliderRects=new WeakMap();
  // Read all geometry before changing classes and data attributes.
  // This avoids synchronous style/layout recalculation for every card.
  for(var i=0;i<list.length;i++){
   var card=list[i];
   if(!observedCards.has(card)){
    observedCards.add(card);stats.ajaxCards++;
    if(intersectionObserver)intersectionObserver.observe(card);
   }
   if(near(card,sliderRects))toReveal.push(card);
  }
  for(var j=0;j<toReveal.length;j++)reveal(toReveal[j]);
 }
 function schedule(){if(queued)return;queued=true;window.requestAnimationFrame(scan);}
 function fallback(){
  if(document.hidden)return;
  // Observe slide movement even if a theme replaces the Swiper instance or
  // fails to dispatch events.  No network work is performed by this check.
  var list=cards(),sliderRects=new WeakMap();
  for(var i=0;i<list.length;i++){
   if(!list[i].classList.contains(visibleClass) && near(list[i],sliderRects)){
    stats.fallbacks++;schedule();return;
   }
  }
  bindSwipers();
 }
 try{
  if('IntersectionObserver' in window){
   intersectionObserver=new IntersectionObserver(function(entries){
    var toReveal=[],sliderRects=new WeakMap();
    for(var i=0;i<entries.length;i++)
     if(entries[i].isIntersecting && near(entries[i].target,sliderRects))toReveal.push(entries[i].target);
    for(var j=0;j<toReveal.length;j++)reveal(toReveal[j]);
   },{rootMargin:'650px 260px 800px 260px',threshold:0.01});
  }
  // IntersectionObserver is viewport-aware; global scroll scans would
  // duplicate it and force geometry reads on unrelated user scrolls.
  if(!intersectionObserver)window.addEventListener('scroll',schedule,{passive:true});
  // Browser-generated scroll/pointerover events during RTL layout or scroll
  // restoration are NOT proof of user intent. Only input-device events can
  // unlock post-load warming.
  window.addEventListener('wheel',function(event){
   if(event.isTrusted && sliderNearby(homeSlider()))cityUserIntent();
  },{passive:true});
  var lastHoveredNav=null;
  document.addEventListener('pointermove',function(event){
   if(!event.isTrusted || !(event.movementX || event.movementY))return;
   var slider=fromTarget(event.target);
   if(!slider){lastHoveredNav=null;return;}
   cityUserIntent();
   var arrow=event.target.closest('.next-arrow,.prev-arrow');
   if(!arrow){lastHoveredNav=null;return;}
   if(arrow===lastHoveredNav)return;
   lastHoveredNav=arrow;
   warmNavigation(slider,arrow.matches('.prev-arrow')?-1:1);
   schedule();
  },{passive:true});
  document.addEventListener('pointerdown',onCityNavigation,true);
  document.addEventListener('click',onCityNavigation,true);
  document.addEventListener('keydown',onCityKey,true);
  document.addEventListener('touchstart',function(event){
   var slider=fromTarget(event.target);
   if(!slider || !event.touches || !event.touches.length)return;
   touchStartX=event.touches[0].clientX;
   touchStartY=event.touches[0].clientY;
   touchSlider=slider;
  },{passive:true});
  document.addEventListener('touchmove',function(event){
   if(event.isTrusted && sliderNearby(homeSlider()))cityUserIntent();
   if(!touchSlider || touchStartX===null || !event.touches || !event.touches.length)return;
   var dx=event.touches[0].clientX-touchStartX;
   var dy=event.touches[0].clientY-touchStartY;
   if(Math.abs(dx)<22 || Math.abs(dx)<Math.abs(dy)*1.2)return;
   cityUserIntent();
   var rtl=(touchSlider.swiper && touchSlider.swiper.rtlTranslate) ||
    document.documentElement.dir==='rtl';
   warmNavigation(touchSlider,(dx<0)!==!!rtl?1:-1);
   touchStartX=null;
  },{passive:true});
  document.addEventListener('touchend',function(){touchStartX=null;touchSlider=null;},{passive:true});
  window.addEventListener('resize',schedule,{passive:true});
  window.addEventListener('orientationchange',schedule,{passive:true});
  if(window.visualViewport){
   window.visualViewport.addEventListener('resize',schedule,{passive:true});
   window.visualViewport.addEventListener('scroll',schedule,{passive:true});
  }
  if('MutationObserver' in window){
   mutationObserver=new MutationObserver(schedule);
   observeHome();
   // If this asset runs before the city widget exists, the DOMContentLoaded
   // scan binds the observer later; do not attach to body as a fallback.
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',schedule,{once:true});
  window.addEventListener('load',function(){
   finishedLoad=true;
   schedule();
   queueIdleWarm();
  },{once:true});
  window.mad4bCityVisibleReady=true;
  schedule();
  window.setTimeout(schedule,250);
  window.setTimeout(schedule,1000);
  window.setTimeout(schedule,2300);
  // The full repeated fallback is for browsers without IntersectionObserver.
  // Modern browsers already get intersection + Swiper change/resize events.
  if(!intersectionObserver){
   stats.fallbackMode='legacy-interval';
   window.setInterval(fallback,1100);
  }else{
   stats.fallbackMode='bounded';
   window.setTimeout(fallback,1200);
   window.setTimeout(fallback,2800);
   window.setTimeout(fallback,5500);
  }
 }catch(e){
  root.classList.remove('mad4b-city-geometry-lazy');
  if(mutationObserver)mutationObserver.disconnect();
  if(intersectionObserver)intersectionObserver.disconnect();
 }
})();
