// Normalize source-of-truth Core 305/306 and independently verified Native
// enrollment; never manufacture a grant, session, or owner approval.
const deny=reason=>Object.freeze({ready:false,reason});
const same=(a,b,k)=>a?.[k]!=null && a[k]===b?.[k];
const scope=['site_uuid','canonical_origin','environment'];
export function originalCoreWpcliBinding({job,request,grant,transport,site,native}) {
 if(!job||!request||!grant||!transport||!site||!native)return deny('ORIGINAL_RECORD_MISSING');
 if(job.operation!=='company.remote.wpcli.full'||grant.operation!==job.operation||
    request.operation!==job.operation||job.command_format!=='wpcli.argv.json.v1'||
    request.command_format!==job.command_format||job.target_scope!=='wordpress'||
    request.target_scope!==job.target_scope)return deny('OPERATION_OR_FORMAT_MISMATCH');
 if(job.state!=='BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY'||
    request.status!=='ADMITTED_PENDING_INDEPENDENT_HOST_AUTHORITY'||
    grant.state!=='CENTRAL_OPERATION_SCOPE_GRANTED'||grant.grant_mode!=='persistent'||
    job.grant_mode!=='persistent')return deny('JOB_OR_GRANT_NOT_ADMITTED');
 if(!same(job,request,'command_sha256')||!same(job,request,'grant_id')||
    !same(job,transport,'transport_id')||!same(grant,transport,'transport_id')||
    !same(job,grant,'grant_id'))return deny('JOB_GRANT_TRANSPORT_MISMATCH');
 if(job.company!==request.company||job.company!==grant.company||
    job.company!==transport.company||job.company!==site.company||
    scope.some(k=>!same(job,request,k)||!same(job,grant,k)||
      !same(job,transport,k)||!same(job,site,k)))return deny('COMPANY_OR_SITE_MISMATCH');
 if(job.target_host_id!==transport.host_id)return deny('TARGET_HOST_MISMATCH');
 if(job.target_ssh_principal!==transport.host_principal||
    grant.target_ssh_principal!==transport.host_principal||
    job.target_host_id!==grant.target_host_id)return deny('SSH_IDENTITY_MISMATCH');
 if(transport.full_wpcli_grant_id!==job.grant_id||
    !Array.isArray(transport.capabilities)||!transport.capabilities.includes('wpcli.full'))
    return deny('CHANNEL_CAPABILITY_NOT_ENROLLED');
 // 305's pending transport is NOT a verified active SSH session. The
 // original Core must independently record evidence before changing state.
 if(transport.state!=='ENROLLED'||transport.host_authority_verified!==true||
    transport.full_wpcli_active!==true)return deny('INDEPENDENT_HOST_ENROLLMENT_PENDING');
 if(native.registration_state!=='ENROLLED'||native.execute_registered!==true||
    native.host_key_verified!==true||native.site_binding_verified!==true||
    native.grant_verifier_bound!==true)return deny('NATIVE_EXECUTOR_NOT_CERTIFIED');
 if(native.site_uuid!==job.site_uuid||native.company!==job.company||
    native.host_id!==transport.host_id||
    native.ssh_host_key_fingerprint!==transport.host_key_fingerprint)
    return deny('NATIVE_EXACT_BINDING_MISMATCH');
 if(typeof site.wordpress_root!=='string'||!site.wordpress_root.startsWith('/')||
    typeof site.wordpress_principal!=='string'||!site.wordpress_principal)
    return deny('WORDPRESS_ROOT_OR_PRINCIPAL_NOT_ENROLLED');
 const binding={
    company_id:job.company,site_uuid:job.site_uuid,canonical_origin:job.canonical_origin,
    environment:job.environment,ssh_target_id:job.target_host_id,
    ssh_account:transport.host_principal,ssh_host_key_fingerprint:transport.host_key_fingerprint,
    wordpress_root:site.wordpress_root,wordpress_principal:site.wordpress_principal,
    enrollment_revision:String(transport.assignment_revision),
    actor_id:String(job.central_principal_id)
 };
 return Object.freeze({ready:true,
  job:Object.freeze({...binding,job_id:job.job_id,channel_id:transport.transport_id,
    grant_id:job.grant_id,operation:job.operation,target_scope:job.target_scope,
    state:job.state,command_sha256:job.command_sha256}),
  channel:Object.freeze({...binding,state:'ENROLLED',revoked:false,
    capabilities:['wpcli.full'],authorization_source:'original_core_registered_connection',
    full_wpcli_active:true})});
}
