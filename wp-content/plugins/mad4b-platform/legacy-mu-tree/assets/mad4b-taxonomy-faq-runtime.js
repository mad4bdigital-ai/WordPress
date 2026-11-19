(function(){'use strict';
var cfg=window.MAD4B_TAXONOMY_FAQ||{};
if(!cfg.endpoint)return;
var seq=0,pendingPath='',lastPath=window.location.pathname;
// LiteSpeed can delay this script until the first filter click. The SSR mount
// records the true unfiltered origin path even when location already changed.
var initialMount=document.querySelector('[data-mad4b-faq-mount="1"]');
var originPath=(initialMount&&initialMount.getAttribute('data-mad4b-faq-path'))||lastPath;
var originHTML='',originRows=0;
function isAllowed(path){return /^\/(?:en\/|fr\/|de\/|es\/|it\/|ar\/)?tours-and-activities\/(?:jsf\/jet-engine:tours_query_archive\/tax\/[a-z0-9_-]+:[a-z0-9_-]+(?:;[a-z0-9_-]+:[a-z0-9_-]+){0,3}\/)?$/.test(path)}
function mount(){return document.querySelector('[data-mad4b-faq-mount="1"]')}
function emit(name,detail){document.dispatchEvent(new CustomEvent(name,{detail:detail}))}
function update(path){
 var target=mount();if(!target)return;
 if(!isAllowed(path)){
  // An unsupported route must never show FAQs from the previous archive.
  seq++;pendingPath='';target.hidden=true;
  target.removeAttribute('aria-busy');return;
 }
 // The native Elementor markup for the unfiltered archive is already
 // present at page load. Restore it synchronously on Reset instead of waiting
 // on a second WordPress render; never reuse it for a filtered route.
 if(path===originPath && originHTML &&
   (target.getAttribute('data-mad4b-faq-path')!==path || pendingPath)){
   seq++;pendingPath='';
   target.innerHTML=originHTML;target.hidden=originRows===0;
   target.removeAttribute('aria-busy');
   target.setAttribute('data-mad4b-faq-path',path);
   if(window.elementorFrontend && window.elementorFrontend.elementsHandler &&
     typeof window.elementorFrontend.elementsHandler.runReadyTrigger==='function'){
     target.querySelectorAll('.elementor-element').forEach(function(el){
       window.elementorFrontend.elementsHandler.runReadyTrigger(window.jQuery?window.jQuery(el):el);
     });
   }
   emit('mad4b/taxonomy-faq/updated',{count:originRows,path:path,source:'origin_restore'});
   return;
 }
 if(target.getAttribute('data-mad4b-faq-path')===path && !pendingPath)return;
 if(pendingPath===path)return;
 var id=++seq;pendingPath=path;
 // Superseded requests are ignored by seq; aborting may reject native JSF listeners.

 // Hide stale content before asynchronous JetSmartFilters / SEO requests finish.
 target.hidden=true;target.setAttribute('aria-busy','true');
 fetch(cfg.endpoint+'?path='+encodeURIComponent(path),{
  credentials:'same-origin',headers:{'Accept':'application/json'}
 }).then(function(r){if(!r.ok)throw new Error('faq_http_'+r.status);return r.json()}).then(function(data){
  if(id!==seq||!data||data.status!=='ready'||data.path!==path||window.location.pathname!==path)return;
  pendingPath='';target.innerHTML=data.html||'';
  target.hidden=!(data.count>0);target.removeAttribute('aria-busy');
  target.setAttribute('data-mad4b-faq-path',path);
  if(data.count>0 && window.elementorFrontend && window.elementorFrontend.elementsHandler &&
    typeof window.elementorFrontend.elementsHandler.runReadyTrigger==='function') {
    target.querySelectorAll('.elementor-element').forEach(function(el){
      window.elementorFrontend.elementsHandler.runReadyTrigger(window.jQuery?window.jQuery(el):el);
    });
  }
  emit('mad4b/taxonomy-faq/updated',{count:data.count,path:path});
 }).catch(function(err){
  if(id!==seq)return;
  pendingPath='';target.hidden=true;target.removeAttribute('aria-busy');
  if(err.name!=='AbortError')emit('mad4b/taxonomy-faq/error',{reason:String(err.message||err),path:path});
 });
}
// Record the original archive only, not a filtered result or a term page.
if(isAllowed(originPath) && originPath.indexOf('/jsf/')===-1){
 var originMount=mount();if(originMount){
  originHTML=originMount.innerHTML;
  originRows=originMount.querySelectorAll('.jet-listing-grid__item').length;
 }
}
function refresh(){var path=window.location.pathname;
 if(path===lastPath)return;
 lastPath=path;update(path);
}
function fromEvent(ev){var d=ev.detail||{};
 var path=d.request_path||window.location.pathname;
 // DFSB can emit after an earlier route was superseded. Trust current path.
 if(path!==window.location.pathname)path=window.location.pathname;
 update(path);
}
['dfsb/ajax-presentation-updated','dfsb/ajax-presentation-reset'].forEach(function(n){document.addEventListener(n,fromEvent)});
window.addEventListener('popstate',refresh);
// JetSmartFilters updates history without necessarily emitting popstate.
// Wrap the two native methods once, preserving their return values and args.
['pushState','replaceState'].forEach(function(name){
 var original=history[name];if(typeof original!=='function'||original.__mad4bFaqWatch)return;
 var wrapped=function(){var value=original.apply(this,arguments);refresh();return value};
 wrapped.__mad4bFaqWatch=true;history[name]=wrapped;
});
// A bounded low-cost fallback detects URLs changed by other plugins that cached
// native history methods before this module loaded. No API fetch on unchanged URL.
var checkCount=0,watch=setInterval(function(){
 refresh();if(++checkCount>=300){clearInterval(watch)}
},500);
// Replay a missed route change if the optimizer executed after a JSF click.
if(originPath!==lastPath)update(lastPath);
})();
