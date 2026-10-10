/* DOM contract simulation, not a browser or ChatGPT host acceptance receipt. */
'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const ui=require('../assets/cso-form.js');
let checks=0;
function check(value,label) { assert.ok(value,label); checks++; }
class Element {
  constructor(doc,tag) {this.ownerDocument=doc;this.tagName=tag.toUpperCase();this.children=[];this.attrs={};this.listeners={};this.textContent='';this.value='';this.checked=false;this.disabled=false;}
  setAttribute(k,v) {this.attrs[k]=v;if(k==='value') this.value=v;}
  removeAttribute(k) {delete this.attrs[k];}
  append(...nodes) {this.children.push(...nodes);}
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
  console.log('CSO FORM DOM SIMULATION: '+checks+' PASS; browser/host acceptance NOT_RUN');
})().catch(e=>{console.error(e);process.exitCode=1;});
