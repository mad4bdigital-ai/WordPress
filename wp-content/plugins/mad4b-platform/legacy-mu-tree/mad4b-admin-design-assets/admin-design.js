(function(){
'use strict';
function start(){
 if(!document.body.classList.contains('mad4b-design-v2-page'))return;
 const root=document.querySelector('#wpbody-content > .wrap')||document.querySelector('#wpbody-content .wrap');
 if(!root||root.querySelector('#mad4b-remediation'))return;
 Array.from(root.querySelectorAll('pre')).forEach(function(pre){
  if(pre.textContent.length<950||pre.closest('form,details,.notice,.CodeMirror,.wp-editor-wrap'))return;
  const box=document.createElement('details');box.className='mad4b-design-technical-details';
  const summary=document.createElement('summary');summary.textContent='Technical evidence and raw diagnostics';
  pre.before(box);box.append(summary,pre);
 });
 root.querySelectorAll('table.widefat,table.wp-list-table').forEach(function(table,n){
  if(table.closest('.mad4b-workspace-table,.mad4b-scp-table-wrap,.mad4b-design-table-region,.mad4b-responsive-table'))return;
  const box=document.createElement('div');box.className='mad4b-design-table-region';
  box.tabIndex=0;box.setAttribute('role','region');
  box.setAttribute('aria-label','Results table '+(n+1));
  table.before(box);box.append(table);
 });
 if(root.querySelector('.nav-tab-wrapper,[role=tablist],.mad4b-scp-tabs'))return;
 const heads=Array.from(root.children).filter(x=>x.tagName==='H2'&&x.textContent.trim().length>3);
 if(heads.length>=3&&heads.length<=12){
  const sections=[];let current=null;
  Array.from(root.children).forEach(function(node){
   if(heads.includes(node)){
    current=document.createElement('section');
    current.id='mad4b-design-pane-'+sections.length;
    current.setAttribute('role','tabpanel');current.tabIndex=0;
    node.before(current);sections.push(current);
   }
   if(current&&node.parentElement===root)current.append(node);
  });
  const nav=document.createElement('nav');nav.className='mad4b-design-section-tabs';
  nav.setAttribute('role','tablist');nav.setAttribute('aria-label','Page sections');
  sections[0].before(nav);
  const tabs=sections.map(function(sec,i){
   const b=document.createElement('button');b.type='button';b.className='mad4b-design-section-tab';
   b.textContent=heads[i].textContent.trim().slice(0,65);
   b.id='mad4b-design-tab-'+i;b.setAttribute('role','tab');
   b.setAttribute('aria-controls',sec.id);sec.setAttribute('aria-labelledby',b.id);
   nav.append(b);return b;
  });
  const all=document.createElement('button');all.type='button';all.className='mad4b-design-section-tab';
  all.textContent='All sections';all.setAttribute('role','tab');nav.append(all);tabs.push(all);
  function select(i,focus){
   tabs.forEach((b,j)=>{b.setAttribute('aria-selected',j===i?'true':'false');b.tabIndex=j===i?0:-1;});
   sections.forEach((s,j)=>{s.hidden=i!==sections.length&&j!==i;});
   if(focus)tabs[i].focus();
  }
  tabs.forEach((b,i)=>{
   b.addEventListener('click',()=>select(i,false));
   b.addEventListener('keydown',e=>{
    const j=e.key==='ArrowRight'?(i+1)%tabs.length:e.key==='ArrowLeft'?(i+tabs.length-1)%tabs.length:e.key==='Home'?0:e.key==='End'?tabs.length-1:-1;
    if(j>=0){e.preventDefault();select(j,true);}
   });
  });
  const target=decodeURIComponent(location.hash.slice(1));
  const from=sections.findIndex(s=>s.id===target||!!(target&&s.querySelector('[id="'+target.replace(/"/g,'')+'"]')));
  select(from<0?0:from,false);
  window.addEventListener('hashchange',()=>{
   const h=decodeURIComponent(location.hash.slice(1));
   const i=sections.findIndex(s=>s.id===h||!!(h&&s.querySelector('[id="'+h.replace(/"/g,'')+'"]')));
   if(i>=0)select(i,false);
  });
 }else{
  const headings=Array.from(root.querySelectorAll('h2')).filter(h=>!h.closest('form,details,.notice,.mad4b-workspace')&&h.textContent.trim().length>3).slice(0,15);
  if(headings.length<3)return;
  const nav=document.createElement('nav');nav.className='mad4b-design-anchor-nav';
  nav.setAttribute('aria-label','Report sections');
  headings.forEach((h,i)=>{
   if(!h.id)h.id='mad4b-section-'+i;
   const a=document.createElement('a');a.href='#'+encodeURIComponent(h.id);
   a.textContent=h.textContent.trim().slice(0,55);nav.append(a);
  });
  const first=root.querySelector('h1');if(first)first.after(nav);
 }
}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start,{once:true});else start();
})();
