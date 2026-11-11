/* Admin-only, bounded and advisory: provider notices -> unverified MAD4B alerts. */
(function () {
  'use strict';
  const cfg=window.MAD4BConfigurationNoticeBridge;
  if(!cfg||!cfg.endpoint||!cfg.nonce||!document.querySelector('#wpbody-content'))return;
  function collect(){
    const items=[];const seen=new Set();
    document.querySelectorAll('#wpbody-content .notice-error, #wpbody-content .notice-warning, #wpbody-content .update-nag').forEach(node=>{
      if(items.length>=12)return;
      if(node.closest('[data-mad4b-configuration-alert]'))return;
      let text=(node.innerText||node.textContent||'').replace(/https?:\/\/\S+/g,'[link]')
        .replace(/[\w.+-]+@[\w.-]+\.[A-Za-z]{2,}/g,'[email]')
        .replace(/\b(?:\d{1,3}\.){3}\d{1,3}\b/g,'[ip]')
        .replace(/\b(?:token|secret|api[_-]?key|password)\s*[:=]\s*\S+/gi,'[redacted]')
        .replace(/\s+/g,' ').trim().slice(0,180);
      if(text.length<14||seen.has(text))return;
      if(/^content readiness\s*:/i.test(text))return; // Native ContentJob alerts have their own ledger.
      const plugin=(node.dataset.pluginSlug||node.dataset.plugin||'').split('/')[0]
        .trim().toLowerCase().replace(/[^a-z0-9_-]/g,'').slice(0,64);
      seen.add(text);items.push({kind:node.classList.contains('notice-error')?'error':'warning',text,plugin});
    });
    if(!items.length)return;
    fetch(cfg.endpoint,{method:'POST',credentials:'same-origin',cache:'no-store',
      headers:{'Content-Type':'application/json','X-WP-Nonce':cfg.nonce,'Accept':'application/json'},
      body:JSON.stringify({notices:items}),keepalive:false}).catch(()=>{});
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',collect,{once:true});else collect();
})();
