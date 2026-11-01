import test from 'node:test';
import assert from 'node:assert/strict';
import {generateKeyPairSync,sign} from 'node:crypto';
import {CONTRACT,argvDigest,signatureBytes,verifyOriginalCoreArgvEnvelope} from './signed_argv_envelope.mjs';
const {publicKey,privateKey}=generateKeyPairSync('ed25519');
const now=1761984000;
const payload={job_id:'a'.repeat(32),company_id:'c1',site_uuid:'site1',canonical_origin:'https://staging.example',
  environment:'staging',ssh_target_id:'hosting-3',ssh_account:'siteuser',
  ssh_host_key_fingerprint:'SHA256:original',wordpress_root:'/srv/site',wordpress_principal:'siteuser',
  enrollment_revision:'7',actor_id:'owner1',channel_id:'conn1',grant_id:'grant1',
  command_sha256:argvDigest(['db','query','SELECT 1']),issued_epoch:now,expires_epoch:now+60,
  nonce:'b'.repeat(32),argv:['db','query','SELECT 1']};
const expected={...payload};
function envelope(p=payload,key=privateKey){return {contract:CONTRACT,payload:p,signature:sign(null,signatureBytes(p),key).toString('base64')}}
const check=(e,exp=expected,key=publicKey,t=now)=>verifyOriginalCoreArgvEnvelope({envelope:e,expected:exp,trustedPublicKey:key,now:t});
test('original Core signer and exact enrollment permit argv, no command-specific approval',()=>{
 const r=check(envelope());assert.equal(r.ok,true);assert.deepEqual([...r.argv],payload.argv);
});
for(const [name,mutation,reason] of [
 ['company changed',{company_id:'other'},'ENROLLED_BINDING_MISMATCH'],
 ['site changed',{site_uuid:'foreign'},'ENROLLED_BINDING_MISMATCH'],
 ['origin changed',{canonical_origin:'https://other.example'},'ENROLLED_BINDING_MISMATCH'],
 ['ssh account changed',{ssh_account:'root'},'ENROLLED_BINDING_MISMATCH'],
 ['wp root changed',{wordpress_root:'/tmp'},'ENROLLED_BINDING_MISMATCH'],
 ['wp principal changed',{wordpress_principal:'other'},'ENROLLED_BINDING_MISMATCH'],
 ['host fingerprint changed',{ssh_host_key_fingerprint:'SHA256:other'},'ENROLLED_BINDING_MISMATCH'],
 ['transport changed',{channel_id:'conn2'},'ENROLLED_BINDING_MISMATCH'],
 ['grant changed',{grant_id:'grant2'},'ENROLLED_BINDING_MISMATCH'],
 ['command payload changed',{argv:['plugin','list']},'COMMAND_PAYLOAD_INVALID'],
 ['invalid target override',{argv:['--path=/tmp'],command_sha256:argvDigest(['--path=/tmp'])},'COMMAND_PAYLOAD_INVALID'],
 ['invalid NUL',{argv:['eval','\0'],command_sha256:argvDigest(['eval','\0'])},'COMMAND_PAYLOAD_INVALID'],
 ['expired',{issued_epoch:now-350,expires_epoch:now-300},'ENVELOPE_EXPIRED'],
 ['too long lifetime',{expires_epoch:now+500},'ENVELOPE_EXPIRED']
])test(name,()=>assert.equal(check(envelope({...payload,...mutation})).reason,reason));
test('unsigned payload change is rejected',()=>{const v=envelope();v.payload={...payload,site_uuid:'forged'};assert.equal(check(v,{...expected,site_uuid:'forged'}).reason,'SIGNATURE_INVALID')});
test('unsigned extra fields are rejected',()=>{const v=envelope({...payload,extra:'bad'});assert.equal(check(v).reason,'PAYLOAD_FIELDS_INVALID')});
test('wrong signing authority rejected',()=>{const other=generateKeyPairSync('ed25519');assert.equal(check(envelope(payload,other.privateKey)).reason,'SIGNATURE_INVALID')});
test('bad public key rejected',()=>assert.equal(check(envelope(),expected,'invalid').reason,'SIGNER_UNAVAILABLE'));
test('noncanonical signature rejected',()=>{const e=envelope();e.signature=e.signature+'A';assert.equal(check(e).reason,'ENVELOPE_INVALID')});
