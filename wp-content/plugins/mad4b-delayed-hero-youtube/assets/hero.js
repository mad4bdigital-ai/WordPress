/* MAD4B Auto Hero: visible + deferred playback, privacy-enhanced YouTube host. */
/* MAD4B: render poster first, create YouTube iframe only after load + device delay. */
(function () {
 'use strict';
 var selector='[data-mad4b-yt-id][data-mad4b-yt-mode="delayed_auto"]';
 function mobile(){return /Mobile|Android|Silk\/|Kindle|BlackBerry|Opera Mini|Opera Mobi/i.test(navigator.userAgent);}
 function reduced(){
  var c=navigator.connection||navigator.mozConnection||navigator.webkitConnection;
  return !!((window.matchMedia&&matchMedia('(prefers-reduced-motion: reduce)').matches)||(c&&c.saveData));
 }
 function onVisible(fn){
  if(reduced())return;
  if(document.visibilityState==='visible'){fn();return;}
  document.addEventListener('visibilitychange',function handler(){
   if(document.visibilityState!=='visible')return;
   document.removeEventListener('visibilitychange',handler);
   if(!reduced())fn();
  });
 }
 function create(t){
  if(!t.isConnected||reduced()||document.visibilityState!=='visible'||t.dataset.mad4bYtLoaded)return;
  var id=t.dataset.mad4bYtId||'';
  if(!/^[A-Za-z0-9_-]{11}$/.test(id))return;
  t.dataset.mad4bYtLoaded='1';
  var layer=document.createElement('div');layer.className='mad4b-yt-hero-layer';layer.setAttribute('aria-hidden','true');
  var frame=document.createElement('iframe');frame.setAttribute('title','Decorative background travel video');
  frame.setAttribute('tabindex','-1');frame.setAttribute('aria-hidden','true');
  frame.setAttribute('allow','autoplay; encrypted-media; picture-in-picture');
  frame.setAttribute('referrerpolicy','strict-origin-when-cross-origin');
  frame.setAttribute('loading','eager');
  var start=Math.max(0,Math.min(600,parseInt(t.dataset.mad4bYtStart,10)||0));
  var end=Math.max(0,Math.min(3600,parseInt(t.dataset.mad4bYtEnd,10)||0));
  var params=new URLSearchParams({autoplay:'1',mute:'1',playsinline:'1',controls:'0',loop:'1',playlist:id,rel:'0',disablekb:'1'});
  if(start)params.set('start',String(start));
  if(end>start)params.set('end',String(end));
  frame.src='https://www.youtube-nocookie.com/embed/'+encodeURIComponent(id)+'?'+params.toString();
  frame.addEventListener('load',function(){
   // Retain the poster until the iframe has loaded; reduced-motion still keeps it.
   if(!reduced()&&layer.isConnected)layer.classList.add('mad4b-ready');
  },{once:true});
  layer.appendChild(frame);t.insertBefore(layer,t.firstChild);
 }
 function init(){
  if(reduced())return;
  var targets=document.querySelectorAll(selector);
  for(var i=0;i<targets.length;i++)(function(t){
   var isMobile=mobile(),min=isMobile?900:600,max=isMobile?10000:8000;
   var delay=parseInt(t.getAttribute('data-mad4b-yt-'+(isMobile?'mobile':'desktop')),10);
   if(!Number.isFinite(delay))delay=isMobile?3000:1800;
   delay=Math.max(min,Math.min(max,delay));
   window.setTimeout(function(){onVisible(function(){
    function ready(){
     if(window.requestIdleCallback)window.requestIdleCallback(function(){create(t);},{timeout:1300});
     else create(t);
    }
    if(!window.IntersectionObserver){ready();return;}
    var io=new IntersectionObserver(function(entries){
     if(entries.some(function(x){return x.isIntersecting})){io.disconnect();ready();}
    },{rootMargin:'120px 0px',threshold:0.01});io.observe(t);
   });},delay);
  })(targets[i]);
 }
 if(document.readyState==='complete')init();
 else window.addEventListener('load',init,{once:true});
})();

/* Optional per-company explicit play alternative. */
/* MAD4B: Privacy-first YouTube hero. Poster is local; no YouTube network
 * request until a visitor deliberately clicks the inline play control. */
(function () {
 'use strict';
 var selector='[data-mad4b-yt-id][data-mad4b-yt-mode="click_to_play"]';
 function create(t,button){
  if(!t.isConnected||t.dataset.mad4bYtLoaded)return;
  var id=t.dataset.mad4bYtId||'';
  if(!/^[A-Za-z0-9_-]{11}$/.test(id))return;
  t.dataset.mad4bYtLoaded='1';
  var layer=document.createElement('div');
  layer.className='mad4b-yt-hero-layer';
  layer.setAttribute('aria-hidden','true');
  var frame=document.createElement('iframe');
  frame.setAttribute('title','Travel background video');
  frame.setAttribute('tabindex','-1');
  frame.setAttribute('aria-hidden','true');
  frame.setAttribute('allow','autoplay; encrypted-media; picture-in-picture');
  frame.setAttribute('referrerpolicy','strict-origin-when-cross-origin');
  frame.setAttribute('loading','eager');
  var start=Math.max(0,Math.min(600,parseInt(t.dataset.mad4bYtStart,10)||0));
  var end=Math.max(0,Math.min(3600,parseInt(t.dataset.mad4bYtEnd,10)||0));
  var params=new URLSearchParams({autoplay:'1',mute:'1',playsinline:'1',controls:'0',loop:'1',playlist:id,rel:'0',disablekb:'1'});
  if(start)params.set('start',String(start));
  if(end>start)params.set('end',String(end));
  frame.src='https://www.youtube-nocookie.com/embed/'+encodeURIComponent(id)+'?'+params.toString();
  frame.addEventListener('load',function(){
   if(layer.isConnected)layer.classList.add('mad4b-ready');
  },{once:true});
  layer.appendChild(frame);
  t.insertBefore(layer,t.firstChild);
  button.remove();
 }
 function init(){
  document.querySelectorAll(selector).forEach(function(t){
   if(t.querySelector('.mad4b-yt-hero-play'))return;
   var id=t.dataset.mad4bYtId||'';
   if(!/^[A-Za-z0-9_-]{11}$/.test(id))return;
   var button=document.createElement('button');
   button.type='button';
   button.className='mad4b-yt-hero-play';
   var labels={en:'Play background video',ar:'تشغيل فيديو الخلفية',fr:'Lire la vidéo en arrière-plan',de:'Hintergrundvideo abspielen',es:'Reproducir vídeo de fondo',it:'Riproduci il video di sfondo'};
   var lang=(document.documentElement.lang||'en').slice(0,2).toLowerCase();
   var label=labels[lang]||labels.en;
   button.textContent='▶ '+label;
   button.setAttribute('aria-label',label);
   button.setAttribute('title',label);
   button.addEventListener('click',function(){create(t,button);},{once:true});
   t.appendChild(button);
  });
 }
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init,{once:true});
 else init();
})();

