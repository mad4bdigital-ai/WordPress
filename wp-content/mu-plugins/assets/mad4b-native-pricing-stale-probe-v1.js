/* Authenticated native CAS negative-path test — zero pricing changes. */
(() => {
  'use strict';
  const button=document.getElementById('mad4b-native-stale-probe');
  const output=document.getElementById('mad4b-native-stale-probe-result');
  if(!button||!output)return;

  button.addEventListener('click',async()=>{
    const config=window.MAD4BJetEngineNativePricingCAS||{};
    const nonce=config.nonce||'';
    const revision=config.revision||'';
    if(!nonce||!/^[a-f0-9]{64}$/.test(revision)){
      output.textContent='Test unavailable: protected native Save form is not ready.';
      return;
    }
    // Guaranteed different yet syntactically valid fingerprint.
    // Server checks nonce, the authoritative DB fingerprint and rejects 409
    // BEFORE the original JetEngine vendor Save handler could run.
    const deliberatelyStale=(revision[0]==='a'?'b':'a')+revision.slice(1);
    const payload=new URLSearchParams();
    payload.set('mad4b_native_cas_nonce',nonce);
    payload.set('mad4b_native_cas_revision',deliberatelyStale);
    payload.set('mad4b_native_cas_probe','1');
    button.disabled=true;
    output.textContent='Checking protected native Save…';
    try {
      const response=await fetch(button.dataset.saveUrl,{
        method:'POST',
        credentials:'same-origin',
        cache:'no-store',
        redirect:'follow',
        headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
        body:payload.toString()
      });
      if(response.status===409){
        output.textContent='PASS — Original Save rejected the stale revision (HTTP 409). No pricing fields were submitted.';
      }else{
        output.textContent='NOT VERIFIED — Server replied HTTP '+response.status+
          '. No Save success is claimed.';
      }
    }catch(error){
      output.textContent='NOT VERIFIED — Could not reach protected Save. No Save success is claimed.';
    }finally{
      button.disabled=false;
    }
  });
})();
