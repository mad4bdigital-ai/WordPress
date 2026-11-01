(function(){
 'use strict';
 if(!document.body||!(
  document.body.classList.contains('post-type-archive-tours-and-activities')||
  document.body.classList.contains('single-tours-and-activities')
 ))return;
 var dock='.elementor-element-650c69';
 var share='.elementor-widget-jet-dropbar:has(.elementor-25031) .jet-dropbar';
 var buttonBefore=new WeakMap(),raf=false;
 var lifted=[];
 function restoreShareLayers(){
  lifted.forEach(function(entry){
   entry.saved.forEach(function(v){
    if(v.value)entry.node.style.setProperty(v.name,v.value,v.priority);
    else entry.node.style.removeProperty(v.name);
   });
  });
  lifted=[];
 }
 function promoteShareLayers(){
  restoreShareLayers();
  var open=document.querySelector(share+'.jet-dropbar-open');
  if(!open)return;
  // Open share rail outranks Elementor containers/cards, the sticky inquiry
  // dock, mobile header and any overflow-clipped hero ancestors.
  var node=open,seen=new Set();
  while(node&&node!==document.body&&node.nodeType===1){
   if(!seen.has(node)){
    seen.add(node);
    var computed=getComputedStyle(node),values=[];
    function set(name,value){
     values.push({name:name,value:node.style.getPropertyValue(name),
       priority:node.style.getPropertyPriority(name)});
     node.style.setProperty(name,value,'important');
    }
    if(computed.position==='static')set('position','relative');
    set('z-index','2147483000');
    if(/hidden|clip/.test(computed.overflowX+' '+computed.overflowY))
      set('overflow','visible');
    lifted.push({node:node,saved:values});
   }
   node=node.parentElement;
  }
 }

 var services={
  facebook:function(u){return 'https://www.facebook.com/sharer.php?'+new URLSearchParams({u:u})},
  twitter:function(u){return 'https://twitter.com/intent/tweet?'+new URLSearchParams({url:u})},
  linkedin:function(u){return 'https://www.linkedin.com/sharing/share-offsite/?'+new URLSearchParams({url:u})},
  whatsapp:function(u){return 'https://api.whatsapp.com/send?'+new URLSearchParams({text:u})},
  telegram:function(u){return 'https://t.me/share/url?'+new URLSearchParams({url:u})},
  threads:function(u){return 'https://www.threads.net/intent/post?'+new URLSearchParams({text:u})},
  email:function(u){return 'mailto:?'+new URLSearchParams({subject:document.title,body:u})}
 };
 function setDock(){
  raf=false;
  if(!matchMedia('(max-width:767px)').matches)return;
  var el=document.querySelector(dock);
  if(!el)return;
  var r=el.getBoundingClientRect();
  var active=r.width>80 && getComputedStyle(el).display!=='none' && getComputedStyle(el).visibility!=='hidden';
  var h=active?Math.ceil(r.height):0;
  if(h>175)h=175; // Guard against a broken nested sticky clone.
  document.body.style.setProperty('--mad4b-mobile-dock-height',h+'px');
 }
 function schedule(){if(raf)return;raf=true;requestAnimationFrame(setDock)}
 function closeShare(){
  document.querySelectorAll(share+'.jet-dropbar-open').forEach(function(d){
   d.classList.remove('jet-dropbar-open');
   var btn=d.querySelector('.jet-dropbar__button');
   if(btn)btn.setAttribute('aria-expanded','false');
  });
  restoreShareLayers();
 }
 function openState(d){
  var opened=d.classList.contains('jet-dropbar-open');
  var b=d.querySelector('.jet-dropbar__button');
  if(b)b.setAttribute('aria-expanded',opened?'true':'false');
  if(opened)promoteShareLayers();
  else restoreShareLayers();
 }
 // Capture the original state before vendor JetElements click handlers run.
 // Only intervene when the native "mode: click" handler failed to change it.
 document.addEventListener('click',function(ev){
  var btn=ev.target.closest('.elementor-widget-jet-dropbar:has(.elementor-25031) .jet-dropbar__button');
  if(!btn)return;
  var d=btn.closest('.jet-dropbar');
  if(d)buttonBefore.set(btn,d.classList.contains('jet-dropbar-open'));
 },true);
 document.addEventListener('click',function(ev){
  var btn=ev.target.closest('.elementor-widget-jet-dropbar:has(.elementor-25031) .jet-dropbar__button');
  if(btn){
   var d=btn.closest('.jet-dropbar');if(!d)return;
   var was=buttonBefore.get(btn);
   // Vendor may register its own click action. Preserve it if it has fired.
   if(was===d.classList.contains('jet-dropbar-open')){
    d.classList.toggle('jet-dropbar-open');
   }
   openState(d);
   return;
  }
  if(!ev.target.closest('.elementor-widget-jet-dropbar:has(.elementor-25031) .jet-dropbar__content'))closeShare();
 },false);
 // UAEL social anchors have no href and normally only work once its jQuery
 // Elementor handler initializes. Provide the same destinations directly.
 document.addEventListener('click',function(ev){
  var a=ev.target.closest('.elementor-widget-jet-dropbar:has(.elementor-25031) .uael-share-btn-facebook, .elementor-widget-jet-dropbar:has(.elementor-25031) .uael-share-btn-twitter, .elementor-widget-jet-dropbar:has(.elementor-25031) .uael-share-btn-linkedin, .elementor-widget-jet-dropbar:has(.elementor-25031) .uael-share-btn-whatsapp, .elementor-widget-jet-dropbar:has(.elementor-25031) .uael-share-btn-telegram, .elementor-widget-jet-dropbar:has(.elementor-25031) .uael-share-btn-threads, .elementor-widget-jet-dropbar:has(.elementor-25031) .uael-share-btn-email');
  if(!a)return;
  var kind=Object.keys(services).find(function(k){return a.classList.contains('uael-share-btn-'+k)});
  if(!kind)return;
  var href=services[kind](location.href);
  // Restore a real URL action for every UAEL link. Its original HTML has no
  // href and relies on a jQuery handler that currently fails on this page.
  // A native new-tab link remains functional even when a popup is blocked.
  a.setAttribute('href',href);
  a.setAttribute('target',kind==='email'?'_self':'_blank');
  a.setAttribute('rel','noopener noreferrer');
  ev.stopImmediatePropagation();
  if(kind==='email')return;
  if(!matchMedia('(max-width:767px)').matches){
   // Desktop preserves UAEL's compact popup, with the safe link as fallback.
   var pop=window.open(href,'popUpWindow',
     'height=400,width=600,resizable=yes,scrollbars=yes,toolbar=yes,noopener=no');
   if(pop){try{pop.opener=null}catch(e){}ev.preventDefault();}
  }
 },true);
 // Side rail remains sticky while scrolling. Explicit close only: close
 // button, toggle, click outside, or Escape. Scrolling must never scatter
 // or dismiss the menu independently of the user's decision.
 window.addEventListener('scroll',schedule,{passive:true});
 document.addEventListener('keydown',function(ev){
  if(ev.key==='Escape')closeShare();
 });
 window.addEventListener('resize',schedule,{passive:true});
 if(window.visualViewport)window.visualViewport.addEventListener('resize',schedule,{passive:true});
 function watch(){
  var el=document.querySelector(dock);
  if(el&&'ResizeObserver' in window){new ResizeObserver(schedule).observe(el)}
  schedule();
  document.querySelectorAll(share).forEach(function(d){
   openState(d);
   var panel=d.querySelector('.jet-dropbar__content');
   if(!panel||panel.querySelector('.mad4b-share-rail-close'))return;
   var x=document.createElement('button');
   x.type='button';
   x.className='mad4b-share-rail-close';
   x.setAttribute('aria-label','Close share menu');
   x.textContent='×';
   x.addEventListener('click',function(ev){ev.preventDefault();ev.stopPropagation();closeShare();d.querySelector('.jet-dropbar__button')?.focus()});
   panel.prepend(x);
  });
 }
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',watch,{once:true});
 else watch();
})();
