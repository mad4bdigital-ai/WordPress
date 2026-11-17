/** MAD4B Recipe Workspace: names and guided selection, original WP POST save. */
(() => {
 'use strict';
 const root=document.getElementById('mad4b-recipe-workspace');
 if(!root)return;
 const form=root.querySelector('#mad4b-rw-form');
 const typeInput=root.querySelector('#mad4b-rw-interface');
 const family=[...root.querySelectorAll('.mad4b-rw-family-btn')];
 const target=root.querySelector('#mad4b-rw-target');
 const field=root.querySelector('#mad4b-rw-field');
 const manual=root.querySelector('#mad4b-rw-field-manual');
 const group=root.querySelector('#mad4b-rw-group');
 const groups=root.querySelector('#mad4b-rw-groups');
 const levels=[...root.querySelectorAll('input[name=layer]')];
 const styles=[...root.querySelectorAll('input[name=style]')];
 const data=JSON.parse(root.querySelector('#mad4b-rw-fields-data')?.textContent||'{}');
 const currentLevel=()=>levels.find(x=>x.checked)?.value||'target';
 const chosenType=()=>typeInput.value;
 const refreshFields=()=>{
  const fields=data[target.value]||{};
  field.replaceChildren(new Option('Choose a field…',''));
  for(const [id,label] of Object.entries(fields))field.add(new Option(label,id));
  const isManual=Object.keys(fields).length===0;
  field.hidden=isManual;
  field.disabled=isManual;
  manual.hidden=!isManual;
  manual.disabled=!isManual;
  manual.required=isManual&&currentLevel()==='field';
  // Native group in JetEngine Options Pages often follows page tab/section IDs;
  // this datalist is advisory, never invents values.
  groups.replaceChildren();
  if(chosenType()==='options'){
   const groupsKnown=['general_settings_tab','shared_tour_pricing_tab','shared_price_sources_tab'];
   groupsKnown.forEach(x=>groups.append(new Option(x,x)));
  }
 };
 const refresh=()=>{
  const type=chosenType(),level=currentLevel();
  family.forEach(x=>{
   const active=x.dataset.interface===type;
   x.classList.toggle('is-active',active);
   x.setAttribute('aria-pressed',String(active));
  });
  [...target.options].forEach(o=>{if(!o.value)return;o.hidden=o.dataset.interface!==type;});
  const selected=target.selectedOptions[0];
  if(selected?.dataset.interface!==type)target.value='';
  root.querySelector('[data-requires=target]').hidden=!['target','group','field'].includes(level);
  root.querySelector('[data-requires=group]').hidden=level!=='group';
  root.querySelector('[data-requires=field]').hidden=level!=='field';
  if(['target','group','field'].includes(level))target.required=true;else target.required=false;
  group.required=level==='group';
  refreshFields();
  field.required=level==='field'&&!field.disabled;
  manual.required=level==='field'&&!manual.disabled;
 };
 family.forEach(btn=>btn.addEventListener('click',()=>{typeInput.value=btn.dataset.interface;refresh();}));
 levels.forEach(x=>x.addEventListener('change',refresh));
 target.addEventListener('change',refreshFields);
 styles.forEach(x=>x.addEventListener('change',()=>{
   const val=styles.find(x=>x.checked)?.value||'cards';
   root.querySelector('#mad4b-rw-preview-title').textContent=val.charAt(0).toUpperCase()+val.slice(1);
   root.querySelector('#mad4b-rw-demo').className='mad4b-rw-demo mad4b-rw-demo-'+val;
 }));
 form.addEventListener('submit',e=>{
   if(!form.reportValidity()){e.preventDefault();return;}
   if(['group','field'].includes(currentLevel())&&!target.value){e.preventDefault();target.focus();}
 });
 refresh();
})();
