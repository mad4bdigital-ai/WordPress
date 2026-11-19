'use strict';
const fs=require('fs'),path=require('path'),vm=require('vm'),assert=require('assert');
const src=fs.readFileSync(path.resolve(__dirname,'../../wp-content/mu-plugins/mad4b-host-authority-owner-ui/host-authority.js'),'utf8');
const Q='d'.repeat(32),G='c'.repeat(32),PLAN='e'.repeat(64);
const ids=('mad4b-host-authority-app mad4b-ha-grant mad4b-ha-command mad4b-ha-refresh mad4b-ha-copy mad4b-ha-dispatch mad4b-ha-plan mad4b-ha-plan-summary mad4b-ha-plan-editor mad4b-ha-edit-plan mad4b-ha-owner-confirm mad4b-ha-command-confirm mad4b-ha-result mad4b-ha-grant-panel mad4b-ha-command-panel mad4b-ha-tracker mad4b-ha-request-id mad4b-ha-tracker-status mad4b-ha-tracker-title mad4b-ha-checked-at mad4b-ha-overall-state mad4b-ha-step-grant mad4b-ha-step-request mad4b-ha-step-verify').split(' ');
function harness(overrides,responseFn) {
 const els={},calls=[],timers=[];
 for(const id of ids)els[id]={id,hidden:false,disabled:false,readOnly:false,checked:false,textContent:'',value:'',style:{},handlers:{},classList:{toggle(){}},addEventListener(kind,fn){this.handlers[kind]=fn;},focus(){this.focused=true;}};
 const cfg=Object.assign({
  owner:true,nonce:'test-only-authenticated-owner-nonce',
  restBase:'/wp-json/mad4b/v1/central-execution-lanes/all-royal-egypt/',
  operation:'company.host-authority.anchor.issue',
  transportId:'a'.repeat(32),siteUuid:'49c562d1-8f2f-456f-b454-26816c6ba4cb',
  hostId:'hosting-2',hostPrincipal:'u948953904',grantId:G,requestId:Q,
  requestStatus:'ADMITTED_PENDING_INDEPENDENT_HOST_AUTHORITY',
  requestExpiresEpoch:2000000000,requestExpired:false,brokerJobExists:true,
  brokerJobState:'QUEUED_FOR_VPS_BROKER_CLAIM',phase:'ORIGINAL305_REQUEST_STAGED',
  anchorState:'MISSING_OR_INVALID',anchorRevision:0,
  planSha256:PLAN,planSource:'previous_owner_request_reference'
 },overrides);
 const sandbox={
  document:{visibilityState:'visible',getElementById(id){return els[id]||null;}},
  window:{MAD4BHostAuthorityV106:cfg,location:{origin:'https://tours.dev.mad4b.com'},
   crypto:{getRandomValues(v){v.fill(3);}},
   setTimeout(f,delay){timers.push({f,delay});return timers.length;}},
  navigator:{clipboard:{async writeText(){}}},
  fetch:async(url,opts)=>{calls.push({url,opts});return responseFn(url,opts);},
  URL,Uint8Array,Date,String,Error,Array
 };
 vm.runInNewContext(src,sandbox,{filename:'host-authority.js'});
 return {els,calls,timers,cfg,
  async tick(){const t=timers.shift();assert(t,'timer missing');await t.f();},
  async event(id,kind='click'){const f=els[id].handlers[kind];assert(f,id+':'+kind+' missing');await f();}
 };
}
function state(overrides={}) {
 return Object.assign({
  company:'all-royal-egypt',environment:'staging',owner_verified:true,
  original328_active:true,grant_id:G,request_id:Q,
  request_status:'ADMITTED_PENDING_INDEPENDENT_HOST_AUTHORITY',
  request_expires_epoch:2000000000,request_expired:false,broker_job_exists:true,
  broker_job_state:'BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY',
  plan_sha256:PLAN,plan_source:'previous_owner_request_reference',
  phase:'ORIGINAL305_REQUEST_STAGED',anchor_state:'MISSING_OR_INVALID',
  anchor_revision:0,server_epoch:1795111200
 },overrides);
}
const ok = body => ({ok:true,status:200,async json(){return body;}});
(async()=>{
 let response=state();
 const h=harness({},async()=>ok(response));
 assert.equal(h.els['mad4b-ha-plan'].value,PLAN);
 assert(h.els['mad4b-ha-plan-editor'].hidden);
 assert(!h.els['mad4b-ha-edit-plan'].hidden);
 assert(!h.els['mad4b-ha-tracker'].hidden);
 assert.equal(h.timers.length,1);
 assert.equal(h.timers[0].delay,650);
 await h.tick();
 assert.equal(h.calls.length,1);
 assert(h.calls[0].url.endsWith('/host-authority-owner-state'));
 assert.equal(h.calls[0].opts.method,'GET');
 assert(h.els['mad4b-ha-tracker-status'].textContent.includes('Claim accepted'));
 assert(h.els['mad4b-ha-overall-state'].textContent.includes('Claim accepted'));
 assert(h.els['mad4b-ha-checked-at'].textContent.includes('AJAX'));
 assert.equal(h.calls.filter(c=>c.opts.method==='POST').length,0);
 assert.equal(h.timers.length,1);
 assert.equal(h.timers[0].delay,30000);
 console.log('PASS private AJAX, automatic Root Plan and live Original306 claim');

 response=state({request_expired:true,broker_job_exists:false,
  broker_job_state:'',phase:'OWNER_REQUEST_EXPIRED'});
 const old=h.els['mad4b-ha-command-confirm'];old.checked=true;
 await h.tick();
 assert(h.els['mad4b-ha-tracker'].hidden);
 assert(!h.els['mad4b-ha-command-panel'].hidden);
 assert.equal(old.checked,false,'must require fresh consent');
 assert(h.els['mad4b-ha-command'].disabled);
 assert.equal(h.els['mad4b-ha-plan'].value,PLAN);
 assert.equal(h.calls.filter(c=>c.opts.method==='POST').length,0);
 console.log('PASS expired readback retains plan but clears owner consent');

 await h.event('mad4b-ha-edit-plan');
 assert(!h.els['mad4b-ha-plan-editor'].hidden);
 h.els['mad4b-ha-plan'].value='f'.repeat(64);
 await h.event('mad4b-ha-plan','input');
 await h.tick();
 assert.equal(h.els['mad4b-ha-plan'].value,'f'.repeat(64));
 console.log('PASS explicit manual override protected from asynchronous update');

 const denied=harness({},async()=>({ok:false,status:403,
    async json(){return {code:'rest_forbidden'};}}));
 await denied.tick();
 assert.equal(denied.calls.length,1);
 assert(denied.els['mad4b-ha-checked-at'].textContent.includes('unavailable'));
 assert.equal(denied.calls.filter(c=>c.opts.method==='POST').length,0);
 console.log('PASS REST denial cannot trigger owner mutation');

 const changed=harness({},async()=>ok(state({
  anchor_state:'ACTIVE_INDEPENDENT_HOST_AUTHORITY',anchor_revision:1,phase:'ANCHOR_STATE_REVIEW'
 })));
 await changed.tick();
 assert(changed.els['mad4b-ha-command'].disabled);
 assert(changed.els['mad4b-ha-command-panel'].hidden);
 assert(changed.els['mad4b-ha-tracker-status'].textContent.includes('independent verification'));
 assert.equal(changed.timers.length,0);
 console.log('PASS Anchor revision change stops owner submission and automatic polling');
})().catch(e=>{console.error(e);process.exitCode=1;});
