import test from 'node:test';
import assert from 'node:assert/strict';
import {createHash} from 'node:crypto';
import {dispatchGoverned} from '../executor/central_governed_dispatch.mjs';

const policy={contract:'mad4b.central-dual-execution-policy.v1'};
const uuid='123e4567-e89b-42d3-a456-426614174000';
const origin='https://tours.dev.mad4b.com';
const command='php --version';
const sha=createHash('sha256').update(command).digest('hex');
const req={kind:'general_vps_administration',lane:'vps_ssh',source:'authenticated_operator',
 principal_id:'admin',authorization_ref:'scope-1',environment:'staging',
 correlation_id:'run-123',intent:'repair',host_id:'vps-1',target_scope:'wordpress',
 site_uuid:uuid,origin,command_summary:'Inspect PHP toolchain',command_sha256:sha,
 transport_session_id:'ssh-connected',command};
const ctx={ssh_authenticated:true,ssh_host_fingerprint_verified:true,host_id:'vps-1',
 audit_journal_ready:true,rollback_planned:true,
 grant:{principal_id:'admin',authorization_ref:'scope-1',environment:'staging',
  host_id:'vps-1',site_uuid:uuid,origin,allow_mutations:true,allow_production_mutations:false}};
function mock({exit_code=0,verified=true,throws=false}={}) {
 const journal=[],executions=[];
 const adapters={
  journal:{async start(x){journal.push(x);return {claimed:true}},async finish(x){journal.push(x);return true}},
  transports:{vps_ssh:{async execute(x){executions.push(x);if(throws)throw Error('transport unavailable');return {exit_code}}}},
  async readback(){return {verified}};
 };
 return {journal,executions,adapters};
}

test('authenticated general VPS SSH executes without semantic command catalog',async()=>{
 const m=mock();
 const r=await dispatchGoverned(policy,req,ctx,m.adapters);
 assert.equal(r.status,'VERIFIED');
 assert.equal(r.lane,'vps_ssh');
 assert.equal(m.executions[0].command,command);
 assert.equal(m.journal[0].status,'CLAIMED');
 assert.equal(m.journal.at(-1).status,'VERIFIED');
 assert.equal(m.journal[0].command_sha256,sha);
 assert.equal('command' in m.journal[0],false);
});
test('MCP availability is not required for authorized SSH',async()=>{
 const m=mock();
 const r=await dispatchGoverned(policy,req,{...ctx,mcp_online:false},m.adapters);
 assert.equal(r.status,'VERIFIED');
});
test('a command with altered bytes is blocked before journal or transport',async()=>{
 const m=mock();
 const r=await dispatchGoverned(policy,{...req,command:'dangerously different command'},ctx,m.adapters);
 assert.equal(r.reason,'COMMAND_BYTES_NOT_BOUND');
 assert.equal(m.executions.length,0);
 assert.equal(m.journal.length,0);
});
test('untrusted queue and permission denial never dispatch to SSH',async()=>{
 for(const x of [{req:{...req,source:'untrusted_queue_payload'},ctx},
  {req,ctx:{...ctx,security_denial:true}}]){
  const m=mock();
  const r=await dispatchGoverned(policy,x.req,x.ctx,m.adapters);
  assert.equal(r.status,'DENIED');
  assert.equal(m.executions.length,0);
 }
});
test('cannot execute without journal, readback, or connected transport',async()=>{
 const m=mock();
 assert.equal((await dispatchGoverned(policy,req,ctx,{...m.adapters,journal:null})).status,'DENIED');
 assert.equal((await dispatchGoverned(policy,req,ctx,{...m.adapters,readback:null})).status,'DENIED');
 assert.equal((await dispatchGoverned(policy,req,ctx,{...m.adapters,transports:{}})).status,'DENIED');
 assert.equal(m.executions.length,0);
});
test('failed shell command is reported, not marked success',async()=>{
 const m=mock({exit_code:1});
 const r=await dispatchGoverned(policy,req,ctx,m.adapters);
 assert.equal(r.status,'FAILED_EXECUTION');
 assert.equal(m.journal.at(-1).status,'FAILED_EXECUTION');
});
test('readback failure remains unverified and signals rollback required',async()=>{
 const m=mock({verified:false});
 const r=await dispatchGoverned(policy,req,ctx,m.adapters);
 assert.equal(r.status,'FAILED_READBACK');
 assert.equal(r.rollback_required,true);
 assert.equal(m.journal.at(-1).status,'FAILED_READBACK');
});
test('transport exception is recorded as uncertain and never claims success',async()=>{
 const m=mock({throws:true});
 const r=await dispatchGoverned(policy,req,ctx,m.adapters);
 assert.equal(r.status,'ERROR_UNCERTAIN');
 assert.equal(r.verified,false);
 assert.equal(m.journal.at(-1).status,'ERROR_UNCERTAIN');
});
test('read-only VPS SSH does not require mutation grant/readback',async()=>{
 const m=mock();
 delete m.adapters.readback;
 const c={...ctx,grant:{...ctx.grant,allow_mutations:false}};
 const command='pwd';
 const r=await dispatchGoverned(policy,{...req,intent:'read',command,command_sha256:createHash('sha256').update(command).digest('hex')},c,m.adapters);
 assert.equal(r.status,'VERIFIED');
});
