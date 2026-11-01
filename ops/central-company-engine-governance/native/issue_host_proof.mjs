import {randomBytes} from 'node:crypto';
import {CONTRACT,rootDigest,proofBytes} from './host_proof_transition.mjs';

// Host-side issuer for the original enrolled SSH transport. The host must
// provide a *trusted* registry adapter and its own Ed25519 signer.
// No key material is generated or persisted here. No owner grant is issued.
export async function issueHostProof({registry,wpProbe,signHostProof,transport,grant,site,
  now=Math.floor(Date.now()/1000),nonce=randomBytes(16).toString('hex')}){
 const fail=reason=>Object.freeze({issued:false,reason});
 if(typeof registry?.verifyPinnedBinding!=='function' ||
    typeof wpProbe!=='function'||typeof signHostProof!=='function')
   return fail('HOST_PROOF_ADAPTERS_REQUIRED');
 if(!site||!transport||!grant||site.environment!=='staging'||
    transport.state!=='PENDING_INDEPENDENT_SSH_VERIFICATION'||
    transport.full_wpcli_active!==false||
    transport.capabilities?.includes('wpcli.full')!==true||
    grant.state!=='CENTRAL_OPERATION_SCOPE_GRANTED'||
    grant.grant_mode!=='persistent'||grant.operation!=='company.remote.wpcli.full'||
    grant.transport_id!==transport.transport_id||
    transport.full_wpcli_grant_id!==grant.grant_id)
   return fail('ORIGINAL_CHANNEL_INVALID');
 const fields=['company','site_uuid','canonical_origin','environment'];
 if(fields.some(k=>site[k]!==transport[k]||site[k]!==grant[k]) ||
    typeof site.wordpress_root!=='string'||!site.wordpress_root.startsWith('/')||
    typeof site.wordpress_principal!=='string'||!site.wordpress_principal)
   return fail('REGISTERED_SITE_MISMATCH');
 // The registry adapter must independently authenticate the OS principal,
 // pinned host key and WP root. A successful SSH handshake is insufficient.
 const binding=await registry.verifyPinnedBinding({
   host_id:transport.host_id,host_principal:transport.host_principal,
   host_key_fingerprint:transport.host_key_fingerprint,
   company:site.company,site_uuid:site.site_uuid,
   wordpress_root:site.wordpress_root,wordpress_principal:site.wordpress_principal
 });
 if(binding?.verified!==true||binding.exact_site_wpcli!==true)
   return fail('SIGNED_HOST_REGISTRY_BINDING_REQUIRED');
 let observed;
 try {
   // Fixed diagnostic; the adapter must invoke trusted WP-CLI argv directly
   // and return output independently of the Core-issued job.
   observed=await wpProbe({root:site.wordpress_root,principal:site.wordpress_principal,
     argv:['option','get','home']});
 } catch {return fail('WPCLI_HOST_PROBE_FAILED')}
 if(observed?.exit_code!==0 || typeof observed.stdout!=='string' ||
    observed.stdout.trim()!==site.canonical_origin)
   return fail('REMOTE_WORDPRESS_ORIGIN_MISMATCH');
 if(!/^[a-f0-9]{32}$/.test(nonce)||!Number.isSafeInteger(now))
   return fail('NONCE_OR_TIME_INVALID');
 const proof={
  contract:CONTRACT,company:site.company,site_uuid:site.site_uuid,
  canonical_origin:site.canonical_origin,environment:site.environment,
  transport_id:transport.transport_id,grant_id:grant.grant_id,
  host_id:transport.host_id,ssh_principal:transport.host_principal,
  host_key_fingerprint:transport.host_key_fingerprint,
  wordpress_root:site.wordpress_root,wordpress_principal:site.wordpress_principal,
  readback_origin:observed.stdout.trim(),readback_root_sha256:rootDigest(site.wordpress_root),
  nonce,issued_epoch:now,expires_epoch:now+90
 };
 let signature;
 try {signature=await signHostProof(proofBytes(proof))}catch{return fail('HOST_SIGNER_UNAVAILABLE')}
 if(typeof signature!=='string'||!/^[A-Za-z0-9+/]+={0,2}$/.test(signature))
   return fail('HOST_SIGNATURE_INVALID');
 return Object.freeze({issued:true,proof:Object.freeze(proof),signature,
   next_state:'AWAITING_ORIGINAL_CORE_HOST_PROOF_VERIFICATION',
   grant_created:false,native_execute_registered:false});
}
