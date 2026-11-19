'use strict';
const fs=require('fs'),vm=require('vm'),assert=require('assert'),path=require('path');
const script=fs.readFileSync(path.resolve(__dirname,'../../wp-content/mu-plugins/mad4b-host-authority-owner-ui/host-authority.js'),'utf8');
const GRANT='b'.repeat(32),REQUEST='c'.repeat(32),DIGEST='a'.repeat(64);
const ids='mad4b-host-authority-app mad4b-ha-grant mad4b-ha-command mad4b-ha-refresh mad4b-ha-copy mad4b-ha-plan mad4b-ha-owner-confirm mad4b-ha-command-confirm mad4b-ha-result mad4b-ha-grant-panel mad4b-ha-command-panel mad4b-ha-tracker mad4b-ha-request-id mad4b-ha-tracker-status mad4b-ha-tracker-title mad4b-ha-checked-at mad4b-ha-overall-state mad4b-ha-step-grant mad4b-ha-step-request mad4b-ha-step-verify'.split(' ');
function setup(override={},responder) {
 const elements={}; for(const id of ids)elements[id]={
  id,value:'',checked:false,disabled:false,hidden:false,textContent:'',style:{},
  classList:{toggle(){}},handlers:{},focus(){this.focused=true;},
  addEventListener(kind,fn){this.handlers[kind]=fn;}
 };
 const config=Object.assign({
  owner:true,nonce:'signed-test-nonce',
  restBase:'/wp-json/mad4b/v1/central-execution-lanes/all-royal-egypt/',
  transportId:'a'.repeat(32),grantId:GRANT,requestId:'',
  siteUuid:'49c562d1-8f2f-456f-b454-26816c6ba4cb',
  hostId:'hosting-2',hostPrincipal:'u948953904',
  operation:'company.host-authority.anchor.issue',phase:'BOOTSTRAP_GRANT_STAGED'
 },override);
 const calls=[],copied=[];
 const sandbox={window:{MAD4BHostAuthorityV106:config,
    location:{origin:'https://tours.dev.mad4b.com'},
    crypto:{getRandomValues(v){v.fill(42);}}},
  document:{getElementById(id){return elements[id]||null;}},
  navigator:{clipboard:{async writeText(t){copied.push(t);}}},
  fetch:async(url,options)=>{calls.push({url,options});return responder(url,options);},
  URL,Uint8Array,Date,Array,String,Error
 };
 vm.runInNewContext(script,sandbox);
 return {elements,calls,copied,
  event:async(id,type='click')=>{assert(elements[id].handlers[type],id+':'+type);await elements[id].handlers[type]();}};
}
const accepted={ok:true,status:202,async json(){return {
 request_id:REQUEST,company:'all-royal-egypt',environment:'staging',
 host_v2_bootstrap_only:true,execution_performed:false,
 status:'ADMITTED_PENDING_INDEPENDENT_HOST_AUTHORITY'
}}};
const latest={ok:true,status:200,async json(){return {
 request_id:REQUEST,status:'ADMITTED_PENDING_INDEPENDENT_HOST_AUTHORITY'
}}};
(async()=>{
 const a=setup({},async(url)=>url.endsWith('/commands')?accepted:latest);
 assert(a.elements['mad4b-ha-grant-panel'].hidden);
 assert(!a.elements['mad4b-ha-command-panel'].hidden);
 a.elements['mad4b-ha-owner-confirm'].checked=true;
 a.elements['mad4b-ha-plan'].value=DIGEST;
 await a.event('mad4b-ha-plan','input');
 assert(a.elements['mad4b-ha-command'].disabled,'grant consent must not approve owner submission');
 a.elements['mad4b-ha-command-confirm'].checked=true;
 await a.event('mad4b-ha-command-confirm','change');
 assert(!a.elements['mad4b-ha-command'].disabled);
 await a.event('mad4b-ha-command');
 assert.equal(a.calls.length,1);
 assert.equal(a.calls[0].options.method,'POST');
 assert(a.elements['mad4b-ha-command-panel'].hidden);
 assert(!a.elements['mad4b-ha-tracker'].hidden);
 assert.equal(a.elements['mad4b-ha-request-id'].textContent,REQUEST);
 assert(a.elements['mad4b-ha-tracker-title'].focused);
 await a.event('mad4b-ha-refresh');
 assert.equal(a.calls[1].options.method,'GET');
 await a.event('mad4b-ha-copy');
 assert.equal(a.copied[0],REQUEST);
 console.log('PASS separate consent, one owner POST, tracker, GET-only refresh and copy');
 const b=setup({requestId:REQUEST,phase:'ORIGINAL305_REQUEST_STAGED',
  requestStatus:'ADMITTED_PENDING_INDEPENDENT_HOST_AUTHORITY'},async()=>latest);
 assert(b.elements['mad4b-ha-command-panel'].hidden);
 assert(!b.elements['mad4b-ha-tracker'].hidden);
 await b.event('mad4b-ha-refresh');
 assert.equal(b.calls.length,1);
 assert.equal(b.calls[0].options.method,'GET');
 console.log('PASS persisted request reload cannot repeat owner POST');
 const c=setup({requestId:REQUEST,phase:'ORIGINAL305_REQUEST_STAGED'},
  async()=>({ok:false,status:503,async json(){return {code:'temporary_readback_unavailable'};}}));
 await c.event('mad4b-ha-refresh');
 assert(!c.elements['mad4b-ha-result'].hidden,'readback failures must remain visible');
 assert(c.elements['mad4b-ha-result'].textContent.includes('rejected'));
 console.log('PASS readback failure visible without execution');
})().catch(e=>{console.error(e);process.exitCode=1;});
