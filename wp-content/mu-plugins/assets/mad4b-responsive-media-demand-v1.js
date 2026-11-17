/* MAD4B site-local responsive media: measure an actually visible card, then ask for one cached physical width. */
(function(){
 'use strict';
 var opts=window.MAD4BResponsiveMedia||{};
 if(!opts.endpoint||!opts.selector||opts.step!==64||!('IntersectionObserver' in window))return;
 var endpoint;
 try{endpoint=new URL(opts.endpoint,location.origin);if(endpoint.origin!==location.origin)return;}catch(e){return;}
 var inFlight=0,maxConcurrent=2,observer=null,seen=new WeakSet(),requests=new WeakMap(),waiting=[];
 function targetWidth(cssWidth){
   if(!cssWidth||cssWidth<64)return 0;
   var conn=navigator.connection||{};
   var dpr=conn.saveData?1:Math.min(3,Math.max(1,window.devicePixelRatio||1));
   var pixels=Math.ceil(cssWidth*dpr);
   return Math.min(opts.maxWidth,Math.max(opts.minWidth,Math.ceil(pixels/opts.step)*opts.step));
 }
 function termId(card){
   var item=card.closest('.jet-listing-grid__item');if(!item)return 0;
   var found=(item.className||'').match(/(?:^|\s)jet-listing-dynamic-post-(\d+)(?=\s|$)/);
   return found?Number(found[1]):0;
 }
 function sendBatch(batch){
   var targets=Object.create(null),keys=[];
   batch.forEach(function(item){
     var card=item.card;
     if(!card.isConnected)return;
     var width=targetWidth(item.width),term=termId(card);
     if(!width||!term||requests.get(card)===width)return;
     requests.set(card,width);
     var key=String(term)+':'+String(width);
     if(!targets[key]){targets[key]=[];keys.push(key);}
     targets[key].push(card);
   });
   if(!keys.length)return;
   var endpointUrl=new URL(endpoint.href);
   endpointUrl.searchParams.set('items',keys.join(','));
   inFlight++;
   fetch(endpointUrl.href,{credentials:'omit',cache:'no-store',headers:{Accept:'application/json'}})
     .then(function(r){if(!r.ok)throw new Error('no media');return r.json();})
     .then(function(answer){
       var values=answer&&answer.items||{};
       keys.forEach(function(key){
         var result=values[key];
         if(!result||result.state!=='ready'||typeof result.url!=='string')return;
         var asset=new URL(result.url,location.origin);
         if(asset.origin!==location.origin||!/\.mad4b-demand-\d+-[a-f0-9]{12}\.webp$/.test(asset.pathname))return;
         targets[key].forEach(function(card){
           if(!card.isConnected)return;
           card.style.setProperty('background-image','url("'+asset.href+'")','important');
           card.setAttribute('data-mad4b-responsive-image','ready');
         });
       });
     }).catch(function(){}).finally(function(){inFlight--;drain();});
 }
 function drain(){
   while(inFlight<maxConcurrent && waiting.length){
     sendBatch(waiting.splice(0,8));
   }
 }

 observer=new IntersectionObserver(function(entries){
   entries.forEach(function(e){if(!e.isIntersecting)return;observer.unobserve(e.target);waiting.push({card:e.target,width:e.boundingClientRect.width});});
   drain();
 },{rootMargin:'0px',threshold:0.01});
 function register(root){
   if(!root||!root.querySelectorAll)return;
   var cards=[];
   if(root.matches&&root.matches(opts.selector))cards.push(root);
   root.querySelectorAll(opts.selector).forEach(function(x){cards.push(x);});
   cards.forEach(function(card){if(seen.has(card))return;seen.add(card);observer.observe(card);});
 }
 function initialize(){
   register(document);
   if('MutationObserver' in window){
     var changes=new MutationObserver(function(items){
       items.forEach(function(item){item.addedNodes.forEach(function(node){if(node.nodeType===1)register(node);});});
     });
     changes.observe(document.body,{childList:true,subtree:true});
   }
 }
 // Do not compete with initial render, fonts, CSS Async or LCP.
 // On-demand media is an enhancement, never a prerequisite for first paint.
 function startAfterLoad(){
   if('requestIdleCallback' in window)requestIdleCallback(initialize,{timeout:2600});
   else window.setTimeout(initialize,350);
 }
 if(document.readyState==='complete')startAfterLoad();
 else window.addEventListener('load',startAfterLoad,{once:true});
})();
