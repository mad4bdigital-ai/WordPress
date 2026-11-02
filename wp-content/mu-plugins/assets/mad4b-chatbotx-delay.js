(function(){
 'use strict';
 var cfg=window.MAD4B_CHATBOTX_DELAY;
 if(!cfg||!cfg.settings||!/^https:\/\/chatbotx\.mad4b\.com\/chat-widget\/plugin\.js$/.test(cfg.origin))return;
 var started=false,requested=false,tries=0;
 var mobile=/Mobile|Android|Silk\/|Kindle|BlackBerry|Opera Mini|Opera Mobi/i.test(navigator.userAgent);
 var delay=mobile?Math.max(3000,Math.min(12000,Number(cfg.mobileMs)||5000)):
  Math.max(2000,Math.min(10000,Number(cfg.desktopMs)||3200));
 function load(){
  if(started||tries>=3||document.visibilityState!=='visible')return;
  started=true;tries++;
  var script=document.createElement('script');
  script.type='module';
  script.async=true;
  script.crossOrigin='anonymous';
  script.referrerPolicy='strict-origin-when-cross-origin';
  script.src=cfg.origin;
  script.onload=function(){
   if(window.csmChatWidget&&typeof window.csmChatWidget.init==='function')
    window.csmChatWidget.init(cfg.settings);
  };
  script.onerror=function(){
   // Do not silently remove ChatbotX after a transient CORS/network failure.
   // At most two retries, no duplicate live modules, and never while hidden.
   started=false;
   if(script.parentNode)script.parentNode.removeChild(script);
   if(tries<3)window.setTimeout(function(){
    if(document.visibilityState==='visible')load();
    else {
     var visible=function(){if(document.visibilityState==='visible'){
      document.removeEventListener('visibilitychange',visible);load();
     }};
     document.addEventListener('visibilitychange',visible);
    }
   },tries*2000);
  };
  document.head.appendChild(script);
 }
 function schedule(){
  if(requested)return;
  requested=true;
  window.setTimeout(function(){
   if(document.visibilityState!=='visible'){
    var h=function(){
     if(document.visibilityState==='visible'){
      document.removeEventListener('visibilitychange',h);
      if(window.requestIdleCallback)window.requestIdleCallback(load,{timeout:1800});
      else load();
     }
    };
    document.addEventListener('visibilitychange',h);
   }else if(window.requestIdleCallback)window.requestIdleCallback(load,{timeout:1800});
   else load();
  },delay);
 }
 if(document.readyState==='complete')schedule();
 else window.addEventListener('load',schedule,{once:true});
})();
