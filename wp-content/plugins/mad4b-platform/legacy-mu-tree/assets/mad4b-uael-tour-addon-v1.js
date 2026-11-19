(function(window,document){
 'use strict';
 const logPrefix='MAD4B UAEL Tour Addon';
 const decorate=(widget)=>{
  if(!widget || widget.dataset.mad4bUaelReady==='1')return;
  const native=widget.querySelector('template[data-mad4b-uael-native-template]');
  if(native){
   const html=native.content.cloneNode(true);
   native.replaceWith(html);
  }
  const gallery=widget.querySelector('.uael-gallery-parent .uael-img-gallery-wrap');
  if(!gallery)return;
  const tabs=widget.querySelectorAll('.uael-masonry-filter');
  tabs.forEach(tab=>{
   // UAEL core remains owner of filtering and lightbox interactions.
   tab.setAttribute('role','button');
   tab.tabIndex=0;
   tab.addEventListener('keydown',e=>{
     if(e.key==='Enter'||e.key===' '){e.preventDefault();tab.click();}
   });
  });
  widget.dataset.mad4bUaelReady='1';
  // Native UAEL's Elementor-ready callback performs Isotope/Masonry and
  // Fancybox setup after deferred nodes have actually entered the DOM.
  if(window.elementorFrontend?.hooks?.doAction && window.jQuery){
   try{
    window.elementorFrontend.hooks.doAction(
      'frontend/element_ready/uael-image-gallery.default',
      window.jQuery(widget),window.jQuery
    );
   }catch(e){widget.dataset.mad4bUaelReady='init-error';}
  }
  const ready=widget.querySelectorAll('.uael-grid-item').length;
  if(!ready)widget.dataset.mad4bUaelReady='missing-gallery';
 };
 const tryActivate=()=>{
  document.querySelectorAll('.elementor-popup-modal [data-mad4b-uael-tour="1"]')
    .forEach(host=>decorate(host.closest('.elementor-widget-uael-image-gallery')));
 };
 const scheduled=()=>{
  for(const delay of [0,80,250,700]){
   window.setTimeout(tryActivate,delay);
  }
 };
 if(window.jQuery)window.jQuery(document).on('elementor/popup/show',scheduled);
 document.addEventListener('click',e=>{
  const t=e.target.closest('#single_tour_gellery_button,[href*="popup%3Aopen"]');
  if(t)scheduled();
 },true);
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',scheduled,{once:true});
 else scheduled();
})(window,document);
