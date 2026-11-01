import test from 'node:test';
import assert from 'node:assert/strict';
import {createOriginalCoreWpcliExecutor,payloadHash} from './full_wpcli_execute.mjs';
const ID='a'.repeat(32);
const scope={company_id:'c1',site_uuid:'site1',canonical_origin:'https://staging.example',
  environment:'staging',ssh_target_id:'hosting-3',ssh_account:'siteuser',
  ssh_host_key_fingerprint:'SHA256:pin',wordpress_root:'/srv/site',
  wordpress_principal:'siteuser',enrollment_revision:'7',actor_id:'a1'};
function fixture(overrides={}){
 const argv=['option','get','home'];
 const job={...scope,job_id:ID,channel_id:'conn1',grant_id:'grant1',
  command_sha256:payloadHash(argv),argv,target_scope:'wordpress',
  operation:'company.remote.wpcli.full',
  state:'BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY',...overrides.job};
 const channel={...scope,state:'ENROLLED',capabilities:['wpcli.full'],
  authorization_source:'original_core_registered_connection',full_wpcli_active:true,...overrides.channel};
 const admission={job_id:ID,operation:job.operation,eligible:true,verified:true,
  owner_enrolled_wpcli:true,command_sha256:job.command_sha256,
  site_uuid:job.site_uuid,company:job.company_id,target_id:job.ssh_target_id,
  origin:job.canonical_origin,environment:job.environment,
  host_principal:job.ssh_account,host_key_fingerprint:job.ssh_host_key_fingerprint,
  transport_id:job.channel_id,grant_id:job.grant_id,...overrides.admission};
 const receipt={contract:'mad4b.broker-remote-wpcli-receipt.v1',verified:true,
  independent_host_readback_verified:true,job_id:ID,
  command_sha256:job.command_sha256,site_uuid:job.site_uuid,
  company_id:job.company_id,exit_code:0,...overrides.receipt};
 let claimed=false,runs=0;
 const core={fetchBoundJob:async()=>job,fetchEnrolledChannel:async()=>channel,
  claimJobOnce:async()=>{if(claimed)return false;claimed=true;return true},
  audit:async()=>{}};
 const broker={verify312:async()=>admission,verify313:async()=>{
  if(overrides.readbackThrows)throw Error('transport failure');return receipt}};
 const transport={runBoundWpcli:async()=>{runs++;if(overrides.executionThrows)throw Error('timeout');
  return {exit_code:0}}};
 return {executor:createOriginalCoreWpcliExecutor({core,broker,transport}),runs:()=>runs};
}
test('enrolled exact-site channel runs once with no command approval',async()=>{
 const x=fixture();assert.equal((await x.executor.execute(ID)).state,'VERIFIED');
 assert.equal((await x.executor.execute(ID)).reason,'JOB_ALREADY_CLAIMED');
 assert.equal(x.runs(),1);
});
for(const [name,patch,reason] of [
 ['unregistered',{channel:{state:'UNENROLLED'}},'EXACT_CHANNEL_NOT_ENROLLED'],
 ['revoked',{channel:{revoked:true}},'EXACT_CHANNEL_NOT_ENROLLED'],
 ['other company',{channel:{company_id:'other'}},'EXACT_CHANNEL_NOT_ENROLLED'],
 ['wrong root',{channel:{wordpress_root:'/tmp'}},'EXACT_CHANNEL_NOT_ENROLLED'],
 ['wrong principal',{channel:{ssh_account:'root'}},'EXACT_CHANNEL_NOT_ENROLLED'],
 ['no approved channel',{channel:{authorization_source:'ssh_probe'}},'EXACT_CHANNEL_NOT_ENROLLED'],
 ['inactive channel',{channel:{full_wpcli_active:false}},'EXACT_CHANNEL_NOT_ENROLLED'],
 ['different origin',{channel:{canonical_origin:'https://elsewhere.test'}},'EXACT_CHANNEL_NOT_ENROLLED'],
 ['no payload',{job:{argv:null}},'WPCLI_PAYLOAD_NOT_AVAILABLE'],
 ['tampered payload',{job:{argv:['plugin','list']}},'COMMAND_DIGEST_MISMATCH'],
 ['wrong 312 company',{admission:{company:'other'}},'BROKER_312_AUTHORITY_DENIED'],
 ['wrong 312 transport',{admission:{transport_id:'other'}},'BROKER_312_AUTHORITY_DENIED'],
 ['wrong 312 fingerprint',{admission:{host_key_fingerprint:'CHANGED'}},'BROKER_312_AUTHORITY_DENIED'],
 ['denied 312',{admission:{eligible:false}},'BROKER_312_AUTHORITY_DENIED'],
 ['wrong 313',{receipt:{independent_host_readback_verified:false}},'READBACK_UNVERIFIED_NO_RETRY']
 ])test(name,async()=>{
 const x=fixture(patch);const result=await x.executor.execute(ID);
 assert.equal(result.reason??result.state,reason);
 if(reason!=='READBACK_UNVERIFIED_NO_RETRY')assert.equal(x.runs(),0);
});
test('uncertain SSH completion never blindly replays',async()=>{
 const x=fixture({executionThrows:true});
 assert.equal((await x.executor.execute(ID)).state,'EXECUTION_UNCERTAIN_NO_RETRY');
 assert.equal((await x.executor.execute(ID)).reason,'JOB_ALREADY_CLAIMED');
 assert.equal(x.runs(),1);
});
test('uncertain 313 response never blindly replays',async()=>{
 const x=fixture({readbackThrows:true});
 assert.equal((await x.executor.execute(ID)).state,'READBACK_UNCERTAIN_NO_RETRY');
 assert.equal((await x.executor.execute(ID)).reason,'JOB_ALREADY_CLAIMED');
 assert.equal(x.runs(),1);
});
