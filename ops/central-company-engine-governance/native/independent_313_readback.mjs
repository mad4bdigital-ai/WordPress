import {createPublicKey,verify} from 'node:crypto';

// Second-stage 313 verification. This does not execute, enroll, mint grants,
// or mark a WordPress option as certified. A separate original-Core atomic
// finalizer must consume the verified result with a durable readback journal.
export const CONTRACT='mad4b.native-independent-313-readback.v1';
const FIELDS=Object.freeze([
 'contract','job_id','company','site_uuid','origin','environment',
 'host_id','transport_id','grant_id','command_sha256','output_sha256',
 'readback_sha256','exit_code','observed_wordpress_home','issued_epoch',
 'expires_epoch','nonce'
]);
const HEX32=/^[a-f0-9]{32}$/;
const HEX64=/^[a-f0-9]{64}$/;
const NO=reason=>Object.freeze({verified:false,reason,finalized:false});
export const native313Bytes=p=>Buffer.from(JSON.stringify(FIELDS.map(k=>p[k])),'utf8');
const same=(...v)=>v.every(x=>typeof x==='string'&&x.length>0&&x===v[0]);

export function verifyIndependent313Readback({
 originalJob,pending313,registeredNative,readback,signature,pinnedObserverKey,
 pinnedObserverKeyId,pinnedObserverKeySource,
 now=Math.floor(Date.now()/1000)
}={}){
 if(!originalJob||!pending313||!registeredNative||!readback||
    typeof readback!=='object'||Array.isArray(readback)||
    Object.keys(readback).length!==FIELDS.length||
    FIELDS.some(k=>!Object.prototype.hasOwnProperty.call(readback,k))||
    readback.contract!==CONTRACT)
  return NO('NATIVE_313_SHAPE_INVALID');
 if(pending313.contract!=='mad4b.broker-remote-wpcli-receipt.v1'||
    pending313.state!=='PENDING_INDEPENDENT_NATIVE_READBACK'||
    pending313.host_signed_attestation_received!==true||
    pending313.native_executor_certified!==false||
    pending313.independent_host_readback_verified!==false)
  return NO('ORIGINAL_313_PENDING_RECEIPT_REQUIRED');
 if(registeredNative.registration_state!=='ENROLLED'||
    registeredNative.execute_registered!==true||
    registeredNative.independent_readback_observer_enrolled!==true||
    registeredNative.observer_signer_is_execution_signer!==false||
    registeredNative.observer_key_pinned!==true||
    typeof registeredNative.observer_key_id!=='string'||
    registeredNative.observer_key_id.length<8||
    pinnedObserverKeyId!==registeredNative.observer_key_id||
    pinnedObserverKeySource!=='original_core_pinned_registry')
  return NO('PINNED_INDEPENDENT_NATIVE_OBSERVER_REQUIRED');
 if(!HEX32.test(readback.job_id)||!HEX32.test(readback.nonce)||
    !HEX64.test(readback.command_sha256)||
    !HEX64.test(readback.output_sha256)||
    !HEX64.test(readback.readback_sha256)||
    !Number.isSafeInteger(readback.exit_code)||
    readback.exit_code<0||readback.exit_code>255||
    !Number.isSafeInteger(readback.issued_epoch)||
    !Number.isSafeInteger(readback.expires_epoch)||
    readback.issued_epoch>now+10||readback.issued_epoch<now-120||
    readback.expires_epoch<=now||
    readback.expires_epoch>readback.issued_epoch+120)
  return NO('NATIVE_313_FRESHNESS_OR_DIGEST_INVALID');
 const job=originalJob,p=pending313,n=registeredNative,r=readback;
 if(job.operation!=='company.remote.wpcli.full'||
    job.target_scope!=='wordpress'||job.environment!=='staging'||
    !same(r.job_id,job.job_id,p.job_id)||
    !same(r.company,job.company,p.company,n.company)||
    !same(r.site_uuid,job.site_uuid,p.site_uuid,n.site_uuid)||
    !same(r.origin,job.canonical_origin,p.origin,n.canonical_origin)||
    !same(r.environment,job.environment,p.environment,n.environment)||
    !same(r.host_id,job.target_host_id,p.target_id,n.host_id)||
    !same(r.transport_id,job.transport_id,p.transport_id,n.transport_id)||
    !same(r.grant_id,job.grant_id,p.grant_id,n.grant_id)||
    !same(r.command_sha256,job.command_sha256,p.command_sha256)||
    !same(r.output_sha256,p.output_sha256)||
    !same(r.readback_sha256,p.readback_sha256)||
    r.exit_code!==p.exit_code||
    r.observed_wordpress_home!==job.canonical_origin)
  return NO('NATIVE_313_EXACT_SCOPE_OR_OBSERVATION_MISMATCH');
 let key,sig;
 try{
  key=pinnedObserverKey?.type==='public'?pinnedObserverKey:createPublicKey(pinnedObserverKey);
  sig=Buffer.from(signature,'base64');
  if(key.asymmetricKeyType!=='ed25519'||sig.length!==64||
     sig.toString('base64')!==signature||
     !verify(null,native313Bytes(r),key,sig))
   return NO('NATIVE_313_SIGNATURE_INVALID');
 }catch{return NO('PINNED_NATIVE_OBSERVER_KEY_UNAVAILABLE')}
 return Object.freeze({
  verified:true,finalized:false,contract:CONTRACT,
  job_id:r.job_id,company:r.company,site_uuid:r.site_uuid,
  grant_id:r.grant_id,transport_id:r.transport_id,
  command_sha256:r.command_sha256,
  next_operation:'ORIGINAL_CORE_ATOMIC_313_FINALIZE',
  note:'Signature verification alone is not a durable original-Core 313 receipt'
 });
}
