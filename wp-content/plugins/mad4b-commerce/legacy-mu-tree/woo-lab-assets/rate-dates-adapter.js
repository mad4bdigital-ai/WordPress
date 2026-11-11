/* ETG WooCommerce Lab — jQuery UI datepicker guard, no vendor modifications. */
(function () {
 'use strict';
 const cfg=window.ETGLabRateDates; if(!cfg || !cfg.tourId)return;
 const $=window.jQuery; if(!$ || !$.fn.datepicker)return;
 let controller=null, requestId=0, lastKey='', allowed=null;
 const dateSelector='.jet-date-range__from, .jet-date-range__to';
 function controls(){return $(dateSelector).filter(function(){return $(this).closest('.jet-date-range').length;});}
 function findSeason(){return $('select[name="season"]').first();}
 function findProgram(){return $('select[name="related_program_term_id"]').first();}
 function keyOf(d){return d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0');}
 function clearInvalid(group){
   const $group=$(group), from=$group.find('.jet-date-range__from'),to=$group.find('.jet-date-range__to');
   let invalid=false;
   for(const input of [from,to]){const raw=input.val();if(!raw)continue;let d;try{d=input.datepicker('getDate');}catch(e){}if(d&&!allowed.has(keyOf(d)))invalid=true;}
   const d1=from.val()?from.datepicker('getDate'):null,d2=to.val()?to.datepicker('getDate'):null;
   if(d1&&d2){for(let d=new Date(d1);d<=d2;d.setDate(d.getDate()+1)){if(!allowed.has(keyOf(d))){invalid=true;break;}}}
   if(invalid){for(const input of [from,to]){input.datepicker('setDate',null);input.val('').trigger('change');}$group.find('.jet-date-range__input').val('').trigger('change');$group.attr('data-etg-date-cleared','1');}
 }
 function attach(){
  if(allowed===null)return;
  controls().each(function(){const input=$(this);if(!input.hasClass('hasDatepicker'))return;
   const existing=input.datepicker('option','beforeShowDay');
   if(existing&&existing.etgGuard)return;
   const guard=function(date){let old=typeof existing==='function'?existing.call(this,date):[true,'',''];if(!Array.isArray(old))old=[!!old,'',''];return [!!old[0]&&(allowed===null || allowed.has(keyOf(date))),old[1]||'',old[2]||''];};guard.etgGuard=true;
   input.datepicker('option','beforeShowDay',guard);input.datepicker('refresh');
  });
  $('.jet-date-range').each(function(){clearInvalid(this);this.dataset.etgRateDatesLoaded='1';this.dataset.etgRateCoveredCount=String(allowed.size);});
 }
 async function refresh(){
  const inputs=controls();if(!inputs.length)return;
  const season=findSeason().val()||'';const program=findProgram().val()||'';
  const now=new Date(), first=new Date(now.getFullYear(),now.getMonth(),1),last=new Date(first.getFullYear()+1,first.getMonth(),0);
  const from=keyOf(first),to=keyOf(last),key=[season,program,from,to].join(':');if(key===lastKey){attach();return;}lastKey=key;
  if(controller)controller.abort();controller=new AbortController();const token=++requestId;allowed=null;
  const url=new URL(cfg.endpoint,window.location.origin);Object.entries({product_id:cfg.tourId,season,program_id:program,from,to}).forEach(([k,v])=>url.searchParams.set(k,v));
  try {const response=await fetch(url,{signal:controller.signal,credentials:'same-origin'});if(!response.ok)throw Error('coverage unavailable');const json=await response.json();if(token!==requestId)return;
   allowed=new Set(json.dates||[]);attach();document.dispatchEvent(new CustomEvent('etg:rate-dates-ready',{detail:{season,allowedDates:json.dates||[],semantics:json.semantics}}));
  }catch(e){if(e.name!=='AbortError'){lastKey='';console.warn('ETG rate date coverage failed',e);}}
 }
 $(document).on('change','select',function(){if($(this).is(findSeason())||$(this).is(findProgram()))refresh();});
 $(document).on('jet-filter-content-rendered',()=>{lastKey='';refresh();});
 $(function(){refresh();});
})();
