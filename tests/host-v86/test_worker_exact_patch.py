#!/usr/bin/env python3
"""Original306 minimal-Delta CAS patch against current live-first worker."""
from __future__ import annotations
import hashlib
import importlib.util
from pathlib import Path
import unittest

ROOT=Path(__file__).resolve().parents[2]
SRC=ROOT/"ops/host-anchor-v86/patch_original306_worker.py"
spec=importlib.util.spec_from_file_location("host_v86_worker_patch",SRC)
patch=importlib.util.module_from_spec(spec)
spec.loader.exec_module(patch)

LIVE=Path('/opt/mad4b-company-transport/native-313-independent/claimed-job-worker.py')


class WorkerPatchChecks(unittest.TestCase):
    def setUp(self):
        self.assertEqual(len(patch.ORIGINAL_WORKER_SHA256),64)
        self.assertEqual(len(patch.CLASSIFIER_SHA256),64)

    def test_live_worker_exact_preimage_only(self):
        if not LIVE.is_file():
            self.skipTest("Remote enrolled VPS live worker not mounted")
        before=LIVE.read_bytes()
        if hashlib.sha256(before).hexdigest()!=patch.ORIGINAL_WORKER_SHA256:
            self.skipTest("Worker already patched or updated; CAS fallback not allowed")
        after=patch.render(before)
        self.assertNotEqual(after,before)
        self.assertEqual(hashlib.sha256(after).hexdigest(),
                         "819d2509fc5f072189f9c23ddde7116739ba2b6442950bdadf8436a08f7c7120")
        self.assertEqual(after.count(b"firstparty_host_v2_claimed_observation"),2)
        self.assertEqual(after.count(b"ORIGINAL306_HOST_V2_CLAIM_HELD"),1)
        compile(after, "original306_worker_v86_exact.py", "exec")

    def test_changed_live_preimage_must_fail(self):
        with self.assertRaisesRegex(ValueError,"ORIGINAL306_LIVE_SOURCE_PREIMAGE_CHANGED"):
            patch.render(b"#!/usr/bin/env python3\n")

    def test_handler_does_not_create_original312_job_or_receipt(self):
        txt=patch.READ_ONLY_ADAPTER
        self.assertNotIn("sign_existing_original_306_claim(",txt)
        self.assertNotIn("original_signer(",txt)
        self.assertNotIn("add_option(",txt)
        self.assertNotIn("update_option(",txt)
        self.assertNotIn("file.write_text(",txt)
        self.assertIn("resolve_remote_host_v2_claim",txt)
        self.assertIn("execution_authorized",txt)
        self.assertIn("original313_certified",txt)

    def test_only_one_existing_remote_claimed_branch_modified(self):
        self.assertEqual(patch.CLAIMED_BRANCH.count("continue"),1)
        self.assertIn("if host_v2_observation is not None",patch.CLAIMED_BRANCH)
        self.assertIn("return None",patch.READ_ONLY_ADAPTER)

    def test_classifier_is_pinned_to_v86_exact_source(self):
        orig=ROOT/"ops/host-anchor-v86/original306_typed_claim.py"
        self.assertEqual(hashlib.sha256(orig.read_bytes()).hexdigest(),
                         patch.CLASSIFIER_SHA256)


if __name__=="__main__":
    unittest.main()
