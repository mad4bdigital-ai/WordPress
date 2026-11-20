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
   nav.addEventListener('click',function(event){
     if(!event.target.closest('a[href^="#"]'))return;
     drawer.open=false;
   });
 }
 function init(){
   if(document.body.classList.contains('elementor-editor-active'))return;
   document.querySelectorAll('[data-mad4b-native-toc]').forEach(sync);
 }
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init,{once:true});
 else init();
 document.addEventListener('jet-filter-content-rendered',init);
}());
