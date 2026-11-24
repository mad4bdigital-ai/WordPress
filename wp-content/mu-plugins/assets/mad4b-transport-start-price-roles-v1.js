(function(){
  'use strict';
  if (typeof document==='undefined'||typeof window==='undefined') return;
  const cfg=window.MAD4BTransportSourceRoleV1;
  if (!cfg||!cfg.post_id) return;
  function start(){
    const root=document.querySelector('.mad4b-transport-price-role-panel');
    if (!root||Number(root.dataset.postId)!==Number(cfg.post_id))return;
    const select=document.getElementById('mad4b-transport-price-role-select');
    const save=document.getElementById('mad4b-transport-price-role-save');
    const result=document.getElementById('mad4b-transport-price-role-result');
    if(!select||!save||!result)return;
    let revision=Number(cfg.revision);
    save.addEventListener('click',async()=>{
      save.disabled=true;
      result.textContent='Validating source role and existing saved price…';
      try{
        const data=new URLSearchParams();
        data.set('action',cfg.action);
        data.set('post_id',String(cfg.post_id));
        data.set('mode',select.value);
        data.set('revision',String(revision));
        data.set('nonce',cfg.nonce);
        const response=await fetch(cfg.ajax_url,{
          method:'POST',credentials:'same-origin',
          headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8',
                   'Accept':'application/json'},
          body:data.toString()
        });
        const reply=await response.json();
        if(!response.ok||!reply||!reply.success)
          throw new Error(reply&&reply.data&&reply.data.code||'save_not_authorized');
        revision=Number(reply.data.revision);
        result.textContent='Saved role: '+reply.data.mode+
          '. Supplier cost and checkout remain unapproved. Reload to review the resolved sources.';
      }catch(e){
        result.textContent='Not saved: '+(e&&e.message||'unknown_error')+
          '. Verify permission, saved price and revision, then retry.';
      }finally{save.disabled=false;}
    });
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start,{once:true});
  else start();
})();
