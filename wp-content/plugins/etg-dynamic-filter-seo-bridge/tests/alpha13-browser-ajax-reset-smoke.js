'use strict';
const assert=require('assert'),fs=require('fs'),vm=require('vm');
const path=require('path');
const source=fs.readFileSync(path.join(__dirname,'..','assets','js','ajax-filter-state.js'),'utf8');
function sleep(ms){return new Promise(r=>setTimeout(r,ms));}
function response(body,status=200){return {ok:status>=200&&status<300,status,text:()=>Promise.resolve(JSON.stringify(body))};}
function ready(title, extra={}){return Object.assign({contract:'etg.dfsb.ajax-presentation.v1',status:'ready',authorizing:false,url_authority:false,seo_mutation:false,provider:'jet-engine',query_id:'tours_query_archive',presentation_state_complete:true,filtered_query_complete:false,values:{tokens:{title:{value:title,type:'text'}},slots:{}},blocking_reasons:[]},extra);}
function makeElement(initial){const attrs={'data-etg-dfsb-token':'title','data-etg-dfsb-group':'auto'};let content=initial;return{style:{backgroundImage:''},tagName:'DIV',get innerHTML(){return content},set innerHTML(v){content=String(v)},get textContent(){return content},set textContent(v){content=String(v)},getAttribute(n){return Object.prototype.hasOwnProperty.call(attrs,n)?attrs[n]:null},setAttribute(n,v){attrs[n]=String(v)},removeAttribute(n){delete attrs[n]},hasAttribute(n){return Object.prototype.hasOwnProperty.call(attrs,n)},closest(){return null}}}
async function boot(initialFiltered){const subs={},events=[],el=makeElement(initialFiltered?'Initial Cairo':'Archive default');const group={'currentQuery':initialFiltered?{_tax_query_location_jet:'cairo'}:{}};const window={location:{pathname:initialFiltered?'/tours-and-activities/jsf/jet-engine:tours_query_archive/tax/location_jet:cairo/':'/tours-and-activities/'},ETGDFSB_AJAX:{endpoint:'/rest',contract:'etg.dfsb.ajax-presentation.v1',maxTokens:100,maxSlots:50,timeoutMs:8000,groupRetryAttempts:4,groupRetryDelayMs:10,jsfVersion:'3.8.3.1',supportedJsfVersions:['3.8.3.1']},JetSmartFilters:{filterGroups:{'jet-engine/tours_query_archive':group},events:{subscribe(n,fn){subs[n]=fn}}},setTimeout,clearTimeout};
let next=ready(initialFiltered?'Initial AJAX Cairo':'Unused');
const document={querySelectorAll(sel){if(sel.includes('data-etg-dfsb-token'))return[el];return[]},dispatchEvent(e){events.push(e);return true},addEventListener(){}};
const ctx={window,document,CustomEvent:class{constructor(type,init){this.type=type;this.detail=(init&&init.detail)||{}}},WeakMap,Promise,JSON,Object,Array,String,Number,Math,Error,isFinite,AbortController:global.AbortController,fetch:()=>Promise.resolve(response(next)),console,setTimeout,clearTimeout};
vm.runInNewContext(source,ctx,{filename:'ajax-filter-state.js'});await sleep(60);
return {window,group,subs,el,events,setNext:v=>{next=v}};}
(async()=>{
  const a=await boot(true);assert.strictEqual(a.el.textContent,'Initial AJAX Cairo');
  a.window.location.pathname='/tours-and-activities/';a.group.currentQuery={};a.subs['ajaxFilters/updated']('jet-engine','tours_query_archive');await sleep(60);
  assert.strictEqual(a.el.textContent,'','clearing a page that initially loaded filtered must clear stale term content');
  assert(a.events.some(e=>e.type==='etg-dfsb/ajax-presentation-reset'&&e.detail.restored_initial===false),'filtered-origin clear must declare non-initial reset');

  const b=await boot(false);b.setNext(ready('Giza'));b.window.location.pathname='/tours-and-activities/jsf/jet-engine:tours_query_archive/tax/location_jet:giza/';b.group.currentQuery={_tax_query_location_jet:'giza'};b.subs['ajaxFilters/updated']('jet-engine','tours_query_archive');await sleep(60);assert.strictEqual(b.el.textContent,'Giza');
  b.window.location.pathname='/tours-and-activities/';b.group.currentQuery={};b.subs['ajaxFilters/updated']('jet-engine','tours_query_archive');await sleep(60);assert.strictEqual(b.el.textContent,'Archive default','unfiltered-origin clear restores original archive content');

  const c=await boot(false);c.setNext(ready('Partial Presentation',{presentation_state_complete:true,filtered_query_complete:false,result_query_complete:false}));c.window.location.pathname='/tours-and-activities/jsf/jet-engine:tours_query_archive/tax/location_jet:cairo/';c.group.currentQuery={_tax_query_location_jet:'cairo',_meta_query_price:'100'};c.subs['ajaxFilters/updated']('jet-engine','tours_query_archive');await sleep(60);assert.strictEqual(c.el.textContent,'Partial Presentation','taxonomy presentation must update even when result query is incomplete');

  c.setNext(ready('',{status:'blocked',presentation_state_complete:false,values:{tokens:{},slots:{}},blocking_reasons:['malformed_filter']}));c.group.currentQuery={_tax_query_location_jet:'broken'};c.subs['ajaxFilters/updated']('jet-engine','tours_query_archive');await sleep(60);assert.strictEqual(c.el.textContent,'','blocked live state must fail closed instead of retaining stale term content');
  console.log('AJAX clear/stale/partial-presentation behavior PASS');
})().catch(e=>{console.error(e.stack||e);process.exit(1)});
