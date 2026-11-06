#!/usr/bin/env python3
"""Read-only safety regression for one-shot schema host receipt delivery."""
import importlib.util,os,pathlib,stat,sys,tempfile,types,unittest
from unittest.mock import patch
ROOT=pathlib.Path(__file__).resolve().parent

def load(name,file):
 s=importlib.util.spec_from_file_location(name,ROOT/file)
 m=importlib.util.module_from_spec(s);s.loader.exec_module(m)
 return m

class SchemaReceiptRecoveryTests(unittest.TestCase):
 @classmethod
 def setUpClass(cls):
  cls.res=load('schema_receipt_recovery_test','schema-receipt-resume.py')
  # The VPS-only central_wpcli_runtime is intentionally absent on GitHub CI.
  # Supply a fail-closed import-only adapter; all tested WP invocations remain
  # explicit mocks, so any unexpected real WP-CLI call fails this fixture.
  def forbid_unmocked_wpcli(*args, **kwargs):
   raise AssertionError('HERMETIC_WPCLI_ACCESS_FORBIDDEN')
  fixture=types.ModuleType('central_wpcli_runtime')
  fixture.central_wp=forbid_unmocked_wpcli
  with patch.dict(sys.modules,{'central_wpcli_runtime':fixture}):
   cls.exe=load('schema_original_execution_test','original-wpcli-executor.py')
 def test_receipt_enforced_root_only(self):
  with tempfile.TemporaryDirectory() as d:
   p=pathlib.Path(d)/'receipt'
   p.write_bytes(b'authentic-receipt-must-stay-immutable')
   p.chmod(0o600)
   self.assertEqual(self.res.root_bytes(p),b'authentic-receipt-must-stay-immutable')
   p.chmod(0o644)
   with self.assertRaisesRegex(RuntimeError,'ROOT_ONLY'):
    self.res.root_bytes(p)
 def test_symlink_rejected(self):
  with tempfile.TemporaryDirectory() as d:
   p=pathlib.Path(d)/'real';p.write_bytes(b'xyz');p.chmod(0o600)
   alias=pathlib.Path(d)/'alias';alias.symlink_to(p)
   with self.assertRaisesRegex(RuntimeError,'ROOT_ONLY'):
    self.res.root_bytes(alias)
 def test_scope_mismatch_denied(self):
  self.assertFalse(self.res.matching({'job_id':'old'}, {'job_id':'new'}, ['job_id']))
 def test_central_slot_exhaustion_classified(self):
  outcome=types.SimpleNamespace(returncode=75,stdout='',stderr='STAGING_WPCLI_BUSY: two active sessions')
  with patch.object(self.exe,'WP',return_value=['wp']), patch.object(self.exe.subprocess,'run',return_value=outcome):
   with self.assertRaisesRegex(RuntimeError,'CENTRAL_WPCLI_SLOT_BUSY_DEFERRED'):
    self.exe.wp_json(['mad4b','broker-remote-ssh','status'])
 def test_wpcli_busy_with_nonstandard_exit_code_deferred(self):
  outcome=types.SimpleNamespace(returncode=1,stdout='',stderr='STAGING_WPCLI_BUSY: two active WordPress CLI sessions')
  with patch.object(self.exe,'WP',return_value=['wp']), patch.object(self.exe.subprocess,'run',return_value=outcome):
   with self.assertRaisesRegex(RuntimeError,'CENTRAL_WPCLI_SLOT_BUSY_DEFERRED'):
    self.exe.wp_json(['mad4b','broker-remote-ssh','status'])
 def test_original_receipt_conflict_is_not_ignored(self):
  outcome=types.SimpleNamespace(returncode=1,stdout='',stderr='Error: WPCLI_RECEIPT_ALREADY_EXISTS')
  with patch.object(self.exe,'WP',return_value=['wp']),patch.object(self.exe.subprocess,'run',return_value=outcome):
   with self.assertRaisesRegex(RuntimeError,'WPCLI_RECEIPT_ALREADY_EXISTS'):
    self.exe.wp_json(['mad4b','broker-remote-ssh','wpcli-receipt'])
 def test_expired_receipt_denied_before_target_access(self):
  import json,time
  job='1'*32
  host='srv2030908';digest='a'*64;readback='b'*64
  record={'contract':'mad4b.native-original-313-execution.v1','job_id':job,
    'company':'wayfatours','environment':'production','origin':'https://wayfatours.com',
    'target_id':host,'command_sha256':digest,'exit_code':0,'job_mutating':False,
    'state':'EXECUTED_PENDING_INDEPENDENT_READBACK',
    'schema_readback_type':'jet_engine_meta_boxes','output_sha256':readback,
    'expected_readback_sha256':readback,'schema_readback_sha256':readback}
  verifier=types.SimpleNamespace(verify=lambda *args:{'verified':True,'eligible':True,
    'owner_enrolled_wpcli':True,'operation':'company.remote.wpcli.full',
    'target_scope':'wordpress','expires_epoch':int(time.time())-10})
  target_access=unittest.mock.Mock()
  engine=types.SimpleNamespace(HOST=host,DIGEST=digest,
    original_module=lambda *args:verifier,pinned_target=target_access)
  def locked(path,max_bytes=16000):
   if path.suffix=='.reserved':return b'reserved-for-one-exact-original-312-job\n'
   return json.dumps(record).encode()
  with patch.object(self.res,'module',return_value=engine),patch.object(self.res,'root_bytes',side_effect=locked):
   with self.assertRaisesRegex(RuntimeError,'EXPIRED_OR_INSUFFICIENT_ORIGINAL_312_WINDOW_NO_REPLAY'):
    self.res.resume(job)
  target_access.assert_not_called()
 def test_preflight_uses_enrolled_actor_not_unauthenticated_wpcli(self):
  source=(ROOT/'original-wpcli-executor.py').read_text()
  self.assertIn("lane=wp_json(['--user='+str(preflight_actor)",source)
  self.assertIn('preflight_actor=resolve_actor()',source)
 def test_resume_code_cannot_reexecute_schema_command(self):
  source=(ROOT/'schema-receipt-resume.py').read_text()
  self.assertNotIn("'option','get','jet_engine_meta_boxes'",source)
  self.assertIn('EXPIRED_OR_INSUFFICIENT_ORIGINAL_312_WINDOW_NO_REPLAY',source)
  worker=(ROOT/'claimed-job-worker.py').read_text()
  self.assertIn("work.insert(0,(job,'schema.readback.resume'))",worker)
  self.assertIn("schema-receipt-resume.py'",worker)

if __name__=='__main__':unittest.main()
