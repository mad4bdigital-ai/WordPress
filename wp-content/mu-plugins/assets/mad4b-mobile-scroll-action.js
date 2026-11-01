(function(){
 'use strict';
 if(!document.body||!document.body.classList.contains('post-type-archive-tours-and-activities'))return;
 var dock='.elementor-element-650c69',pending=false;
 function measure(){
  pending=false;
  if(!matchMedia('(max-width:767px)').matches)return;
  var node=document.querySelector(dock);
  if(!node)return;
  var rect=node.getBoundingClientRect(),css=getComputedStyle(node);
  var active=rect.width>80&&css.display!=='none'&&css.visibility!=='hidden';
  document.body.style.setProperty('--mad4b-mobile-dock-height',
   (active?Math.min(Math.ceil(rect.height),175):0)+'px');
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
 if(window.visualViewport)window.visualViewport.addEventListener('resize',schedule,{passive:true});
})();
