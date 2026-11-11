(()=>{
 'use strict';
 const root=document.querySelector('.mad4b-import-workspace');
 if(!root)return;
 const form=root.querySelector('.mad4b-import-form');
 if(!form)return;
 const mode=form.querySelector('[name="mode"]');
 const proof=form.querySelector('[name="proof_type"]');
 const connection=form.querySelector('[name="connection_id"]');
 const connectionField=connection?.closest('.mad4b-import-field');
 const update=()=>{
  const contracted=mode?.value==='contracted_provider';
  if(connectionField)connectionField.hidden=!contracted;
  if(contracted && proof && !['contract_reference','partner_connection'].includes(proof.value))
   proof.value='contract_reference';
  if(!contracted && connection)connection.value='';
 };
 mode?.addEventListener('change',update);
 update();
})();
