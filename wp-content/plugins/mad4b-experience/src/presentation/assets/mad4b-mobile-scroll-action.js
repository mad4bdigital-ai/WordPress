(function(){
 'use strict';
 if(!document.body||!document.body.classList.contains('post-type-archive-tours-and-activities'))return;
 var dock='.elementor-element-650c69',pending=false;
 function measure(){
  pending=false;
  if(!matchMedia('(max-width:767px)').matches){document.body.style.removeProperty('--mad4b-mobile-dock-height');document.body.style.removeProperty('--mad4b-mobile-dock-visual-shift');return;}
  var node=document.querySelector(dock);
  if(!node)return;
  var rect=node.getBoundingClientRect(),css=getComputedStyle(node);
  var active=rect.width>80&&css.display!=='none'&&css.visibility!=='hidden';
  document.body.style.setProperty('--mad4b-mobile-dock-height',
   (active?Math.min(Math.ceil(rect.height),175):0)+'px');
  // VisualViewport detects the actual visible bottom on mobile browsers,
  // including the expanded/collapsed address bar (absent in DevTools).
  var vv=window.visualViewport;
  if(!vv || !active || vv.scale!==1 || vv.height<window.innerHeight*.72)return;
  var visibleBottom=vv.offsetTop+vv.height;
  var actualBottom=node.getBoundingClientRect().bottom;
  var current=parseFloat(document.body.style.getPropertyValue('--mad4b-mobile-dock-visual-shift'))||0;
  var delta=visibleBottom-actualBottom;
  if(!Number.isFinite(delta)||Math.abs(delta)<1.5)return;
  var next=Math.max(-160,Math.min(160,Math.round(current+delta)));
  if(Math.abs(next-current)>=2)
   document.body.style.setProperty('--mad4b-mobile-dock-visual-shift',next+'px');
 }
 function schedule(){if(!pending){pending=true;requestAnimationFrame(measure);}}
 function init(){
  var node=document.querySelector(dock);
  if(node&&'ResizeObserver' in window)new ResizeObserver(schedule).observe(node);
  schedule();
 }
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init,{once:true});
 else init();
 window.addEventListener('resize',schedule,{passive:true});
 if(window.visualViewport){window.visualViewport.addEventListener('resize',schedule,{passive:true});window.visualViewport.addEventListener('scroll',schedule,{passive:true});}
})();
