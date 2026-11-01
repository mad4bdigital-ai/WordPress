import test from 'node:test';
import assert from 'node:assert/strict';
import {generateKeyPairSync,sign} from 'node:crypto';
import {CONTRACT,rootDigest,proofBytes,verifySiteHostProof} from './host_proof_transition.mjs';
const {privateKey,publicKey}=generateKeyPairSync('ed25519');
const now=1770000000;
const company='all-royal-egypt',site_uuid='staging-1';
const canonical_origin='https://staging.allroyalegypt.com';
const wordpress_root='/home/staging/allroyalegypt/public_html';
const transport_id='a'.repeat(32),grant_id='b'.repeat(32);
function fixture(){
 const site={company,site_uuid,canonical_origin,environment:'staging',wordpress_root,wordpress_principal:'siteuser'};
 const grant={company,site_uuid,canonical_origin,environment:'staging',grant_id,
  transport_id,state:'CENTRAL_OPERATION_SCOPE_GRANTED',grant_mode:'persistent',
  operation:'company.remote.wpcli.full'};
 const transport={company,site_uuid,canonical_origin,environment:'staging',transport_id,
  full_wpcli_grant_id:grant_id,host_id:'hosting-2',host_principal:'siteuser',
  host_key_fingerprint:'SHA256:pinned',state:'PENDING_INDEPENDENT_SSH_VERIFICATION',
  full_wpcli_active:false,full_wpcli_pending_native_enrollment:true,capabilities:['wpcli.full']};
 const proof={contract:CONTRACT,company,site_uuid,canonical_origin,environment:'staging',transport_id,grant_id,
  host_id:'hosting-2',ssh_principal:'siteuser',host_key_fingerprint:'SHA256:pinned',wordpress_root,
  wordpress_principal:'siteuser',readback_origin:canonical_origin,
  readback_root_sha256:rootDigest(wordpress_root),
  nonce:'c'.repeat(32),issued_epoch:now-2,expires_epoch:now+40};
 return {site,grant,transport,proof};
}
function run(f,signingKey=privateKey,key=publicKey,at=now) {
 const signature=sign(null,proofBytes(f.proof),signingKey).toString('base64');
 return verifySiteHostProof({...f,signature,trustedHostProofKey:key,now:at});
}
test('site-specific Host Proof verifies but does not enroll or enable execution',()=>{
 const x=run(fixture());assert.equal(x.verified,true);
 assert.equal(x.state,'HOST_PROOF_VERIFIED_NATIVE_EXECUTOR_ENROLLMENT_PENDING');
 assert.equal(x.full_wpcli_active,false);assert.equal(x.execution_certified,false);
});
for(const [name,section,field,value,reason] of [
 ['wrong company','site','company','other','SITE_HOST_OR_GRANT_SCOPE_DRIFT'],
 ['wrong origin','proof','readback_origin','https://wrong.test','SITE_HOST_OR_GRANT_SCOPE_DRIFT'],
 ['wrong root','site','wordpress_root','/other','SITE_HOST_OR_GRANT_SCOPE_DRIFT'],
 ['wrong SSH user','transport','host_principal','root','SITE_HOST_OR_GRANT_SCOPE_DRIFT'],
 ['wrong fingerprint','transport','host_key_fingerprint','other','SITE_HOST_OR_GRANT_SCOPE_DRIFT'],
 ['production target','proof','environment','production','SITE_HOST_OR_GRANT_SCOPE_DRIFT'],
 ['replayed grant','proof','grant_id','d'.repeat(32),'SITE_HOST_OR_GRANT_SCOPE_DRIFT'],
 ['unregistered transport','transport','state','REVOKED','ORIGINAL_CHANNEL_NOT_PENDING'],
 ['already active','transport','full_wpcli_active',true,'ORIGINAL_CHANNEL_NOT_PENDING'],
 ['wrong grant','grant','state','REVOKED','ORIGINAL_CHANNEL_NOT_PENDING'],
 ['wrong scope','grant','operation','maintenance.shell','ORIGINAL_CHANNEL_NOT_PENDING'],
 ['stale proof','proof','issued_epoch',now-300,'PROOF_STALE_OR_INVALID'],
 ['missing proof field','proof','readback_root_sha256',undefined,'PROOF_SHAPE_INVALID']
])test(name,()=>{
 const f=fixture();if(value===undefined)delete f[section][field];else f[section][field]=value;
 assert.equal(run(f).reason,reason);
});
test('different signature key rejected',()=>{
 const f=fixture(),other=generateKeyPairSync('ed25519');
 assert.equal(run(f,other.privateKey).reason,'HOST_PROOF_SIGNATURE_INVALID');
});
test('wrong pinned signing key rejected',()=>{
 const f=fixture(),other=generateKeyPairSync('ed25519');
 assert.equal(run(f,privateKey,other.publicKey).reason,'HOST_PROOF_SIGNATURE_INVALID');
});
test('tampered signed host proof rejected',()=>{
 const f=fixture();
 const signature=sign(null,proofBytes(f.proof),privateKey).toString('base64');
 f.proof.nonce='f'.repeat(32);
 const result=verifySiteHostProof({...f,signature,trustedHostProofKey:publicKey,now});
 assert.equal(result.reason,'HOST_PROOF_SIGNATURE_INVALID');
});
test('signature cannot be used to claim future enrollment',()=>{
 const f=fixture(),result=run(f);
 assert.equal(result.verified,true);assert.ok(!('enrollment_record' in result));
 assert.equal(f.transport.state,'PENDING_INDEPENDENT_SSH_VERIFICATION');
});
