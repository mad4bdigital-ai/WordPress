/** Source-owned, read-only translated Extras selection for the nonfinancial runtime. */
(()=>{'use strict';
 const groups='[data-extra-actions][data-parent-tour]';
 const selected=new Set();
 const valid=value=>/^[1-9][0-9]*$/.test(String(value));
 function sync(group){
  const id=String(group.dataset.extraActions||''),active=selected.has(id);
  group.dataset.selected=String(active);
  const add=group.querySelector('[data-extra-select]'),remove=group.querySelector('[data-extra-remove]');
  if(add){add.hidden=active;add.setAttribute('aria-pressed',String(active));}
  if(remove){remove.hidden=!active;remove.setAttribute('aria-pressed',String(active));}
 }
 function syncAll(){document.querySelectorAll(groups).forEach(sync);}
 document.addEventListener('click',e=>{
  const button=e.target.closest?.('[data-extra-select],[data-extra-remove]');
  const group=button?.closest(groups);if(!group)return;
  const id=String(group.dataset.extraActions),parent=String(group.dataset.parentTour);
  if(!valid(id)||!valid(parent))return;
  const isSelected=!button.hasAttribute('data-extra-remove');
  if(isSelected)selected.add(id);else selected.delete(id);
  syncAll();
  document.dispatchEvent(new CustomEvent('shared-extra-selection-change',{detail:{tourId:Number(parent),extraTourId:Number(id),selected:isSelected,source:'extra-select-actions',items:[...selected].map(tourId=>({tourId:Number(tourId)})),status:'selection_only_not_booking_authority'}}));
 });
 document.addEventListener('shared-extra-selection-change',e=>{
  const d=e.detail||{},id=String(d.extraTourId||'');
  if(d.source==='extra-select-actions'||!valid(id)||typeof d.selected!=='boolean')return;
  const matches=[...document.querySelectorAll(groups)].filter(x=>x.dataset.extraActions===id && (!d.tourId||x.dataset.parentTour===String(d.tourId)));
  if(!matches.length)return;
  if(d.selected)selected.add(id);else selected.delete(id);syncAll();
 });
 document.addEventListener('jet-filter-content-rendered',syncAll);
 document.addEventListener('DOMContentLoaded',syncAll);
 if(document.readyState!=='loading')syncAll();
})();
