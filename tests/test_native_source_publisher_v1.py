"""Native Source v2 offline: no hardcoded operations/sites, original CAS SHA intact."""
from __future__ import annotations
import base64
from copy import deepcopy
from datetime import datetime, timedelta, timezone
import importlib.util
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest import mock
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey
from cryptography.hazmat.primitives.serialization import Encoding,PublicFormat

ROOT=Path(__file__).resolve().parents[1]
DIR=ROOT/'ops/native-source-publisher-v1'
spec=importlib.util.spec_from_file_location('mad4b_dynamic_source_test', DIR/'native_source_publisher.py')
lib=importlib.util.module_from_spec(spec)
sys.modules[spec.name]=lib
spec.loader.exec_module(lib)
DEFINITION=json.loads((DIR/'catalog/generated/source.code.release.manifest.json').read_text())
JS1='wp-content/plugins/mad4b-commerce/modules/presentation/assets/unified-date-v0.4.3.js'
JS2='wp-content/plugins/mad4b-integration/partner.js'
SITE1='tours-central:staging'
SITE2='wayfa-central:production'
UUID1='e5e64bca-ed62-48d2-b2b3-dff693cd702a'
UUID2='12345678-1234-4123-8123-123456789abc'

class FakeRegistry:
 def __init__(self,manifest1,manifest2):
  self.manifests={x['operation_id']:x for x in (manifest1,manifest2)}
 def resolve(self,op):
  if op not in self.manifests:raise ValueError('NOT_INSTALLED')
  return self.manifests[op]

class SourcePublisher(unittest.TestCase):
 def setUp(self):
  self.temp=tempfile.TemporaryDirectory(prefix='mad4b-native-dynamic-')
  self.addCleanup(self.temp.cleanup)
  self.root=Path(self.temp.name)
  self.git=self.root/'git'
  self.git.mkdir()
  self.site1=self.root/'site1';self.site2=self.root/'site2'
  for root,file in ((self.git,JS1),(self.git,JS2),
                    (self.site1,JS1),(self.site2,JS2)):
   (root/file).parent.mkdir(parents=True,exist_ok=True)
  (self.git/JS1).write_text('const source=1;\n')
  (self.git/JS2).write_text('const source=2;\n')
  (self.site1/JS1).write_text('const installed=1;\n')
  (self.site2/JS2).write_text('const installed=2;\n')
  for args in ([ 'init','-q' ],['config','user.email','publisher@example.invalid'],
               ['config','user.name','Publisher Fixture'],['add','.'],['commit','-qm','multi source']):
   subprocess.run(['git','-C',str(self.git),*args],check=True)
  self.commit=subprocess.check_output(['git','-C',str(self.git),'rev-parse','HEAD'],text=True).strip()
  self.private=Ed25519PrivateKey.generate()
  self.broker_private=Ed25519PrivateKey.generate()
  public=lambda key:base64.b64encode(key.public_key().public_bytes(Encoding.Raw,PublicFormat.Raw)).decode()
  self.policy={
    'contract':lib.POLICY_CONTRACT,'enabled':True,
    'source_git_root':str(self.git),'runtime_registry':str(self.root/'registry.json'),
    'receipt_dir':str(self.root/'receipts'),
    'broker_public_key_b64':public(self.broker_private),
    'master_approval_public_key_b64':public(self.private),
    'targets':{
       SITE1:{'root':str(self.site1),'container':'wordpress-1','site_uuid':UUID1,
              'origin':'https://tours.dev.mad4b.com','allowed_targets':[JS1]},
       SITE2:{'root':str(self.site2),'container':'wordpress-2','site_uuid':UUID2,
              'origin':'https://wayfatours.com','allowed_targets':[JS2]},
    }}
  (self.root/'receipts').mkdir(mode=0o700)
  manifest1=deepcopy(DEFINITION)
  manifest2=deepcopy(DEFINITION)
  manifest2['operation_id']='partner.source.update'
  manifest2['provider']='partner-source-provider'
  manifest2['approval_ability']='mad4b/partner-source-update'
  manifest2['command']['bound_operation_id']='partner.source.update'
  self.registry=FakeRegistry(manifest1,manifest2)
  self.manifest1=manifest1
  self.manifest2=manifest2
  self.request1=self.request(SITE1,JS1,UUID1,'https://tours.dev.mad4b.com',
                            manifest1['operation_id'],self.site1)
  self.request2=self.request(SITE2,JS2,UUID2,'https://wayfatours.com',
                            manifest2['operation_id'],self.site2)
  self.plan1=lib.make_plan(self.request1,self.policy,self.registry)
  self.plan2=lib.make_plan(self.request2,self.policy,self.registry)

 def request(self,scope,path,uuid,origin,op,root):
  return {'contract':lib.REQUEST_CONTRACT,'operation_id':op,
    'scope':scope,'site_uuid':uuid,'origin':origin,
    'git_commit':self.commit,'files':[{'source':path,'target':path,
      'expected_before_sha256':lib.sha((root/path).read_bytes())}]}

 def approval(self,plan,minutes=8):
  binding=plan[lib.BINDING_KEY]
  ticket={
    'contract':lib.APPROVAL_CONTRACT,
    'operation_id':binding['operation_id'],
    'plan_sha256':plan['plan_sha256'],
    'approval_ability':binding['approval_ability'],
    'approval_provider':binding['provider'],
    'source_manifest_sha256':binding['manifest_sha256'],
    'operation_binding_sha256':binding['binding_sha256'],
    'scope':plan['company']+':'+plan['environment'],
    'site_uuid':plan['site_uuid'],'origin':plan['origin'],
    'ticket_id':'deadbeef-0000-4000-8000-000000000012',
    'ticket_payload_sha256':'e'*64,
    'approver_role':'master_admin','ticket_state':'approved',
    'expires_at':(datetime.now(timezone.utc)+timedelta(minutes=minutes)).isoformat(),
  }
  ticket['signature']=base64.b64encode(self.private.sign(lib.approval_message(ticket))).decode()
  return ticket

 def test_two_operators_two_sites_one_engine(self):
  for plan,op,scope in ((self.plan1,self.manifest1['operation_id'],SITE1),
                        (self.plan2,self.manifest2['operation_id'],SITE2)):
   with self.subTest(op=op):
    lib.check_plan(plan,self.policy,registry=self.registry)
    binding=plan[lib.BINDING_KEY]
    self.assertEqual(binding['operation_id'],op)
    self.assertEqual(plan['company']+':'+plan['environment'],scope)
    self.assertEqual(plan['changed_count'],1)
    self.assertEqual(binding['manifest_sha256'],lib.sha(lib.canonical(
      self.registry.resolve(op))))
  self.assertNotEqual(self.plan1['plan_sha256'],self.plan2['plan_sha256'])

 def test_original_core_digest_contract_is_preserved(self):
  for plan in (self.plan1,self.plan2):
   original=lib.core_plan(plan)
   self.assertNotIn(lib.BINDING_KEY,original)
   self.assertEqual(lib.core.digest(lib.core.canonical({
      k:v for k,v in original.items() if k not in ('plan_sha256','changed_count')
   })),original['plan_sha256'])
   lib.core.confirm_plan(original,lib.company_policy(self.policy),expect_preimage=True)

 def test_manifest_binding_not_swappable_for_same_plan(self):
  tampered=deepcopy(self.plan1)
  tampered['source_binding']=deepcopy(self.plan2['source_binding'])
  with self.assertRaises(lib.Denied):lib.check_plan(tampered,self.policy,registry=self.registry)
  tampered=deepcopy(self.plan1)
  tampered['source_binding']['manifest_sha256']='0'*64
  with self.assertRaises(lib.Denied):lib.check_plan(tampered,self.policy,registry=self.registry)
  tampered=deepcopy(self.plan1)
  tampered['hidden_superuser_grant']=True
  with self.assertRaises(lib.Denied):lib.check_plan(tampered,self.policy,registry=self.registry)

 def test_signed_approval_has_provider_manifest_and_site(self):
  for plan in (self.plan1,self.plan2):
   lib.verify_master_approval(plan,self.policy,self.approval(plan))
   facts=lib.approval_operation_input(plan)
   self.assertEqual(facts['operation_id'],plan['source_binding']['operation_id'])
   self.assertEqual(facts['source_manifest_sha256'],plan['source_binding']['manifest_sha256'])
   self.assertEqual(facts['operation_binding_sha256'],plan['source_binding']['binding_sha256'])
   self.assertEqual(facts['site_uuid'],plan['site_uuid'])
  mutated=self.approval(self.plan1)
  mutated['source_manifest_sha256']='0'*64
  with self.assertRaises(lib.Denied):lib.verify_master_approval(self.plan1,self.policy,mutated)
  mutated=self.approval(self.plan1)
  mutated['operation_id']=self.manifest2['operation_id']
  with self.assertRaises(lib.Denied):lib.verify_master_approval(self.plan1,self.policy,mutated)
  with self.assertRaises(lib.Denied):lib.verify_master_approval(
    self.plan1,self.policy,self.approval(self.plan1,minutes=-1))

 def test_cross_site_file_and_operation_unenrolled_denied(self):
  bad=deepcopy(self.request1);bad['files'][0]['target']=JS2
  with self.assertRaises(lib.Denied):lib.make_plan(bad,self.policy,self.registry)
  bad=deepcopy(self.request1);bad['scope']=SITE2
  with self.assertRaises(lib.Denied):lib.make_plan(bad,self.policy,self.registry)
  bad=deepcopy(self.request1);bad['operation_id']='unregistered.unknown.operation'
  with self.assertRaises(lib.Denied):lib.make_plan(bad,self.policy,self.registry)

 def test_source_target_equivalence_and_derived_change_count(self):
  bad=deepcopy(self.request1)
  bad['files'][0]['source']=JS2
  with self.assertRaisesRegex(lib.Denied,'SOURCE_TARGET_EQUIVALENCE_REQUIRED'):
   lib.make_plan(bad,self.policy,self.registry)
  for count in (999,0,True,-1):
   bad_plan=deepcopy(self.plan1)
   bad_plan['changed_count']=count
   with self.subTest(count=count),self.assertRaisesRegex(
     lib.Denied,'SOURCE_DERIVED_CHANGED_COUNT_MISMATCH'):
    lib.check_plan(bad_plan,self.policy,registry=self.registry)
  bad_plan=deepcopy(self.plan1)
  bad_plan['files'][0]['source']=JS2
  with self.assertRaisesRegex(lib.Denied,'SOURCE_TARGET_EQUIVALENCE_REQUIRED'):
   lib.check_plan(bad_plan,self.policy,registry=self.registry)

 def test_preimage_drift_denies(self):
  (self.site1/JS1).write_text('CHANGED EXTERNALLY\n')
  with self.assertRaises(lib.Denied):lib.make_plan(self.request1,self.policy,self.registry)
  with self.assertRaises(lib.Denied):lib.check_plan(self.plan1,self.policy,registry=self.registry)

 def test_after_readback_requires_exact_postimage(self):
  lib.check_plan(self.plan1,self.policy,registry=self.registry)
  (self.site1/JS1).write_bytes((self.git/JS1).read_bytes())
  lib.check_plan(self.plan1,self.policy,after=True,registry=self.registry)
  with self.assertRaises(lib.Denied):lib.check_plan(self.plan1,self.policy,registry=self.registry)

 def test_site_policy_dynamic_and_fail_closed(self):
  lib.validate_policy(self.policy)
  swapped=deepcopy(self.policy)
  swapped['targets'][SITE1]['allowed_targets']=['../etc/passwd']
  with self.assertRaises(lib.Denied):lib.validate_policy(swapped)
  swapped=deepcopy(self.policy)
  swapped['targets'][SITE1]['site_uuid']='not-a-uuid'
  with self.assertRaises(lib.Denied):lib.validate_policy(swapped)
  swapped=deepcopy(self.policy)
  swapped['master_approval_public_key_b64']=swapped['broker_public_key_b64']
  with self.assertRaises(lib.Denied):lib.validate_policy(swapped)

 def test_dynamic_registry_family_enrollment_only(self):
  ops=lib.discover_source_operations(self.registry)
  self.assertEqual({x['operation_id'] for x in ops},
                   {self.manifest1['operation_id'],self.manifest2['operation_id']})
  junk=deepcopy(self.manifest2);junk['source_family']='nontrusted.custom'
  reg=FakeRegistry(self.manifest1,junk)
  self.assertEqual([x['operation_id'] for x in lib.discover_source_operations(reg)],
                   [self.manifest1['operation_id']])
  with self.assertRaises(lib.Denied):lib.enrolled_original_provider(junk['operation_id'],reg)

 def test_master_ticket_actual_row_is_dynamic_and_denies_revocation(self):
  a=self.approval(self.plan2)
  row={
   'ticket_id':a['ticket_id'],'status':'approved',
   'ticket_class':'mutation','server_id':'mad4b-write',
   'ability_name':self.manifest2['approval_ability'],
   'provider':self.manifest2['provider'],
   'payload_sha256':a['ticket_payload_sha256'],
   'site_uuid':UUID2,'master_admin_approver_verified':True,
   'approval_input_unchanged':True,
   'approved_by':5,'agent_id':17,
   'target_fingerprint':'a'*64,'exact_plan_target_fingerprint':'a'*64,
   'expires_at':(datetime.now(timezone.utc)+timedelta(minutes=8)).strftime('%Y-%m-%d %H:%M:%S'),
  }
  lib.validate_master_ticket_row(self.plan2,a,row)
  for key,value in [('provider',self.manifest1['provider']),
                    ('ability_name',self.manifest1['approval_ability']),
                    ('site_uuid',UUID1),('status','revoked'),
                    ('master_admin_approver_verified',False),
                    ('exact_plan_target_fingerprint','0'*64)]:
   test=dict(row);test[key]=value
   with self.subTest(key=key),self.assertRaises(lib.Denied):
    lib.validate_master_ticket_row(self.plan2,a,test)

 def test_actual_cli_does_not_expose_apply_or_sign(self):
  code=(DIR/'native_source_publisher.py').read_text()
  self.assertIn('choices=("status","plan","verify")',code)
  self.assertNotIn('private_key',code.lower())
  self.assertNotIn('TARGET_PATH =',code)
  self.assertNotIn('EXPECTED_ORIGIN =',code)
  self.assertNotIn('OPERATION = "source.code.release"',code)
  self.assertFalse(lib.POLICY_PATH.exists())
  with self.assertRaises(lib.Denied):
   lib.enrolled_original_provider('source.code.release')

 def test_ticket_readback_command_uses_dynamic_ability(self):
  a=self.approval(self.plan2)
  with mock.patch.object(lib.core,'run',return_value=b'{}\n') as probe:
   with self.assertRaises(lib.Denied):
    lib.fresh_master_ticket_readback(self.plan2,self.policy,a)
   call=probe.call_args.args[0]
   self.assertEqual(call[:5],['docker','exec','-u','www-data','wordpress-2'])
   self.assertIn("Authorization_Target_Fingerprint::fingerprint",call[-1])
   self.assertIn("Native_Source_Release_Review::request_input",call[-1])
   self.assertIn('partner.source.update',lib.approval_operation_input(self.plan2)['operation_id'])

if __name__=='__main__':
 unittest.main()
