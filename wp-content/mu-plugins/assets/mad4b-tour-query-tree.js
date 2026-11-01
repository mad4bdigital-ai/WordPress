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
 document.addEventListener('click',function(ev){
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
