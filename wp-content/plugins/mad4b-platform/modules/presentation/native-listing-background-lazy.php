<?php
/**
 * Staging-only native JetEngine listing card background loading.
 * Scope: vetted Elementor listing container IDs, never hero, menu or brand media.
 * No rewriting of product content or price data.
 */
defined('ABSPATH') || exit;
add_action('wp_head',static function(){
 if(wp_get_environment_type()!=='staging'||is_admin()||is_feed()||is_preview())return;
 if(class_exists('Elementor\\Plugin') && isset(\Elementor\Plugin::$instance->preview)
   && \Elementor\Plugin::$instance->preview->is_preview_mode())return;
 ?>
 <style id="mad4b-staging-listing-bg-lazy" data-no-optimize="1">
 html.mad4b-listing-bg-lazy :is(.elementor-element-46370df3,.elementor-element-85bc6bc):not(.mad4b-listing-bg-visible) { background-image: none !important; }
 </style>
 <script id="mad4b-staging-listing-bg-observer" data-no-optimize="1">
 (function(){
   'use strict';
   var root=document.documentElement;
   if(!root||!('IntersectionObserver' in window)||!('MutationObserver' in window))return;
   var selector='.elementor-element-46370df3,.elementor-element-85bc6bc';
   var observer,mo,queued=false,watched=0,swiperHooks=new WeakSet(),seen=new WeakSet();
   function exposeViewportSlides(){
     // Only eager-show rendered slides intersecting the slider plus one neighbor
     // when the slider approaches the visual viewport. Keep other 40+ cards lazy.
     var sliders=document.querySelectorAll('.elementor-element-78d62e0 .swiper');
     for(var i=0;i<sliders.length;i++){
       var slider=sliders[i],bounds=slider.getBoundingClientRect();
       if(bounds.bottom < -500 || bounds.top > window.innerHeight+650)continue;
       var swiper=slider.swiper;
       if(swiper&&!swiperHooks.has(swiper)){
         swiperHooks.add(swiper);swiper.on('slideChange',schedule);swiper.on('resize',schedule);
       }
       var cards=slider.querySelectorAll(selector);
       for(var j=0;j<cards.length;j++){
         var r=cards[j].getBoundingClientRect();
         var buffer=Math.min(Math.max(140,r.width),450);
         if(r.right>bounds.left-buffer && r.left<bounds.right+buffer)
           cards[j].classList.add('mad4b-listing-bg-visible');
       }
     }
   }
   // Shared IIFE scope: Swiper events registered by exposeViewportSlides()
   // must see schedule() even in strict mode (block-scoped try declarations do not).
   function scan(){
     queued=false;
     if(!observer)return;
     exposeViewportSlides();
     var elements=document.querySelectorAll(selector);
     for(var i=0;i<elements.length;i++)if(!seen.has(elements[i])){
       seen.add(elements[i]);observer.observe(elements[i]);watched++;
     }
   }
   function schedule(){if(queued)return;queued=true;requestAnimationFrame(scan);}
   try {
     observer=new IntersectionObserver(function(items){
       for(var i=0;i<items.length;i++)if(items[i].isIntersecting){
         items[i].target.classList.add('mad4b-listing-bg-visible');
         observer.unobserve(items[i].target);
       }
     },{rootMargin:'450px 0px 450px 0px'});
     mo=new MutationObserver(schedule);
     mo.observe(root,{childList:true,subtree:true});
     root.classList.add('mad4b-listing-bg-lazy');
     schedule();
     document.addEventListener('DOMContentLoaded',schedule,{once:true});
     window.addEventListener('load',schedule,{once:true});
     window.addEventListener('scroll',schedule,{passive:true});
     window.addEventListener('resize',schedule,{passive:true});
     window.setTimeout(schedule,1300); // Swiper may initialize after DOM ready.
     // Fail open if the document never produced any matching listing cards.
     window.setTimeout(function(){if(!watched){root.classList.remove('mad4b-listing-bg-lazy');mo.disconnect();}},18000);
   }catch(e){root.classList.remove('mad4b-listing-bg-lazy');if(mo)mo.disconnect();if(observer)observer.disconnect();}
 })();
 </script>
 <?php
},5);
