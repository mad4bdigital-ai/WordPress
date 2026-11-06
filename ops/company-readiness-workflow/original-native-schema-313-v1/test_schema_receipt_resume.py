#!/usr/bin/env python3
"""Read-only safety regression for one-shot schema host receipt delivery."""
import importlib.util,os,pathlib,stat,tempfile,types,unittest
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
 def test_original_receipt_conflict_is_not_ignored(self):
  outcome=types.SimpleNamespace(returncode=1,stdout='',stderr='Error: WPCLI_RECEIPT_ALREADY_EXISTS')
  with patch.object(self.exe,'WP',return_value=['wp']),patch.object(self.exe.subprocess,'run',return_value=outcome):
   with self.assertRaisesRegex(RuntimeError,'WPCLI_RECEIPT_ALREADY_EXISTS'):
    self.exe.wp_json(['mad4b','broker-remote-ssh','wpcli-receipt'])
 def test_resume_code_cannot_reexecute_schema_command(self):
  source=(ROOT/'schema-receipt-resume.py').read_text()
  self.assertNotIn("'option','get','jet_engine_meta_boxes'",source)
  self.assertIn('EXPIRED_OR_INSUFFICIENT_ORIGINAL_312_WINDOW_NO_REPLAY',source)
  worker=(ROOT.parent/'claimed-job-worker.py').read_text()
  self.assertIn("work.insert(0,(job,'schema.readback.resume'))",worker)
  self.assertIn("schema-receipt-resume.py'",worker)

if __name__=='__main__':unittest.main()
