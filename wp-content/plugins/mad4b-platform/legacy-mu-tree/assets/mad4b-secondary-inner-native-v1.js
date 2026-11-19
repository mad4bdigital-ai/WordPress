/* MAD4B native inner-card batching. JetEngine owns every AJAX request and Swiper. */
(function(window, $) {
 'use strict';
 if(!window || !$) return;
 const states = new WeakMap();
 const bound = new WeakSet();
 const number = (v, def=0) => Number.isFinite(Number(v)) ? Number(v) : def;
 const realCards = root => [...root.querySelectorAll(':scope > .jet-listing-grid__item')]
  .filter(el => !el.classList.contains('swiper-slide-duplicate') && !el.classList.contains('swiper-slide-blank'));
 const navFor = wrapper => {
  const root = wrapper.querySelector('.jet-listing-grid__items');
  if(!root) return null;
  let nav = $(root).data('nav');
  if(typeof nav === 'string'){try{nav=JSON.parse(nav)}catch(_){return null}}
  if(!nav || !nav.enabled || !nav.widget_settings || !number(nav.widget_settings.posts_num)
   || !/^#mad4b-native-inner-[0-9]+-[a-z0-9]+$/.test(String(nav.more_el||'')))return null;
  return {root,nav};
 };
 function onInit(swiper){
  if(!swiper || !swiper.el || !swiper.on) return;
  const wrapper=swiper.el.closest('.jet-listing-grid.jet-listing');
  if(!wrapper) return;
  const setup=navFor(wrapper);
  if(!setup) return;
  const {root,nav}=setup;
  const buttonId=(nav.more_el||'').replace(/^#/,'');
  if(!/^mad4b-native-inner-[0-9]+-[a-z0-9]+$/.test(buttonId))return;
  const trigger=document.getElementById(buttonId);
  if(!trigger || !wrapper.contains(trigger))return;
  const pages=number(root.dataset.pages,1);
  const page=number($(root).data('page'),1);
  if(pages<=1)return;
  let state=states.get(wrapper);
  if(!state){state={loading:false,pendingPage:null,restore:null,swiper:null,failed:false,page};states.set(wrapper,state)}
  state.swiper=swiper;
  state.page=page;
  if(state.restore!==null){
   const target=Math.min(Math.max(0,state.restore), Math.max(0,realCards(root).length-1));
   state.restore=null;
   try{swiper.slideTo(target,0,false)}catch(_){}
  }
  if(state.pendingPage!==null && page>=state.pendingPage){
   state.loading=false;state.pendingPage=null;state.failed=false;
   wrapper.removeAttribute('aria-busy');
  }
  // Keep the original infinite option only after the last page is present.
  if(page>=pages && trigger.dataset.mad4bInnerOriginalLoop==='true' && !swiper.params.loop){
   try{swiper.params.loop=true;swiper.loopCreate();swiper.update()}catch(_){}
  }
  if(bound.has(swiper))return;
  bound.add(swiper);
  const prefetch=()=>{
   if(state.loading || state.failed || state.page>=pages || state.swiper!==swiper)return;
   const cards=realCards(root);
   const at=Math.max(0,number(swiper.realIndex,number(swiper.activeIndex,0)));
   const shown=Math.max(1,Math.ceil(number(swiper.params.slidesPerView,1)));
   if(at+shown+shown < cards.length)return;
   // The native JetEngine click listener is delegated on document. Trigger it,
   // never POST unsigned query data or replace this Slider with another widget.
   state.loading=true;state.pendingPage=state.page+1;state.restore=at;
   wrapper.setAttribute('aria-busy','true');
   trigger.dispatchEvent(new MouseEvent('click',{bubbles:true,cancelable:true}));
   const expected=state.pendingPage;
   // JetEngine's load-more handler does not rewind its page counter on
   // network failure. Offer a safe page reload instead of skipping cards.
   window.setTimeout(()=>{
    if(!state.loading || state.pendingPage!==expected)return;
    state.loading=false;state.failed=true;state.pendingPage=null;
    wrapper.removeAttribute('aria-busy');
    if(wrapper.querySelector('.mad4b-native-inner-retry'))return;
    const link=document.createElement('a');
    link.className='mad4b-native-inner-retry';
    link.href=window.location.href;
    link.textContent=trigger.getAttribute('data-mad4b-retry-label') || 'Reload to continue';
    link.setAttribute('role','status');
    wrapper.appendChild(link);
   },10000);
  };
  swiper.on('slideChangeTransitionEnd',prefetch);
  swiper.on('reachEnd',prefetch);
  swiper.on('touchEnd',prefetch);
  // A foldable/mobile-UA at tablet viewport can receive a phone-sized first
  // batch from the native signed request (e.g. 2 cards, 2 slides visible).
  // Swiper then starts atEnd and has no slide change to trigger lazy paging.
  // Fill ONLY that insufficient first viewport, after it becomes visible;
  // regular phones with enough cards never prefetch on initialization.
  const fillVisible=()=>{
   if(state.loading || state.failed || state.swiper!==swiper || state.page>=pages)return;
   const cards=realCards(root);
   let dynamic=1;
   try{if(typeof swiper.slidesPerViewDynamic==='function')dynamic=number(swiper.slidesPerViewDynamic(),1)}catch(_){}
   const shown=Math.max(1,Math.min(12,Math.ceil(Math.max(number(swiper.params.slidesPerView,1),dynamic))));
   if(cards.length<=shown)prefetch();
  };
  if('IntersectionObserver' in window){
   const observer=new IntersectionObserver((entries)=>{
    if(!entries.some(e=>e.isIntersecting))return;
    observer.disconnect();
    fillVisible();
   },{threshold:0.01});
   observer.observe(wrapper);
   swiper.on('destroy',()=>observer.disconnect());
  }else if(wrapper.getBoundingClientRect().top < window.innerHeight){
   fillVisible();
  }
 }
 const register=()=>{
  if(!window.JetPlugins?.hooks?.addAction)return false;
  if(window.__mad4bNativeInnerHookRegistered)return true;
  window.__mad4bNativeInnerHookRegistered=true;
  window.JetPlugins.hooks.addAction('jet-engine.listing-grid.slider.swiper.after-init','mad4b-native-inner',onInit);
  return true;
 };
 if(!register()){
  $(window).on('jet-engine/frontend/loaded',register);
  $(register);
 }
 $(document).on('jet-engine/listing-grid/after-load-more',function(){
  document.querySelectorAll('.jet-listing-grid[aria-busy="true"]').forEach(wrapper=>{
   const state=states.get(wrapper);
   if(!state)return;
   const setup=navFor(wrapper);if(!setup)return;
   const nextPage=number($(setup.root).data('page'),state.page);
   if(nextPage>=state.page){state.page=nextPage;state.loading=false;state.pendingPage=null;wrapper.removeAttribute('aria-busy')}
  });
 });
})(window, window.jQuery);
