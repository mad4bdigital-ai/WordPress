import test from 'node:test';
import assert from 'node:assert/strict';
import {originalCoreWpcliBinding as map} from './core_native_binding.mjs';
const scope={company:'all-royal-egypt',site_uuid:'staging-site',canonical_origin:'https://staging.allroyalegypt.com',environment:'staging'};
const transportId='a'.repeat(32),grantId='b'.repeat(32),jobId='c'.repeat(32),digest='d'.repeat(64);
const fresh=()=>({
 job:{...scope,job_id:jobId,grant_id:grantId,transport_id:transportId,
   operation:'company.remote.wpcli.full',state:'BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY',
   grant_mode:'persistent',target_scope:'wordpress',target_host_id:'hosting-2',
   target_ssh_principal:'webowner',command_format:'wpcli.argv.json.v1',command_sha256:digest,central_principal_id:8},
 request:{...scope,request_id:jobId,grant_id:grantId,operation:'company.remote.wpcli.full',
   status:'ADMITTED_PENDING_INDEPENDENT_HOST_AUTHORITY',target_scope:'wordpress',
   command_format:'wpcli.argv.json.v1',command_sha256:digest},
 grant:{...scope,grant_id:grantId,transport_id:transportId,operation:'company.remote.wpcli.full',
   state:'CENTRAL_OPERATION_SCOPE_GRANTED',grant_mode:'persistent',target_host_id:'hosting-2',target_ssh_principal:'webowner'},
 transport:{...scope,transport_id:transportId,full_wpcli_grant_id:grantId,
   state:'PENDING_INDEPENDENT_SSH_VERIFICATION',host_authority_verified:false,full_wpcli_active:false,
   host_id:'hosting-2',host_principal:'webowner',host_key_fingerprint:'SHA256:example',
   capabilities:['wpcli.full'],assignment_revision:9},
 site:{...scope,wordpress_root:'/home/sites/staging/wp',wordpress_principal:'webowner'},
 native:{registration_state:'INSTALLED_UNENROLLED',execute_registered:false,
   host_key_verified:false,site_binding_verified:false,grant_verifier_bound:false,
   site_uuid:'staging-site',company:'all-royal-egypt',host_id:'hosting-2',
   ssh_host_key_fingerprint:'SHA256:example'},
});
function prepared(){const d=fresh();Object.assign(d.transport,
  {state:'ENROLLED',host_authority_verified:true,full_wpcli_active:true});
 Object.assign(d.native,{registration_state:'ENROLLED',execute_registered:true,
   host_key_verified:true,site_binding_verified:true,grant_verifier_bound:true});return d}
test('real original pending state cannot become active native authority',()=>{
 const x=map(fresh());assert.equal(x.ready,false);assert.equal(x.reason,'INDEPENDENT_HOST_ENROLLMENT_PENDING');
});
test('completed site-bound channel normalizes without per-command owner ticket',()=>{
 const x=map(prepared());assert.equal(x.ready,true);assert.equal(x.job.channel_id,transportId);
 assert.equal(x.channel.authorization_source,'original_core_registered_connection');
});
for(const [name,segment,key,value,reason] of [
 ['foreign company','job','company','other','COMPANY_OR_SITE_MISMATCH'],
 ['foreign site','site','site_uuid','other','COMPANY_OR_SITE_MISMATCH'],
 ['production environment drift','transport','environment','production','COMPANY_OR_SITE_MISMATCH'],
 ['changed transport id','job','transport_id','other','JOB_GRANT_TRANSPORT_MISMATCH'],
 ['changed grant id','job','grant_id','other','JOB_GRANT_TRANSPORT_MISMATCH'],
 ['tampered digest','request','command_sha256','f'.repeat(64),'JOB_GRANT_TRANSPORT_MISMATCH'],
 ['changed ssh principal','grant','target_ssh_principal','root','SSH_IDENTITY_MISMATCH'],
 ['changed host key','native','ssh_host_key_fingerprint','SHA256:wrong','NATIVE_EXACT_BINDING_MISMATCH'],
 ['no independent host key proof','native','host_key_verified',false,'NATIVE_EXECUTOR_NOT_CERTIFIED'],
 ['missing execute','native','execute_registered',false,'NATIVE_EXECUTOR_NOT_CERTIFIED'],
 ['missing wordpress root','site','wordpress_root','','WORDPRESS_ROOT_OR_PRINCIPAL_NOT_ENROLLED'],
 ['invalid command format','job','command_format','shell.text','OPERATION_OR_FORMAT_MISMATCH'],
 ['revoked grant','grant','state','REVOKED','JOB_OR_GRANT_NOT_ADMITTED']
])test(name,()=>{const x=prepared();x[segment][key]=value;assert.equal(map(x).reason,reason)});
