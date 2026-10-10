#!/usr/bin/env python3
"""Negative policy tests for observed, non-signing standalone native evidence."""
import tempfile
from pathlib import Path
import unittest

import mad4b_standalone_evidence as native
import mad4b_standalone_runner_receipt as signer
import json
import hashlib
import zipfile


class EvidencePolicyTests(unittest.TestCase):
    def test_states_fail_closed(self):
        self.assertEqual(native.status_from_checks([]), "BLOCKED")
        self.assertEqual(native.status_from_checks([{"state": "BLOCKED"}]), "BLOCKED")
        self.assertEqual(native.status_from_checks([{"state": "PASS"}, {"state": "FAIL"}]), "FAIL")
        self.assertEqual(native.status_from_checks([{"state": "FAIL"}, {"state": "BLOCKED"}]), "FAIL")
        self.assertEqual(native.status_from_checks([{"state": "PASS"}]), "PASS")

    def test_missing_source_cannot_yield_pass(self):
        with tempfile.TemporaryDirectory() as d:
            root = Path(d)
            results, evidence = native.evidence(root, "a" * 40,
                root / "missing.zip", root / "receipt.json", "php")
            self.assertEqual(set(results), set(native.GATES))
            self.assertFalse(any(x == "PASS" for x in results.values()))
            self.assertFalse(evidence["external_owner_review_required"] is False)
            self.assertFalse(evidence["github_ci_certified"])
            self.assertFalse(evidence["production_authorized"])

    def test_no_signature_or_deployment_capability(self):
        self.assertEqual(native.CONTRACT, "mad4b.standalone-native-evidence.v1")
        self.assertEqual(len(native.GATES), 5)
        self.assertFalse(hasattr(native, "sign_release"))
        self.assertFalse(hasattr(native, "deploy_to_wordpress"))



class RunnerReceiptTests(unittest.TestCase):
    def setUp(self):
        now = 1800000000
        self.now = now
        self.head = "a" * 40
        self.payload = {
            "contract": "mad4b.standalone-build-control.v1.job.v1",
            "expected_head": self.head, "plan_sha256": "b" * 64,
            "profile": "build-only", "site_uuid": "site-1",
            "profile_digest": "profile-1", "origin": "https://staging.example.test",
        }
        self.claim = {"job": {
            "job_id": "11111111-1111-4111-8111-111111111111",
            "operation_id": "standalone_source_build", "status": "claimed",
            "executor_id": "runner_staging_01", "claim_generation": 3,
            "lease_expires_at_epoch": now + 600, "provider_checkpoint": "provider_returned",
            "cancel_requested_at": "", "payload": self.payload,
        }}
        self.policy = {
            "contract": "mad4b.standalone-build-runner-enrollment.v1",
            "environment": "staging", "owner_approval": "EXACT STAGING SOURCE BUILD",
            "executor_id": "runner_staging_01", "allowed_source_sha": self.head,
            "profile": "build-only", "site_uuid": "site-1",
            "profile_digest": "profile-1", "origin": "https://staging.example.test",
        }
        self.report = {"source_commit_sha": self.head, "profile": "build-only",
            "build_state": "BUILT_UNVERIFIED", "production_authorized": False,
            "archive_sha256": "c" * 64, "build_fingerprint": "d" * 64,
            "package_manifest_digest": "e" * 64}

    def test_exact_fenced_receipt_fields(self):
        c = signer.make_claims(self.claim, self.policy, self.report, self.now)
        self.assertEqual(c["claim_generation"], 3)
        self.assertEqual(c["job_id"], self.claim["job"]["job_id"])
        self.assertEqual(c["target_source_sha"], self.head)
        self.assertFalse(c["production_authorized"])
        self.assertEqual(c["build_state"], "BUILT_UNVERIFIED")
        self.assertEqual(c["expires_at_epoch"], self.now + 300)
        self.assertNotIn("lease_token", c)

    def test_receipt_rejects_cancel_and_expired_lease(self):
        self.claim["job"]["cancel_requested_at"] = "2026-10-11T00:00:00Z"
        with self.assertRaisesRegex(signer.Refusal, "active_finished_lease_required"):
            signer.make_claims(self.claim, self.policy, self.report, self.now)
        self.claim["job"]["cancel_requested_at"] = ""
        self.claim["job"]["lease_expires_at_epoch"] = self.now - 1
        with self.assertRaisesRegex(signer.Refusal, "active_finished_lease_required"):
            signer.make_claims(self.claim, self.policy, self.report, self.now)

    def test_receipt_rejects_wrong_site_and_head(self):
        self.policy["origin"] = "https://wrong.example.test"
        with self.assertRaisesRegex(signer.Refusal, "site_enrollment_mismatch"):
            signer.make_claims(self.claim, self.policy, self.report, self.now)
        self.policy["origin"] = self.payload["origin"]
        self.policy["allowed_source_sha"] = "f" * 40
        with self.assertRaisesRegex(signer.Refusal, "unapproved_source_or_profile"):
            signer.make_claims(self.claim, self.policy, self.report, self.now)

    def test_receipt_rejects_production_and_fake_result(self):
        self.policy["environment"] = "production"
        with self.assertRaisesRegex(signer.Refusal, "trusted_runner_policy_mismatch"):
            signer.make_claims(self.claim, self.policy, self.report, self.now)
        self.policy["environment"] = "staging"
        self.report["build_state"] = "SIGNED_RELEASE"
        with self.assertRaisesRegex(signer.Refusal, "build_report_untrusted"):
            signer.make_claims(self.claim, self.policy, self.report, self.now)

    def test_archive_hash_manifest_verified_before_signing(self):
        with tempfile.TemporaryDirectory() as tmp:
            out = Path(tmp)
            file_name = "includes/test.php"
            raw = b"<?php return true;\n"
            digest = hashlib.sha256(raw).hexdigest()
            manifest = (file_name + chr(0) + str(len(raw)) + chr(0) + digest + "\n").encode()
            msha = hashlib.sha256(manifest).hexdigest()
            provenance = {"package_files": [{"path": file_name,
                "sha256": digest, "bytes": len(raw)}],
                "build_fingerprint": "d" * 64}
            archive = out / "sample.zip"
            import stat
            with zipfile.ZipFile(archive, "w") as z:
                for name, data in (
                    ("mad4b-site-control-plane/" + file_name, raw),
                    ("mad4b-site-control-plane/MAD4B-BUILD-PROVENANCE.json",
                     json.dumps(provenance).encode()),
                ):
                    item = zipfile.ZipInfo(name)
                    item.compress_type = zipfile.ZIP_STORED
                    item.external_attr = (stat.S_IFREG | 0o644) << 16
                    z.writestr(item, data)
            self.report["archive_sha256"] = hashlib.sha256(archive.read_bytes()).hexdigest()
            self.report["package_manifest_digest"] = msha
            canonical = {"archive_sha256": self.report["archive_sha256"],
                "build_fingerprint": self.report["build_fingerprint"],
                "package_manifest_digest": msha, "archive_file_count": 2,
                "manifest_file_count": 1}
            signer.verify_archive(archive, canonical, self.report)
            canonical["archive_sha256"] = "f" * 64
            with self.assertRaisesRegex(signer.Refusal, "archive_digest_mismatch"):
                signer.verify_archive(archive, canonical, self.report)

if __name__ == "__main__":
    unittest.main()
