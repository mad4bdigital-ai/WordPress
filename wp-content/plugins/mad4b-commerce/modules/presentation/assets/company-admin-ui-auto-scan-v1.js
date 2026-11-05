/* MAD4B Admin UI Auto Scan v1 — private metadata, WCAG screen audit.
   Automatically inspects visited WordPress admin screens and AJAX updates.
   No DOM content, cookies or full URLs are sent or persisted. */
(()=>{'use strict';
 const CONTRACT='mad4b.admin-ui-auto-scan.v1';
 const cfg=window.MAD4BAdminUIAutoScanConfig||null;
 if(!document.documentElement||!cfg||!cfg.enabled)return;
 const scopes='#wpbody-content,.components-popover,.select2-container--open,.ui-dialog,.swal2-container,.el-popper,.ant-modal-root';
 const candidate='p,span,small,strong,em,label,legend,li,td,th,dt,dd,a,button,select,summary,h1,h2,h3,h4,h5,h6,input,textarea';
 const SKIP='[contenteditable],.block-editor-writing-flow,.editor-styles-wrapper,.elementor-editor-wrapper,.elementor-editor-active,.CodeMirror,.ace_editor,.wp-editor-area,canvas,svg,pre,code,.media-modal,[data-mad4b-contrast-ignore]';
 const regions={woocommerce:'#woocommerce_dashboard_status,.woocommerce-layout,.wc-admin,.wc_status_list',
  fluent:'.fs_app,.fs_setup,.fluent_support,.fluentform,.ff-el-group,.fluent-forms',
  elementor:'.elementor-panel,.elementor-editor,.elementor-control',
  wpml:'#icl_div,.wpml-ls,.otgs-installer',
  'rank-math':'.rank-math-wrap,.rank-math-seo-dashboard',
  mad4b:'.mad4b-admin-notice-hub,.mad4b-admin-table-shell,[class^="mad4b-"]',
  wordpress:'.postbox,.wp-list-table,.notice,.update-nag,.wrap,.wp-filter'};
 const route=()=>{
  const name=location.pathname.split('/').pop()||'index.php';
  const parsed=/^[a-z0-9_-]+\.php$/i.test(name)?name:'index.php';
  const query=new URLSearchParams(location.search);
  for(const key of ['page','post_type']){
   const val=query.get(key);
   if(val&&/^[a-z0-9_-]{1,65}$/i.test(val))return parsed+'?'+key+'='+val;
  }
  return parsed;
 };
 const rgb=s=>{
  const m=String(s||'').match(/^rgba?\(\s*([\d.]+)[,\s]+([\d.]+)[,\s]+([\d.]+)(?:[,\s\/]+([\d.]+))?\s*\)$/i);
  return m?[+m[1],+m[2],+m[3],m[4]===undefined?1:+m[4]]:null;
 };
 const over=(a,b)=>a.slice(0,3).map((c,i)=>c*a[3]+b[i]*(1-a[3])).concat(1);
 const luma=c=>{const a=c.slice(0,3).map(v=>{v/=255;return v<=.04045?v/12.92:Math.pow((v+.055)/1.055,2.4)});return a[0]*.2126+a[1]*.7152+a[2]*.0722};
 const ratio=(a,b)=>{const x=luma(a),y=luma(b);return(Math.max(x,y)+.05)/(Math.min(x,y)+.05)};
 const family=el=>{
  for(const [key,sel] of Object.entries(regions))if(el.closest(sel))return key;
  return 'third-party';
 };
 const region=el=>{
  const n=el.closest('[id]');
  const id=n?.id||'';
  if(/^[a-z][a-z0-9_-]{1,69}$/i.test(id)&&!/(?:user|post|customer|order|message|ticket)[_-]?\d+/i.test(id))return id;
  return el.closest('.postbox')?'postbox':'unknown';
 };
 const visible=el=>{
  const s=getComputedStyle(el);
  if(s.display==='none'||s.visibility==='hidden'||+s.opacity===0)return false;
  if(!el.getClientRects().length)return false;
  return true;
 };
 const skip=el=>el.closest(SKIP)!==null||el.closest('.dashicons,.ab-icon,.media-frame,.notice-dismiss')!==null||el.matches('[disabled],input[type=checkbox],input[type=radio],input[type=hidden],input[type=range],input[type=file],input[type=color]');
 const direct=el=>el.matches('input,select,textarea')||Array.from(el.childNodes).some(n=>n.nodeType===3&&n.textContent.trim().length>0);
 const bg=(el,memo)=>{
  if(!el||el.nodeType!==1)return [17,27,37,1];
  if(memo.has(el))return memo.get(el);
  const behind=bg(el.parentElement,memo);
  const paint=rgb(getComputedStyle(el).backgroundColor);
  const color=paint?(paint[3]===1?paint:over(paint,behind)):behind;
  memo.set(el,color);return color;
 };
 let pending=false,running=false,rerun=false,scans=0,sends=0,lastFingerprint='',lastReport=null;
 let latestStart=0;
 function summarize(issues){
  return Array.from(issues.values()).sort((a,b)=>b.count-a.count).slice(0,12);
 }
 function start(){
  if(!document.body?.classList.contains('wp-admin'))return;
  if(running){rerun=true;return;}
  running=true;pending=false;
  const begun=performance.now();
  const all=[];const seen=new Set();
  for(const scope of document.querySelectorAll(scopes)){
   for(const el of scope.querySelectorAll(candidate)){
    if(seen.has(el))continue;seen.add(el);all.push(el);
    if(all.length>=8500)break;
   }
   if(all.length>=8500)break;
  }
  const total=all.length,memo=new WeakMap(),issues=new Map(),families={},frames=document.querySelectorAll('#wpbody-content iframe').length;
  let cursor=0,examined=0,overflow=0;
  const mode=document.documentElement.getAttribute('data-mad4b-admin-theme')==='dark'?'dark':'light';
  const correction=window.MAD4BAdminExperienceContrast?.stats?.()||{};
  const add=(type,el)=>{
   const fam=el?family(el):'wordpress',reg=el?region(el):'unknown';
   const key=[type,fam,reg].join('|');
   if(!issues.has(key))issues.set(key,{type,family:fam,region:reg,count:0});
   issues.get(key).count++;families[fam]=(families[fam]||0)+1;
  };
  const bodyWidth=document.documentElement.scrollWidth;
  overflow=Math.max(0,bodyWidth-innerWidth);
  if(overflow>=5)add('horizontal_overflow',document.getElementById('wpbody-content'));
  function work(deadline){
   const startTick=performance.now();
   let count=0;
   while(cursor<total && count<150 && performance.now()-startTick<12){
    const el=all[cursor++];count++;
    if(!el.isConnected||skip(el)||!visible(el))continue;
    const s=getComputedStyle(el);
    if(s.backgroundImage!=='none')continue;
    if(direct(el)){
     examined++;
     const ink=rgb(s.color);
     if(ink){
      const paper=bg(el,memo);
      const large=parseFloat(s.fontSize)>=24||(parseFloat(s.fontSize)>=18.67&&parseFloat(s.fontWeight)>=700);
      if(ratio(ink,paper)<(large?3:4.5)-.1)add('text_contrast',el);
     }
    }
    if(el.clientWidth>0&&el.scrollWidth>el.clientWidth+9&&
       s.whiteSpace==='nowrap'&&(s.overflowX==='hidden'||s.textOverflow==='ellipsis'))add('clipped_text',el);
    if(el.matches('button,a,input,select,textarea')&&direct(el)){
     const r=el.getBoundingClientRect();
     if(r.width>0&&r.height>0&&r.width<26&&r.height<26)add('small_target',el);
    }
   }
   if(cursor<total){
    if(typeof requestIdleCallback==='function')requestIdleCallback(work,{timeout:700});
    else setTimeout(work,12);
    return;
   }
   if(frames)add('unscanned_opaque_frame',document.getElementById('wpbody-content'));
   const report={contract:CONTRACT,route:route(),mode,viewport:innerWidth,
    metrics:{examined,auto_text_fixed:correction.activeCorrections||0,
      auto_surfaces_fixed:correction.activeSurfaces||0,remaining_issues:Array.from(issues.values()).reduce((a,b)=>a+b.count,0),
      horizontal_overflow_px:overflow,uninspectable_frames:frames,
      scan_duration_ms:Math.round(performance.now()-begun)},
    families,issues:summarize(issues)};
   lastReport=report;running=false;scans++;
   window.dispatchEvent(new CustomEvent('mad4b:admin-ui-scan-complete',{detail:report}));
   maybeSend(report);
   if(rerun){rerun=false;setTimeout(schedule,350)}
  }
  work();
 }
 function maybeSend(report){
  if(!cfg.ajaxUrl||!cfg.nonce||sends>=3)return;
  const fingerprint=JSON.stringify([report.route,report.mode,report.metrics.auto_text_fixed,
    report.metrics.auto_surfaces_fixed,report.metrics.remaining_issues,report.metrics.horizontal_overflow_px]);
  if(fingerprint===lastFingerprint)return;
  if(sends>0&&Date.now()-latestStart<7000)return;
  lastFingerprint=fingerprint;latestStart=Date.now();sends++;
  const params=new URLSearchParams({action:'mad4b_admin_ui_auto_scan_v1',nonce:cfg.nonce,payload:JSON.stringify(report)});
  fetch(cfg.ajaxUrl,{method:'POST',body:params,credentials:'same-origin',cache:'no-store',
    headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},keepalive:false})
    .catch(()=>{});
 }
 let debounce=0;
 function schedule(){
   if(!document.body?.classList.contains('wp-admin')||debounce)return;
   // A busy SPA must still complete scans under continuous AJAX updates.
   debounce=setTimeout(()=>{debounce=0;start()},650);
 }
 function ready(){
  if(!document.body?.classList.contains('wp-admin'))return;
  setTimeout(schedule,750);
  window.addEventListener('mad4b:admin-theme-change',schedule);
  window.addEventListener('mad4b:admin-contrast-audited',schedule);
  window.addEventListener('load',schedule,{once:true});
  const observer=new MutationObserver(records=>{
   const containers='#wpbody-content,.components-popover,.select2-container--open,.ui-dialog,.swal2-container,.el-popper,.ant-modal-root';
   if(records.some(r=>{
    const el=r.target?.nodeType===1?r.target:null;
    if(r.type==='attributes')return !!el?.closest(containers) &&
      ['class','hidden','aria-expanded'].includes(r.attributeName);
    if(r.type==='childList'&&r.addedNodes.length)return !!el?.closest(containers)||
      Array.from(r.addedNodes).some(n=>n.nodeType===1&&n.matches?.(containers));
    return false;
   }))schedule();
  });
  observer.observe(document.body,{subtree:true,childList:true,attributes:true,attributeFilter:['class','hidden','aria-expanded']});
  document.addEventListener('click',schedule,{passive:true});
 }
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',ready,{once:true});else ready();
 window.MAD4BAdminUIAutoScan={scan:start,schedule,report:()=>lastReport,stats:()=>({scans,sends,running})};
})();
