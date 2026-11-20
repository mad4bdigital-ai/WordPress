(function(){
 'use strict';
 // Keep Fluent Forms + intl-tel-input as the only source of truth for country
 // metadata, dial codes, number validation, and serialization.
 const selector='.fluentform .iti input[type="tel"].ff-el-phone';
 const jobs=new WeakMap();
 function api(){
  return window.intlTelInputGlobals && window.intlTelInputGlobals.getInstance &&
   window.intlTelInputGlobals.getCountryData ? window.intlTelInputGlobals : null;
 }
 function parseExplicitInternational(raw){
  if(typeof raw!=='string'||raw.length>50)return null;
  const match=raw.trim().match(/^(\+|00)\s*([\d\s().-]+)$/);
  if(!match)return null;
  const digits=match[2].replace(/\D/g,'');
  return digits.length>=1 && digits.length<=15?digits:null;
 }
 function countryFor(raw,all,current){
  const digits=parseExplicitInternational(raw);
  if(!digits||!Array.isArray(all)||!all.length)return null;
  // ITU E.164: country calling codes have up to 3 digits.
  let code=null;
  for(let n=Math.min(3,digits.length);n>=1;n--){
   const prefix=digits.slice(0,n);
   if(all.some(c=>String(c.dialCode)===prefix)){code=prefix;break}
  }
  if(!code)return null;
  const matches=all.filter(c=>String(c.dialCode)===code && c.iso2);
  if(!matches.length)return null;
  if(matches.length===1)return matches[0].iso2;
  const rest=digits.slice(code.length);
  // Shared country calling codes (+1, +7, +44). Specific regional area
  // prefixes outrank generic defaults only when the full area code is typed.
  const regional=[];
  for(const c of matches){
   for(const area of (c.areaCodes||[])){
    const value=String(area);
    if(value&&rest.startsWith(value))regional.push({iso:c.iso2,len:value.length});
   }
  }
  regional.sort((a,b)=>b.len-a.len);
  if(regional.length && (regional.length===1 || regional[0].len>regional[1].len))
   return regional[0].iso;
  // When countries share a calling code, honor an existing manual selection.
  if(current&&matches.some(c=>c.iso2===current))return current;
  const primary=matches.find(c=>c.priority===0)||matches[0];
  return primary.iso2;
 }
 function check(input,attempt){
  if(!input.isConnected||!input.matches(selector))return;
  const source=api();
  if(!source){
   if(attempt<6)schedule(input,attempt+1,220);
   return;
  }
  const instance=source.getInstance(input);
  if(!instance||typeof instance.setCountry!=='function'||typeof instance.getSelectedCountryData!=='function'){
   // Fluent Forms initializes this asynchronously; never create a second ITI.
   if(attempt<6)schedule(input,attempt+1,220);
   return;
  }
  const before=input.value;
  if(parseExplicitInternational(before)===null)return;
  const current=instance.getSelectedCountryData()?.iso2||'';
  const country=countryFor(before,source.getCountryData(),current);
  if(!country||country===current)return;
  let start=null,end=null;
  try{start=input.selectionStart;end=input.selectionEnd}catch(e){}
  instance.setCountry(country); // Native library also updates flag & dial indicator.
  if(input.value!==before){
   input.value=before;
   if(start!==null&&end!==null)try{input.setSelectionRange(start,end)}catch(e){}
  }
 }
 function schedule(input,attempt=0,delay=90){
  const prior=jobs.get(input);
  if(prior)clearTimeout(prior);
  jobs.set(input,setTimeout(function(){
   jobs.delete(input);
   check(input,attempt);
  },delay));
 }
 function onEntry(event){
  const input=event.target;
  if(input&&input.matches&&input.matches(selector))schedule(input);
 }
 // Delegation covers Elementor popups, forms embedded by JetEngine, and AJAX.
 document.addEventListener('input',onEntry,false);
 document.addEventListener('change',onEntry,false);
 document.addEventListener('paste',function(event){
  const input=event.target;
  if(input&&input.matches&&input.matches(selector))schedule(input,0,150);
 },false);
})();
