import {createPublicKey,verify,createHash} from 'node:crypto';

// Independent, site-specific proof. This verifier is intentionally not an
// enrollment writer or a means of creating grants. The signer key must come
// from the original, pinned host registry and never from the request body.
export const CONTRACT='mad4b.native-site-host-proof.v1';
const fields=['contract','company','site_uuid','canonical_origin','environment',
  'transport_id','grant_id','host_id','ssh_principal','host_key_fingerprint',
  'wordpress_root','wordpress_principal','readback_origin',
  'readback_root_sha256','nonce','issued_epoch','expires_epoch'];
const hex32=/^[a-f0-9]{32}$/;
const hex64=/^[a-f0-9]{64}$/;
const deny=reason=>Object.freeze({verified:false,reason});
export const rootDigest=x=>createHash('sha256').update(x,'utf8').digest('hex');
export const proofBytes=p=>Buffer.from(JSON.stringify(fields.map(k=>p[k])),'utf8');

export function verifySiteHostProof({proof,signature,trustedHostProofKey,transport,site,grant,now=Math.floor(Date.now()/1000)}) {
 if(!proof||typeof proof!=='object'||Array.isArray(proof)||
    !transport||!site||!grant||
    proof.contract!==CONTRACT||Object.keys(proof).length!==fields.length||
    fields.some(k=>!Object.prototype.hasOwnProperty.call(proof,k)))
   return deny('PROOF_SHAPE_INVALID');
 if(!Number.isSafeInteger(proof.issued_epoch)||!Number.isSafeInteger(proof.expires_epoch)||
    proof.issued_epoch>now+10||proof.issued_epoch<now-120||
    proof.expires_epoch<=now||proof.expires_epoch>proof.issued_epoch+120||
    !hex32.test(proof.nonce)||!hex64.test(proof.readback_root_sha256))
   return deny('PROOF_STALE_OR_INVALID');
 if(transport.state!=='PENDING_INDEPENDENT_SSH_VERIFICATION'||
    transport.full_wpcli_pending_native_enrollment!==true||
    transport.full_wpcli_active!==false||transport.capabilities?.includes('wpcli.full')!==true||
    grant.state!=='CENTRAL_OPERATION_SCOPE_GRANTED'||grant.grant_mode!=='persistent'||
    grant.operation!=='company.remote.wpcli.full'||
    grant.transport_id!==transport.transport_id||
    transport.full_wpcli_grant_id!==grant.grant_id)
   return deny('ORIGINAL_CHANNEL_NOT_PENDING');
 const exact=[
  [proof.company,transport.company],[proof.company,grant.company],[proof.company,site.company],
  [proof.site_uuid,transport.site_uuid],[proof.site_uuid,grant.site_uuid],[proof.site_uuid,site.site_uuid],
  [proof.canonical_origin,transport.canonical_origin],[proof.canonical_origin,grant.canonical_origin],
  [proof.canonical_origin,site.canonical_origin],[proof.environment,transport.environment],
  [proof.environment,grant.environment],[proof.environment,site.environment],
  [proof.transport_id,transport.transport_id],[proof.grant_id,grant.grant_id],
  [proof.host_id,transport.host_id],[proof.ssh_principal,transport.host_principal],
  [proof.host_key_fingerprint,transport.host_key_fingerprint],
  [proof.wordpress_root,site.wordpress_root],[proof.wordpress_principal,site.wordpress_principal],
  [proof.readback_origin,site.canonical_origin],
  [proof.readback_root_sha256,rootDigest(site.wordpress_root)]
 ];
 if(exact.some(([a,b])=>typeof a!=='string'||typeof b!=='string'||a.length===0||a!==b)||
    !proof.wordpress_root.startsWith('/')||proof.environment!=='staging')
   return deny('SITE_HOST_OR_GRANT_SCOPE_DRIFT');
 // The independent remote service, not central WP, signs the observed
 // origin/root. Its key and SSH host fingerprint are independently pinned.
 let key,sig;
 try{
  key=trustedHostProofKey?.type==='public'?trustedHostProofKey:createPublicKey(trustedHostProofKey);
  sig=Buffer.from(signature,'base64');
  if(key.asymmetricKeyType!=='ed25519'||sig.length!==64||
     sig.toString('base64')!==signature||!verify(null,proofBytes(proof),key,sig))
    return deny('HOST_PROOF_SIGNATURE_INVALID');
 }catch{return deny('HOST_PROOF_SIGNER_UNAVAILABLE')}
 return Object.freeze({verified:true,contract:CONTRACT,company:proof.company,
   site_uuid:proof.site_uuid,transport_id:proof.transport_id,grant_id:proof.grant_id,
   host_id:proof.host_id,nonce:proof.nonce,
   state:'HOST_PROOF_VERIFIED_NATIVE_EXECUTOR_ENROLLMENT_PENDING',
   full_wpcli_active:false,execution_certified:false});
}
