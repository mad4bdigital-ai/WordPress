"""Offline native source publisher: exact Git CAS, signed admin approval, deny by default."""
import base64
import importlib.util
import json
from pathlib import Path
import subprocess
import sys
import tempfile
from datetime import datetime,timedelta,timezone
import unittest
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey
from cryptography.hazmat.primitives.serialization import Encoding,PublicFormat

ROOT=Path(__file__).resolve().parents[1]
BASE=ROOT/'ops/native-source-publisher-v1'
spec=importlib.util.spec_from_file_location('mad4b_test_local_native',BASE/'native_source_publisher.py')
lib=importlib.util.module_from_spec(spec)
sys.modules[spec.name]=lib
spec.loader.exec_module(lib)

class SourcePublisher(unittest.TestCase):
 @classmethod
 def setUpClass(cls):
  cls.priv=Ed25519PrivateKey.generate()
  cls.pub=base64.b64encode(cls.priv.public_key().public_bytes(Encoding.Raw,PublicFormat.Raw)).decode()
  cls.broker_priv=Ed25519PrivateKey.generate()
  cls.broker_pub=base64.b64encode(cls.broker_priv.public_key().public_bytes(Encoding.Raw,PublicFormat.Raw)).decode()
 def setUp(self):
  self.tmp=tempfile.TemporaryDirectory(prefix='publisher-offline-')
  self.addCleanup(self.tmp.cleanup)
  self.root=Path(self.tmp.name)
  self.git=self.root/'source'; self.site=self.root/'site'; self.receipts=self.root/'receipts'
  path=lib.TARGET_PATH
  (self.git/path).parent.mkdir(parents=True)
  (self.site/path).parent.mkdir(parents=True)
  self.receipts.mkdir(mode=0o700)
  (self.git/path).write_text("const version='new_043';\n")
  (self.site/path).write_text("const version='old_043';\n")
  subprocess.run(['git','-C',str(self.git),'init','-q'],check=True)
  subprocess.run(['git','-C',str(self.git),'config','user.email','mad4b@example.invalid'],check=True)
  subprocess.run(['git','-C',str(self.git),'config','user.name','MAD4B test'],check=True)
  subprocess.run(['git','-C',str(self.git),'add','.'],check=True)
  subprocess.run(['git','-C',str(self.git),'commit','-qm','initial fixture'],check=True)
  self.commit=subprocess.check_output(['git','-C',str(self.git),'rev-parse','HEAD'],text=True).strip()
  self.pre=lib.sha((self.site/path).read_bytes())
  self.policy={'contract':lib.POLICY_CONTRACT,'enabled':True,
    'operation':lib.OPERATION,'targets':{lib.TARGET_SCOPE:{
      'site_uuid':lib.EXPECTED_UUID,'origin':lib.EXPECTED_ORIGIN,
      'root':str(self.site),'container':'test-wp-native',
      'allowed_targets':[lib.TARGET_PATH]}},
    'source_git_root':str(self.git),'runtime_registry':str(self.root/'native-registry.json'),
    'receipt_dir':str(self.receipts),
    'broker_public_key_b64':self.broker_pub,'master_approval_public_key_b64':self.pub}
  self.request={'contract':lib.SPEC_CONTRACT,'scope':lib.TARGET_SCOPE,
    'site_uuid':lib.EXPECTED_UUID,'origin':lib.EXPECTED_ORIGIN,
    'git_commit':self.commit,'file':lib.TARGET_PATH,
    'expected_before_sha256':self.pre}
  self.plan=lib.make_plan(self.request,self.policy)
 def sign(self,expiry=None):
  a={'contract':lib.APPROVAL_CONTRACT,'operation':lib.OPERATION,
    'plan_sha256':self.plan['plan_sha256'],'site_uuid':lib.EXPECTED_UUID,
    'origin':lib.EXPECTED_ORIGIN,'scope':lib.TARGET_SCOPE,
    'ticket_id':'deadbeef-0000-4000-8000-000000000012',
    'ticket_payload_sha256':'f'*64,
    'approver_role':'master_admin','ticket_state':'approved',
    'expires_at':(expiry or datetime.now(timezone.utc)+timedelta(minutes=6)).isoformat()}
  a['signature']=base64.b64encode(self.priv.sign(lib.approval_message(a))).decode()
  return a
 def test_valid_one_file_git_plan(self):
  self.assertEqual(self.plan['changed_count'],1)
  self.assertEqual(len(self.plan['files']),1)
  self.assertEqual(self.plan['files'][0]['expected_before_sha256'],self.pre)
  self.assertEqual(self.plan['company'],'tours-central')
  lib.check_plan(self.plan,self.policy)
 def test_approval_crypto_positive(self):
  lib.verify_master_approval(self.plan,self.policy,self.sign())
 def test_wrong_scope_denied(self):
  for key,value in [('origin','https://wayfatours.com'),
                    ('scope','tours-central:production'),
                    ('file','wp-content/plugins/woocommerce/woocommerce.php')]:
   d=dict(self.request);d[key]=value
   with self.subTest(key=key),self.assertRaises(lib.Denied):lib.make_plan(d,self.policy)
 def test_policy_target_allowlist_cannot_expand(self):
  d=json.loads(json.dumps(self.policy))
  d['targets'][lib.TARGET_SCOPE]['allowed_targets']=['wp-content/plugins/other/test.js']
  with self.assertRaises(lib.Denied):lib.validate_policy(d)
  d=json.loads(json.dumps(self.policy))
  d['targets']['wayfatours:production']={}
  with self.assertRaises(lib.Denied):lib.validate_policy(d)
 def test_preimage_drift_denied(self):
  (self.site/lib.TARGET_PATH).write_text("CHANGED")
  with self.assertRaises(lib.Denied):lib.make_plan(self.request,self.policy)
  with self.assertRaises(lib.Denied):lib.check_plan(self.plan,self.policy)
 def test_git_plan_digest_tampering_denied(self):
  bad=dict(self.plan)
  bad['files']=[dict(self.plan['files'][0],source_sha256='0'*64)]
  with self.assertRaises(lib.Denied):lib.check_plan(bad,self.policy)
  (self.git/lib.TARGET_PATH).write_text("changed worktree after commit")
  lib.check_plan(self.plan,self.policy)
 def test_master_approval_forgery_expiry_replay_denied(self):
  a=self.sign();a['approver_role']='other'
  with self.assertRaises(lib.Denied):lib.verify_master_approval(self.plan,self.policy,a)
  a=self.sign();a['signature']='AQ=='
  with self.assertRaises(lib.Denied):lib.verify_master_approval(self.plan,self.policy,a)
  a=self.sign(datetime.now(timezone.utc)-timedelta(seconds=1))
  with self.assertRaises(lib.Denied):lib.verify_master_approval(self.plan,self.policy,a)
  a=self.sign(datetime.now(timezone.utc)+timedelta(hours=2))
  with self.assertRaises(lib.Denied):lib.verify_master_approval(self.plan,self.policy,a)
 def test_master_ticket_payload_binding_is_mandatory(self):
  a=self.sign()
  a['ticket_payload_sha256']='0'*64
  with self.assertRaises(lib.Denied):
   lib.verify_master_approval(self.plan,self.policy,a)
  a=self.sign()
  a.pop('ticket_payload_sha256')
  with self.assertRaises(lib.Denied):
   lib.verify_master_approval(self.plan,self.policy,a)
 def test_master_ticket_replay_fail_closed(self):
  # Never allow a second Broker grant to reuse an already claimed approval.
  a=self.sign()
  lib.reserve_master_ticket_once(self.plan,self.policy,a,self.receipts)
  marker=self.receipts/('master-'+a['ticket_id']+'.reserved')
  self.assertEqual(marker.read_text().strip(),self.plan['plan_sha256'])
  with self.assertRaises(lib.Denied):
   lib.reserve_master_ticket_once(self.plan,self.policy,a,self.receipts)
 def test_broker_and_master_keys_must_be_independent(self):
  d=dict(self.policy)
  d['master_approval_public_key_b64']=self.broker_pub
  with self.assertRaises(lib.Denied):lib.validate_policy(d)
 def test_unregistered_operation_denies_even_with_valid_fixture_keys(self):
  with self.assertRaises(lib.Denied):lib.enrolled_original_provider()
  with self.assertRaises(lib.Denied):lib.admission(self.plan,self.policy,self.sign(),{})
 def test_original_worker_reserved_job_and_independent_313_not_faked(self):
  source=(BASE/'original_313_host_handler.py').read_text()
  self.assertIn('protected_file(RESERVATIONS/',source)
  self.assertIn('original_312_for_claim(job_id,plan)',source)
  self.assertIn('grant.get("original_job_id")!=job_id',source)
  self.assertIn('SOURCE_HOST_HANDLER_NOT_FIRST_PARTY_INSTALLED',source)
  self.assertIn('fresh_master_ticket_readback(plan,policy,approval)',(BASE/'native_source_publisher.py').read_text())
  check=(BASE/'original_313_independent_readback.py').read_text()
  self.assertIn('"original_313_signed_receipt_issued":False',check)
  self.assertIn('"signature_generated":False',check)
  self.assertIn('evidence_for_original_job(',check)
  self.assertIn('handler.original_312_for_claim(job_id,plan)',check)
  cli=(BASE/'native_source_publisher.py').read_text()
  self.assertIn('choices=("status","plan","verify")',cli)
 def test_registry_proposal_is_non_authorizing(self):
  d=json.loads((BASE/'catalog/publisher-enrollment-proposal.json').read_text())
  self.assertEqual(d['file_allowlist'],[lib.TARGET_PATH])
  self.assertFalse(d['publisher_enabled'])
  self.assertFalse(d['registry_enrolled'])
  self.assertFalse(d['production_writes_authorized'])

if __name__=='__main__':unittest.main()
