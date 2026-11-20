'use strict';
// No external packages: deterministic reproduction of the Fluent Form 6
// select-option childList feedback loop observed in real Chromium.
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const path=require('node:path');
const file=path.join(__dirname,'../assets/transport-form6-dropoff.js');
const script=fs.readFileSync(file,'utf8');
assert.match(script,/option\.textContent\s*!==\s*c\.choices\[idx\]/,
  'Never write an unchanged drop-off option label into an observed subtree');

(async()=>{
 let childListWrites=0,observerNotifications=0;
 const observers=[];
 const notify=()=>{
   childListWrites++;
   for(const observer of observers.filter(o=>o.active)){
     queueMicrotask(()=>{
       if(!observer.active)return;
       observerNotifications++;
       assert.ok(observerNotifications<10,
          'The drop-off MutationObserver is stuck updating its own option text');
       observer.callback([{type:'childList'}]);
     });
   }
 };
 const makeTextNode=(initial)=>{
   let text=initial;
   return {get textContent(){return text},set textContent(v){text=String(v);notify()}};
 };
 const groups=[];
 const inputs={};
 for(const key of ['mad4b_dropoff_mode','mad4b_dropoff_place_name',
       'mad4b_dropoff_city','mad4b_dropoff_address']){
   const label=makeTextNode('Original '+key),group={style:{display:''},querySelector:x=>x==='label'?label:null};
   groups.push(group);
   inputs[key]={
     name:key,dataset:{},disabled:false,required:false,tagName:key==='mad4b_dropoff_mode'?'SELECT':'INPUT',
     value:'',addEventListener(event,fn){(this.events??={})[event]=fn},
     setAttribute(k,v){(this.attrs??={})[k]=v},
     closest(x){return x==='.mad4b-transport-dropoff-field'?group:null}
   };
 }
 const choices=['','same_as_pickup','hotel','airport','rail_station','port','meeting_point','to_confirm'];
 inputs.mad4b_dropoff_mode.options=choices.map(value=>Object.assign(makeTextNode('Original '+value),{value}));
 const form={querySelector(selector){
   const match=selector.match(/\[name="([^"]+)"\]/);
   return match?inputs[match[1]]||null:null;
 }};
 const handlers={};
 const document={
   readyState:'loading',body:{},
   querySelectorAll(selector){return selector==='form.fluent_form_6'?[form]:[]},
   addEventListener(type,fn){(handlers[type]??=[]).push(fn)},
   dispatch(type,event={}){for(const fn of handlers[type]||[])fn(event)}
 };
 class MutationObserver {
   constructor(callback){this.callback=callback;this.active=false;observers.push(this)}
   observe(body,opts){assert.strictEqual(body,document.body);
     assert.equal(opts.childList,true);this.active=true}
   disconnect(){this.active=false}
 }
 const window={
   MAD4BTransportForm6Dropoff:{tourId:60473,defaultEligible:true,eligibleRates:[],lang:'en'}
 };
 vm.runInNewContext(script,{document,window,MutationObserver,setTimeout(){return 1},console},
   {filename:file,timeout:1000});
 document.dispatch('DOMContentLoaded');
 assert.equal(observers.length,1,'A bounded new form observer must be registered');
 assert.equal(inputs.mad4b_dropoff_mode.disabled,false);
 assert.equal(groups[0].style.display,'block');
 assert.equal(groups[1].style.display,'none');
 assert.equal(inputs.mad4b_dropoff_mode.options[2].textContent,'Hotel or cruise ship');
 const writesAfterFirstTranslation=childListWrites;
 document.dispatch('mad4b:tour-rate-changed');
 assert.equal(childListWrites,writesAfterFirstTranslation,
   'An unchanged update must not write option text to a childList observer');
 // Simulate a late translation/update in an actual FluentForm subtree.
 inputs.mad4b_dropoff_mode.options[2].textContent='Temporary wrong translation';
 await new Promise(resolve=>setImmediate(resolve));
 assert.equal(inputs.mad4b_dropoff_mode.options[2].textContent,'Hotel or cruise ship');
 assert.ok(observerNotifications<=2,
    'One external mutation may cause at most one translating follow-up update');
 const writesAfterRecovery=childListWrites;
 document.dispatch('shared-presentation-selection-changed');
 assert.equal(childListWrites,writesAfterRecovery,
    'A later rate update must not reenter the MutationObserver loop');
 inputs.mad4b_dropoff_mode.value='hotel';
 inputs.mad4b_dropoff_mode.events.change();
 assert.deepEqual(groups.map(x=>x.style.display),['block','block','block','block'],
   'Choosing a hotel still reveals all final drop-off details');
 assert.equal(inputs.mad4b_dropoff_place_name.disabled,false);
 console.log('PASS 8/8 transport Form6 mutation-noop, source translation, dynamic rate and hotel-field tests');
})().catch(e=>{console.error('HOLD_TRANSPORT_FORM6_OBSERVER',e.message);process.exitCode=3});
