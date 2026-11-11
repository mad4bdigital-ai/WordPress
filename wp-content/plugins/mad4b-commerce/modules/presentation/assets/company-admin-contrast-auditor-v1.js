/* MAD4B Admin Experience — source-owned semantic contrast guard.
   Dark-mode only, text only. Corrects actual unreadable plugin colors by
   WCAG luminance instead of invert/difference filters. No content writes. */
(()=>{'use strict';
 const root=document.documentElement;
 const dark=()=>root.getAttribute('data-mad4b-admin-theme')==='dark';
 const skip='[contenteditable],.block-editor-writing-flow,.editor-styles-wrapper,.elementor-editor-active,.elementor-editor-wrapper,.media-modal,canvas,svg,pre,code,.CodeMirror,.ace_editor,.wp-editor-area,[data-mad4b-contrast-ignore]';
 const candidates='p,span,small,strong,em,label,legend,li,td,th,dt,dd,a,button,select,option,summary,h1,h2,h3,h4,h5,h6,input:not([type="hidden"]):not([type="checkbox"]):not([type="radio"]):not([type="file"]):not([type="color"]),textarea,div';
 const changes=new Map();
 const surfaceChanges=new Map();
 const surfaces='.postbox,.stuffbox,.card,.components-card,.components-panel,.components-panel__body,.wc-admin-card,.woocommerce-card,.woocommerce-layout__main,.wp-list-table,.wp-filter,.form-table,.inside,.notice,.update-nag,[class$="-card"],[class$="_card"],[class$="-panel"],[class$="_panel"],[class*="__card"],[class*="__panel"]';
 let pending=false,observer=null,scheduled=0,visited=0,generation=0,completedScans=0;
 const parse=value=>{
  const hit=(value||'').match(/^rgba?\(\s*([\d.]+)[,\s]+([\d.]+)[,\s]+([\d.]+)(?:[,\s\/]+([\d.]+))?\s*\)$/i);
  return hit?[+hit[1],+hit[2],+hit[3],hit[4]===undefined?1:+hit[4]]:null;
 };
 const blend=(top,bottom)=>{
  const a=top[3]+bottom[3]*(1-top[3]);if(!a)return [0,0,0,0];
  return [0,1,2].map(i=>(top[i]*top[3]+bottom[i]*bottom[3]*(1-top[3]))/a).concat(a);
 };
 const lum=rgb=>{
  const ch=rgb.slice(0,3).map(n=>{const x=n/255;return x<=0.04045?x/12.92:Math.pow((x+0.055)/1.055,2.4);});
  return .2126*ch[0]+.7152*ch[1]+.0722*ch[2];
 };
 const ratio=(a,b)=>{const x=lum(a),y=lum(b);return (Math.max(x,y)+.05)/(Math.min(x,y)+.05);};
 const bg=(el,cache)=>{
  if(!el||el.nodeType!==1)return [17,27,37,1];
  if(cache.has(el))return cache.get(el);
  let parent=bg(el.parentElement,cache);
  const color=parse(getComputedStyle(el).backgroundColor);
  const result=color&&color[3]>0?blend(color,parent):parent;
  cache.set(el,result);return result;
 };
 const skipNode=el=>{
  if(!el.closest('#wpbody-content,.components-popover,.select2-container--open,.ui-dialog,.swal2-container,.el-popper,.ant-modal-root'))return true;
  if(el.closest(skip))return true;
  // Leave non-text badges / color-coded severity and editorial canvases intact.
  if(el.closest('.wp-block,.components-snackbar,.media-frame,.dashicons,.ab-icon,[class*="icon-"],.notice-dismiss'))return true;
  if(el.matches('[disabled],[aria-disabled="true"]'))return true;
  if(el.offsetWidth===0&&el.offsetHeight===0)return true;
  return false;
 };
 const directText=el=>{
  if(el.matches('input,select,textarea'))return true;
  for(const n of el.childNodes)if(n.nodeType===3&&n.textContent.trim())return true;
  return false;
 };
 const threshold=el=>{
  const s=getComputedStyle(el);const size=parseFloat(s.fontSize)||14;
  return size>=24||(size>=18.67&&parseFloat(s.fontWeight)>=700)?3:4.5;
 };
 function revert(){
  for(const [el,prior] of surfaceChanges){
   if(!el.isConnected)continue;
   if(prior.value)el.style.setProperty('background-color',prior.value,prior.priority);
   else el.style.removeProperty('background-color');
   el.removeAttribute('data-mad4b-auto-surface');
  }
  surfaceChanges.clear();
  for(const [el,prior] of changes){
   if(!el.isConnected)continue;
   if(prior.value)el.style.setProperty('color',prior.value,prior.priority);
   else el.style.removeProperty('color');
   el.removeAttribute('data-mad4b-auto-contrast');
  }
  changes.clear();
 }
 function audit(){
  pending=false;
  const run=++generation;
  if(!dark()){revert();visited=0;return;}
  // Avoid keeping detached AJAX/SPA elements alive after route changes.
  for(const el of changes.keys())if(!el.isConnected)changes.delete(el);
  for(const el of surfaceChanges.keys())if(!el.isConnected)surfaceChanges.delete(el);
  const roots=[...document.querySelectorAll('body.wp-admin #wpbody-content,body.wp-admin .components-popover,body.wp-admin .select2-container--open,body.wp-admin .ui-dialog,body.wp-admin .swal2-container,body.wp-admin .el-popper,body.wp-admin .ant-modal-root')];
  if(!roots.length)return;
  const cache=new WeakMap();let examined=0,corrected=0,surfaceCorrected=0;
  const surfacesToScan=[],textsToScan=[];
  const visitedSurfaces=new Set(),visitedTexts=new Set();
  for(const parent of roots){
   for(const e of parent.querySelectorAll(surfaces)){
    if(visitedSurfaces.has(e))continue;visitedSurfaces.add(e);surfacesToScan.push(e);
    if(surfacesToScan.length>=2000)break;
   }
   for(const e of parent.querySelectorAll(candidates)){
    if(visitedTexts.has(e))continue;visitedTexts.add(e);textsToScan.push(e);
    if(textsToScan.length>=12000)break;
   }
  }
  let si=0,ti=0;
  const eligible=el=>el.isConnected && !skipNode(el);
  function harmonizeSurface(panel){
   if(!eligible(panel))return;
   const style=getComputedStyle(panel);
   if(style.backgroundImage!=='none')return;
   const paint=parse(style.backgroundColor);
   if(!paint||paint[3]<.94)return;
   if(lum(paint)<=.70)return;
   if(Math.max(...paint.slice(0,3))-Math.min(...paint.slice(0,3))>=31)return;
   if(!surfaceChanges.has(panel))surfaceChanges.set(panel,{
    value:panel.style.getPropertyValue('background-color'),priority:panel.style.getPropertyPriority('background-color')});
   panel.style.setProperty('background-color','#1C2A37','important');
   panel.setAttribute('data-mad4b-auto-surface','1');
   surfaceCorrected++;
  }
  function harmonizeText(el){
   if(!eligible(el)||!directText(el))return;
   examined++;
   const style=getComputedStyle(el),color=parse(style.color);
   if(!color)return;
   const background=bg(el,cache);
   if(ratio(color,background)>=threshold(el))return;
   if(!changes.has(el))changes.set(el,{
    value:el.style.getPropertyValue('color'),priority:el.style.getPropertyPriority('color')});
   const white=[242,248,252,1],ink=[13,27,38,1];
   const fg=ratio(white,background)>=ratio(ink,background)?'#F2F8FC':'#0D1B26';
   el.style.setProperty('color',fg,'important');
   el.setAttribute('data-mad4b-auto-contrast','1');corrected++;
  }
  function step(){
   if(run!==generation||!dark())return;
   const began=performance.now();let operations=0;
   // Surfaces first, then their text, so no text is compared to an obsolete
   // pale card background. Each pass is bounded to avoid a long UI freeze.
   while(si<surfacesToScan.length&&operations<170&&performance.now()-began<12){
    harmonizeSurface(surfacesToScan[si++]);operations++;
   }
   while(si>=surfacesToScan.length&&ti<textsToScan.length&&operations<170&&performance.now()-began<12){
    harmonizeText(textsToScan[ti++]);operations++;
   }
   if(si<surfacesToScan.length||ti<textsToScan.length){
    if(typeof requestIdleCallback==='function')requestIdleCallback(step,{timeout:500});
    else setTimeout(step,8);
    return;
   }
   visited=examined;completedScans++;
   window.dispatchEvent(new CustomEvent('mad4b:admin-contrast-audited',{
    detail:{examined,corrected,surfaceCorrected,active:changes.size,
       surfaces:surfaceChanges.size,complete:true,segments:Math.ceil((surfacesToScan.length+textsToScan.length)/170)}}));
  }
  step();
 }
 function schedule(){if(pending)return;pending=true;requestAnimationFrame(audit);}
 function setup(){
  const body=document.body;if(!body||!body.classList.contains('wp-admin'))return;
  schedule();
  observer=new MutationObserver(records=>{
   const containers='#wpbody-content,.components-popover,.select2-container--open,.ui-dialog,.swal2-container,.el-popper,.ant-modal-root';
   if(records.some(x=>{
     const parent=x.target.nodeType===1?x.target:null;
     const within=!!parent?.closest(containers);
     if(x.type==='attributes'){
       if(!within)return false;
       // Our own styling must not trigger repeated audits.
       if(x.attributeName==='style' &&
          (parent.hasAttribute('data-mad4b-auto-contrast')||parent.hasAttribute('data-mad4b-auto-surface')))return false;
       return ['class','style','hidden','aria-expanded','aria-hidden'].includes(x.attributeName);
     }
     if(x.type==='childList'&&(x.addedNodes.length||x.removedNodes.length)){
       if(within)return true;
       return Array.from(x.addedNodes).some(n=>n.nodeType===1&&n.matches?.(containers));
     }
     return false;
   }))schedule();
  });
  observer.observe(document.body,{childList:true,subtree:true,attributes:true,attributeFilter:['class','style','hidden','aria-expanded','aria-hidden']});
  document.addEventListener('click',schedule,{passive:true});
  window.addEventListener('mad4b:admin-theme-change',schedule);
  window.addEventListener('load',schedule,{once:true});
  window.addEventListener('storage',e=>{if(e.key==='mad4b.admin.theme.v1')schedule();});
  // CSS inserted later by plugins can change contrast without an AJAX node
  // insertion. Monitor stylesheet additions, not all attribute mutations.
  const head=document.head;
  if(head){
   const stylesObserver=new MutationObserver(records=>{
    if(records.some(rec=>Array.from(rec.addedNodes).some(node=>
      node.nodeType===1&&node.matches('style,link[rel="stylesheet"]'))))schedule();
   });
   stylesObserver.observe(head,{childList:true,subtree:true});
  }
  // Lazy-scanned rows or tabs should be corrected as they come into view.
  window.addEventListener('scroll',()=>{clearTimeout(scheduled);scheduled=setTimeout(schedule,130);},{passive:true});
 }
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',setup,{once:true});else setup();
 window.MAD4BAdminExperienceContrast={audit,schedule,stats:()=>({inspected:visited,activeCorrections:changes.size,activeSurfaces:surfaceChanges.size,completedScans})};
})();
