'use strict';
const fs = require('fs');
const vm = require('vm');
const assert = require('assert');
const source = fs.readFileSync(
  require('path').resolve(__dirname, '../../wp-content/mu-plugins/mad4b-host-authority-owner-ui/host-authority.js'), 'utf8'
);

function create(override = {}, responder) {
  const ids = ['mad4b-host-authority-app', 'mad4b-ha-grant',
    'mad4b-ha-command', 'mad4b-ha-plan', 'mad4b-ha-owner-confirm',
    'mad4b-ha-refresh', 'mad4b-ha-result'];
  const controls = {};
  for (const id of ids) {
    controls[id] = {id, checked:false, disabled:false, style:{}, value:'',
      textContent:'', handlers:{}, addEventListener(type, fn) {
        this.handlers[type] = fn;
      }};
  }
  const config = Object.assign({
    owner:true, nonce:'synthetic-test-nonce',
    restBase:'/wp-json/mad4b/v1/central-execution-lanes/all-royal-egypt/',
    transportId:'a'.repeat(32), grantId:'', requestId:'',
    hostId:'hosting-2',hostPrincipal:'u948953904',
    siteUuid:'49c562d1-8f2f-456f-b454-26816c6ba4cb',
    operation:'company.host-authority.anchor.issue',
    phase:'AWAITING_OWNER_APPROVAL'
  },override);
  const calls = [];
  const fetch = async (url,options) => {
    calls.push({url,options});
    if (responder) return responder(url,options,calls.length);
    if (url.endsWith('/grants')) return {
      ok:true,status:201,async json() {return {
        grant_id:'b'.repeat(32),company:'all-royal-egypt',environment:'staging',
        operation:config.operation,host_v2_bootstrap_only:true,
        execution_authorized:false,host_v2_execution_authorized:false,
        state:'CENTRAL_OPERATION_SCOPE_GRANTED'
      };}};
    if (url.endsWith('/commands')) return {
      ok:true,status:202,async json() {return {
        request_id:'c'.repeat(32),company:'all-royal-egypt',
        environment:'staging',host_v2_bootstrap_only:true,
        execution_performed:false,
        status:'ADMITTED_PENDING_INDEPENDENT_HOST_AUTHORITY'
      };}};
    return {ok:true,status:200,async json(){return {
      request_id:'c'.repeat(32),
      status:'ADMITTED_PENDING_INDEPENDENT_HOST_AUTHORITY'};}};
  };
  const sandbox = {
    window:{MAD4BHostAuthorityV106:config,
      location:{origin:'https://tours.dev.mad4b.com'},
      crypto:{getRandomValues(a){a.fill(0x23);}}},
    document:{getElementById(id){return controls[id]||null;}},
    URL,Uint8Array,Error,Array,String,fetch
  };
  vm.runInNewContext(source,sandbox,{filename:'host-authority.js'});
  return {controls,config,calls,
    event:async (id,type='click') => {
      const fn = controls[id].handlers[type];
      assert(fn,'missing handler '+id+':'+type);
      return fn();
    }};
}
async function test() {
  {
    const x=create({owner:false});
    assert(!x.controls['mad4b-ha-grant'].handlers.click,
      'No browser action may be exposed to an unverified owner.');
    console.log('PASS anonymous owner denied');
  }
  {
    const x=create();
    const grant=x.controls['mad4b-ha-grant'];
    assert(grant.disabled,'grant must require explicit consent');
    x.controls['mad4b-ha-owner-confirm'].checked=true;
    await x.event('mad4b-ha-owner-confirm','change');
    assert(!grant.disabled);
    await x.event('mad4b-ha-grant');
    assert(grant.disabled,'grant is single owner action');
    assert.equal(x.calls.length,1);
    assert(x.calls[0].url.startsWith('https://tours.dev.mad4b.com/wp-json/'));
    assert(!x.calls[0].url.includes(':8080'));
    const r=JSON.parse(x.calls[0].options.body);
    assert.deepEqual(Object.keys(r),
      ['operation','host_id','host_principal','grant_mode','transport_id','reason']);
    assert.equal(r.grant_mode,'persistent');
    assert.equal(r.host_id,'hosting-2');
    assert.equal(x.calls[0].options.headers['X-WP-Nonce'],'synthetic-test-nonce');
    assert.equal(x.calls[0].options.credentials,'same-origin');
    assert(!('ttl_seconds' in r));
    assert(/No Anchor was issued/.test(x.controls['mad4b-ha-result'].textContent));
    console.log('PASS original305 owner grant exact non-executing request');
    x.controls['mad4b-ha-plan'].value='e'.repeat(64);
    await x.event('mad4b-ha-plan','input');
    assert(!x.controls['mad4b-ha-command'].disabled);
    await x.event('mad4b-ha-command');
    assert.equal(x.calls.length,2);
    const q=JSON.parse(x.calls[1].options.body);
    assert.deepEqual(Object.keys(q),
      ['grant_id','command','argv','verification','summary','correlation_id','target_scope']);
    assert.deepEqual(Object.keys(q.verification),
      ['authority_mode','operation_id','plan_sha256','site_uuid','target_host_id']);
    assert.equal(q.verification.authority_mode,'bootstrap');
    assert.equal(q.correlation_id,'23'.repeat(16));
    assert.equal(q.argv[3],'--plan_sha256='+'e'.repeat(64));
    assert.equal(q.command,null);
    assert.equal(q.target_scope,'host');
    assert(/NOT an issued Host Anchor/.test(x.controls['mad4b-ha-result'].textContent));
    assert(x.controls['mad4b-ha-command'].disabled);
    await x.event('mad4b-ha-refresh');
    assert.equal(x.calls.length,3);
    assert.equal(x.calls[2].options.method,'GET');
    console.log('PASS exact signed plan reference and safe request readback');
  }
  {
    const x=create({restBase:'/wp-json/mad4b/v1/central-execution-lanes/all-royal-egypt:8080/'});
    x.controls['mad4b-ha-owner-confirm'].checked=true;
    await x.event('mad4b-ha-owner-confirm','change');
    assert(x.controls['mad4b-ha-grant'].disabled,'internal port must disable action');
    console.log('PASS internal 8080 endpoint disabled');
  }
  {
    const x=create({},async()=>({ok:false,status:403,async json(){return {
      code:'mad4b_ssh_intake_owner_step_up_required'
    };}}));
    x.controls['mad4b-ha-owner-confirm'].checked=true;
    await x.event('mad4b-ha-owner-confirm','change');
    await x.event('mad4b-ha-grant');
    assert(!x.controls['mad4b-ha-result'].textContent.includes('staged'));
    assert(x.controls['mad4b-ha-result'].textContent.includes('rejected'));
    assert(!x.controls['mad4b-ha-grant'].disabled);
    console.log('PASS denied original owner session cannot claim success');
  }
}
test().catch((e)=>{console.error(e);process.exitCode=1;});
