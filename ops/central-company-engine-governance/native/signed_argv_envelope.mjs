import {createHash, createPublicKey, verify} from 'node:crypto';

// This protocol is not a privilege grant. Core owns its signing key, the
// enrolled connection and the job; the native process verifies its issuer.
export const CONTRACT = 'mad4b.original-core-signed-wpcli-argv.v1';
const HEX32 = /^[a-f0-9]{32}$/;
const HEX64 = /^[a-f0-9]{64}$/;
const B64 = /^(?:[A-Za-z0-9+/]{4})*(?:[A-Za-z0-9+/]{2}==|[A-Za-z0-9+/]{3}=)?$/;
const FIELDS = Object.freeze(['job_id', 'company_id', 'site_uuid', 'canonical_origin',
  'environment', 'ssh_target_id', 'ssh_account', 'ssh_host_key_fingerprint',
  'wordpress_root', 'wordpress_principal', 'enrollment_revision', 'actor_id',
  'channel_id', 'grant_id', 'command_sha256', 'issued_epoch', 'expires_epoch', 'nonce', 'argv']);
const DENY = reason => Object.freeze({ok:false, reason});

function validArgs(argv){
  return Array.isArray(argv) && argv.length>0 && argv.length<=256 &&
    argv.every(a => typeof a === 'string' && a.length <= 8192 &&
      !/[\0\r\n]/.test(a) && !/^--(?:path|url|ssh|http|user)(?:=|$)/i.test(a));
}
export const argvDigest = argv => createHash('sha256').update(JSON.stringify(argv),'utf8').digest('hex');
export const signatureBytes = payload => Buffer.from(JSON.stringify([CONTRACT,...FIELDS.map(k=>payload[k])]),'utf8');

export function verifyOriginalCoreArgvEnvelope({envelope, trustedPublicKey, expected, now=Math.floor(Date.now()/1000)}){
  if(!envelope || typeof envelope!=='object' || envelope.contract!==CONTRACT ||
     !envelope.payload || typeof envelope.payload!=='object' ||
     Array.isArray(envelope.payload) || typeof envelope.signature!=='string' ||
     !B64.test(envelope.signature))return DENY('ENVELOPE_INVALID');
  const p=envelope.payload;
  if(Object.keys(p).length!==FIELDS.length ||
     FIELDS.some(k=>!Object.prototype.hasOwnProperty.call(p,k)))return DENY('PAYLOAD_FIELDS_INVALID');
  if(!HEX32.test(p.job_id) || !HEX32.test(p.nonce) || !HEX64.test(p.command_sha256) ||
     !validArgs(p.argv) || argvDigest(p.argv)!==p.command_sha256)
    return DENY('COMMAND_PAYLOAD_INVALID');
  if(!Number.isSafeInteger(p.issued_epoch) || !Number.isSafeInteger(p.expires_epoch) ||
     p.issued_epoch>now+10 || p.issued_epoch<now-300 || p.expires_epoch<=now ||
     p.expires_epoch>p.issued_epoch+300)return DENY('ENVELOPE_EXPIRED');
  if(!expected || typeof expected!=='object' ||
     FIELDS.filter(k=>!['issued_epoch','expires_epoch','nonce','argv'].includes(k))
       .some(k=>typeof expected[k]!=='string' || expected[k]!==p[k]))return DENY('ENROLLED_BINDING_MISMATCH');
  const sig=Buffer.from(envelope.signature,'base64');
  if(sig.length!==64 || sig.toString('base64')!==envelope.signature)return DENY('SIGNATURE_INVALID');
  try {
    const publicKey=trustedPublicKey?.type==='public' ? trustedPublicKey : createPublicKey(trustedPublicKey);
    if(publicKey.asymmetricKeyType!=='ed25519' || !verify(null,signatureBytes(p),publicKey,sig))
      return DENY('SIGNATURE_INVALID');
  } catch {return DENY('SIGNER_UNAVAILABLE')}
  return Object.freeze({ok:true, argv:Object.freeze([...p.argv]),command_sha256:p.command_sha256,
    job_id:p.job_id,nonce:p.nonce,expires_epoch:p.expires_epoch});
}
