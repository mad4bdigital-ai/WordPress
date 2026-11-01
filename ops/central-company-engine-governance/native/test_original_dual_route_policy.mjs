import test from 'node:test';
import assert from 'node:assert/strict';
import {routeDual} from './original_dual_route_policy.mjs';
const policy={contract:'mad4b.central-dual-execution-policy.v1'};
const request={
 lane:'vps_ssh',kind:'general_vps_administration',source:'authenticated_operator',
 principal_id:'7',authorization_ref:'original-shell-grant',environment:'staging',
 correlation_id:'staging-probe-01',intent:'diagnostic',host_id:'hosting-2',
 target_scope:'wordpress',command_summary:'approved fixed read-only diagnostic',
 command_sha256:'a'.repeat(64),transport_session_id:'original-session',
 site_uuid:'staging-site',origin:'https://staging.allroyalegypt.com'};
const context={host_id:'hosting-2',ssh_authenticated:true,
 ssh_host_fingerprint_verified:true,security_denial:false,
 grant:{principal_id:'7',authorization_ref:'original-shell-grant',
 environment:'staging',host_id:'hosting-2',allow_general_shell:true,
 revoked:false,allow_mutations:false,site_uuid:'staging-site',
 origin:'https://staging.allroyalegypt.com'}};
test('original general shell is retained as independent staging route',()=>{
 const out=routeDual(policy,request,context);
 assert.equal(out.status,'AUTHORIZED_FOR_DISPATCH');
 assert.equal(out.lane,'vps_ssh');
 assert.equal(out.execution_performed,false);
});
test('semantic executor operation cannot elevate to general shell lane',()=>{
 const out=routeDual(policy,{...request,kind:'registered_semantic_action'},context);
 assert.equal(out.status,'DENIED');
 assert.equal(out.reason,'EXECUTOR_JOB_CANNOT_ELEVATE_TO_SSH');
});
test('shell route cannot operate after explicit security denial',()=>{
 const out=routeDual(policy,request,{...context,security_denial:true});
 assert.equal(out.status,'DENIED');
 assert.equal(out.reason,'SECURITY_OR_PERMISSION_DENIAL');
});
test('shell route cannot use wrong host pin',()=>{
 const out=routeDual(policy,request,{...context,ssh_host_fingerprint_verified:false});
 assert.equal(out.status,'DENIED');
});
test('shell requires its own original general-shell grant',()=>{
 const out=routeDual(policy,request,{...context,
   grant:{...context.grant,allow_general_shell:false}});
 assert.equal(out.reason,'SSH_GENERAL_SHELL_NOT_AUTHORIZED');
});
test('shell cannot execute unaudited mutations without rollback plan',()=>{
 const out=routeDual(policy,{...request,intent:'repair'},{
   ...context,grant:{...context.grant,allow_mutations:true},
   audit_journal_ready:false,rollback_planned:false});
 assert.equal(out.reason,'SSH_MUTATION_PRECONDITIONS_MISSING');
});
test('production mutation is not authorized by staging grant',()=>{
 const out=routeDual(policy,{...request,environment:'production',intent:'deployment'},context);
 assert.equal(out.status,'DENIED');
});
