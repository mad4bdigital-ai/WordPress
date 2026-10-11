/* DOM contract simulation, not a browser or ChatGPT host acceptance receipt. */
'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const ui=require('../assets/cso-form.js');
let checks=0;
function check(value,label) { assert.ok(value,label); checks++; }
class Element {
  constructor(doc,tag) {this.ownerDocument=doc;this.tagName=tag.toUpperCase();this.children=[];this.attrs={};this.listeners={};this.textContent='';this._value='';this.checked=false;this.disabled=false;}
  get value() {return this.tagName==='SELECT'?(this.children.find(o=>o.selected)?.value??''):this._value;}
  set value(v) {if(this.tagName==='SELECT') this.children.forEach(o=>{o.selected=o.value===String(v);}); else this._value=String(v);}
  setAttribute(k,v) {this.attrs[k]=String(v);if(k==='value') this.value=v;}
  removeAttribute(k) {delete this.attrs[k];}
  append(...nodes) {nodes.forEach(n=>{n.parent=this;});this.children.push(...nodes);if(this.tagName==='SELECT'&&!this.multiple&&!this.children.some(o=>o.selected)&&this.children.length) this.children[0].selected=true;}
  remove() {if(this.parent) this.parent.children=this.parent.children.filter(n=>n!==this);}
  replaceChildren(...nodes) {this.children=nodes;this.textContent='';}
  addEventListener(k,fn) {this.listeners[k]=fn;}
  focus() {this.ownerDocument.focused=this;}
  get selectedOptions() {return this.children.filter(x=>x.selected);}
  async fire(k) {return this.listeners[k]?.({preventDefault(){}});}
}
class Document {createElement(tag) {return new Element(this,tag);}}
function fixture(fields,transport=async()=>({status:'VALIDATED'}),direction='rtl') {
  const doc=new Document();const root=doc.createElement('main');const config={presentation:{direction,form:{sealed_form:{material:{ability_name:'fixture/edit'}},fields}}};
  return {root,doc,...ui.render(root,config,transport)};
}
function flat(node) {return [node,...node.children.flatMap(flat)];}
(async()=>{
  let entered=0;
  const state=fixture([
    {field_id:'title',label:'اسم الرحلة <img src=x onerror=evil()>',type:'string',required:true,help:'تفاصيل',maxLength:80},
    {field_id:'price',type:'number',minimum:0},
    {field_id:'enabled',type:'boolean'},
    {field_id:'secret_key',type:'secret_handoff'},
    {field_id:'readonly',type:'string',read_only:true},
    {field_id:'destination',type:'enum',options:[{value:12,label:'الأقصر'},{value:29,label:'القاهرة'}]},
    {field_id:'nested',type:'repeater'},
  ],async(action,args)=>{entered++;check(action==='typed_validate','only validation sent');check(args.values.title==='رحلة <script>alert(1)</script>','literal user text preserved for server validation');check(typeof args.values.price==='number','typed numeric payload');check(args.values.destination===29,'typed native enum identifier');check(!Object.hasOwn(args.values,'secret_key'),'no secret payload');return {status:'VALIDATED'};});
  check(state.root.attrs.dir==='rtl','Arabic direction');
  check(state.controls.length===5,'secret and readonly cannot be edited');
  check(!flat(state.root).some(x=>x.tagName==='IMG'||x.tagName==='SCRIPT'),'metadata does not create executable DOM');
  check(flat(state.root).some(x=>x.attrs.role==='status'&&x.attrs['aria-live']==='polite'),'live status');
  for(const row of state.controls) {
    check(flat(state.root).some(x=>x.tagName==='LABEL'&&x.attrs.for===row.control.attrs.id),'accessible label association');
    check(row.control.attrs['aria-describedby'].includes('-help'),'help association');
    check(row.control.attrs.autocomplete==='off','local input caching disabled');
  }
  await state.inputs.fire('submit');
  check(entered===0,'required failure cannot call provider');
  check(state.doc.focused===state.controls[0].control,'required field receives focus');
  check(state.controls[0].control.attrs['aria-invalid']==='true','invalid accessible state');
  state.controls[0].control.value='رحلة <script>alert(1)</script>';
  state.controls[1].control.value='12.75';
  state.controls[3].control.value='29';
  state.controls[4].control.value='[{"day":1}]';
  await state.inputs.fire('submit');
  check(entered===1,'single validation request');
  check(state.status.textContent.includes('لم تُحفظ'),'no-save outcome clear');
  check(flat(state.review).some(x=>x.tagName==='TABLE'),'review table');
  check(!flat(state.review).some(x=>x.tagName==='SCRIPT'),'review treats markup as text');
  check(ui.readControl({value:'123'},'number',{type:'integer'})===123,'integer conversion');
  assert.throws(()=>ui.readControl({value:'3.5'},'number',{type:'integer'}));checks++;
  assert.throws(()=>ui.readControl({value:'Infinity'},'number',{}));checks++;
  assert.throws(()=>ui.readControl({value:'{broken'},'typed-json',{}));checks++;
  check(ui.readControl({value:''},'text',{})===undefined,'blank optional omitted');
  check(ui.readControl({checked:false},'checkbox',{})===false,'false boolean retained');
  assert.throws(()=>fixture([{field_id:'__proto__',type:'string'}]));checks++;
  assert.throws(()=>fixture(Array.from({length:129},()=>({field_id:'x',type:'string'}))));checks++;
  const stale=fixture([{field_id:'x',type:'string'}],async()=>{throw new Error('SCHEMA_CHANGED');},'ltr');
  await stale.inputs.fire('submit');
  check(stale.status.textContent.includes('stale'),'stale form recovery message');
  check(stale.doc.focused===stale.errors,'failure summary focus');
  let release;let calls=0;
  const pending=fixture([{field_id:'x',type:'string'}],()=>{calls++;return new Promise(r=>{release=r;});});
  const first=pending.inputs.fire('submit'); await pending.inputs.fire('submit');
  check(calls===1,'double submit blocked');release({status:'VALIDATED'});await first;
  const code=fs.readFileSync(path.join(__dirname,'../assets/cso-form.js'),'utf8');
  check(!/innerHTML|eval\(|new Function|localStorage|sessionStorage/.test(code),'no execution or persistent value cache');
  const css=fs.readFileSync(path.join(__dirname,'../assets/cso-form.css'),'utf8');
  check(css.includes('@media(max-width:360px)'),'320px layout rule');
  global.location={href:'https://site.example/form',origin:'https://site.example'};
  let sent=0;
  global.fetch=async(url,options)=>{sent++;check(options.credentials==='same-origin','cookie request same-origin');check(options.redirect==='error','redirect rejection');check(options.cache==='no-store','request cache disabled');return {ok:true,text:async()=>'{"status":"VALIDATED"}'};};
  await ui.request({endpoint:'https://site.example/wp-json/mad4b/v1/conversational-site-operations',nonce:'nonce'},'typed_validate',{});
  await assert.rejects(ui.request({endpoint:'https://other.example/',nonce:'nonce'},'typed_validate',{}),/ORIGIN/);checks++;
  await assert.rejects(ui.request({endpoint:'http://site.example/',nonce:'nonce'},'typed_validate',{}),/ORIGIN/);checks++;
  check(sent===1,'foreign or HTTP endpoint receives no request');
  const dependency=fixture([{field_id:'enabled',type:'boolean'},{field_id:'details',type:'string',required:true,visible_when:{field:'enabled',operator:'equals',value:true}}],async(action,args)=>{check(!Object.hasOwn(args.values,'details'),'hidden-field value omitted');return {status:'VALIDATED'};});
  dependency.controls[1].control.value='previous hidden value';
  check(dependency.controls[1].group.hidden===true,'dependency initially hidden');
  await dependency.inputs.fire('submit');
  dependency.controls[0].control.checked=true;await dependency.controls[0].control.fire('change');
  check(dependency.controls[1].group.hidden===false,'dependency reveals required field');
  check(ui.condition({all:[{field:'x',operator:'equals',value:1},{field:'y',operator:'in',value:['a']}]},{x:1,y:'a'}),'bounded declarative condition');
  check(!ui.condition({field:'x',operator:'eval',value:'evil()'},{x:1}),'untrusted expression rejected');
  assert.throws(()=>ui.constraints(-1,{minimum:0}));checks++;
  assert.throws(()=>ui.constraints('سفر',{maxLength:2}));checks++;
  assert.throws(()=>ui.constraints([1,2],{maxItems:1}));checks++;
  ui.constraints('رحلة',{maxLength:4});checks++;
  check(ui.fieldType({field_type:'relation',value_type:'integer'})==='number','native relation IDs preserve integer typing');
  check(ui.fieldType({field_type:'relationship',value_type:'object'})==='typed-json','object relationship remains typed JSON');
  const choices=fixture([{field_id:'city',type:'enum',options:[{value:'city-cairo',label:'القاهرة'}]}]);
  const choice=choices.controls[0].control;
  choice.value='city-cairo';check(choice.value==='','unknown raw DOM option clears the selection');
  ui.applySuggestion(choice,'select',{},'city-cairo');check(ui.readControl(choice,'select',{})==='city-cairo','string enum suggestion uses exact JSON option');
  assert.throws(()=>ui.applySuggestion(choice,'select',{},'foreign'),/OPTION/);checks++;
  const relation={value:''};ui.applySuggestion(relation,'typed-json',{type:'object'},{id:7});check(ui.readControl(relation,'typed-json',{}).id===7,'object relationship suggestion retains object type');
  const multi={value:'[]'};ui.applySuggestion(multi,'typed-json',{type:'array',maxItems:2},7);ui.applySuggestion(multi,'typed-json',{type:'array',maxItems:2},7);check(multi.value==='[7]','array suggestions are typed and deduplicated');
  const suggested=fixture([{field_id:'city',type:'enum',options:[{value:'city-cairo',label:'القاهرة'}],autocomplete:true}],async()=>({items:[{value:'city-cairo',label:'القاهرة'}]}));
  const suggestButton=flat(suggested.controls[0].group).find(n=>n.tagName==='BUTTON');await suggestButton.fire('click');
  const suggestList=flat(suggested.controls[0].group).filter(n=>n.tagName==='SELECT')[1];suggestList.value=JSON.stringify('city-cairo');await suggestList.fire('change');
  check(ui.readControl(suggested.controls[0].control,'select',{})==='city-cairo','native DOM select seam preserves autocomplete typing');
  await suggestButton.fire('click');check(flat(suggested.controls[0].group).filter(n=>n.tagName==='SELECT').length===2,'repeated suggestions replace prior list');
  const native=fixture([{key:'query',type:'string',control:'select',enum:['القاهرة','الأقصر'],min_length:1,max_length:7,minimum:null,maximum:null,required:true}]);
  check(native.controls[0].name==='query'&&native.controls[0].kind==='select','real read-foundation key and enum contract renders');
  check(native.controls[0].control.attrs.minlength==='1'&&native.controls[0].control.attrs.maxlength==='7','snake_case native constraints project to controls');
  check(!Object.hasOwn(native.controls[0].control.attrs,'min')&&!Object.hasOwn(native.controls[0].control.attrs,'max'),'null numeric bounds do not become zero bounds');
  ui.constraints(12,{minimum:null,maximum:null});checks++;
  let boundedCalls=0;
  const nativeText=fixture([{key:'query',type:'string',min_length:2,max_length:4}],async()=>{boundedCalls++;return {status:'VALIDATED'};});
  nativeText.controls[0].control.value='a';await nativeText.inputs.fire('submit');
  check(boundedCalls===0,'native minimum length blocks invalid provider request');
  nativeText.controls[0].control.value='long-value';await nativeText.inputs.fire('submit');
  check(boundedCalls===0,'native maximum length blocks invalid provider request');
  nativeText.controls[0].control.value='رحلة';await nativeText.inputs.fire('submit');
  check(boundedCalls===1,'native Unicode length matches codepoint constraint');
  console.log('CSO FORM DOM SIMULATION: '+checks+' PASS; browser/host acceptance NOT_RUN');
})().catch(e=>{console.error(e);process.exitCode=1;});
