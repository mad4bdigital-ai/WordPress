import test from 'node:test';
import assert from 'node:assert/strict';
import {routeDual} from '../executor/central_dual_route_policy.mjs';

const policy={contract:'mad4b.central-dual-execution-policy.v1'};
const hash='a'.repeat(64);
const uuid='123e4567-e89b-42d3-a456-426614174000';
const origin='https://tours.dev.mad4b.com';
const request={
  kind:'general_vps_administration',lane:'vps_ssh',source:'authenticated_operator',
  principal_id:'central-admin',authorization_ref:'scoped-grant-1',
  environment:'staging',correlation_id:'job-001',intent:'upgrade',
  host_id:'mad4b-vps',target_scope:'wordpress',site_uuid:uuid,origin,
  command_summary:'Verify and deploy one approved staging plugin release',
  command_sha256:hash,transport_session_id:'session-1'
};
const context={
  security_denial:false,permission_denial:false,mcp_online:false,
  ssh_authenticated:true,ssh_host_fingerprint_verified:true,
  host_id:'mad4b-vps',audit_journal_ready:true,rollback_planned:true,
  grant:{principal_id:'central-admin',authorization_ref:'scoped-grant-1',
    environment:'staging',allow_mutations:true,allow_general_shell:true,allow_production_mutations:false,
    host_id:'mad4b-vps',site_uuid:uuid,origin}
};
const get=(r=request,c=context)=>routeDual(policy,r,c);

test('general authenticated SSH works when MCP is unavailable',()=>{
  const d=get();
  assert.equal(d.status,'AUTHORIZED_FOR_DISPATCH');
  assert.equal(d.lane,'vps_ssh');
  assert.equal(d.mcp_health_not_required,true);
  assert.equal(d.execution_performed,false);
});
test('SSH is not restricted to the Executor semantic action catalog',()=>{
  const d=get({...request,command_summary:'Read arbitrary permitted VPS diagnostic'});
  assert.equal(d.status,'AUTHORIZED_FOR_DISPATCH');
});
test('an enrolled standalone SSH session cannot be forged from untrusted work',()=>{
  for(const source of ['untrusted_queue_payload','web_content','provider_response']){
    assert.equal(get({...request,source}).status,'DENIED');
  }
});
test('an explicit safety or authorization denial cannot be bypassed with SSH',()=>{
  assert.equal(get(request,{...context,security_denial:true}).status,'DENIED');
  assert.equal(get(request,{...context,permission_denial:true}).status,'DENIED');
});
test('general SSH requires its own host identity, authenticated principal and grant',()=>{
  assert.equal(get(request,{...context,ssh_authenticated:false}).status,'DENIED');
  assert.equal(get(request,{...context,ssh_host_fingerprint_verified:false}).status,'DENIED');
  assert.equal(get({...request,host_id:'another-vps'}).status,'DENIED');
  assert.equal(get(request,{...context,grant:{...context.grant,revoked:true}}).status,'DENIED');
});
test('SSH mutation requires authority, journal readiness and rollback',()=>{
  assert.equal(get(request,{...context,grant:{...context.grant,allow_mutations:false}}).status,'DENIED');
  assert.equal(get(request,{...context,audit_journal_ready:false}).status,'DENIED');
  assert.equal(get(request,{...context,rollback_planned:false}).status,'DENIED');
});
test('read-only general SSH requires no mutation grant',()=>{
  const c={...context,grant:{...context.grant,allow_mutations:false}};
  assert.equal(get({...request,intent:'read'},c).status,'AUTHORIZED_FOR_DISPATCH');
});
test('production mutation remains a separate explicit authority',()=>{
  const r={...request,environment:'production'};
  assert.equal(get(r,{...context,grant:{...context.grant,environment:'production'}}).status,'DENIED');
  const authorized={...context,grant:{...context.grant,environment:'production',allow_production_mutations:true}};
  assert.equal(get(r,authorized).status,'AUTHORIZED_FOR_DISPATCH');
});
test('unknown host and unbound WordPress site are rejected',()=>{
  assert.equal(get({...request,site_uuid:'00000000-0000-4000-8000-000000000000'}).status,'DENIED');
  assert.equal(get({...request,host_id:'different'}).status,'DENIED');
});
test('Executor jobs cannot be rerouted as generic SSH',()=>{
  assert.equal(get({...request,kind:'registered_semantic_action'}).status,'DENIED');
});
test('semantic Executor requires registered handler, exact stage and no raw command',()=>{
  const r={kind:'registered_semantic_action',lane:'registered_executor',source:'authenticated_operator',
    principal_id:'central-admin',authorization_ref:'scoped-grant-1',
    environment:'staging',correlation_id:'job-2',intent:'repair',
    action:'company.mu-providers.converge',site_uuid:uuid,origin,plan_sha256:hash};
  const c={...context,executor_registered:true,exact_stage_ticket_verified:true,executor_id:'company-stage-executor'};
  assert.equal(get(r,c).status,'AUTHORIZED_FOR_DISPATCH');
  assert.equal(get({...r,command:'raw shell from queue'},c).status,'DENIED');
  assert.equal(get(r,{...c,exact_stage_ticket_verified:false}).status,'DENIED');
});
test('unsupported lane is rejected',()=>{
  assert.equal(get({...request,lane:'mystery'}).status,'DENIED');
});
