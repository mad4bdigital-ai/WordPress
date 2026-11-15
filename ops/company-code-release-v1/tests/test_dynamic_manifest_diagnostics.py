"""Read-only status classification for enrolled dynamic Provider vs per-Job plan."""
from __future__ import annotations

import importlib.util
from pathlib import Path
from unittest.mock import patch
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location("company_lifecycle_broker_diag", ROOT/"company_lifecycle_broker.py")
broker = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(broker)
SPEC2 = importlib.util.spec_from_file_location("company_code_release_diag", ROOT/"company_code_release.py")
release = importlib.util.module_from_spec(SPEC2)
SPEC2.loader.exec_module(release)


class DynamicManifestDiagnostics(unittest.TestCase):
    def setUp(self):
        self.enroll={
            "contract":"mad4b.company-native-wpcli-enrollment.v1",
            "state":"ORIGINAL_305_PERSISTENT_PAIR_ENROLLED",
            "company":"wayfatours","environment":"production",
            "site_uuid":"a3a7cc36-db7e-4a14-8456-b41857def05c",
            "host_authority_verified":True,
            "full_wpcli_active":True,
            "persistent_grant_present":True,
            "execution_performed":False,
        }
        self.verify={
            "contract":"mad4b.broker-exact-ssh-authority.v1",
            "verifier_registered":True,
            "remote_execution_authority_automatically_granted":False,
            "shell_executed":False
        }
        self.dynamic={
            "dynamic_registry_enrolled":True,
            "dynamic_provider_manifest_registered":True,
            "dynamic_scope_plan_matched":False,
            "dynamic_306_digest_registered":False,
            "dynamic_313_independent_verifier_registered":False,
            "dynamic_313_finalizer_connected":False,
            "central_313_status_observed":True,
            "central_313_independent_verifier_registered":True,
            "central_313_finalizer_configured":True,
            "central_313_operational_ready":True,
        }

    def check(self, dynamic):
        return broker.reconcile(
            self.enroll,self.verify,{"dispatch_registered":False},
            {"read","plugin.install"},
            company="wayfatours",environment="production",
            site_uuid=self.enroll["site_uuid"],
            origin="https://wayfatours.com",dynamic=dynamic
        )

    def test_verified_provider_without_exact_signed_plan_is_distinct_hold(self):
        result=self.check(self.dynamic)
        self.assertEqual(result["state"],"HOLD_EXACT_DYNAMIC_PLAN_ENROLLMENT")
        self.assertTrue(result["dynamic_provider_manifest_registered"])
        self.assertFalse(result["dynamic_scope_plan_matched"])
        self.assertTrue(result["central_313_operational_ready"])
        self.assertIn("EXACT_DYNAMIC_CODE_RELEASE_PLAN_NOT_ENROLLED",result["blockers"])
        self.assertNotIn("ORIGINAL_313_COMPANY_CODE_RELEASE_HANDLER_NOT_REGISTERED",result["blockers"])
        self.assertNotIn("COMPANY_CODE_RELEASE_DISPATCH_ABILITY_NOT_REGISTERED",result["blockers"])
        self.assertFalse(result["release_route_registered"])
        self.assertFalse(result["code_release_execution_allowed"])
        self.assertFalse(result["original_signed_job_grant_verified"])
        self.assertFalse(result["original_313_release_receipt_verified"])
        self.assertFalse(result["production_mutated"])

    def test_claimed_provider_without_verified_registry_is_rejected(self):
        raw={**self.dynamic,"dynamic_registry_enrolled":False}
        result=self.check(raw)
        self.assertFalse(result["dynamic_provider_manifest_registered"])
        self.assertIn("ORIGINAL_313_COMPANY_CODE_RELEASE_HANDLER_NOT_REGISTERED",result["blockers"])
        self.assertIn("COMPANY_CODE_RELEASE_DISPATCH_ABILITY_NOT_REGISTERED",result["blockers"])
        self.assertFalse(result["code_release_execution_allowed"])

    def test_enrolled_plan_without_original_digest_is_still_hold(self):
        raw={**self.dynamic,"dynamic_scope_plan_matched":True}
        result=self.check(raw)
        self.assertEqual(result["state"],"READY_FOR_ORIGINAL_HANDLER_ENROLLMENT")
        self.assertIn("ORIGINAL_306_DYNAMIC_CODE_RELEASE_DIGEST_NOT_VERIFIED",result["blockers"])
        self.assertFalse(result["release_route_registered"])
        self.assertFalse(result["code_release_execution_allowed"])

    def test_ready_dynamic_route_still_not_an_individual_grant(self):
        raw={**self.dynamic,
             "dynamic_scope_plan_matched":True,
             "dynamic_306_digest_registered":True,
             "dynamic_313_independent_verifier_registered":True,
             "dynamic_313_finalizer_connected":True}
        result=self.check(raw)
        self.assertEqual(result["state"],"HOLD_EXACT_SIGNED_JOB_AND_RELEASE_RECEIPT")
        self.assertTrue(result["release_route_registered"])
        self.assertFalse(result["code_release_execution_allowed"])
        self.assertFalse(result["original_signed_job_grant_verified"])

    def test_registered_legacy_plan_schema_remains_a_hold(self):
        old={**self.dynamic,
             "dynamic_scope_plan_matched":True,
             "dynamic_scope_legacy_plan_schema":True,
             "dynamic_306_digest_registered":True,
             "dynamic_313_independent_verifier_registered":True,
             "dynamic_313_finalizer_connected":True}
        report=self.check(old)
        self.assertEqual(report["state"],"HOLD_REGISTERED_LEGACY_PLAN_SCHEMA")
        self.assertTrue(report["dynamic_scope_plan_matched"])
        self.assertTrue(report["dynamic_306_digest_registered"])
        self.assertTrue(report["dynamic_scope_legacy_plan_schema"])
        self.assertTrue(report["central_313_operational_ready"])
        self.assertIn("REGISTERED_DYNAMIC_PLAN_MISSING_REQUIRED_SNAPSHOTS",
                      report["blockers"])
        self.assertFalse(report["original_signed_job_grant_verified"])
        self.assertFalse(report["original_313_release_receipt_verified"])
        self.assertFalse(report["code_release_execution_allowed"])
        self.assertFalse(report["production_mutated"])

    def test_doctor_exposes_legacy_schema_without_reopening_execution(self):
        row=self.check({**self.dynamic,
            "dynamic_scope_plan_matched":True,
            "dynamic_scope_legacy_plan_schema":True,
            "dynamic_306_digest_registered":True,
            "dynamic_313_independent_verifier_registered":True,
            "dynamic_313_finalizer_connected":True})
        fake={
            "company":"wayfatours","environment":"production",
            "site_uuid":self.enroll["site_uuid"],"git_commit":"a"*40,
            "plan_sha256":"b"*64,"target_root":"/private/test",
        }
        with tempfile.TemporaryDirectory() as temp:
            with patch.object(release,"confirm_plan"):
                result=release.release_admission_doctor(
                    fake,{},fixed_policy_path=Path(temp)/"absent",
                    runtime_check=lambda a,b:None,
                    original_broker_status=row
                )
        self.assertEqual(result["state"],"HOLD_REGISTERED_LEGACY_PLAN_SCHEMA")
        self.assertTrue(result["git_plan_verified"])
        self.assertTrue(result["dynamic_scope_legacy_plan_schema"])
        self.assertFalse(result["production_execution_authorized"])
        self.assertFalse(result["production_mutated"])

    def test_doctor_surfaces_new_status_without_authorizing(self):
        row=self.check(self.dynamic)
        with tempfile.TemporaryDirectory() as temp:
            fake={
                "company":"wayfatours",
                "environment":"production",
                "site_uuid":self.enroll["site_uuid"],
                "git_commit":"a"*40,
                "plan_sha256":"b"*64,
                "target_root":"/private/test",
            }
            with patch.object(release,"confirm_plan") as cp:
                with patch.object(release,"target_binding",return_value={
                    "site_uuid":self.enroll["site_uuid"],"root":fake["target_root"]
                }):
                    result=release.release_admission_doctor(
                        fake,{},fixed_policy_path=Path(temp)/"absent",
                        runtime_check=lambda a,b:None,
                        original_broker_status=row
                    )
                cp.assert_called()
        self.assertEqual(result["state"],"HOLD_EXACT_DYNAMIC_PLAN_ENROLLMENT")
        self.assertTrue(result["git_plan_verified"])
        self.assertTrue(result["original_runtime_binding_verified"])
        self.assertTrue(result["dynamic_provider_manifest_registered"])
        self.assertFalse(result["dynamic_scope_plan_matched"])
        self.assertTrue(result["central_313_operational_ready"])
        self.assertFalse(result["production_execution_authorized"])
        self.assertFalse(result["production_mutated"])


if __name__=="__main__":
    unittest.main()
