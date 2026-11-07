#!/usr/bin/env python3
"""Read-only capacity routing regressions for the original 313 worker."""
import importlib.util,pathlib,tempfile,unittest
from unittest.mock import patch
P=pathlib.Path(__file__).resolve().parent.parent/'claimed-job-worker.py'
spec=importlib.util.spec_from_file_location('original_313_worker_capacity',P)
W=importlib.util.module_from_spec(spec);spec.loader.exec_module(W)
JOB='a'*32
BUSY='ORIGINAL_NATIVE_313_STOPPED:CENTRAL_WPCLI_SLOT_BUSY_DEFERRED\n'
class WorkerCapacityRegression(unittest.TestCase):
 def test_exact_central_capacity_codes_are_retryable(self):
  for code in ('CENTRAL_WORDPRESS_BUSY','WPCLI_DYNAMIC_ACTOR_BUSY','CENTRAL_WPCLI_SLOT_BUSY_DEFERRED'):
   self.assertTrue(W.is_retryable_central_capacity(RuntimeError(code)),code)
  self.assertTrue(W.is_retryable_central_capacity(W.TransientCentralReadBusy('ORIGINAL_CENTRAL_WPCLI_READ_TIMEOUT')))
 def test_real_authorization_denials_remain_fail_closed(self):
  for code in ('CENTRAL_SIGNED_AUTHORITY_PREFLIGHT_REJECTED','ORIGINAL_BROKER_WPCLI_DENIED',
               'OWNER_PERMISSION_DENIED','WPCLI_RECEIPT_ALREADY_EXISTS'):
   self.assertFalse(W.is_retryable_central_capacity(RuntimeError(code)),code)
 def test_schema_preflight_busy_without_reservation_deferred(self):
  with tempfile.TemporaryDirectory() as temp,patch.object(W,'ROOT',pathlib.Path(temp)):
   for code in ('CENTRAL_WORDPRESS_BUSY','WPCLI_DYNAMIC_ACTOR_BUSY','CENTRAL_WPCLI_SLOT_BUSY_DEFERRED'):
    self.assertTrue(W.unreserved_schema_preflight_busy(JOB,'schema.readback',2,'ORIGINAL_NATIVE_313_STOPPED:'+code+'\n'))
 def test_reserved_schema_must_never_replay_as_busy(self):
  with tempfile.TemporaryDirectory() as temp,patch.object(W,'ROOT',pathlib.Path(temp)):
   root=pathlib.Path(temp);(root/'reserved').mkdir();(root/'reserved'/(JOB+'.reserved')).write_text('reserved')
   self.assertFalse(W.unreserved_schema_preflight_busy(JOB,'schema.readback',2,BUSY))
 def test_host_receipt_must_never_be_reexecuted(self):
  with tempfile.TemporaryDirectory() as temp,patch.object(W,'ROOT',pathlib.Path(temp)):
   root=pathlib.Path(temp);(root/'receipts').mkdir();(root/'receipts'/(JOB+'.json')).write_text('{}')
   self.assertFalse(W.unreserved_schema_preflight_busy(JOB,'schema.readback',2,BUSY))
 def test_other_operations_and_auth_denials_never_retryable(self):
  with tempfile.TemporaryDirectory() as temp,patch.object(W,'ROOT',pathlib.Path(temp)):
   self.assertFalse(W.unreserved_schema_preflight_busy(JOB,'plugin.install',2,BUSY))
   self.assertFalse(W.unreserved_schema_preflight_busy(JOB,'schema.readback',2,
       'ORIGINAL_NATIVE_313_STOPPED:CENTRAL_SIGNED_AUTHORITY_PREFLIGHT_REJECTED'))
   self.assertFalse(W.unreserved_schema_preflight_busy(JOB,'schema.readback',1,BUSY))
   self.assertFalse(W.unreserved_schema_preflight_busy('bad-id','schema.readback',2,BUSY))
 def test_minimum_360_second_original_start_window_unchanged(self):
  source=P.read_text()
  self.assertIn("time.time()+360:continue",source)
  self.assertIn("(job+'.reserved')",source)
if __name__=='__main__':unittest.main()
