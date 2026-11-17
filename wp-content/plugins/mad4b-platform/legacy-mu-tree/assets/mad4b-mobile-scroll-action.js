/* Original Elementor CTA dock: edge-to-edge on mobile, floats track measured height. */
(function(){
 'use strict';
 if(!document.body||!document.body.classList.contains('post-type-archive-tours-and-activities'))return;
 if(window.MAD4BMobileDockReady)return;
 window.MAD4BMobileDockReady=true;
 var selector='.elementor-element-650c69',node=null,marker=null,originalParent=null,host=null,pending=false;
 function mobile(){return window.matchMedia('(max-width:767px)').matches;}
 function mount(){
  if(!node)node=document.querySelector(selector);
  if(!node)return false;
  if(mobile()){
   if(!host){
    marker=document.createComment('MAD4B Elementor dock origin');
    originalParent=node.parentNode;
    originalParent.insertBefore(marker,node);
    host=document.createElement('div');
    host.className='mad4b-mobile-dock-host elementor elementor-30843';
    host.setAttribute('data-mad4b-dock','original-elementor');
    document.body.appendChild(host);
   }
   if(node.parentNode!==host)host.appendChild(node);
  }else if(host){
   if(marker&&marker.parentNode)marker.parentNode.insertBefore(node,marker);
   else if(originalParent)originalParent.appendChild(node);
   host.remove();host=null;
  }
  return true;
 }
 function measure(){
  pending=false;
  if(!mount()){document.body.style.setProperty('--mad4b-mobile-dock-height','0px');return;}
  if(!mobile()){
   document.body.style.removeProperty('--mad4b-mobile-dock-height');
   document.body.style.removeProperty('--mad4b-mobile-dock-visual-shift');
   return;
  }
  var surface=host||node,box=surface.getBoundingClientRect(),style=getComputedStyle(surface);
  var visible=box.width>80&&style.display!=='none'&&style.visibility!=='hidden';
  document.body.style.setProperty('--mad4b-mobile-dock-height',
    (visible?Math.min(Math.ceil(box.height),175):0)+'px');
  /* No compensating transform: portal is already viewport-fixed at bottom 0. */
  document.body.style.removeProperty('--mad4b-mobile-dock-visual-shift');
 }
 function schedule(){if(!pending){pending=true;requestAnimationFrame(measure);}}
 function init(){
  if(!mount())return;
  if('ResizeObserver' in window)new ResizeObserver(schedule).observe(node);
  schedule();
 }
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init,{once:true});
 else init();
 window.addEventListener('resize',schedule,{passive:true});
 if(window.visualViewport)window.visualViewport.addEventListener('resize',schedule,{passive:true});
})();
