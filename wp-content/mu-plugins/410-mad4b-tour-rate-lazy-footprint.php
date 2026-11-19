<?php
/**
 * Plugin Name: MAD4B Rate Accordion Lazy Footprint
 * Version: 1.0.0
 * Description: Reserve the JetEngine rate listing footprint from its existing
 * first-party Query Builder total before signed lazy AJAX swaps the placeholder.
 */
defined('ABSPATH') || exit;

/**
 * This is presentation-only: no independent rate query, no price or booking
 * change and no disabling of JetEngine signed AJAX / date filtering.
 */
add_action('wp_head', static function (): void {
    if (is_admin() || wp_doing_ajax() || !is_singular('tours-and-activities')
        || is_preview() || (defined('REST_REQUEST') && REST_REQUEST)) {
        return;
    }
    echo <<<'HTML'
<style id="mad4b-rate-lazy-footprint-v1" data-no-optimize="1">
@media(max-width:767px){
  body.single-tours-and-activities
  .jet-listing-grid.mad4b-rate-lazy-reserved.jet-listing-grid--lazy-load:not(.jet-listing-grid--lazy-load-completed){
    min-block-size:var(--mad4b-rate-lazy-reserve,36px)!important;
    box-sizing:border-box;
  }
}
</style>
<script id="mad4b-rate-lazy-footprint-v1-js" data-no-optimize="1">
(function(){
 'use strict';
 const widgetSelector='.elementor-widget-jet-listing-grid#tour_rates_accordion';
 const rateRowHeight=138.5;
 const attached=new WeakSet();
 const parsedNumber=s=>{
   const raw=String(s||'').trim().replace(/[٠-٩]/g,c=>String(c.charCodeAt(0)-0x660))
       .replace(/[۰-۹]/g,c=>String(c.charCodeAt(0)-0x6f0))
       .replace(/[\s,\u066c]/g,'');
   return /^\d{1,3}$/.test(raw)?Number(raw):NaN;
 };
 function update(widget){
   const grid=widget.querySelector('.jet-listing-grid--lazy-load[data-lazy-load]');
   if(!grid||grid.classList.contains('jet-listing-grid--lazy-load-completed')) return;
   let config;
   try{config=JSON.parse(grid.getAttribute('data-lazy-load')||'{}');}catch(_){return;}
   const queryId=String(config?.query?.query_id||'');
   if(!/^\d+$/.test(queryId)) return;
   const documentRoot=widget.closest('.elementor-template')||widget.parentElement;
   const count=documentRoot?.querySelector('.jet-engine-query-count[data-query="'+queryId+'"].count-type-total');
   const n=parsedNumber(count?.textContent);
   if(!Number.isFinite(n)||n<1||n>20) return;
   // The default native row is ~138.5 CSS px at mobile widths. This
   // reservation vanishes automatically when the actual AJAX card arrives.
   const reserve=Math.round(rateRowHeight*n);
   if(grid.style.getPropertyValue('--mad4b-rate-lazy-reserve')!==reserve+'px'){
     grid.style.setProperty('--mad4b-rate-lazy-reserve',reserve+'px');
   }
   if(!grid.classList.contains('mad4b-rate-lazy-reserved')){
     grid.classList.add('mad4b-rate-lazy-reserved');
   }
 }
 function bind(widget){
   update(widget);
   if(attached.has(widget)) return;
   attached.add(widget);
   // Observe only the associated rate template, never the whole document.
   const root=widget.closest('.elementor-template')||widget.parentElement;
   if(!root) return;
   let scheduled=false;
   const observer=new MutationObserver(()=>{
     if(scheduled)return;
     scheduled=true;
     requestAnimationFrame(()=>{
       scheduled=false;
       if(!widget.isConnected){observer.disconnect();return;}
       update(widget);
     });
   });
   observer.observe(root,{childList:true,subtree:true,characterData:true,
      attributes:true,attributeFilter:['class','data-lazy-load']});
 }
 function boot(){document.querySelectorAll(widgetSelector).forEach(bind);}
 if(document.readyState==='loading'){
   document.addEventListener('DOMContentLoaded',boot,{once:true});
 }else{boot();}
 window.addEventListener('pageshow',boot,{passive:true});
})();
</script>
HTML;
}, 18);
