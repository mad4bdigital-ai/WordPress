(function(){
 'use strict';
 function close(li){
  li.dataset.open='false';
  var b=li.querySelector(':scope > .mad4b-tour-tree-row > .mad4b-tour-tree-toggle');
  if(b)b.setAttribute('aria-expanded','false');
  li.querySelectorAll('.mad4b-tour-tree-item[data-open=true]').forEach(function(child){
   child.dataset.open='false';var t=child.querySelector(':scope > .mad4b-tour-tree-row > .mad4b-tour-tree-toggle');
   if(t)t.setAttribute('aria-expanded','false');
  });
 }
 function activateTab(tab,focus){
  var nav=tab.closest('.mad4b-tour-facet-nav');
  if(!nav)return;
  nav.querySelectorAll('.mad4b-tour-facet-tab[role=tab]').forEach(function(btn){
   var selected=btn===tab;
   btn.setAttribute('aria-selected',selected?'true':'false');
   btn.tabIndex=selected?0:-1;
   var panel=document.getElementById(btn.getAttribute('aria-controls'));
   if(panel)panel.hidden=!selected;
  });
  nav.querySelectorAll('.mad4b-tour-tree-item[data-open=true]').forEach(close);
  if(focus)tab.focus();
  // Never force a global scroll jump. On small screens, tabs are scrollable.
  if(tab.scrollIntoView)tab.scrollIntoView({block:'nearest',inline:'nearest',behavior:'auto'});
 }
 document.addEventListener('keydown',function(ev){
  var tab=ev.target.closest('.mad4b-tour-facet-tab[role=tab]');
  if(!tab)return;
  var list=tab.closest('[role=tablist]');
  if(!list)return;
  var tabs=[].slice.call(list.querySelectorAll(':scope > .mad4b-tour-facet-tab[role=tab]'));
  var index=tabs.indexOf(tab),next=-1;
  if(ev.key==='ArrowRight')next=(index+1)%tabs.length;
  else if(ev.key==='ArrowLeft')next=(index+tabs.length-1)%tabs.length;
  else if(ev.key==='Home')next=0;
  else if(ev.key==='End')next=tabs.length-1;
  else if(ev.key==='Enter'||ev.key===' ')next=index;
  if(next<0)return;
  ev.preventDefault();
  activateTab(tabs[next],true);
 });
 document.addEventListener('click',function(ev){
  var tab=ev.target.closest('.mad4b-tour-facet-tab[role=tab]');
  if(tab){ev.preventDefault();activateTab(tab,false);return;}
  var button=ev.target.closest('.mad4b-tour-query-mega .mad4b-tour-tree-toggle');
  if(!button){
   if(!ev.target.closest('.mad4b-tour-query-mega'))
    document.querySelectorAll('.mad4b-tour-query-mega .mad4b-tour-tree-item[data-open=true]').forEach(close);
   return;
  }
  ev.preventDefault();
  var li=button.closest('.mad4b-tour-tree-item');if(!li)return;
  var next=li.dataset.open!=='true';
  var level=li.parentElement;
  if(level)level.querySelectorAll(':scope > .mad4b-tour-tree-item[data-open=true]').forEach(close);
  li.dataset.open=next?'true':'false';button.setAttribute('aria-expanded',next?'true':'false');
 });
 document.addEventListener('keydown',function(ev){
  if(ev.key!=='Escape')return;
  var root=ev.target.closest('.mad4b-tour-query-mega');
  if(!root)return;
  var open=root.querySelectorAll('.mad4b-tour-tree-item[data-open=true]');
  if(!open.length)return;
  open.forEach(close);var first=root.querySelector('.mad4b-tour-tree-toggle');
  if(first)first.focus();
  ev.stopPropagation();
 });
 // On desktop CSS controls hover, but assistive technology needs the ARIA state.
 document.addEventListener('pointerover',function(ev){
  if(!window.matchMedia('(min-width: 992px)').matches)return;
  var li=ev.target.closest('.mad4b-tour-query-mega .mad4b-tour-tree-parent');
  if(!li)return;
  var btn=li.querySelector(':scope > .mad4b-tour-tree-row > .mad4b-tour-tree-toggle');
  if(btn)btn.setAttribute('aria-expanded','true');
 });
 document.addEventListener('pointerout',function(ev){
  if(!window.matchMedia('(min-width: 992px)').matches)return;
  var li=ev.target.closest('.mad4b-tour-query-mega .mad4b-tour-tree-parent');
  if(!li||li.contains(ev.relatedTarget))return;
  var btn=li.querySelector(':scope > .mad4b-tour-tree-row > .mad4b-tour-tree-toggle');
  if(btn&&li.dataset.open!=='true')btn.setAttribute('aria-expanded','false');
 });
})();
