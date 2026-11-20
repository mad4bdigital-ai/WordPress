#!/usr/bin/env python3
"""Patch does not operate until first-party Root release is commissioned."""
from pathlib import Path
import hashlib,importlib.util,unittest
from unittest.mock import patch
ROOT=Path(__file__).resolve().parents[2]
SRC=ROOT/"ops/host-anchor-v118/patch_original306_worker.py"
spec=importlib.util.spec_from_file_location("v118_patch_only",SRC)
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
WORKER=Path("/opt/mad4b-company-transport/native-313-independent/claimed-job-worker.py")
BASE=Path("/opt/mad4b-company-provisioning/reports/host-v121-staging-root-install-20261119/rollback/0-claimed-job-worker.py")
def original_source():
 if WORKER.is_file() and hashlib.sha256(WORKER.read_bytes()).hexdigest()=="35947c92db959f4ed0a1416b96b10bd65e7cbb3dd4baf8d6af3f303e6ceef5b3":
  return WORKER.read_text()
 if BASE.is_file() and hashlib.sha256(BASE.read_bytes()).hexdigest()=="35947c92db959f4ed0a1416b96b10bd65e7cbb3dd4baf8d6af3f303e6ceef5b3":
  return BASE.read_text()
 raise unittest.SkipTest("Original306 exact pre-v121 source unavailable")

SOURCE=ROOT/"ops/host-anchor-v118/queued_original_capture.py"

class GuardedHook(unittest.TestCase):
 def test_exact_installed_worker_target_only(self):
  before=original_source()
  after=m.patch_text(before)
  self.assertIn("if not firstparty_host_v118_capture_original_queued(job):",after)
  self.assertIn("if first_anchor_scope and firstparty_host_v95_first_claim_only(actor,entry):",after)
  self.assertEqual(after.count("HOST_V118_QUEUED_CAPTURE_HELD:"),1)
  self.assertIn(m.MODULE_SHA,after)
  self.assertEqual(hashlib.sha256(SOURCE.read_bytes()).hexdigest(),m.MODULE_SHA)
  self.assertEqual(before.count("return sign_existing_original_306_claim(job,entry)"),
                   after.count("return sign_existing_original_306_claim(job,entry)"))
  self.assertEqual(before.count("def claim_authorized_job(actor):"),
                   after.count("def claim_authorized_job(actor):"))
  compile(after,"v118-patched-worker-candidate","exec")
 def test_idempotence_requires_reconciliation(self):
  after=m.patch_text(original_source())
  with self.assertRaisesRegex(ValueError,"ALREADY_PATCHED"):
   m.patch_text(after)
 def test_old_or_missing_exact_v95_scope_rejected(self):
  source=original_source().replace(
   "if first_anchor_scope and firstparty_host_v95_first_claim_only(actor,entry):",
   "if firstparty_host_v95_first_claim_only(actor,entry):")
  with self.assertRaisesRegex(ValueError,"EXACT_V95"):
   m.patch_text(source)
 def test_no_live_install_or_invocation(self):
  self.assertEqual(m.main(),2)
  self.assertNotIn("os.replace",SRC.read_text())
  self.assertNotIn("subprocess.run",SRC.read_text())
  self.assertNotIn("wp option update",SRC.read_text())
if __name__=="__main__":
 unittest.main(verbosity=2)
