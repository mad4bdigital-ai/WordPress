"""Original 313 candidate handler: fail-closed, no live enrollment or receipt."""
import base64
import hashlib
import importlib.util
import json
import shutil
from pathlib import Path
from cryptography.hazmat.primitives import serialization
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey
import sys
import tempfile
import unittest
from unittest import mock

HERE = Path(__file__).resolve().parents[1]
sys.path.insert(0,str(HERE))
import original_313_code_release_contract as original
import original_313_host_handler as handler
from test_original_313_contract import plan as fixture_plan


class HostHandlerTests(unittest.TestCase):
    def setUp(self):
        self.plan=fixture_plan()
        self.job_id="a"*32
        self.proof={"contract":"mad4b.broker-exact-ssh-authority.v1",
             "verified":True,"eligible":True,"owner_enrolled_wpcli":True,
             "central_scope_only":True,"shell_executed":False,
             "job_id":self.job_id,"target_id":"srv2030908",
             "company":self.plan["company"],"environment":self.plan["environment"],
             "site_uuid":self.plan["site_uuid"],"origin":self.plan["origin"],
             "command_sha256":original.command_sha256(self.plan["plan_sha256"]),
             "operation":"company.remote.wpcli.full","target_scope":"wordpress",
             "expires_epoch":10**11}
        self.scope={"contract":"mad4b.company-code-release.lifecycle-bridge.v1",
             "company":self.plan["company"],"environment":self.plan["environment"],
             "site_uuid":self.plan["site_uuid"],
             "canonical_origin":self.plan["origin"],
             "original_305_standing_wpcli_verified":True,
             "original_312_verifier_registered":True,
             "original_313_code_release_handler_registered":True,
             "company_code_release_dispatch_ability_registered":True,
             "dynamic_registry_enrolled":False,
             "dynamic_306_digest_registered":False,
             "dynamic_313_independent_verifier_registered":False,
             "dynamic_313_finalizer_connected":False}

    def test_root_enrolled_dynamic_operation_replaces_static_worker_listing(self):
        with tempfile.TemporaryDirectory() as td:
            root=Path(td)
            scripts=root/"providers"/"release"
            scripts.mkdir(parents=True)
            src=HERE/"original_313_host_handler.py"
            installed=scripts/"handler.py"
            shutil.copy2(src,installed)
            (scripts/"verifier.py").write_text("independent readback test fixture\n")
            schema={
                "contract":"mad4b.dynamic-evidence-schema.v1",
                "schema_id":"code_release.v1",
                "success_state":"HOST_READBACK_VERIFIED_PENDING_ORIGINAL_313",
                "fields":{"file_count":"integer","readback_sha256":"sha256",
                          "result_status":"string"},
            }
            (scripts/"schema.json").write_text(json.dumps(schema))
            ops=Path(__file__).resolve().parents[2]/"dynamic-operation-registry-v1"
            shutil.copy2(ops/"registry.py",root/"registry.py")
            h=lambda f:hashlib.sha256(f.read_bytes()).hexdigest()
            manifest={
                "contract":"mad4b.dynamic-operation-provider.v1",
                "operation_id":"company.code.release",
                "provider":"mad4b-company-release",
                "version":1,
                "plan_contract":"mad4b.company-code-release.plan.v1",
                "handler":{"path":"providers/release/handler.py",
                           "sha256":h(installed)},
                "verifier":{"path":"providers/release/verifier.py",
                            "sha256":h(scripts/"verifier.py")},
                "evidence_schema":"code_release.v1",
                "evidence":{"path":"providers/release/schema.json",
                            "sha256":h(scripts/"schema.json")},
                "command":{"format":"wpcli.argv.json.v1","mode":"plan_sha256",
                           "bound_operation_id":"company.code.release",
                           "prefix":["mad4b","company-code-release","apply"],
                           "flag":"--plan_sha256="},
            }
            (root/"provider.json").write_text(json.dumps(manifest))
            key=Ed25519PrivateKey.generate()
            pub=base64.b64encode(key.public_key().public_bytes(
                serialization.Encoding.Raw,serialization.PublicFormat.Raw)).decode()
            (root/"index.json").write_text(json.dumps({
                "contract":"mad4b.dynamic-operation-index.v1",
                "installed_by_first_party":True,
                "independent_verifier_public_key_b64":pub,
                "providers":[{"manifest":"provider.json",
                              "sha256":h(root/"provider.json")}],
            }))
            store=root/"plans"
            store.mkdir(mode=0o700)
            (store/(self.plan["plan_sha256"]+".json")).write_text(
                json.dumps(self.plan))
            spec=importlib.util.spec_from_file_location(
                "dynamic_test_installed_release", installed)
            module=importlib.util.module_from_spec(spec)
            spec.loader.exec_module(module)
            with mock.patch.object(module,"DYNAMIC_INSTALLED_REGISTRY",root):
                with mock.patch.object(module,"DYNAMIC_PLAN_STORE",store):
                    self.assertTrue(module.dynamic_operation_enrolled(self.plan))
                    # No registration in old worker, but enrolled provider
                    # dispatch is now a separate first-party lane.
                    module.installed_original_operation(self.plan)
                    changed={**self.plan,"git_commit":"e"*40}
                    (store/(self.plan["plan_sha256"]+".json")).write_text(
                        json.dumps(changed))
                    with self.assertRaisesRegex(module.NotEnrolled,
                                                "REGISTERED_PLAN_MISMATCH"):
                        module.installed_original_operation(self.plan)

    def test_actual_original_worker_not_registered(self):
        # Synthetic test Plan is NOT an installed first-party plan. A live
        # registry must refuse it even before the legacy digest check.
        with self.assertRaisesRegex(handler.NotEnrolled,
                                    "DYNAMIC_HOST_HANDLER_NOT_PINNED|COMMAND_DIGEST_NOT_ENROLLED"):
            handler.installed_original_operation(self.plan)

    def test_worker_and_executor_both_required(self):
        digest=original.command_sha256(self.plan["plan_sha256"])
        with mock.patch.object(handler,"dynamic_operation_enrolled",return_value=False):
            with self.assertRaisesRegex(handler.NotEnrolled,
                                        "CODE_RELEASE_HANDLER_NOT_ENROLLED"):
                handler.installed_original_operation(
                self.plan, digest_reader=lambda _: {digest:"company.code.release"},
                operation_inspector=lambda _: {"read","plugin.install"})
        with mock.patch.object(handler,"dynamic_operation_enrolled",return_value=False):
            with self.assertRaisesRegex(handler.NotEnrolled,
                                        "COMMAND_DIGEST_NOT_ENROLLED"):
                handler.installed_original_operation(
                    self.plan, digest_reader=lambda _: {},
                    operation_inspector=lambda _: {"company.code.release"})
            handler.installed_original_operation(
                self.plan,
                digest_reader=lambda _: {digest:"company.code.release"},
                operation_inspector=lambda _: {"company.code.release"})

    def test_old_code_release_source_does_not_authorize(self):
        with mock.patch.object(handler, "installed_original_operation",side_effect=handler.NotEnrolled("ORIGINAL_313_CODE_RELEASE_HANDLER_NOT_ENROLLED")):
            with mock.patch.object(handler.release,"apply_release") as apply:
                with self.assertRaises(handler.NotEnrolled):
                    handler.original_claim(self.job_id,self.plan,
                                           status_provider=lambda c,e:self.scope,
                                           signed_authority=lambda a,b,c:self.proof)
                apply.assert_not_called()

    def test_original_312_proof_with_original_registered_operation(self):
        with mock.patch.object(handler,"installed_original_operation",return_value=None):
            proof=handler.original_claim(
                self.job_id,self.plan,
                status_provider=lambda c,e:self.scope,
                signed_authority=lambda a,b,c:self.proof)
            self.assertEqual(proof,self.proof)

    def test_dynamic_306_313_replaces_legacy_static_dispatch_only_after_live_enrollment(self):
        self.scope.update(
            original_313_code_release_handler_registered=False,
            company_code_release_dispatch_ability_registered=False,
            dynamic_registry_enrolled=True,
            dynamic_306_digest_registered=True,
            dynamic_313_independent_verifier_registered=True,
            dynamic_313_finalizer_connected=True,
        )
        with mock.patch.object(handler,"installed_original_operation",
                               return_value=None):
            result=handler.original_claim(
                self.job_id,self.plan,
                status_provider=lambda c,e:self.scope,
                signed_authority=lambda a,b,c:self.proof)
            self.assertEqual(result,self.proof)
            for name in (
                "dynamic_registry_enrolled",
                "dynamic_306_digest_registered",
                "dynamic_313_independent_verifier_registered",
                "dynamic_313_finalizer_connected",
            ):
                self.scope[name]=False
                with self.assertRaisesRegex(handler.NotEnrolled,"RELEASE_NOT_ENROLLED"):
                    handler.original_claim(
                        self.job_id,self.plan,
                        status_provider=lambda c,e:self.scope,
                        signed_authority=lambda a,b,c:self.proof)
                self.scope[name]=True

    def test_missing_wp_dispatch_blocks_original_claim(self):
        self.scope["company_code_release_dispatch_ability_registered"]=False
        with mock.patch.object(handler,"installed_original_operation",return_value=None):
            with self.assertRaisesRegex(handler.NotEnrolled, "RELEASE_NOT_ENROLLED"):
                handler.original_claim(
                    self.job_id,self.plan,
                    status_provider=lambda c,e:self.scope,
                    signed_authority=lambda a,b,c:self.proof)

    def test_default_execution_path_must_verify_real_live_worker(self):
        # No fake authority argument or local boolean may be passed from the
        # production CLI. The real 306 digest is currently unregistered.
        policy={"contract":"mad4b.company-code-release-policy.v1",
                "receipt_dir":"/var/lib/mad4b/company-code-release/receipts"}
        with mock.patch.object(handler.release,"apply_release") as apply:
            with self.assertRaisesRegex(handler.NotEnrolled,
                                        "DYNAMIC_HOST_HANDLER_NOT_PINNED|COMMAND_DIGEST_NOT_ENROLLED"):
                handler.execute_once(
                    self.job_id,self.plan,policy,{"original_job_id":self.job_id})
            apply.assert_not_called()

    def test_caller_cannot_pass_312_proof_as_execution_permission(self):
        with self.assertRaises(TypeError):
            handler.execute_once(
                self.job_id,self.plan,{},{"original_job_id":self.job_id},
                original_authority_proof=self.proof,
                original_operation_verified=True,
            )

    def test_unverified_312_prevents_host_apply(self):
        self.proof["eligible"]=False
        policy={"contract":"mad4b.company-code-release-policy.v1",
                "receipt_dir":"/var/lib/mad4b/company-code-release/receipts"}
        with mock.patch.object(handler.release,"apply_release") as apply:
            with self.assertRaisesRegex(original.GateDenied,"NOT_ELIGIBLE"):
                handler.execute_once(
                    self.job_id,self.plan,policy,{"contract":"grant"},
                    original_claim_provider=lambda job,p:self.proof)
            apply.assert_not_called()

    def test_worker_flag_false_prevents_host_apply(self):
        with mock.patch.object(handler.release,"apply_release") as apply:
            with self.assertRaisesRegex(handler.NotEnrolled, "WORKER_ENROLLMENT_NOT_VERIFIED"):
                handler.execute_once(
                    self.job_id,self.plan,{},{"contract":"grant"},
                    original_claim_provider=lambda job,p:(
                        (_ for _ in ()).throw(handler.NotEnrolled(
                            "ORIGINAL_313_WORKER_ENROLLMENT_NOT_VERIFIED"
                        ))
                    ))
            apply.assert_not_called()

    def test_root_pinned_plan_path(self):
        planpath,grantpath=handler.exact_receipt_files(self.job_id,self.plan["plan_sha256"])
        self.assertEqual(planpath.parent,handler.PLANS)
        self.assertEqual(grantpath.parent,handler.GRANTS)
        for bad in ("../../etc/passwd","G"*32,""):
            with self.assertRaises(handler.NotEnrolled):
                handler.exact_receipt_files(bad,self.plan["plan_sha256"])

    def test_broker_release_grant_must_name_original_312_job(self):
        policy={"contract":"mad4b.company-code-release-policy.v1",
                "receipt_dir":"/var/lib/mad4b/company-code-release/receipts"}
        with mock.patch.object(handler.release, "apply_release") as apply:
            with self.assertRaisesRegex(handler.NotEnrolled, "JOB_AND_BROKER_GRANT_NOT_BOUND"):
                handler.execute_once(
                    self.job_id,self.plan,policy,
                    {"original_job_id":"b"*32},
                    original_claim_provider=lambda job,p:self.proof)
            apply.assert_not_called()

    def test_signed_grant_is_required_even_after_original312(self):
        policy={"contract":"mad4b.company-code-release-policy.v1",
                "receipt_dir":"/var/lib/mad4b/company-code-release/receipts"}
        with mock.patch.object(handler.release,"verify_grant",side_effect=handler.release.Denied("NO_GRANT")):
            with mock.patch.object(handler.release,"apply_release") as apply:
                with self.assertRaises(handler.release.Denied):
                    handler.execute_once(
                        self.job_id,self.plan,policy,{"contract":"invalid","original_job_id":self.job_id},
                        original_claim_provider=lambda job,p:self.proof)
                apply.assert_not_called()

    def test_host_propagates_incomplete_release_states_without_false_rollback(self):
        policy={"contract":"mad4b.company-code-release-policy.v1",
                "receipt_dir":"/var/lib/mad4b/company-code-release/receipts"}
        cases={
          "ROLLED_BACK":"HOST_APPLY_ROLLED_BACK",
          "ABORTED_NO_MUTATION":"HOST_APPLY_ABORTED_NO_MUTATION",
          "ROLLBACK_UNVERIFIED":"HOST_APPLY_INCIDENT_RECOVERY_REQUIRED",
          "UNKNOWN":"HOST_APPLY_RESULT_UNRECOGNIZED_HOLD",
        }
        for underlying,expected in cases.items():
            with self.subTest(state=underlying):
                with mock.patch.object(handler.release,"verify_grant",return_value=None):
                    with mock.patch.object(handler.release,"apply_release",
                                           return_value={"state":underlying}):
                        result=handler.execute_once(
                            self.job_id,self.plan,policy,
                            {"contract":"signed-fixture","original_job_id":self.job_id},
                            original_claim_provider=lambda job,p:self.proof)
                self.assertEqual(result["state"],expected)
                self.assertEqual(result["incident_requires_recovery"],
                                 underlying=="ROLLBACK_UNVERIFIED")
                self.assertFalse(result["original_313_certified"])
                self.assertFalse(result["original_313_receipt_generated"])

    def test_positive_mock_host_apply_never_certifies_313(self):
        policy={"contract":"mad4b.company-code-release-policy.v1",
                "receipt_dir":"/var/lib/mad4b/company-code-release/receipts"}
        with mock.patch.object(handler.release,"verify_grant",return_value=None):
            with mock.patch.object(handler.release,"apply_release",
                                   return_value={"state":"APPLIED_VERIFIED"}) as apply:
                result=handler.execute_once(
                    self.job_id,self.plan,policy,{"contract":"signed-fixture","original_job_id":self.job_id},
                    original_claim_provider=lambda job,p:self.proof)
        self.assertEqual(result["state"],"HOST_APPLIED_PENDING_INDEPENDENT_ORIGINAL_313")
        self.assertFalse(result["original_313_certified"])
        self.assertFalse(result["original_313_receipt_generated"])
        apply.assert_called_once()


if __name__=="__main__":
    unittest.main()
