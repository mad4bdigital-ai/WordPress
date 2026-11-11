(()=>{
 'use strict';
 const shell=document.querySelector('.mad4b-pricing-unified-shell');
 if(!shell)return;
 // Only one popover dropdown at a time, closes naturally outside.
 const more=shell.querySelector('.mad4b-pricing-unified-more');
 document.addEventListener('click',e=>{
  if(more?.open&&!more.contains(e.target))more.open=false;
 });
 document.addEventListener('keydown',e=>{
  if(e.key==='Escape'&&more?.open){more.open=false;
   more.querySelector('summary')?.focus();}
 });
 // Rate Generator's original form is very long. Split existing DOM children
 // into local sections *inside the same native WooCommerce form*; no iframes,
 // cloned inputs, second save handler or altered POST field names.
 const generator=document.getElementById('shared-rate-generation-fields');
 if(!generator)return;
 const source=[...generator.children];
 const panels=[
  {id:'draft',label:'Create Rate Draft',children:[]},
  {id:'batch',label:'Batch CSV',children:[]},
  {id:'choice',label:'Rate & Quote Inspection',children:[]},
  {id:'followup',label:'Draft Follow-up',children:[]},
 ];
 let current=0;
 let pendingSeparator=null;
 for(const node of source){
  if(node.tagName==='HR'){
   if(pendingSeparator)panels[current].children.push(pendingSeparator);
   pendingSeparator=node;
   continue;
  }
  let next=current;
  if(node.tagName==='H3'){
   const name=node.textContent.toLowerCase();
   if(name.includes('batch rate source'))next=1;
   else if(name.includes('rate choice inspection'))next=2;
   else if(name.includes('recently generated rate'))next=3;
  }
  if(next!==current){current=next;pendingSeparator=null;}
  else if(pendingSeparator){
   panels[current].children.push(pendingSeparator);pendingSeparator=null;
  }
  panels[current].children.push(node);
 }
 if(pendingSeparator)panels[current].children.push(pendingSeparator);
 // Don't hide unclassified/unknown future fields. Do not create empty tabs.
 const available=panels.filter(x=>x.children.length>0);
 if(available.length<2)return;
 const nav=document.createElement('nav');
 nav.className='mad4b-rate-native-local-tabs';
 nav.setAttribute('aria-label','Rate Generator sections');
 const sections=[];
 for(const [index,panel] of available.entries()){
  const btn=document.createElement('button');
  btn.type='button';btn.textContent=panel.label;
  btn.setAttribute('role','tab');
  btn.id='mad4b-rate-tab-'+panel.id;
  btn.setAttribute('aria-controls','mad4b-rate-panel-'+panel.id);
  const content=document.createElement('section');
  content.id='mad4b-rate-panel-'+panel.id;
  content.className='mad4b-rate-native-section';
  content.setAttribute('role','tabpanel');
  content.setAttribute('aria-labelledby',btn.id);
  for(const child of panel.children)content.append(child);
  nav.append(btn);sections.push({btn,content,id:panel.id});
 }
 generator.prepend(nav,...sections.map(s=>s.content));
 const switchTo=id=>{
  if(!sections.some(s=>s.id===id))id=sections[0].id;
  for(const section of sections){
   const active=section.id===id;
   section.content.hidden=!active;
   section.btn.setAttribute('aria-selected',String(active));
   section.btn.tabIndex=active?0:-1;
   section.btn.classList.toggle('is-current',active);
  }
  try{sessionStorage.setItem('mad4b-rate-generator-section-v1',id);}catch{}
 };
 for(const section of sections){
  section.btn.addEventListener('click',()=>switchTo(section.id));
  section.btn.addEventListener('keydown',e=>{
   const i=sections.indexOf(section);
   if(e.key!=='ArrowRight'&&e.key!=='ArrowLeft')return;
   e.preventDefault();
   const next=(i+(e.key==='ArrowRight'?1:-1)+sections.length)%sections.length;
   switchTo(sections[next].id);sections[next].btn.focus();
  });
 }
 let preferred=sections[0].id;
 try{preferred=sessionStorage.getItem('mad4b-rate-generator-section-v1')||preferred;}catch{}
 switchTo(preferred);
})();
