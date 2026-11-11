/** MAD4B Shared Travel Date v1: presentation/session only, never pricing authority. */
(() => {
 'use strict';
 const KEY='mad4b:travel-date:v1', MAX_AGE=12*60*60*1000;
 const valid=d=>typeof d==='string'&&/^\d{4}-\d{2}-\d{2}$/.test(d)&&(()=>{
  const x=new Date(d+'T12:00:00');return !Number.isNaN(x.getTime())&&[x.getFullYear(),String(x.getMonth()+1).padStart(2,'0'),String(x.getDate()).padStart(2,'0')].join('-')===d;
 })();
 const nowIso=()=>{const t=new Date();return [t.getFullYear(),String(t.getMonth()+1).padStart(2,'0'),String(t.getDate()).padStart(2,'0')].join('-');};
 const normalize=v=>{
  const s=String(v||'').trim();if(valid(s))return s;
  const numeric=s.match(/^(\d{1,2})[\/.-](\d{1,2})[\/.-](\d{4})$/);
  if(numeric){const d=[numeric[3],numeric[2].padStart(2,'0'),numeric[1].padStart(2,'0')].join('-');return valid(d)?d:null;}
  const months={jan:1,feb:2,mar:3,apr:4,may:5,jun:6,jul:7,aug:8,sep:9,oct:10,nov:11,dec:12};
  const short=s.match(/^(\d{1,2})-([a-z]{3})-(\d{2,4})$/i);
  if(short){const m=months[short[2].toLowerCase()];const y=Number(short[3])<100?2000+Number(short[3]):Number(short[3]);
   const d=[String(y).padStart(4,'0'),String(m||0).padStart(2,'0'),short[1].padStart(2,'0')].join('-');return valid(d)?d:null;}
  return null;
 };
 const read=()=>{try{const v=JSON.parse(sessionStorage.getItem(KEY)||'null');return v&&valid(v.date)&&v.date>=nowIso()&&Date.now()-v.at<MAX_AGE?v.date:null;}catch(_){return null;}};
 const write=(date,origin)=>{if(!valid(date)||date<nowIso())return;try{sessionStorage.setItem(KEY,JSON.stringify({date,at:Date.now(),origin:String(origin||'ui').slice(0,40)}));}catch(_){}document.dispatchEvent(new CustomEvent('mad4b-travel-date-changed',{detail:{travel_date:date,origin}}));};
 const drop=()=>{try{sessionStorage.removeItem(KEY);}catch(_){}document.dispatchEvent(new CustomEvent('mad4b-travel-date-changed',{detail:{travel_date:null,origin:'clear'}}));};
 const urlDate=normalize(new URLSearchParams(location.search).get('travel_date'));
 if(urlDate&&urlDate>=nowIso())write(urlDate,'url');
 const current=()=>read();
 const isTourUrl=url=>{try{const u=new URL(url,location.href);return u.origin===location.origin&&/(?:^|\/)tours-and-activities\//.test(u.pathname);}catch(_){return false;}};
 function attachSearch(widget){
  if(widget.dataset.mad4bDateBound)return;widget.dataset.mad4bDateBound='1';
  const form=widget.querySelector('form')||widget.querySelector('.jet-ajax-search');
  if(!form)return;
  const lang=(document.documentElement.lang||'en').slice(0,2);
  const label={ar:'تاريخ الرحلة',en:'Travel Date',fr:'Date du voyage',de:'Reisedatum',es:'Fecha del viaje',it:'Data del viaggio'}[lang]||'Travel Date';
  const wrap=document.createElement('label');wrap.className='mad4b-shared-travel-date-search';
  const caption=document.createElement('span');caption.className='mad4b-travel-date-label';caption.textContent=label;
  const input=document.createElement('input');input.type='date';input.name='travel_date';input.min=nowIso();input.setAttribute('aria-label',label);
  input.value=current()||'';wrap.append(caption,input);
  const searchForm=form.matches('form')?form:form.querySelector('form');
  if(searchForm) searchForm.append(wrap);else form.append(wrap);
  input.addEventListener('change',()=>{if(valid(input.value))write(input.value,'search');else drop();});
  if(searchForm)searchForm.addEventListener('submit',()=>{
    const date=normalize(input.value);if(!date)return;
    const action=searchForm.getAttribute('action');if(!action||!isTourUrl(action))return;
    const dest=new URL(action,location.href);dest.searchParams.set('travel_date',date);
    searchForm.action=dest.pathname+dest.search;
  },true);
  document.addEventListener('mad4b-travel-date-changed',e=>{
    if(e.detail?.origin==='search'&&document.activeElement===input)return;
    input.value=e.detail?.travel_date||'';
  });
 }
 function attachFluent(input){
  if(input.dataset.mad4bDateBound)return;input.dataset.mad4bDateBound='1';
  const initial=current();if(initial&&!normalize(input.value)){
    const apply=()=>{
      if(normalize(input.value))return;
      if(input._flatpickr&&typeof input._flatpickr.setDate==='function')
        input._flatpickr.setDate(initial,true,'Y-m-d');
      else{input.value=initial;input.dispatchEvent(new Event('change',{bubbles:true}));}
    };
    apply();setTimeout(apply,500);
  }
  const update=()=>{const date=normalize(input.value);if(date)write(date,'fluent');};
  input.addEventListener('change',update);
  input.addEventListener('input',update);
 }
 function attachNativeFacet(root){
  if(root.dataset.mad4bDateBound)return;root.dataset.mad4bDateBound='1';
  const fields=()=>[...root.querySelectorAll('input')].filter(x=>!['hidden','submit','button'].includes(x.type));
  const source=fields()[0],target=fields()[1];
  if(!source)return;
  const previous=current();if(previous&&!source.value){
    const fmt=previous.replace(/-/g,'.');
    source.value=fmt;
    if(target)target.value=fmt;
  }
  const changed=()=>{
    const parsed=normalize(source.value.replace(/\./g,'/'));
    const iso=parsed||normalize(source.value.replace(/^(\d{4})\.(\d{2})\.(\d{2})$/,'$1-$2-$3'));
    if(iso){if(target)target.value=source.value;write(iso,'facet');}
  };
  source.addEventListener('change',changed,true);
  source.addEventListener('blur',changed,true);
  if(target){target.tabIndex=-1;target.setAttribute('aria-hidden','true');}
  document.addEventListener('mad4b-travel-date-changed',e=>{
   if(e.detail?.origin==='facet')return;
   const d=e.detail?.travel_date;if(!d)return;
   if(!source.value)source.value=d.replace(/-/g,'.');
   if(target&&!target.value)target.value=source.value;
  });
 }
 function scan(){
   document.querySelectorAll('[data-mad4b-travel-date-search="1"]').forEach(attachSearch);
   document.querySelectorAll('form [name="datetime"]').forEach(attachFluent);
   document.querySelectorAll('.mad4b-travel-date-facet .jet-smart-filters-date-range').forEach(attachNativeFacet);
 }
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',scan,{once:true});else scan();
 let scheduled=false;
 new MutationObserver(()=>{if(scheduled)return;scheduled=true;requestAnimationFrame(()=>{scheduled=false;scan();});}).observe(document.documentElement,{childList:true,subtree:true});
 document.addEventListener('click',e=>{
  const link=e.target.closest('a[href]');if(!link||!isTourUrl(link.href))return;
  const date=current();if(!date)return;
  const u=new URL(link.href);if(u.pathname.replace(/\/$/,'')===location.pathname.replace(/\/$/,''))return;
  if(!u.searchParams.has('travel_date')){u.searchParams.set('travel_date',date);link.href=u.toString();}
 },true);
 window.MAD4BTravelDate=Object.freeze({get:current,set:d=>{const n=normalize(d);if(n)write(n,'api');return Boolean(n);},clear:drop,version:'1.0.0'});
})();
