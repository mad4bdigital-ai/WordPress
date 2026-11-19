'use strict';
const fs=require('fs'),vm=require('vm'),assert=require('assert'),path=require('path');
const script=fs.readFileSync(path.resolve(__dirname,'../../wp-content/mu-plugins/mad4b-host-authority-owner-ui/host-authority.js'),'utf8');
const GRANT='b'.repeat(32),REQUEST='c'.repeat(32),DIGEST='a'.repeat(64);
const ids='mad4b-ha-dispatch mad4b-host-authority-app mad4b-ha-grant mad4b-ha-command mad4b-ha-refresh mad4b-ha-copy mad4b-ha-plan mad4b-ha-owner-confirm mad4b-ha-command-confirm mad4b-ha-result mad4b-ha-grant-panel mad4b-ha-command-panel mad4b-ha-tracker mad4b-ha-request-id mad4b-ha-tracker-status mad4b-ha-tracker-title mad4b-ha-checked-at mad4b-ha-overall-state mad4b-ha-step-grant mad4b-ha-step-request mad4b-ha-step-verify'.split(' ');
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
const dispatched={ok:true,status:202,async json(){return {
 job_id:REQUEST,company:'all-royal-egypt',environment:'staging',
 state:'QUEUED_FOR_VPS_BROKER_CLAIM',host_v2_bootstrap_only:true,
 executed:false,plan_sha256:DIGEST
}}};
(async()=>{
 const a=setup({},async(url)=>url.endsWith('/commands')?accepted:(url.endsWith('/broker-dispatch')?dispatched:latest));
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
 assert.equal(a.calls.length,2);
 assert.equal(a.calls[0].options.method,'POST');
 assert.equal(a.calls[1].options.method,'POST');
 assert(a.calls[1].url.endsWith('/broker-dispatch'));
 assert.equal(JSON.parse(a.calls[1].options.body).request_id,REQUEST);
 assert(a.elements['mad4b-ha-command-panel'].hidden);
 assert(!a.elements['mad4b-ha-tracker'].hidden);
 assert.equal(a.elements['mad4b-ha-request-id'].textContent,REQUEST);
 assert(a.elements['mad4b-ha-tracker-title'].focused);
 await a.event('mad4b-ha-refresh');
 assert.equal(a.calls[2].options.method,'GET');
 await a.event('mad4b-ha-copy');
 assert.equal(a.copied[0],REQUEST);
 console.log('PASS separate consent, one owner POST, tracker, GET-only refresh and copy');
 const b=setup({requestId:REQUEST,phase:'ORIGINAL305_REQUEST_STAGED',
  requestStatus:'ADMITTED_PENDING_INDEPENDENT_HOST_AUTHORITY'},async()=>latest);
 assert(b.elements['mad4b-ha-command-panel'].hidden);
 assert(!b.elements['mad4b-ha-tracker'].hidden);
 await b.event('mad4b-ha-refresh');
 assert(b.calls.length>=1);
 assert.equal(b.calls[0].options.method,'GET');
 console.log('PASS persisted request reload cannot repeat owner POST');
 const c=setup({requestId:REQUEST,phase:'ORIGINAL305_REQUEST_STAGED'},
  async()=>({ok:false,status:503,async json(){return {code:'temporary_readback_unavailable'};}}));
 await c.event('mad4b-ha-refresh');
 assert(!c.elements['mad4b-ha-result'].hidden,'readback failures must remain visible');
 assert(c.elements['mad4b-ha-result'].textContent.includes('rejected'));
 console.log('PASS readback failure visible without execution');
 const expired=setup({
  requestId:REQUEST,
  requestExpired:true,
  requestStatus:'EXPIRED_OWNER_REQUEST_RENEWAL_REQUIRED',
  phase:'OWNER_REQUEST_EXPIRED'
 },async(url)=>url.endsWith('/broker-dispatch')?dispatched:accepted);
 assert(expired.elements['mad4b-ha-command-panel'].hidden===false,
   'expired request must allow a fresh owner action');
 assert(expired.elements['mad4b-ha-tracker'].hidden,
   'historical expired request must not be shown as currently queued');
 assert.equal(expired.calls.length,0,'GET page cannot silently renew');
 expired.elements['mad4b-ha-plan'].value=DIGEST;
 expired.elements['mad4b-ha-command-confirm'].checked=true;
 await expired.event('mad4b-ha-plan','input');
 await expired.event('mad4b-ha-command');
 assert.equal(expired.calls.length,2);
 assert(expired.calls[0].url.endsWith('/commands'));
 assert(expired.calls[1].url.endsWith('/broker-dispatch'));
 assert(!expired.elements['mad4b-ha-tracker'].hidden);
 console.log('PASS expired Owner Request requires fresh consent and dispatches once');
 const existingBroker=setup({requestId:REQUEST,requestExpired:true,
  brokerJobExists:true,brokerJobState:'BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY',
  phase:'ORIGINAL305_REQUEST_STAGED'},async()=>latest);
 assert(!existingBroker.elements['mad4b-ha-tracker'].hidden,
   'An existing Original306 Broker Job must not be treated as an expired unqueued request');
 assert(existingBroker.elements['mad4b-ha-command-panel'].hidden,
   'Never reopen owner submission when broker job already exists');
 assert.equal(existingBroker.calls.length,0);
 console.log('PASS expired Owner receipt with existing Broker Job cannot be renewed');
 let dispatchAttempts=0;
 const retry=setup({},async(url)=>{
   if(url.endsWith('/commands'))return accepted;
   if(url.endsWith('/broker-dispatch')) {
     dispatchAttempts++;
     return dispatchAttempts===1
       ?{ok:false,status:503,async json(){return {code:'transient_broker_busy'};}}
       :dispatched;
   }
   return latest;
 });
 retry.elements['mad4b-ha-plan'].value=DIGEST;
 retry.elements['mad4b-ha-command-confirm'].checked=true;
 await retry.event('mad4b-ha-plan','input');
 await retry.event('mad4b-ha-command');
 assert.equal(retry.calls.length,2);
 assert(!retry.elements['mad4b-ha-tracker'].hidden);
 assert(!retry.elements['mad4b-ha-result'].hidden);
 assert(!retry.elements['mad4b-ha-dispatch'].hidden);
 await retry.event('mad4b-ha-dispatch');
 assert.equal(retry.calls.length,3);
 assert(retry.calls[2].url.endsWith('/broker-dispatch'));
 assert(retry.calls.filter(x=>x.url.endsWith('/commands')).length===1,
   'Retry dispatch must NEVER repeat owner request');
 assert(retry.elements['mad4b-ha-dispatch'].hidden);
 console.log('PASS broker retry never reissues Original305 Owner Request');

})().catch(e=>{console.error(e);process.exitCode=1;});
