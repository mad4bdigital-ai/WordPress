(function(w,d,c){
  "use strict";
  if(w.MAD4BConsent)return;
  var layer=c.dataLayer||"dataLayer";
  w[layer]=w[layer]||[];
  w.gtag=w.gtag||function(){w[layer].push(arguments);};
  function gtag(){w.gtag.apply(w,arguments);}
  var denied={
    analytics_storage:"denied",ad_storage:"denied",ad_user_data:"denied",
    ad_personalization:"denied",personalization_storage:"denied",
    functionality_storage:"granted",security_storage:"granted"
  };
  gtag("consent","default",denied);
  var key="mad4b_consent_v2:"+String(c.site);
  var state=null,loaded=false,configs={},receiptId=null;
  function uid(){
    if(w.crypto&&w.crypto.randomUUID)return w.crypto.randomUUID();
    var a=new Uint8Array(16);
    if(!w.crypto||!w.crypto.getRandomValues)return null;
    w.crypto.getRandomValues(a);
    a[6]=(a[6]&15)|64;a[8]=(a[8]&63)|128;
    return [...a].map(function(v,i){
      var h=v.toString(16).padStart(2,"0");
      return [4,6,8,10].indexOf(i)>=0?"-"+h:h;
    }).join("");
  }
  function read(){
    try{
      var a=JSON.parse(w.localStorage.getItem(key)||"null");
      if(a&&a.v===2&&a.policy===c.policy&&a.until>Date.now()
        &&String(a.country||"")===String(c.country||"")
        &&typeof a.a==="boolean"&&typeof a.m==="boolean"){
        receiptId=a.receipt_id||null;
        return {a:a.a,m:a.m};
      }
    }catch(e){}
    return null;
  }
  function consent(s){
    return {
      analytics_storage:s.a?"granted":"denied",
      ad_storage:s.m?"granted":"denied",
      ad_user_data:s.m?"granted":"denied",
      ad_personalization:s.m?"granted":"denied",
      personalization_storage:"denied",
      functionality_storage:"granted",security_storage:"granted"
    };
  }
  function googleScript(id){
    if(!/^(G-[A-Z0-9]{6,18}|AW-[0-9]{6,16})$/.test(id))return;
    var node=d.createElement("script");
    node.async=true;node.src="https://www.googletagmanager.com/gtag/js?id="+encodeURIComponent(id);
    d.head.appendChild(node);
  }
  function startTags(){
    if(!state)return;
    if(c.strategy==="split_google_tags"){
      var ids=[];
      if(state.a && c.ga4)ids.push(c.ga4);
      if(state.m && c.ads)ids.push(c.ads);
      if(!ids.length)return;
      if(!loaded){
        loaded=true;googleScript(ids[0]);gtag("js",new Date());
      }
      ids.forEach(function(id){
        if(configs[id])return;
        configs[id]=true;
        gtag("config",id);
      });
      return;
    }
    var allowed=state.a&&state.m;
    if(c.strategy==="verified_gtm_any" && c.gtmAudited)allowed=state.a||state.m;
    if(!allowed||loaded)return;
    loaded=true;
    (c.ids||[]).forEach(function(id){
      if(!/^GTM-[A-Z0-9]{6,20}$/.test(id))return;
      var script=d.createElement("script");
      script.async=true;
      script.src="https://www.googletagmanager.com/gtm.js?id="+encodeURIComponent(id)
        +(layer==="dataLayer"?"":"&l="+encodeURIComponent(layer));
      d.head.appendChild(script);
    });
  }
  function evidence(s){
    if(!c.receiptEndpoint)return;
    try{
      var eventId=uid();
      if(!receiptId)receiptId=uid();
      if(!eventId||!receiptId)return;
      var body=JSON.stringify({
        event_id:eventId,receipt_id:receiptId,
        policy_revision:c.policy,policy_proof:c.policyProof,language:c.language,
        analytics:!!s.a,marketing:!!s.m
      });
      // A best-effort audit acknowledgement is not a condition of the
      // visitor's choice. No name, IP, email or browser fingerprint is sent.
      w.fetch(c.receiptEndpoint,{
        method:"POST",headers:{"Content-Type":"application/json"},
        credentials:"omit",mode:"same-origin",keepalive:true,body:body
      }).catch(function(){});
    }catch(e){}
  }
  function apply(s,save){
    var previous=state;
    var wasLoaded=loaded;
    state={a:!!s.a,m:!!s.m};
    gtag("consent","update",consent(state));
    w[layer].push({
      event:"mad4b_consent_update",
      mad4b_analytics_consent:state.a,
      mad4b_marketing_consent:state.m,
      mad4b_policy_revision:c.policy
    });
    if(save){
      if(!receiptId)receiptId=uid();
      try{
        w.localStorage.setItem(key,JSON.stringify({
          v:2,a:state.a,m:state.m,receipt_id:receiptId,
          policy:c.policy,country:c.country||"",until:Date.now()+c.ttl*86400000
        }));
      }catch(e){}
      evidence(state);
    }
    // Stop scripts previously loaded under revoked consent via full reload.
    // Revocation never leaves a marketing tag running in the same page.
    if(wasLoaded&&previous&&(previous.a&&!state.a||previous.m&&!state.m)){
      w.location.reload();
      return;
    }
    startTags();
  }
  var saved=read();
  if(saved)apply(saved,false);
  w.MAD4BConsent={
    hasChoice:function(){return !!state;},
    choice:function(){return state?{a:state.a,m:state.m}:null;},
    choose:function(a,m){apply({a:!!a,m:!!m},true);},
    open:function(){d.dispatchEvent(new Event("mad4b:openconsent"));},
    loaded:function(){return loaded;},
    status:function(){return {strategy:c.strategy,policy:c.policy,receipt:!!receiptId};}
  };
})(window,document,window.MAD4BConsentConfig||{});
