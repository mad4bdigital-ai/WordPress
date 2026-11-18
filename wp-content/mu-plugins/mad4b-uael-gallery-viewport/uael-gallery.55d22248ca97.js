/* MAD4B offscreen UAEL image-gallery native viewport admission v1.0.0.
 * Preserve original Elementor action and initialize it before it enters view.
 * No synthetic slider, no replacement markup, no gallery-content mutation.
 */
(function(win,doc){
 'use strict';
 if(win.__mad4bUAELGalleryViewportV1 || !win.jQuery)return;
 var state=win.__mad4bUAELGalleryViewportV1={version:'1.0.0',installed:false,intercepted:0,deferred:0,activated:0};
 win.jQuery(win).on('elementor/frontend/init',function(){
  var frontend=win.elementorFrontend,hooks=frontend&&frontend.hooks;
  if(!hooks || typeof hooks.addAction!=='function' || hooks.__mad4bUAELGalleryViewportPatched)return;
  var original=hooks.addAction;
  hooks.addAction=function(name,callback,priority){
   if(name!=='frontend/element_ready/uael-image-gallery.default' || typeof callback!=='function')
    return original.apply(this,arguments);
   state.intercepted++;
   var pending=new WeakMap();
   var wrapped=function($scope){
    var element=$scope&&($scope[0]||$scope);
    if(!element || !element.querySelector || frontend.isEditMode?.() ||
      element.closest('header,footer,.elementor-popup-modal'))
      return callback.apply(this,arguments);
    var gallery=element.querySelector('.uael-img-carousel-wrap');
    if(!gallery)return callback.apply(this,arguments);
    var previous=pending.get(element);
    if(previous && previous.waiting){
      previous.context=this;previous.args=arguments;
      return;
    }
    // An invisible, zero-height, or near-viewport gallery keeps native startup.
    var box=element.getBoundingClientRect();
    if(!('IntersectionObserver' in win) ||
        box.width<1 || box.height<1 ||
        (box.bottom>=-1500 && box.top<=win.innerHeight+1500))
      return callback.apply(this,arguments);
    state.deferred++;
    var job={context:this,args:arguments,waiting:true},observer;
    pending.set(element,job);
    observer=new IntersectionObserver(function(entries){
      if(!job.waiting || !entries.some(function(entry){return entry.isIntersecting;}))return;
      job.waiting=false;observer.disconnect();pending.delete(element);
      if(element.isConnected){
        state.activated++;
        callback.apply(job.context,job.args);
      }
    },{rootMargin:'1500px 0px 1500px 0px',threshold:0});
    observer.observe(element);
   };
   return original.call(this,name,wrapped,priority);
  };
  hooks.__mad4bUAELGalleryViewportPatched=true;
  state.installed=true;
 });
})(window,document);
