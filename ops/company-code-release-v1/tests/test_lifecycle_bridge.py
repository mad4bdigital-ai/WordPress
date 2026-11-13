import ast
import importlib.util
import pathlib
import tempfile
import unittest
from unittest.mock import patch

ROOT=pathlib.Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location("lifecycle_bridge",ROOT/"company_lifecycle_broker.py")
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)


class OriginalLifecycleBridgeTests(unittest.TestCase):
    def setUp(self):
        self.enroll={
            "contract":"mad4b.company-native-wpcli-enrollment.v1",
            "state":"ORIGINAL_305_PERSISTENT_PAIR_ENROLLED",
            "company":"wayfatours","environment":"production",
            "site_uuid":"a3a7cc36-db7e-4a14-8456-b41857def05c","host_authority_verified":True,
            "full_wpcli_active":True,"persistent_grant_present":True,
            "execution_performed":False,
        }
        self.verify={
            "contract":"mad4b.broker-exact-ssh-authority.v1",
            "verifier_registered":True,
            "remote_execution_authority_automatically_granted":False,
            "shell_executed":False,
        }
        self.dispatch={"dispatch_registered":False}
    def report(self,ops=("read","plugin.install")):
        return m.reconcile(
            self.enroll,self.verify,self.dispatch,set(ops),
            company="wayfatours",environment="production",
            site_uuid="a3a7cc36-db7e-4a14-8456-b41857def05c",
            origin="https://wayfatours.com",
        )
    def test_standing_305_not_equivalent_to_code_release_authority(self):
        x=self.report()
        self.assertTrue(x["original_305_standing_wpcli_verified"])
        self.assertTrue(x["original_312_verifier_registered"])
        self.assertFalse(x["code_release_execution_allowed"])
        self.assertFalse(x["production_mutated"])
        self.assertFalse(x["legacy_staging_ssh_installation_is_authoritative"])
        self.assertIn("ORIGINAL_313_COMPANY_CODE_RELEASE_HANDLER_NOT_REGISTERED",x["blockers"])
    def test_readiness_does_not_mint_dispatch(self):
        x=self.report()
        self.assertFalse(x["company_code_release_dispatch_ability_registered"])
        self.assertIn("COMPANY_CODE_RELEASE_DISPATCH_ABILITY_NOT_REGISTERED",x["blockers"])
    def test_live_dynamic_registry_is_distinguished_from_legacy_handler(self):
        x=m.reconcile(
            self.enroll,self.verify,self.dispatch,{"read","plugin.install"},
            company="wayfatours",environment="production",
            site_uuid="a3a7cc36-db7e-4a14-8456-b41857def05c",
            origin="https://wayfatours.com",
            dynamic={
                "dynamic_registry_enrolled":True,
                "dynamic_scope_plan_matched":True,
                "dynamic_306_digest_registered":True,
                "dynamic_313_independent_verifier_registered":True,
                "dynamic_313_finalizer_connected":False,
            })
        self.assertTrue(x["dynamic_306_digest_registered"])
        self.assertTrue(x["dynamic_313_independent_verifier_registered"])
        self.assertFalse(x["dynamic_313_finalizer_connected"])
        self.assertEqual(x["state"],"HOLD_DYNAMIC_ORIGINAL_313_FINALIZER")
        self.assertFalse(x["code_release_execution_allowed"])
        self.assertIn("DYNAMIC_313_ORIGINAL_FINALIZER_NOT_CONNECTED",
                      x["blockers"])
        self.assertNotIn("ORIGINAL_313_COMPANY_CODE_RELEASE_HANDLER_NOT_REGISTERED",
                         x["blockers"])

    def test_305_wrong_uuid_denied(self):
        self.enroll["site_uuid"]="00000000-0000-0000-0000-000000000000"
        self.assertFalse(self.report()["original_305_standing_wpcli_verified"])
    def test_305_wrong_environment_denied(self):
        self.enroll["environment"]="staging"
        self.assertFalse(self.report()["original_305_standing_wpcli_verified"])
    def test_305_standing_disabled_denied(self):
        self.enroll["full_wpcli_active"]=False
        self.assertFalse(self.report()["original_305_standing_wpcli_verified"])
    def test_312_must_be_original_no_auto_grant(self):
        self.verify["remote_execution_authority_automatically_granted"]=True
        self.assertFalse(self.report()["original_312_verifier_registered"])
    def test_even_operation_and_dispatch_presence_require_real_job_and_receipt(self):
        self.dispatch={"dispatch_registered":True}
        x=self.report(("read","plugin.install",m.RELEASE_OPERATION))
        self.assertTrue(x["original_313_code_release_handler_registered"])
        self.assertTrue(x["company_code_release_dispatch_ability_registered"])
        self.assertFalse(x["code_release_execution_allowed"])
        self.assertFalse(x["original_signed_job_grant_verified"])
    def test_company_scope_mismatch_is_not_verified(self):
        self.enroll["company"] = "another-company"
        self.assertFalse(self.report()["original_305_standing_wpcli_verified"])

    def test_qa_company_status_command_is_accepted(self):
        command=m.command305("wayfatours","qa")
        self.assertIn("--environment=qa",command)
    def test_shell_text_in_environment_rejected(self):
        with self.assertRaises(ValueError):
            m.command305("wayfatours","qa;echo unsafe")

    def test_operation_scope_command_input_is_bounded(self):
        with self.assertRaises(ValueError):
            m.command305("wayfatours;touch /tmp/pwned","production")
        with self.assertRaises(ValueError):
            m.command_dispatch("container;echo unsafe")
        command=m.command305("wayfatours","production")
        self.assertIn("--company=wayfatours",command)

    def test_live_reconcile_preserves_verified_status_when_305_readback_fails(self):
        target={"contract":"mad4b.faore.native-operator-dynamic-target.v1",
                "target_container":"one-container",
                "site_uuid":"a3a7cc36-db7e-4a14-8456-b41857def05c",
                "canonical_origin":"https://wayfatours.com",
                "read_only":True,"authorization_created":False,
                "native_execution_performed":False}
        reads=[RuntimeError("sensitive token must never be displayed"),
               self.verify,{"dispatch_registered":True}]
        with (patch.object(m,"resolve_original_target",return_value=target),
              patch.object(m,"call_json",side_effect=reads),
              patch.object(m,"original_handler_operations",
                           return_value={"company.code.release"}),
              patch.object(m,"dynamic_status",return_value={})):
            report=m.live_reconcile("wayfatours","production")
        self.assertEqual(report["state"],"HOLD_INCOMPLETE_ORIGINAL_READBACK")
        self.assertEqual(report["independent_probe_results"]["original305_scope"],
                         "FAILED")
        self.assertEqual(report["independent_probe_results"]["original312_status"],
                         "VERIFIED")
        self.assertTrue(report["original_312_verifier_registered"])
        self.assertFalse(report["original_305_standing_wpcli_verified"])
        self.assertFalse(report["code_release_execution_allowed"])
        self.assertNotIn("sensitive token",str(report))

    def test_live_reconcile_failed_dynamic_probe_is_not_registered(self):
        target={"target_container":"one-container",
                "site_uuid":"a3a7cc36-db7e-4a14-8456-b41857def05c",
                "canonical_origin":"https://wayfatours.com"}
        with (patch.object(m,"resolve_original_target",return_value=target),
              patch.object(m,"call_json",side_effect=[
                  self.enroll,self.verify,{"dispatch_registered":True}]),
              patch.object(m,"original_handler_operations",
                           return_value={"company.code.release"}),
              patch.object(m,"dynamic_status",
                           side_effect=RuntimeError("internal private path"))):
            report=m.live_reconcile("wayfatours","production")
        self.assertEqual(report["state"],"HOLD_INCOMPLETE_ORIGINAL_READBACK")
        self.assertEqual(report["independent_probe_results"][
            "dynamic306_313_registry"],"FAILED")
        self.assertFalse(report["dynamic_306_digest_registered"])
        self.assertFalse(report["code_release_execution_allowed"])
        self.assertNotIn("internal private path",str(report))

    def test_status_timeout_is_bounded_and_not_an_execution_gate(self):
        from subprocess import TimeoutExpired
        with patch.object(m.subprocess,"run",
                          side_effect=TimeoutExpired(["docker","exec"],15)) as fake:
            with self.assertRaises(TimeoutExpired):
                m.call_json(["docker","exec","test"])
            self.assertEqual(fake.call_args.kwargs["timeout"],15)
        with self.assertRaisesRegex(ValueError,"STATUS_TIMEOUT_INVALID"):
            m.call_json(["docker","exec","test"],status_timeout_seconds=500)

    def test_original_status_must_be_object_and_never_echo_stdout(self):
        from types import SimpleNamespace
        with patch.object(m.subprocess,"run",return_value=SimpleNamespace(
            returncode=0,stdout='["token"]',stderr="sensitive_value")):
            with self.assertRaisesRegex(RuntimeError,"ORIGINAL_READ_STATUS_NOT_OBJECT"):
                m.call_json(["docker","exec","test"])

    def test_single_probe_status_does_not_infer_release_authority(self):
        target={"target_container":"test-container",
                "site_uuid":"a3a7cc36-db7e-4a14-8456-b41857def05c"}
        with (patch.object(m,"resolve_original_target",return_value=target),
              patch.object(m,"call_json",return_value=self.verify)):
            report=m.live_probe("wayfatours","production","original312_status")
        self.assertEqual(report["state"],"READBACK_OBSERVED_NOT_RELEASE_AUTHORITY")
        self.assertTrue(report["observed_true"])
        self.assertFalse(report["release_execution_allowed"])
        self.assertFalse(report["production_mutated"])

    def test_single_probe_timeout_fails_closed_without_leaking_message(self):
        from subprocess import TimeoutExpired
        target={"target_container":"test-container",
                "site_uuid":"a3a7cc36-db7e-4a14-8456-b41857def05c"}
        with (patch.object(m,"resolve_original_target",return_value=target),
              patch.object(m,"call_json",
                           side_effect=TimeoutExpired(["docker","exec"],15))):
            report=m.live_probe("wayfatours","production","original312_status")
        self.assertEqual(report["state"],"HOLD_ORIGINAL_STATUS_PROBE_UNAVAILABLE")
        self.assertEqual(report["probe_error_type"],"TimeoutExpired")
        self.assertFalse(report["observed_true"])
        self.assertFalse(report["release_execution_allowed"])
        self.assertNotIn("docker",str(report))

    def test_single_probe_is_not_a_static_provider_allowlist(self):
        with self.assertRaisesRegex(ValueError,"UNKNOWN_ORIGINAL_STATUS_PROBE"):
            m.live_probe("wayfatours","production","source.code.release")

    def test_original_handler_ast_enumeration(self):
        with tempfile.TemporaryDirectory() as temp:
            f=pathlib.Path(temp)/"handler.py"
            f.write_text("p.add_argument('--operation',choices=['read','plugin.install'])")
            self.assertEqual(m.original_handler_operations(f),{"read","plugin.install"})
            f.write_text("p.add_argument('--other',choices=['code.release'])")
            with self.assertRaises(RuntimeError):
                m.original_handler_operations(f)


if __name__=="__main__":
    unittest.main()
