(()=>{
'use strict';
const config=window.MAD4BHybridRateGenerator;
const panel=document.getElementById('mad4b-rate-hybrid-panel');
if(!config||!panel)return;
const kind=panel.querySelector('#mad4b-rate-hybrid-kind');
const input=panel.querySelector('#mad4b-rate-hybrid-target');
const button=panel.querySelector('#mad4b-rate-hybrid-run');
const output=panel.querySelector('#mad4b-rate-hybrid-results');
const el=(tag,text)=>{const x=document.createElement(tag);x.textContent=text;return x;};
button.addEventListener('click',async()=>{
 if(button.disabled)return;
 output.replaceChildren();
 const id=Number(input.value);
 if(!Number.isInteger(id)||id<1){
  output.append(el('p','Enter a valid published or draft Tour / Rate ID.'));return;
 }
 button.disabled=true;
 output.append(el('p','Checking native references and inheritance…'));
 try{
  const data=new URLSearchParams({
   action:config.action,nonce:config.nonce,
   kind:kind.value,record_id:String(id)
  });
  const response=await fetch(config.endpoint,{method:'POST',
   credentials:'same-origin',
   headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},
   body:data.toString()});
  const json=await response.json();
  if(!response.ok||!json.success)throw new Error(json?.data?.message||'Preview failed');
  const result=json.data;
  output.replaceChildren();
  output.append(el('strong','Inherited source: '+(result.effective_source||'Existing default')));
  if(result.effective_policy)
    output.append(el('p','Policy Draft: '+result.effective_policy.title+
      ' · revision '+result.effective_policy.revision));
  else output.append(el('p','No central Draft reference applied; native Rate price remains authoritative.'));
  const conflicts=result.conflicts||[];
  output.append(el('p',conflicts.length
    ?conflicts.length+' conflict(s) must be reviewed in Policy Bindings.'
    :'No inheritance conflicts found.'));
  const steps=el('ol','');
  for(const step of (result.inheritance||[])){
    const count=(step.policies||[]).length;
    steps.append(el('li',step.source.replaceAll('_',' ')+' · '+count+' reference(s)'));
  }
  output.append(steps,el('small','Read-only policy context — price and checkout unchanged.'));
 }catch(e){output.replaceChildren(el('p',String(e.message)));}
 finally{button.disabled=false;}
});
})();
