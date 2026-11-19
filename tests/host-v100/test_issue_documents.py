import unittest,base64,hashlib,importlib.util,time
from pathlib import Path
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey
from cryptography.hazmat.primitives.serialization import Encoding,PublicFormat
p=Path(__file__).resolve().parents[2]/'ops/host-anchor-v100/original312_first_issue_bundle.py'
s=importlib.util.spec_from_file_location('v100',p)
v=importlib.util.module_from_spec(s);s.loader.exec_module(v)
planmod=v.pinned(v.PLAN,v.PLAN_SHA,'v100plan')
bootmod=v.pinned(v.BOOTSTRAP,v.BOOTSTRAP_SHA,'v100bootstrap')
gate=v.pinned(v.GATE,v.GATE_SHA,'v100gate')
class SignedIssue(unittest.TestCase):
 def setUp(self):
  self.n=1700000000
  self.key=Ed25519PrivateKey.generate()
  self.pub=self.key.public_key().public_bytes(Encoding.Raw,PublicFormat.Raw)
  self.p={'contract':planmod.CONTRACTS[v.ISSUE],'operation_id':v.ISSUE,
   'authority_mode':'bootstrap','company':'all-royal-egypt','environment':'staging',
   'site_uuid':'49c562d1-8f2f-456f-b454-26816c6ba4cb',
   'origin':'https://staging.allroyalegypt.com','target_host_id':'hosting-2',
   'ssh_principal':'u948953904','policy_sha256':'1'*64,'profile_digest':'2'*64,
   'host_key_sha256':'3'*64,'ssh_root_sha256':'4'*64,'registry_sha256':'5'*64,
   'grant_revision':0,'anchor_revision':0,'requested_anchor_revision':1,
   'enrollment_id':'6'*32,'enrollment_nonce':'7'*32}
  self.p['plan_sha256']=hashlib.sha256(planmod.canonical(self.p)).hexdigest()
  self.j={'job_id':'a'*32,'operation_id':v.ISSUE,'company':self.p['company'],
   'environment':self.p['environment'],'site_uuid':self.p['site_uuid'],
   'origin':self.p['origin'],'target_host_id':'hosting-2',
   'target_ssh_principal':'u948953904',
   'state':'BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY',
   'job_type':'signed_remote_hostinger_wpcli_v2','principal_type':'signed_host_principal_v2',
   'authority_mode':'bootstrap','host_v2_bootstrap_only':True,
   'executed':False,'expires_epoch':self.n+300,
   'plan_sha256':self.p['plan_sha256'],'command_sha256':'c'*64}
  self.b={'contract':bootmod.CONTRACT,'job_id':self.j['job_id'],
   'operation_id':v.ISSUE,'company':self.p['company'],'environment':'staging',
   'site_uuid':self.p['site_uuid'],'origin':self.p['origin'],
   'target_host_id':'hosting-2','ssh_principal':'u948953904',
   'enrollment_id':self.p['enrollment_id'],'requested_anchor_revision':1,
   'command_sha256':'c'*64,'plan_sha256':self.p['plan_sha256'],
   'nonce':self.p['enrollment_nonce'],'issued_epoch':self.n,'expires_epoch':self.n+45}
  self.b['signature']=base64.b64encode(self.key.sign(bootmod.signed_message(self.b))).decode()
  self.snapshot={'site':{'company':self.p['company'],'environment':'staging',
   'site_uuid':self.p['site_uuid'],'canonical_origin':self.p['origin'],
   'profile_digest':self.p['profile_digest']},
   'anchor_readback':{'contract':'mad4b.original318.host-anchor-current-readback.v2',
   'source':'original318_fresh_wp_readback','company':self.p['company'],
   'environment':'staging','state':'MISSING_OR_INVALID','revision':0,
   'readback_verified':False,'original313_certified':False,
   'write_authorized':False,'observed_epoch':self.n},'original328_active':True}
 def compose(self):
  return v.signed_documents(self.p,self.j,self.snapshot,self.b,self.key,self.pub,
                            now=self.n,nonce='e'*32)
 def test_signed_issue_and_root_ledger(self):
  result=self.compose()
  self.assertFalse(result['anchor_issued'])
  self.assertFalse(result['original313_final_receipt_signed'])
  a=result['anchor'];l=result['revision_event']
  self.assertEqual(l['anchor_nonce'],a['nonce'])
  self.assertEqual(l['event_id'],self.j['job_id'])
  self.assertEqual(a['revision'],l['revision'])
  self.key.public_key().verify(base64.b64decode(a['signature']),v.anchor_body(a))
  gate.sigcheck(l,gate.FIELDS,gate.LEDGER,'MAD4B_ORIGINAL_HOST_LEDGER_V1',
                self.pub,self.n)
  self.assertEqual(result['anchor_message_sha256'],
      hashlib.sha256(v.anchor_body(a)).hexdigest())
 def test_bootstrap_exact_claim_mapping(self):
  for f,vbad in [('company','wayfatours'),('site_uuid','0'*36),
      ('command_sha256','d'*64),('origin','https://other.example'),
      ('issued_epoch',self.n-200),('expires_epoch',self.n+900)]:
   before=self.b[f];self.b[f]=vbad
   try:
    with self.assertRaises((v.IssueHeld,ValueError)):self.compose()
   finally:self.b[f]=before
 def test_php_cross_runtime_known_digest(self):
  # Verified using real PHP 8.5 implode(chr(10), array_map((string))).
  expected="446dbe4bc8a439776b8140159d911b2399ff9f4f9151ce5e476860a7256ef783"
  self.assertEqual(self.compose()['anchor_message_sha256'],expected)
 def test_wrong_bootstrap_signature(self):
  self.b['signature']=base64.b64encode(bytes(64)).decode()
  with self.assertRaises(v.IssueHeld):self.compose()
 def test_invalid_job_scopes(self):
  for k,bad in [('state','QUEUED_FOR_VPS_BROKER_CLAIM'),
      ('company','wayfatours'),('environment','production'),
      ('authority_mode','operational'),('host_v2_bootstrap_only',False),
      ('executed',True),('expires_epoch',self.n+5)]:
   old=self.j[k];self.j[k]=bad
   with self.assertRaises((ValueError,v.IssueHeld)):self.compose()
   self.j[k]=old
 def test_reject_stale_plan_and_wp(self):
  self.p['policy_sha256']='f'*64
  with self.assertRaises(ValueError):self.compose()
  self.p['policy_sha256']='1'*64
  self.snapshot['original328_active']=False
  with self.assertRaises(v.IssueHeld):self.compose()
 def test_php_semantics_false_cast_and_real_newlines(self):
  body=v.anchor_body(self.compose()['anchor'])
  self.assertTrue(body.endswith(b'\n\noriginal_approved_host_authority_reconcile'))
  self.assertEqual(body.count(b'\n'),len(v.FIELDS)-1)
  self.assertNotIn(b'False',body)
 def test_wrong_signer_public(self):
  other=Ed25519PrivateKey.generate()
  pub=other.public_key().public_bytes(Encoding.Raw,PublicFormat.Raw)
  with self.assertRaisesRegex(v.IssueHeld,'SIGNER_PUBKEY_DRIFT'):
   v.signed_documents(self.p,self.j,self.snapshot,self.b,self.key,pub,now=self.n)
if __name__=='__main__':unittest.main(verbosity=2)
