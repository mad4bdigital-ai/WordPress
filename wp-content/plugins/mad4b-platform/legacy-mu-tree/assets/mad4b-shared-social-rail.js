(function(){
 'use strict';
 if(window.__mad4bSharedSocialRailBooted)return;
 window.__mad4bSharedSocialRailBooted=true;
 var widgetSelector='.elementor-widget-jet-dropbar.mad4b-share-rail-managed';
 var nodeSelector=widgetSelector+' .jet-dropbar';
 var active=null,was=new WeakMap(),scanQueued=false,pending=new Set();
 var floating=null,primaryWidget=null,originalAnchor=null,generated=false,scrollQueued=false;
 var onFrontPage=window.MAD4BShareRailIsFrontPage===true;

 var schemes={
  facebook:function(u){return 'https://www.facebook.com/sharer.php?'+new URLSearchParams({u:u})},
  twitter:function(u){return 'https://twitter.com/intent/tweet?'+new URLSearchParams({url:u})},
  linkedin:function(u){return 'https://www.linkedin.com/sharing/share-offsite/?'+new URLSearchParams({url:u})},
  whatsapp:function(u){return 'https://api.whatsapp.com/send?'+new URLSearchParams({text:u})},
  telegram:function(u){return 'https://t.me/share/url?'+new URLSearchParams({url:u})},
  threads:function(u){return 'https://www.threads.net/intent/post?'+new URLSearchParams({text:u})},
  email:function(u){return 'mailto:?'+new URLSearchParams({subject:document.title,body:u})}
 };
 function widgetOf(target){
  var widget=target&&target.closest&&target.closest('.elementor-widget-jet-dropbar');
  return widget&&widget.classList.contains('mad4b-share-rail-managed')?widget:null;
 }
 function railOf(widget){return widget&&widget.querySelector('.jet-dropbar')}
 function isCompatible(widget){
  var dropbar=railOf(widget);
  return !!(dropbar && dropbar.querySelector('.jet-dropbar__button') &&
   dropbar.querySelector('.jet-dropbar__content-wrapper .uael-container.uael-style-inline'));
 }
 function urlFor(container){
  var mode=container.getAttribute('data-share_url_type')||'current_page';
  var supplied=container.getAttribute('data-share_url')||'';
  if(mode==='custom'&&supplied){
   try{
    var url=new URL(supplied,location.href);
    if(url.protocol==='http:'||url.protocol==='https:')return url.href;
   }catch(e){}
  }
  // Do not guess a card link when the vendor explicitly requests current_page.
  return location.href;
 }
 function prepareLinks(widget){
  widget.querySelectorAll('.uael-container.uael-style-inline').forEach(function(container){
   var url=urlFor(container);
   container.querySelectorAll('a[class*="uael-share-btn-"]').forEach(function(a){
    var kind=Object.keys(schemes).find(function(k){return a.classList.contains('uael-share-btn-'+k)});
    if(!kind)return;
    a.href=schemes[kind](url);
    a.target=kind==='email'?'_self':'_blank';
    a.rel='noopener noreferrer';
   });
  });
 }
 // The share rail owns only its widget layer. Never modify the ancestors:
 // doing so elevates the entire Elementor archive above ChatbotX and headers.
 function markRailOpen(dropbar,opened){
  var widget=widgetOf(dropbar);
  if(widget)widget.classList.toggle('mad4b-share-rail-open',!!opened);
 }
 function syncTopLayer(d,opened){
  var panel=d&&d.querySelector('.jet-dropbar__content-wrapper');
  if(!panel||!panel.isConnected||typeof panel.showPopover!=='function'||typeof panel.hidePopover!=='function')return;
  if(panel.getAttribute('popover')!=='manual')panel.setAttribute('popover','manual');
  try{
   var visible=panel.matches(':popover-open');
   if(opened&&!visible)panel.showPopover();
   else if(!opened&&visible)panel.hidePopover();
  }catch(e){}
 }
 function setAria(d,open){
  var button=d&&d.querySelector('.jet-dropbar__button');
  if(button)button.setAttribute('aria-expanded',open?'true':'false');
 }
 function close(){
  var closing=new Set();
  if(active)closing.add(active);
  document.querySelectorAll(nodeSelector+'.jet-dropbar-open').forEach(function(d){closing.add(d)});
  document.querySelectorAll(widgetSelector+'.mad4b-share-rail-open').forEach(function(widget){
   var d=railOf(widget);
   if(d)closing.add(d);
   widget.classList.remove('mad4b-share-rail-open');
  });
  closing.forEach(function(d){
   d.classList.remove('jet-dropbar-open');
   setAria(d,false);
   syncTopLayer(d,false);
  });
  active=null;
 }
 function show(d){
  if(active&&active!==d)close();
  document.querySelectorAll(nodeSelector+'.jet-dropbar-open').forEach(function(other){
   if(other!==d){other.classList.remove('jet-dropbar-open');setAria(other,false);syncTopLayer(other,false)}
  });
  if(!d.classList.contains('jet-dropbar-open'))d.classList.add('jet-dropbar-open');
  setAria(d,true);
  active=d;markRailOpen(d,true);syncTopLayer(d,true);
 }
 function sync(d){
  if(d.classList.contains('jet-dropbar-open'))show(d);
  else if(active===d)close();
  else {setAria(d,false);syncTopLayer(d,false)}
 }

 function makeFallback(){
  // Only on inner pages with no compatible original widget.
  var widget=document.createElement('div');
  widget.className='elementor-widget-jet-dropbar mad4b-share-rail-generated';
  var shell=document.createElement('div');shell.className='jet-dropbar';
  var inner=document.createElement('div');inner.className='jet-dropbar__inner';
  var trigger=document.createElement('button');trigger.type='button';
  trigger.className='jet-dropbar__button';
  trigger.setAttribute('aria-label','Open Share Menu');
  trigger.setAttribute('aria-expanded','false');
  trigger.innerHTML='<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 16V3m0 0 5 5m-5-5L7 8"/><path d="M5 13v6a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-6"/></svg>';
  var wrapper=document.createElement('div');wrapper.className='jet-dropbar__content-wrapper';
  var body=document.createElement('div');body.className='jet-dropbar__content';
  var shares=document.createElement('div');shares.className='uael-container uael-style-inline';
  shares.setAttribute('data-share_url_type','current_page');
  var grid=document.createElement('div');grid.className='elementor-grid';
  var names={facebook:'f',twitter:'X',linkedin:'in',whatsapp:'WA',telegram:'TG',threads:'@',email:'✉'};
  Object.keys(schemes).forEach(function(kind){
   var item=document.createElement('div');item.className='elementor-grid-item';
   var link=document.createElement('a');link.className='uael-share-btn-'+kind;
   link.setAttribute('aria-label','Share via '+kind);
   var visual=document.createElement('span');visual.className='uael-share-btn__icon';
   visual.textContent=names[kind];link.appendChild(visual);item.appendChild(link);grid.appendChild(item);
  });
  shares.appendChild(grid);body.appendChild(shares);wrapper.appendChild(body);
  inner.appendChild(trigger);inner.appendChild(wrapper);shell.appendChild(inner);
  widget.appendChild(shell);
  return widget;
 }
 function findOriginal(){
  var candidates=[...document.querySelectorAll(widgetSelector)]
   .filter(function(w){return w.isConnected && isCompatible(w) && !w.classList.contains('mad4b-share-rail-generated')});
  return candidates.find(function(w){return !!(w.getClientRects().length) &&
    getComputedStyle(w).display!=='none' && !w.closest('.elementor-sticky__spacer')}) || candidates[0] || null;
 }
 function floatingContainer(){
  if(!floating){
   floating=document.createElement('div');
   floating.id='mad4b-floating-share-rail';
   floating.setAttribute('data-mad4b-share-rail','fixed');
   document.body.appendChild(floating);
  }
  return floating;
 }
 function acquireWidget(){
  if(primaryWidget && primaryWidget.isConnected)return;
  var widget=findOriginal();
  if(widget){
   originalAnchor=document.createElement('div');
   originalAnchor.className='mad4b-share-rail-original-placeholder';
   originalAnchor.setAttribute('aria-hidden','true');
   originalAnchor.style.cssText='display:none;visibility:hidden;pointer-events:none;overflow-anchor:none;';
   widget.parentNode.insertBefore(originalAnchor,widget);
   generated=false;
  }else{
   // Do not replace a real archive Share widget with a hidden generated
   // fallback while UAEL/JetElements is still hydrating its inner links.
   if(document.body.classList.contains('post-type-archive-tours-and-activities')
      && document.querySelector('.elementor-element-44b808e .jet-dropbar__button'))return;
   widget=makeFallback();
   widget.style.display='none';
   document.body.appendChild(widget);
   mount(widget);
   generated=true;
  }
  primaryWidget=widget;
  widget.classList.add('mad4b-share-rail-primary');
  document.body.classList.add('mad4b-share-rail-enabled');
 }
 function transitionFloating(){
  if(onFrontPage)return;
  acquireWidget();
  if(!primaryWidget)return;
  // A small scroll threshold filters out accidental 1-2px viewport jumps.
  // An archive hero may resize during slideshow/Font/Elementor hydration.
  // Keep the original Share trigger inside it until the hero actually exits.
  var archiveHero=document.body.classList.contains('post-type-archive-tours-and-activities')
   ? document.querySelector('.elementor-element-6cda41f5') : null;
  var shouldFloat=archiveHero
   ? archiveHero.getBoundingClientRect().bottom<=24
   : window.scrollY>56;
  var widget=primaryWidget;
  if(shouldFloat){
   var layer=floatingContainer();
   if(widget.parentNode!==layer){
    close();
    if(originalAnchor){
     var bounds=widget.getBoundingClientRect();
     originalAnchor.style.width=Math.max(1,Math.round(bounds.width))+'px';
     originalAnchor.style.height=Math.max(1,Math.round(bounds.height))+'px';
     originalAnchor.style.display='block';
    }
    layer.appendChild(widget);
   }
   widget.style.removeProperty('display');
   widget.classList.add('mad4b-share-rail-floating');
   document.body.classList.add('mad4b-share-rail-is-floating');
  }else{
   if(!generated && originalAnchor && originalAnchor.isConnected){
    if(widget.previousSibling!==originalAnchor){
     close();
     originalAnchor.parentNode.insertBefore(widget,originalAnchor.nextSibling);
    }
    originalAnchor.style.display='none';
    widget.style.removeProperty('display');
   }else if(generated){
    close();
    widget.style.display='none';
   }
   widget.classList.remove('mad4b-share-rail-floating');
   document.body.classList.remove('mad4b-share-rail-is-floating');
  }
 }
 function schedulePosition(){
  if(scrollQueued)return;
  scrollQueued=true;
  requestAnimationFrame(function(){scrollQueued=false;transitionFloating()});
 }
 function mount(widget){
  if(widget.classList.contains('mad4b-share-rail-managed'))return;
  if(!isCompatible(widget))return; // Leave unrelated dropbars untouched.
  widget.classList.add('mad4b-share-rail-managed');
  var d=railOf(widget),body=d.querySelector('.jet-dropbar__content');
  if(!body)return;
  if(!body.querySelector('.mad4b-share-rail-close')){
   var button=document.createElement('button');
   button.type='button';button.className='mad4b-share-rail-close';
   button.setAttribute('aria-label','Close share menu');
   button.textContent='×';
   button.addEventListener('click',function(ev){
    ev.preventDefault();ev.stopPropagation();close();
    d.querySelector('.jet-dropbar__button')?.focus();
   });
   body.prepend(button);
  }
  prepareLinks(widget);setAria(d,d.classList.contains('jet-dropbar-open'));
  markRailOpen(d,d.classList.contains('jet-dropbar-open'));
  syncTopLayer(d,d.classList.contains('jet-dropbar-open'));
 }
 function scan(root){
  if(root.nodeType!==1 && root.nodeType!==9)return;
  if(root.matches&&root.matches('.elementor-widget-jet-dropbar'))mount(root);
  if(root.querySelectorAll)
   root.querySelectorAll('.elementor-widget-jet-dropbar').forEach(mount);
 }
 function scheduleScan(mutations){
  // Only inspect affected widgets, not the full Elementor document after
  // every mutation. Crucial on tour listing/filter AJAX with large DOMs.
  mutations.forEach(function(m){
   m.addedNodes.forEach(function(node){
    if(node.nodeType!==1)return;
    var owner=node.closest&&node.closest('.elementor-widget-jet-dropbar');
    if(owner)pending.add(owner);
    if(node.matches&&node.matches('.elementor-widget-jet-dropbar'))pending.add(node);
    if(node.querySelectorAll)
     node.querySelectorAll('.elementor-widget-jet-dropbar').forEach(function(w){pending.add(w)});
   });
  });
  if(!pending.size||scanQueued)return;
  scanQueued=true;
  requestAnimationFrame(function(){
   scanQueued=false;
   pending.forEach(function(widget){if(widget.isConnected)mount(widget)});
   pending.clear();
   if(!primaryWidget)schedulePosition();
  });
 }
 // Snapshot before JetElements handlers. After the synchronous click queue,
 // defer to JetElements if it handled click; otherwise restore click mode.
 document.addEventListener('click',function(ev){
  var button=ev.target.closest&&ev.target.closest(widgetSelector+' .jet-dropbar__button');
  if(!button)return;
  var d=button.closest('.jet-dropbar');
  if(d)was.set(button,d.classList.contains('jet-dropbar-open'));
 },true);
 document.addEventListener('click',function(ev){
  var button=ev.target.closest&&ev.target.closest(widgetSelector+' .jet-dropbar__button');
  if(button){
   var d=button.closest('.jet-dropbar'),previous=was.get(button);
   if(!d)return;
   setTimeout(function(){
    if(!d.isConnected)return;
    if(d.classList.contains('jet-dropbar-open')===previous){
     if(previous)close();
     else show(d);
    }else sync(d);
   },0);
   return;
  }
  if(ev.target.closest && !ev.target.closest(widgetSelector+' .jet-dropbar__content'))
   close();
 },false);
 // Update links at click time to follow JSF AJAX navigation, WPML and the
 // current provider setting. Links work without UAEL's jQuery handler.
 document.addEventListener('click',function(ev){
  var a=ev.target.closest&&ev.target.closest(widgetSelector+' a[class*="uael-share-btn-"]');
  if(!a)return;
  var widget=widgetOf(a);
  if(widget)prepareLinks(widget);
 },true);
 document.addEventListener('keydown',function(ev){
  if(ev.key==='Escape'&&active){
   var trigger=active.querySelector('.jet-dropbar__button');
   close();
   if(trigger&&trigger.isConnected)trigger.focus();
  }
 });
 function init(){
  if(onFrontPage){
   document.body.classList.add('mad4b-share-rail-front-excluded');
   return;
  }
  scan(document);
  transitionFloating();
  window.addEventListener('scroll',schedulePosition,{passive:true});
  window.addEventListener('resize',schedulePosition,{passive:true});
  window.addEventListener('pageshow',schedulePosition);
  if(window.MutationObserver){
   new MutationObserver(scheduleScan).observe(document.body,{childList:true,subtree:true});
  }
 }
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init,{once:true});
 else init();
})();
