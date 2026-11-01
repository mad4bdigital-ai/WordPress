import {createHash} from 'node:crypto';
import {verifyIndependent313Readback,native313Bytes} from './independent_313_readback.mjs';

// Source-only Core adapter contract. The passed provider MUST be the existing
// original-Core, not a new database, independent grant service, or remote shell.
// Durable transactional compare-and-swap and signed journal are provider duties.
export const CONTRACT='mad4b.core-atomic-313-finalization.v1';
const HEX32=/^[a-f0-9]{32}$/;
const HEX64=/^[a-f0-9]{64}$/;
const stop=(state,reason)=>Object.freeze({state,reason,certified:false,executed_again:false});
const eq=(x,y)=>typeof x==='string'&&x.length>0&&x===y;
const pinnedFields=['company','site_uuid','origin','environment','transport_id',
  'grant_id','command_sha256'];

export function createOriginalCore313Finalizer({core}={}) {
 const methods=['readOriginal313Context','verifyCurrent312','commit313Atomically',
   'readFinalized313','audit313'];
 if(!core||methods.some(k=>typeof core[k]!=='function'))
   throw Error('ORIGINAL_CORE_313_ATOMIC_PROVIDER_REQUIRED');
 return Object.freeze({
  async finalizeOnce({job_id,readback,signature,now=Math.floor(Date.now()/1000)}={}) {
   if(!HEX32.test(job_id||''))return stop('DENIED','ORIGINAL_JOB_ID_INVALID');
   // This is a reconciliation of an already executed job. Never call the
   // Native execute entrypoint, release an old reservation or retry WP-CLI.
   let ctx;
   try {ctx=await core.readOriginal313Context(job_id)}
   catch{return stop('PARKED_NO_REPLAY','ORIGINAL_CORE_CONTEXT_UNCERTAIN')}
   const job=ctx?.originalJob,p=ctx?.pending313,n=ctx?.registeredNative;
   if(!job||job.job_id!==job_id||
      job.state!=='BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY'||
      job.executed!==false||job.operation!=='company.remote.wpcli.full'||
      job.target_scope!=='wordpress'||job.environment!=='staging'||
      !p||p.state!=='PENDING_INDEPENDENT_NATIVE_READBACK')
     return stop('DENIED','ORIGINAL_PENDING_JOB_AND_313_REQUIRED');
   if(ctx?.observerRegistry?.authority_source!=='original_core_pinned_registry'||
      ctx?.observerRegistry?.enrolled!==true||
      ctx?.observerRegistry?.revoked!==false||
      ctx?.observerRegistry?.site_uuid!==job.site_uuid||
      ctx?.observerRegistry?.host_id!==job.target_host_id||
      ctx?.observerRegistry?.key_id!==n?.observer_key_id||
      !ctx.observerRegistry.public_key)
     return stop('DENIED','ORIGINAL_CORE_OBSERVER_ENROLLMENT_REQUIRED');
   const proof=verifyIndependent313Readback({
     originalJob:job,pending313:p,registeredNative:n,readback,signature,now,
     pinnedObserverKey:ctx.observerRegistry.public_key,
     pinnedObserverKeyId:ctx.observerRegistry.key_id,
     pinnedObserverKeySource:ctx.observerRegistry.authority_source
   });
   if(proof.verified!==true||proof.finalized!==false)
     return stop('DENIED',proof.reason||'INDEPENDENT_313_NOT_VERIFIED');
   let authority;
   try {authority=await core.verifyCurrent312(job_id)}
   catch{return stop('PARKED_NO_REPLAY','ORIGINAL_312_RECHECK_UNCERTAIN')}
   if(!authority||authority.verified!==true||authority.eligible!==true||
      authority.operation!=='company.remote.wpcli.full'||
      authority.owner_enrolled_wpcli!==true||
      !eq(authority.job_id,job_id)||!eq(authority.target_id,job.target_host_id)||
      !eq(authority.company,job.company)||
      !eq(authority.site_uuid,job.site_uuid)||
      !eq(authority.origin,job.canonical_origin)||
      !eq(authority.environment,job.environment)||
      !eq(authority.grant_id,job.grant_id)||
      !eq(authority.transport_id,job.transport_id)||
      !eq(authority.command_sha256,job.command_sha256))
     return stop('DENIED','ORIGINAL_312_SCOPE_RECHECK_DENIED');
   const proofHash=createHash('sha256').update(native313Bytes(readback)).digest('hex');
   const record={
     contract:CONTRACT,job_id,
     company:readback.company,site_uuid:readback.site_uuid,
     origin:readback.origin,environment:readback.environment,
     host_id:readback.host_id,transport_id:readback.transport_id,
     grant_id:readback.grant_id,command_sha256:readback.command_sha256,
     output_sha256:readback.output_sha256,
     readback_sha256:readback.readback_sha256,
     exit_code:readback.exit_code,observer_key_id:ctx.observerRegistry.key_id,
     observer_nonce:readback.nonce,proof_sha256:proofHash,
     expected_job_state:'BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY',
     expected_313_state:'PENDING_INDEPENDENT_NATIVE_READBACK'
   };
   let commit;
   try {commit=await core.commit313Atomically(Object.freeze({...record}))}
   catch{return stop('PARKED_NO_REPLAY','CORE_313_COMMIT_OUTCOME_UNCERTAIN')}
   // Successful API return isn't proof of durable persistence.
   if(commit?.committed!==true)
     return stop('PARKED_NO_REPLAY','CORE_313_ATOMIC_CAS_NOT_CONFIRMED');
   let durable;
   try {durable=await core.readFinalized313(job_id)}
   catch{return stop('PARKED_NO_REPLAY','CORE_313_DURABLE_READBACK_UNAVAILABLE')}
   if(!durable||durable.contract!==CONTRACT||
      durable.state!=='CORE_313_FINALIZED'||
      durable.independent_readback_verified!==true||
      durable.native_executor_certified!==true||
      !HEX64.test(durable.journal_sha256||'')||
      !pinnedFields.every(k=>eq(durable[k],record[k]))||
      !eq(durable.job_id,job_id)||!eq(durable.host_id,record.host_id)||
      !eq(durable.observer_key_id,record.observer_key_id)||
      !eq(durable.observer_nonce,record.observer_nonce)||
      !eq(durable.proof_sha256,record.proof_sha256)||
      !eq(durable.output_sha256,record.output_sha256)||
      !eq(durable.readback_sha256,record.readback_sha256)||
      durable.exit_code!==record.exit_code)
     return stop('PARKED_NO_REPLAY','CORE_313_POSTCOMMIT_SCOPE_OR_JOURNAL_MISMATCH');
   try {await core.audit313({job_id,contract:CONTRACT,
     event:'CORE_313_DURABLE_READBACK_CONFIRMED',
     journal_sha256:durable.journal_sha256})}
   catch{return stop('PARKED_NO_REPLAY','CORE_313_AUDIT_UNCERTAIN')}
   return Object.freeze({state:'CORE_313_FINALIZED',
     certified:true,executed_again:false,job_id,
     journal_sha256:durable.journal_sha256});
  }
 });
}
