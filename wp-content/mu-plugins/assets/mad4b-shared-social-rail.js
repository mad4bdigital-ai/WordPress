(function(){
 'use strict';
 var widgetSelector='.elementor-widget-jet-dropbar.mad4b-share-rail-managed';
 var nodeSelector=widgetSelector+' .jet-dropbar';
 var rank='2147483000',active=null,ancestors=[],was=new WeakMap(),scanQueued=false,pending=new Set();
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
 function releaseStack(){
  for(var i=ancestors.length-1;i>=0;i--){
   var item=ancestors[i];
   item.styles.forEach(function(v){
    if(v.value)item.node.style.setProperty(v.key,v.value,v.priority);
    else item.node.style.removeProperty(v.key);
   });
  }
  ancestors=[];
 }
 function liftStack(d){
  releaseStack();
  for(var node=d;node&&node!==document.body;node=node.parentElement){
   var computed=getComputedStyle(node),saved=[];
   function set(key,value){
    saved.push({key:key,value:node.style.getPropertyValue(key),
     priority:node.style.getPropertyPriority(key)});
    node.style.setProperty(key,value,'important');
   }
   if(computed.position==='static')set('position','relative');
   set('z-index',rank);
   if(/hidden|clip/.test(computed.overflowX+' '+computed.overflowY))set('overflow','visible');
   ancestors.push({node:node,styles:saved});
  }
 }
 function setAria(d,open){
  var button=d&&d.querySelector('.jet-dropbar__button');
  if(button)button.setAttribute('aria-expanded',open?'true':'false');
 }
 function close(){
  document.querySelectorAll(nodeSelector+'.jet-dropbar-open').forEach(function(d){
   d.classList.remove('jet-dropbar-open');
   setAria(d,false);
  });
  releaseStack();active=null;
 }
 function show(d){
  if(active&&active!==d)close();
  document.querySelectorAll(nodeSelector+'.jet-dropbar-open').forEach(function(other){
   if(other!==d){other.classList.remove('jet-dropbar-open');setAria(other,false)}
  });
  if(!d.classList.contains('jet-dropbar-open'))d.classList.add('jet-dropbar-open');
  setAria(d,true);
  active=d;liftStack(d);
 }
 function sync(d){
  if(d.classList.contains('jet-dropbar-open'))show(d);
  else if(active===d)close();
  else setAria(d,false);
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
 document.addEventListener('keydown',function(ev){if(ev.key==='Escape'&&active)close();});
 function init(){
  scan(document);
  if(window.MutationObserver){
   new MutationObserver(scheduleScan).observe(document.body,{childList:true,subtree:true});
  }
 }
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init,{once:true});
 else init();
})();
