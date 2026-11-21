#!/usr/bin/env python3
import importlib.util,pathlib,unittest,unittest.mock,json
FILE=pathlib.Path(__file__).with_name('host_signed_identity_attest.py')
spec=importlib.util.spec_from_file_location('signed_identity',FILE)
mod=importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)
class SignedIdentity(unittest.TestCase):
 def ok(self):
  return dict(state='ORIGINAL_POLICY_ROTATION_NOT_REQUIRED',
   original_pinned_signer_verified=True,
   original_principal_ledger_verified=True,host_key_verified=True,
   grant_issued=False,production_mutated=False)
 def test_original_host_ledger_is_required(self):
  with unittest.mock.patch.object(mod,'run',return_value=json.dumps(self.ok())) as p:
   self.assertTrue(mod.original_policy_signed_and_current())
   self.assertEqual(p.call_args.args[0],['/usr/bin/python3',str(mod.ORIGINAL_POLICY_SIGNER),'plan'])
 def test_deny_changed_signing_ledger(self):
  for key,bad in [('state','ORIGINAL_SIGNED_POLICY_ROTATION_READY'),
    ('original_pinned_signer_verified',False),('original_principal_ledger_verified',False),
    ('host_key_verified',False),('grant_issued',True),('production_mutated',True)]:
   d=self.ok();d[key]=bad
   with self.subTest(key=key),unittest.mock.patch.object(mod,'run',return_value=json.dumps(d)):
    with self.assertRaisesRegex(RuntimeError,'ORIGINAL_SIGNED_HOST_POLICY_NOT_CURRENT'):
     mod.original_policy_signed_and_current()
 def test_no_actor_impersonation_or_scope_expansion(self):
  s=FILE.read_text()
  for v in ("HOST_SIGNER.is_symlink()","HOST_SIGNER.stat().st_uid!=0",
    "router.authority(target,scope)!='VERIFIED'",
    'read_only_hostinger_binding','HOST_POLICY_NOT_AUTHORIZED',
    'allow_operator','allow_mutation','allow_production_mutation',
    'allow_remote_shell','allow_sql'):
   self.assertIn(v,s)
  self.assertNotIn('wp_set_current_user',s)
  self.assertNotIn('--user=',s)
 def test_identity_proof_never_claims_write_grant(self):
  s=FILE.read_text()
  for v in ('attest-identity',"HOST_SIGNER.read_bytes()",
    "out.get('remote_write_authorized') is not False",
    "out.get('grant_created') is not False",
    "production_mutation':False","grant_created':False"):
   self.assertIn(v,s)
  self.assertNotIn('anchor-issue',s)
 def test_independent_profile_digest_checked(self):
  s=FILE.read_text()
  self.assertIn("observed['digest']!=expected['digest']",s)
  self.assertIn("observed['uuid']!=scope['site_uuid']",s)
  self.assertIn("observed['environment']!=environment",s)
 def test_explicit_exact_target_only(self):
  s=FILE.read_text()
  self.assertIn("parser.add_argument('--target',required=True)",s)
  self.assertIn("parser.add_argument('--dry-run',action='store_true')",s)
if __name__=='__main__':unittest.main(verbosity=2)
