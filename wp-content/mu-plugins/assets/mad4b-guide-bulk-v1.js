/* MAD4B Guide Days Bulk Review — admin only, AJAX and source-revalidated server writes. */
(() => {
 'use strict';
 const cfg=window.MAD4BGuideBulkV1;
 const host=document.getElementById('mad4b-guide-bulk-app');
 if(!cfg || !host)return;
 const list=document.getElementById('mad4b-guide-list');
 const detail=document.getElementById('mad4b-guide-detail');
 const progress=document.getElementById('mad4b-guide-progress');
 const pageLabel=document.getElementById('mad4b-guide-page-label');
 const prev=document.getElementById('mad4b-guide-prev');
 const next=document.getElementById('mad4b-guide-next');
 let current=0,totalPages=1,sources=[],inFlight=false;
 const states=[
  ['unknown','Not reviewed / Unknown'],
  ['full_day','Full guided day'],
  ['half_day','Half guided day'],
  ['none','No guide in base'],
  ['optional','Optional guide (not in base)']
 ];
 const node=(tag,text,cls)=>{
  const el=document.createElement(tag);
  if(text!==undefined&&text!==null)el.textContent=String(text);
  if(cls)el.className=cls;
  return el;
 };
 const message=(text,failed=false)=>{
  progress.textContent=String(text||'');
  progress.style.color=failed?'#a00':'';
 };
 async function ajax(action,params={}) {
  const values=new URLSearchParams({action,nonce:cfg.nonce,...params});
  const response=await fetch(cfg.url,{
   method:'POST',body:values,
   headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
   credentials:'same-origin',cache:'no-store'
  });
  const data=await response.json();
  if(!response.ok||!data?.success)throw new Error(data?.data?.reason||('HTTP '+response.status));
  return data.data;
 }
 const busy=(flag)=>{
  inFlight=flag;
  prev.disabled=flag||current===0;
  next.disabled=flag||current>=totalPages-1;
  document.getElementById('mad4b-guide-reload').disabled=flag;
  document.getElementById('mad4b-guide-bulk-high').disabled=flag;
 };
 function renderIndex(result){
  sources=result.sources||[];
  current=result.page||0;
  totalPages=Math.max(1,result.total_pages||1);
  list.replaceChildren();
  const table=node('table');table.className='widefat striped';
  const head=node('thead'),header=node('tr');
  for(const val of ['Select','Tour / reusable Program','Review state','Days','HIGH candidates','Action'])
   header.append(node('th',val));
  head.append(header);table.append(head);
  const tbody=node('tbody');
  sources.forEach(row=>{
   const tr=node('tr');
   const selection=node('td');
   const checkbox=node('input');
   checkbox.type='checkbox';
   checkbox.className='mad4b-guide-select';
   checkbox.dataset.tour=String(row.tour_id);
   checkbox.dataset.program=String(row.program_id);
   checkbox.dataset.hash=String(row.source_hash||'');
   checkbox.dataset.revision=String(row.revision);
   checkbox.disabled=!(row.high_new_candidates>0&&row.source_hash);
   checkbox.checked=!checkbox.disabled;
   checkbox.setAttribute('aria-label','Approve high-confidence day evidence for '+row.title);
   selection.append(checkbox);
   tr.append(selection);
   const name=node('td');
   name.append(node('strong',row.title||'(untitled)'));
   name.append(node('div',row.kind==='program'
    ?'Shared Program #'+row.program_id+' · linked to '+row.linked_tours+' Tour(s)'
    :'Canonical Tour #'+row.tour_id,'mad4b-guide-bulk-note'));
   tr.append(name);
   const status=node('td');
   status.append(node('span',row.status==='blocked'
    ?'Needs source correction: '+row.block_reason:row.status));
   tr.append(status);
   tr.append(node('td',row.day_count??'—'));
   tr.append(node('td',row.high_new_candidates??0));
   const act=node('td');
   const button=node('button',row.status==='blocked'?'Cannot classify':'Review days');
   button.type='button';button.className='button';
   button.disabled=row.status==='blocked';
   button.addEventListener('click',()=>openReview(row));
   act.append(button);
   tr.append(act);tbody.append(tr);
  });
  table.append(tbody);
  list.append(table);
  const summary=node('p','Sources '+result.total_sources+
   ' · Page '+(current+1)+' of '+totalPages+
   ' · HIGH evidence requires approval; unknown days stay unpriced.','mad4b-guide-bulk-note');
  list.prepend(summary);
  pageLabel.textContent='Page '+(current+1)+' / '+totalPages;
  prev.disabled=current===0;
  next.disabled=current>=totalPages-1;
 }
 async function load(){
  if(inFlight)return;
  busy(true);message('Loading canonical sources…');detail.hidden=true;
  try{
   const result=await ajax(cfg.indexAction,{page:String(current)});
   renderIndex(result);message('Ready: '+result.sources.length+' sources on this page.');
  }catch(e){message('Index failed: '+e.message,true);}
  finally{busy(false);}
 }
 function renderReview(data){
  detail.replaceChildren();
  const top=node('div');
  top.append(node('h2','Guide service day review · '+data.source_key));
  top.append(node('p','Itinerary day basis: '+data.day_unit_basis+
   ' · revision '+data.revision+' · '+data.unresolved_days+' unreviewed days.'));
  detail.append(top);
  const table=node('table');table.className='widefat striped';
  const head=node('tr');
  for(const col of ['Itinerary day','Suggested status','Evidence & reason','Final review decision'])
   head.append(node('th',col));
  const thead=node('thead');thead.append(head);table.append(thead);
  const body=node('tbody');
  const controls=[];
  data.days.forEach(day=>{
   const tr=node('tr');
   const col1=node('td');
   col1.append(node('strong',(day.number?day.number+' · ':'')+(day.title||day.key)));
   col1.append(node('small','Day key: '+day.key,'mad4b-guide-bulk-note'));
   tr.append(col1);
   tr.append(node('td',day.suggested_status+' / '+day.confidence));
   const evidence=node('td');
   evidence.append(node('div',day.reason,'mad4b-guide-bulk-note'));
   evidence.append(node('div',day.evidence||'No source evidence','mad4b-evidence'));
   tr.append(evidence);
   const cell=node('td');
   const select=node('select');
   select.setAttribute('aria-label','Final guide-day decision for '+(day.title||day.key));
   for(const [value,label] of states){
    const option=node('option',label);option.value=value;select.append(option);
   }
   select.value=day.saved_status!=='unknown'?day.saved_status:
      (day.confidence==='high'?day.suggested_status:'unknown');
   cell.append(select);tr.append(cell);body.append(tr);
   controls.push({key:day.key,select,day});
  });
  table.append(body);detail.append(table);
  const halfRow=node('p');
  halfRow.append(node('label','Half-day multiplier (leave blank unless approved): '));
  const halfFactor=node('input');
  halfFactor.type='number';halfFactor.min='0.01';halfFactor.max='1';halfFactor.step='0.01';
  halfFactor.value=data.half_day_factor??'';
  halfRow.append(halfFactor);detail.append(halfRow);
  const label=node('label','Review note (required for decisions not backed by HIGH evidence):');
  const note=node('textarea');
  note.rows=3;note.placeholder='Source contract, supplier confirmation, or documented operator rationale';
  label.append(node('div'));label.append(note);
  detail.append(label);
  const row=node('div');row.className='mad4b-guide-bulk-toolbar';
  const save=node('button','Save reviewed days');
  save.type='button';save.className='button button-primary';
  const close=node('button','Close review');
  close.type='button';close.className='button';
  close.addEventListener('click',()=>{detail.hidden=true;});
  save.addEventListener('click',async()=>{
   const decisions=Object.fromEntries(controls.map(c=>[c.key,c.select.value]));
   if(!Object.values(decisions).some(x=>x!=='unknown')){
    message('No guide service decision selected.',true);return;
   }
   save.disabled=true;message('Verifying source hash and saving reviewed decisions…');
   try{
    const result=await ajax(cfg.approveAction,{
     payload:JSON.stringify({
      mode:'manual_review',tour_id:data.tour_id,program_id:data.program_id,
      source_hash:data.source_hash,revision:data.revision,decisions,
      half_day_factor:halfFactor.value,review_note:note.value
     })
    });
    message('Saved '+result.changed_days+' decisions. Unknown remaining: '+result.unknown_remaining+'.');
    detail.hidden=true;await load();
   }catch(e){message('Review not saved: '+e.message,true);}
   finally{save.disabled=false;}
  });
  row.append(save,close);detail.append(row);
  detail.hidden=false;
  detail.scrollIntoView({behavior:'smooth',block:'start'});
 }
 async function openReview(row){
  if(inFlight)return;
  message('Loading evidence for '+row.title+'…');
  try{
   const data=await ajax(cfg.detailAction,{
    tour_id:String(row.tour_id),program_id:String(row.program_id)
   });
   renderReview(data);message('Review evidence; unclear days remain Unknown.');
  }catch(e){message('Cannot review source: '+e.message,true);}
 }
 async function approveHigh(){
  if(inFlight)return;
  const selected=[...list.querySelectorAll('input.mad4b-guide-select:checked:not(:disabled)')];
  if(!selected.length){message('Select a source with HIGH evidence first.',true);return;}
  if(!window.confirm('Approve only explicitly HIGH-confidence guide evidence for '+
     selected.length+' selected source(s)? Unknown/medium/low days will remain unchanged.'))return;
  busy(true);
  let saved=0,changed=0;const failures=[];
  for(const field of selected){
   try{
    const item=await ajax(cfg.approveAction,{
     payload:JSON.stringify({
      mode:'high_only',tour_id:Number(field.dataset.tour),
      program_id:Number(field.dataset.program),
      source_hash:field.dataset.hash,revision:Number(field.dataset.revision)
     })
    });
    saved++;changed+=item.changed_days;
   }catch(e){failures.push('Tour '+field.dataset.tour+': '+e.message);}
  }
  busy(false);
  message('HIGH approvals: '+saved+' sources / '+changed+' days.'+
      (failures.length?' Exceptions: '+failures.join(' · '):''),!!failures.length);
  if(!failures.length){await load();}
  else {
   // Do not silently erase failure diagnostics before the operator sees them.
   detail.hidden=true;
  }
 }
 document.getElementById('mad4b-guide-reload').addEventListener('click',load);
 document.getElementById('mad4b-guide-bulk-high').addEventListener('click',approveHigh);
 prev.addEventListener('click',()=>{if(!inFlight&&current>0){current--;load();}});
 next.addEventListener('click',()=>{if(!inFlight&&current<totalPages-1){current++;load();}});
 load();
})();
