import {verifyOriginalCoreArgvEnvelope} from './signed_argv_envelope.mjs';
import {createHash} from 'node:crypto';

// Original-Core native execution binding: adapter interfaces MUST be implemented
// by the existing MAD4B 305/306/312/313 + enrolled native transport.
// No SSH credential, grant, or owner identity is minted here.
const hex32=/^[a-f0-9]{32}$/;
const hex64=/^[a-f0-9]{64}$/;
const scalar=x=>typeof x==='string'&&x.length>0;
const sha=x=>createHash('sha256').update(x,'utf8').digest('hex');
const exactFields=['company_id','site_uuid','canonical_origin','environment',
  'ssh_target_id','ssh_account','ssh_host_key_fingerprint','wordpress_root',
  'wordpress_principal','enrollment_revision','actor_id'];
const stop=reason=>Object.freeze({state:'DENIED',reason,executed:false});

// The original Core owns the job payload and signed transfer. Never trust
// caller-submitted command bytes or a generic authenticated SSH handshake.
function checkedArgs(argv){
  if(!Array.isArray(argv)||argv.length===0||argv.length>256||
     !argv.every(x=>typeof x==='string'&&x.length<=8192&&!x.includes('\0')&& !x.includes('\n') && !x.includes('\r')))
    throw Error('INVALID_WPCLI_ARGV');
  // A connected WP-CLI session is bound to its exact WordPress installation.
  // This is a target-escape filter, not a WP-CLI subcommand allowlist.
  const escape=/^--(?:path|url|ssh|http|user)(?:=|$)/i;
  if(argv.some(x=>escape.test(x)))throw Error('WPCLI_TARGET_OVERRIDE_DENIED');
  return argv;
}
const payloadHash=argv=>sha(JSON.stringify(checkedArgs(argv)));
const same=(x,y,fields)=>fields.every(k=>x?.[k]===y?.[k]);

export function createOriginalCoreWpcliExecutor({core,broker,transport}){
  if(!core||!broker||!transport||
     typeof core.fetchBoundJob!=='function'||
     typeof core.fetchEnrolledChannel!=='function'||
     typeof core.claimJobOnce!=='function'||
     typeof core.audit!=='function'||
     typeof core.fetchSignedArgvEnvelope!=='function'||
     typeof core.getPinnedSignerPublicKey!=='function'||
     typeof broker.verify312!=='function'||
     typeof broker.verify313!=='function'||
     typeof transport.runBoundWpcli!=='function')throw Error('ORIGINAL_NATIVE_ADAPTERS_REQUIRED');

  return Object.freeze({
    async execute(jobId){
      if(!hex32.test(jobId))return stop('JOB_ID_INVALID');
      // No impersonated owner approval. Only an existing, active company-site
      // channel enrollment is standing execution authority.
      const job=await core.fetchBoundJob(jobId);
      if(!job||job.job_id!==jobId||job.operation!=='company.remote.wpcli.full'||
         !scalar(job.channel_id)||!hex64.test(job.command_sha256)||
         job.target_scope!=='wordpress'||job.state!=='BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY')
        return stop('ORIGINAL_JOB_NOT_ADMITTED');
      const channel=await core.fetchEnrolledChannel(job.channel_id);
      if(!channel||channel.state!=='ENROLLED'||channel.revoked===true||
         channel.capabilities?.includes('wpcli.full')!==true||
         channel.authorization_source!=='original_core_registered_connection'||
         !same(job,channel,exactFields)||
         channel.full_wpcli_active!==true)
        return stop('EXACT_CHANNEL_NOT_ENROLLED');
      let verifiedArgs;
      try {
        const envelope=await core.fetchSignedArgvEnvelope(jobId);
        const trustedPublicKey=await core.getPinnedSignerPublicKey(channel);
        verifiedArgs=verifyOriginalCoreArgvEnvelope({envelope,trustedPublicKey,expected:job});
      }catch{return stop('SIGNED_ARGV_TRANSPORT_UNAVAILABLE')}
      if(verifiedArgs.ok!==true)return stop(verifiedArgs.reason);
      const argv=verifiedArgs.argv;
      if(verifiedArgs.command_sha256!==job.command_sha256)return stop('COMMAND_DIGEST_MISMATCH');
      const admission=await broker.verify312({job,channel});
      if(!admission||admission.eligible!==true||admission.verified!==true||
         admission.owner_enrolled_wpcli!==true||
         admission.operation!=='company.remote.wpcli.full'||
         admission.job_id!==jobId||admission.command_sha256!==job.command_sha256||
         admission.site_uuid!==job.site_uuid||admission.company!==job.company_id||
         admission.target_id!==job.ssh_target_id||
         admission.origin!==job.canonical_origin||admission.environment!==job.environment||
         admission.host_principal!==job.ssh_account||
         admission.host_key_fingerprint!==job.ssh_host_key_fingerprint||
         admission.transport_id!==job.channel_id||admission.grant_id!==job.grant_id)
        return stop('BROKER_312_AUTHORITY_DENIED');
      // This must be an original atomic durable claim. An uncertain execution
      // is never blindly replayed (even when the caller receives an error).
      if(await core.claimJobOnce(jobId,job.command_sha256)!==true)
        return stop('JOB_ALREADY_CLAIMED');
      await core.audit(jobId,'NATIVE_CLAIMED');
      let result;
      try {
        result=await transport.runBoundWpcli({
          job_id:jobId,channel_id:job.channel_id,site_uuid:job.site_uuid,
          command_sha256:job.command_sha256,
          executable:'wp',argv:[...argv],
          wordpress_root:channel.wordpress_root,
          wordpress_principal:channel.wordpress_principal,
          ssh_target_id:channel.ssh_target_id,
          ssh_account:channel.ssh_account,
          ssh_host_key_fingerprint:channel.ssh_host_key_fingerprint,
        });
      }catch{
        await core.audit(jobId,'EXECUTION_UNCERTAIN_NO_RETRY');
        return Object.freeze({state:'EXECUTION_UNCERTAIN_NO_RETRY',executed:null});
      }
      let proof;
      try {proof=await broker.verify313({job,channel,execution:result})}
      catch {
        await core.audit(jobId,'READBACK_UNCERTAIN_NO_RETRY');
        return Object.freeze({state:'READBACK_UNCERTAIN_NO_RETRY',executed:true});
      }
      // Original 313 is two-stage: its broker-signed host result is first
      // stored PENDING. Never mistake that pending receipt for a failed
      // command, a replayable command, or a certified Native executor.
      const exact=proof?.contract==='mad4b.broker-remote-wpcli-receipt.v1'&&
        proof.job_id===jobId&&proof.command_sha256===job.command_sha256&&
        proof.site_uuid===job.site_uuid&&
        (proof.company===job.company_id||proof.company_id===job.company_id)&&
        proof.grant_id===job.grant_id&&proof.transport_id===job.channel_id&&
        proof.target_id===job.ssh_target_id&&
        proof.origin===job.canonical_origin&&proof.environment===job.environment&&
        proof.exit_code===result?.exit_code;
      if(exact&&proof.state==='PENDING_INDEPENDENT_NATIVE_READBACK'&&
         proof.verified===false&&proof.host_signed_attestation_received===true&&
         proof.native_executor_certified===false&&
         proof.independent_host_readback_verified===false){
        await core.audit(jobId,'ORIGINAL_313_PENDING_INDEPENDENT_READBACK_NO_REPLAY');
        return Object.freeze({state:'PENDING_INDEPENDENT_NATIVE_READBACK',
          certified:false,executed:true,job_id:jobId,exit_code:result.exit_code});
      }
      // Final success requires the separate original-Core atomic journal,
      // not merely a signed 313 message or caller-supplied verified flag.
      if(!exact||proof.state!=='CORE_313_FINALIZED'||
         proof.verified!==true||proof.native_executor_certified!==true||
         proof.independent_host_readback_verified!==true||
         !hex64.test(proof.journal_sha256||'')){
        await core.audit(jobId,'READBACK_UNVERIFIED_NO_RETRY');
        return Object.freeze({state:'READBACK_UNVERIFIED_NO_RETRY',certified:false,executed:true});
      }
      await core.audit(jobId,'NATIVE_EXECUTION_AND_ORIGINAL_CORE_313_VERIFIED');
      return Object.freeze({state:'VERIFIED',certified:true,executed:true,
        job_id:jobId,exit_code:result.exit_code,
        journal_sha256:proof.journal_sha256});
    }
  });
}
export {payloadHash};
