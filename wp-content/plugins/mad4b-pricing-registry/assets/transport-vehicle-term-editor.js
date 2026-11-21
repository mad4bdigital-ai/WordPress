/* Presentation-only adapter for native JetEngine term-meta form fields.
 * Never changes input names, values, submit actions, or native save logic.
 */
(() => {
 'use strict';
 function init(){
  const root=document.querySelector('.jet-engine-term-meta-wrap');
  if(!root||root.dataset.mad4bVehicleUiReady)return;
  root.dataset.mad4bVehicleUiReady='1';
  const info=document.createElement('section');
  info.className='mad4b-vehicle-term-help';
  info.setAttribute('aria-label','Vehicle operating cost profile');
  const heading=document.createElement('strong');
  heading.textContent='Vehicle Operating Cost · EGP · JetEngine Term Meta';
  const note=document.createElement('p');
  note.textContent='The canonical cost profile for this physical vehicle type. Changes inherit into Transportation and Transport Rates unless a local Override exists. The BASE is an included minimum, never an extra charge.';
  const warning=document.createElement('p');
  warning.textContent='Actual PDF trip prices are real company costs; EGP/km and minute coefficients are calibrated estimates. Saving this term does not approve selling prices or enable checkout.';
  warning.className='mad4b-vehicle-term-warning';
  info.append(heading,note,warning);
  const forms=root.querySelector('.jet-engine-term-meta-header');
  if(forms)forms.insertAdjacentElement('afterend',info);
  else root.prepend(info);
  const fields=['mad4b_vehicle_cost_base_egp','mad4b_vehicle_cost_per_km_egp',
   'mad4b_vehicle_cost_drive_min_egp','mad4b_vehicle_cost_wait_min_egp',
   'mad4b_vehicle_cost_overnight_egp','mad4b_vehicle_cost_min_fare_egp',
   'mad4b_vehicle_cost_deadhead_pct','mad4b_vehicle_cost_basis',
   'mad4b_vehicle_cost_samples','mad4b_vehicle_dispatch_mode'];
  let count=0;
  for(const key of fields){
   let input=root.querySelector('[name="'+key+'"]')||
    root.querySelector('[name$="['+key+']"]')||
    root.querySelector('[id="'+key+'"]');
   if(!input)continue;
   const row=input.closest('.cx-control, .form-field, .jet-engine-meta-field')||
    input.parentElement;
   if(!row)continue;
   row.classList.add('mad4b-term-field-card');
   row.dataset.mad4bUiMode=key==='mad4b_vehicle_dispatch_mode'?
    'select-cards':(key==='mad4b_vehicle_cost_basis'?
    'source-cards':'field-card');
   if(key==='mad4b_vehicle_cost_base_egp')
    row.classList.add('mad4b-term-base-floor');
   count++;
  }
  if(count){
   const status=document.createElement('p');
   status.className='mad4b-term-edit-status';
   status.setAttribute('role','status');
   status.setAttribute('aria-live','polite');
   status.textContent='Changes are saved using the original WordPress Update button.';
   root.append(status);
   root.addEventListener('input',e=>{
    if(e.target.matches('input, select'))
     status.textContent='Unsaved vehicle profile changes. Use Update to save.';
   });
  }
 }
 if(document.readyState==='loading')
  document.addEventListener('DOMContentLoaded',init,{once:true});
 else init();
})();
