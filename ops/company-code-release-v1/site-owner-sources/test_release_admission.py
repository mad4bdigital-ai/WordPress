#!/usr/bin/env python3
"""Source-only test: original release policy remains the authority."""
from __future__ import annotations

import copy
import hashlib
import json
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

import release_admission as admission
import verify

ROOT = Path(__file__).resolve().parents[3]
SITE = "wayfatours"
COMPANY = "wayfatours"
ENV = "production"


class AdmissionContractTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.sha = subprocess.check_output(
            ["git", "-C", str(ROOT), "rev-parse", "HEAD"],
            text=True,
        ).strip()

    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        root = Path(self.tmp.name) / "tenant"
        self.root = root
        registry = json.loads((ROOT / verify.REGISTRY).read_text())
        self.variants = [
            item for cap in registry["capabilities"]
            for item in cap["variants"]
            if item["site"] == SITE and item["environment"] == ENV
        ]
        for item in self.variants:
            target = root / item["runtime_path"]
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(ROOT / item["source_artifact"], target)
        self.policy = {
            "contract": admission.release.POLICY_CONTRACT,
            "source_git_root": str(ROOT),
            "targets": {COMPANY + ":" + ENV: {
                "root": str(root),
                "site_uuid": "11111111-2222-3333-4444-555555555555",
                "container": "mock-company-php",
                "origin": "https://wayfatours.com",
                "allowed_targets": ["wp-content/mu-plugins/mad4b-other-allowed.php"],
            }}
        }

    def assess(self):
        return admission.assess(
            repo=ROOT, commit=self.sha, site=SITE, company=COMPANY,
            environment=ENV, policy=self.policy)

    def test_registered_source_and_runtime_without_grant_hold(self):
        row = self.assess()
        self.assertEqual(row["source_committed_variants_verified"], 2)
        self.assertEqual(row["runtime_preimages_verified"], 2)
        self.assertFalse(row["release_execution_allowed"])
        self.assertFalse(row["broker_grant_issued"])
        self.assertFalse(row["production_mutated"])
        self.assertEqual(row["state"], "HOLD_SIGNED_ORIGINAL_RELEASE_ADMISSION")
        self.assertIn("COMPONENT_TARGET_ENROLLMENT_INCOMPLETE", row["blockers"])

    def test_not_enrolled_company_stays_hold(self):
        self.policy["targets"].clear()
        row = self.assess()
        self.assertEqual(row["state"], "HOLD_ORIGINAL_TARGET_NOT_ENROLLED")
        self.assertFalse(row["release_execution_allowed"])

    def test_catalog_ownership_alone_never_grants_release(self):
        self.policy["targets"][COMPANY + ":" + ENV]["allowed_targets"] = [
            v["runtime_path"] for v in self.variants]
        row = self.assess()
        self.assertTrue(all(x["original_target_allowed"] for x in row["targets"]))
        self.assertFalse(row["release_execution_allowed"])
        self.assertIn("EXACT_ORIGINAL_312_JOB_GRANT_NOT_PROVIDED", row["blockers"])
        activation = next(x for x in row["targets"] if x["capability"] == "destination_activation")
        self.assertFalse(activation["original_target_path_supported"])

    def test_runtime_drift_halts_before_admission(self):
        first = self.variants[0]
        (self.root / first["runtime_path"]).write_text("<?php // drift\n")
        with self.assertRaisesRegex(verify.InvalidProvenance, "LIVE_OWNER_BYTES_DRIFT"):
            self.assess()

    def test_verified_original_runtime_binding_is_observation_only(self):
        entry = self.policy["targets"][COMPANY + ":" + ENV]
        def observed(company, environment):
            return {
                "contract": "mad4b.faore.native-operator-dynamic-target.v1",
                "company_id": company, "environment": environment,
                "site_uuid": entry["site_uuid"],
                "canonical_origin": entry["origin"],
                "target_container": entry["container"],
                "read_only": True,
                "authorization_created": False,
                "native_execution_performed": False,
            }
        row = admission.assess(repo=ROOT, commit=self.sha, site=SITE,
                               company=COMPANY, environment=ENV,
                               policy=self.policy, original_identity_reader=observed)
        self.assertTrue(row["original_native_site_identity_verified"])
        self.assertFalse(row["release_execution_allowed"])

    def test_original_site_identity_mismatch_stops_admission(self):
        def spoofed(company, environment):
            return {
                "contract": "mad4b.faore.native-operator-dynamic-target.v1",
                "company_id": company, "environment": environment,
                "site_uuid": "00000000-0000-0000-0000-000000000000",
                "canonical_origin": "https://other-site.invalid",
                "target_container": "mock-company-php",
                "read_only": True,
                "authorization_created": False,
                "native_execution_performed": False,
            }
        row = admission.assess(repo=ROOT, commit=self.sha, site=SITE,
                               company=COMPANY, environment=ENV,
                               policy=self.policy, original_identity_reader=spoofed)
        self.assertFalse(row["original_native_site_identity_verified"])
        self.assertIn("ORIGINAL_NATIVE_SITE_IDENTITY_NOT_VERIFIED", row["blockers"])
        self.assertFalse(row["release_execution_allowed"])

    def test_unverified_git_sha_denied(self):
        with self.assertRaisesRegex(ValueError, "INVALID_COMMIT_SHA"):
            admission.source_at_commit(ROOT, "abc", self.variants[0]["source_artifact"])

    def test_unsupported_registry_site_is_denied(self):
        with self.assertRaisesRegex(verify.InvalidProvenance,
                                    "SITE_ENVIRONMENT_NOT_ENROLLED"):
            admission.assess(repo=ROOT, commit=self.sha, site="unregistered",
                             company=COMPANY, environment=ENV, policy=self.policy)


if __name__ == "__main__":
    unittest.main()
