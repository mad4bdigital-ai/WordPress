/* Enhancement only: native <details> and anchor links work without JS. */
(function () {
 'use strict';
 function sync(nav) {
   var drawer=nav.querySelector('.mad4b-tour-toc__drawer');
   if(!drawer) return;
   var shown=0;
   nav.querySelectorAll('li[data-mad4b-link-id]').forEach(function(li){
     var id=li.getAttribute('data-mad4b-link-id');
     var found=id && document.getElementById('mad4b-tour-section-'+id);
     li.hidden=!found; if(found)shown++;
   });
   nav.querySelectorAll('.mad4b-tour-toc__group').forEach(function(group){
     group.hidden=!group.querySelector('li[data-mad4b-link-id]:not([hidden])');
   });
   var count=nav.querySelector('.mad4b-tour-toc__count');
   if(count) count.textContent=String(shown);
   // Reconcile with actual rendered sections, including conditional JetEngine visibility.
   // Bind once even when JetEngine re-renders the listing.
   if (nav.dataset.mad4bTocScrollBound !== '1') {
     nav.dataset.mad4bTocScrollBound='1';
     var openedAt=window.scrollY;
     drawer.addEventListener('toggle',function(){
       if(drawer.open)openedAt=window.scrollY;
     });
     nav.addEventListener('click',function(event){
       if(!event.target.closest('a[href^="#"]'))return;
       drawer.open=false;
     });
     // A long page swipe must not leave the expanded floating TOC over content.
     window.addEventListener('scroll',function(){
       if(drawer.open && Math.abs(window.scrollY-openedAt)>100)drawer.open=false;
     },{passive:true});
   }
 }
 // One dynamic mobile dock height, shared by independent floating controls.
 // Reuse the authored Elementor sticky bottom inquiry CTA; never clone or move it.
 function bindTourMobileDock(){
   if(!document.body.classList.contains('single-tours-and-activities'))return;
   if(document.body.dataset.mad4bTourDockBound==='1')return;
   var dock=[].slice.call(document.querySelectorAll('.elementor-location-single [data-settings*="sticky"]')).find(function(el){
     if(!el.querySelector('.etg-tour-price,.etg-price-native') ||
        !el.querySelector('a[href="#fluentform_6"]'))return false;
     try{return JSON.parse(el.getAttribute('data-settings')||'{}').sticky==='bottom';}
     catch(e){return false;}
   });
   if(!dock)return;
   document.body.dataset.mad4bTourDockBound='1';
   document.body.classList.add('mad4b-tour-mobile-dock-measured');
   dock.classList.add('mad4b-tour-mobile-action-dock');
   var previous=0;
   function publishHeight(height){
     height=Math.ceil(height);
     if(height<30 || height>260 || height===previous)return;
     previous=height;
     document.body.style.setProperty('--mad4b-tour-mobile-dock-height',height+'px');
   }
   function measureFallback(){publishHeight(dock.getBoundingClientRect().height);}
   if('ResizeObserver' in window){
     var observer=new ResizeObserver(function(entries){
       var box=entries[0] && entries[0].borderBoxSize;
       box=Array.isArray(box)?box[0]:box;
       if(box && typeof box.blockSize==='number' && box.blockSize>0)
         publishHeight(box.blockSize);
       else measureFallback();
     });
     try { observer.observe(dock,{box:'border-box'}); }
     catch(e) { observer.observe(dock); }
     window.addEventListener('pageshow',function(e){
       if(e.persisted)measureFallback();
     },{passive:true});
   }else{
     window.requestAnimationFrame(measureFallback);
     window.addEventListener('resize',measureFallback,{passive:true});
     window.addEventListener('pageshow',measureFallback);
   }
 }
 function init(){
   if(document.body.classList.contains('elementor-editor-active'))return;
   document.querySelectorAll('[data-mad4b-native-toc]').forEach(sync);
   bindTourMobileDock();
 }
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init,{once:true});
 else init();
 window.addEventListener('load',bindTourMobileDock,{once:true});
 document.addEventListener('jet-filter-content-rendered',init);
}());
