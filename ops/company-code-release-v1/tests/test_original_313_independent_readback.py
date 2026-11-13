"""Independent original 313 source-only file readback and denial paths."""
import pathlib
import sys
import tempfile
import unittest
from unittest import mock
sys.path.insert(0,str(pathlib.Path(__file__).resolve().parents[1]))
import company_code_release as release
import original_313_host_handler as handler
import original_313_independent_readback as independent
from test_original_313_contract import plan as fixture_plan
import original_313_code_release_contract as original


class Independent313Tests(unittest.TestCase):
    def setUp(self):
        self.plan=fixture_plan()
        self.job="a"*32
        self.proof={"contract":"mad4b.broker-exact-ssh-authority.v1",
             "verified":True,"eligible":True,"owner_enrolled_wpcli":True,
             "central_scope_only":True,"shell_executed":False,
             "job_id":self.job,"target_id":"srv2030908",
             "company":self.plan["company"],"environment":self.plan["environment"],
             "site_uuid":self.plan["site_uuid"],"origin":self.plan["origin"],
             "command_sha256":original.command_sha256(self.plan["plan_sha256"]),
             "operation":"company.remote.wpcli.full","target_scope":"wordpress",
             "expires_epoch":10**11}
        self.tmp=tempfile.TemporaryDirectory()
        self.dir=pathlib.Path(self.tmp.name)
        self.dir.chmod(0o700)
        self.policy={"contract":"mad4b.company-code-release-policy.v1",
                     "receipt_dir":str(self.dir)}
        self.grant={"original_job_id":self.job,
                    "nonce":"hostreceipt_test_nonce_123456"}
        self.receipt={"contract":"mad4b.company-code-release.receipt.v1",
                      "state":"APPLIED_VERIFIED",
                      "plan_sha256":self.plan["plan_sha256"],
                      "nonce":self.grant["nonce"],
                      "company":self.plan["company"],
                      "environment":self.plan["environment"],
                      "site_uuid":self.plan["site_uuid"],
                      "git_commit":self.plan["git_commit"],
                      "broker_grant_signature_verified":True,
                      "broker_receipt_verified":False,
                      "original_313_receipt":False}
        self.expected={row["target"]:row["source_sha256"]
                       for row in self.plan["files"]}

    def tearDown(self):
        self.tmp.cleanup()

    def call(self, *, receipt=None, observed=None, intent=None):
        with mock.patch.object(independent.release,"verify_grant",return_value=None):
            with mock.patch.object(independent.release,"confirm_plan",return_value=None):
                with mock.patch.object(independent.release,"assert_pinned_receipt_directory",return_value=None):
                    return independent.independent_verify(
                        self.job,self.plan,self.policy,self.grant,self.proof,
                        runtime_check=lambda p,c:None,
                        php_check=lambda p:None,
                        fresh_read=lambda p: self.expected if observed is None else observed,
                        receipt_read=lambda p: (
                            intent if intent is not None and
                            p.name.endswith(".intent.json") else
                            self.receipt if receipt is None else receipt),
                    )

    def test_valid_independent_readback_still_waits_for_original_313(self):
        result=self.call()
        self.assertEqual(result["state"],"HOST_READBACK_VERIFIED_PENDING_ORIGINAL_313")
        self.assertFalse(result["original_313_certified"])
        self.assertEqual(result["file_count"],1)

    def test_provider_proof_requires_host_postimage_timestamp(self):
        self.plan["capability_acceptance"] = {"checks": [
            {"provider": "demo.provider", "capability": "ui.render",
             "id": "render.parity", "targets": list(self.expected)}]}
        signed_content = {k: v for k, v in self.plan.items()
                          if k not in ("plan_sha256", "changed_count")}
        self.plan["plan_sha256"] = release.digest(release.canonical(signed_content))
        self.proof["command_sha256"] = original.command_sha256(
            self.plan["plan_sha256"])
        self.receipt["plan_sha256"] = self.plan["plan_sha256"]
        intent = {"state": "APPLY_STARTED",
                  "plan_sha256": self.plan["plan_sha256"],
                  "nonce": self.grant["nonce"],
                  "site_uuid": self.plan["site_uuid"],
                  "at": "2026-11-13T09:00:00+00:00"}
        with self.assertRaisesRegex(handler.NotEnrolled,
                                    "POSTIMAGE_TIME_MISSING"):
            self.call(intent=intent)

    def dynamic_fixture(self):
        self.plan["capability_acceptance"] = {"checks": [
            {"provider": "demo.provider", "capability": "ui.render",
             "id": "render.parity", "targets": list(self.expected)}]}
        signed = {k:v for k,v in self.plan.items()
                  if k not in ("plan_sha256","changed_count")}
        self.plan["plan_sha256"] = release.digest(release.canonical(signed))
        self.proof["command_sha256"] = original.command_sha256(
            self.plan["plan_sha256"])
        self.receipt["plan_sha256"] = self.plan["plan_sha256"]
        self.receipt["postimage_verified_at"] = "2026-11-13T10:00:00+00:00"
        self.receipt["acceptance_challenge"] = "c"*64
        self.receipt["postimage_sha256"] = (
            release.capability_checks.postimage_fingerprint(
                self.plan, self.expected))
        self.receipt["capability_evidence_sha256"] = {"demo.provider":"d"*64}
        return {"state":"APPLY_STARTED",
                "plan_sha256":self.plan["plan_sha256"],
                "nonce":self.grant["nonce"],
                "site_uuid":self.plan["site_uuid"],
                "at":"2026-11-13T09:59:59+00:00"}

    def test_independent_proof_matches_exact_host_evidence_digest(self):
        intent=self.dynamic_fixture()
        outcome={"evidence_sha256":{"demo.provider":"d"*64},
                 "verified_checks":1}
        with mock.patch.object(independent.capability_checks,
                               "verify_evidence",return_value=outcome):
            receipt=self.call(intent=intent)
        self.assertEqual(receipt["state"],
                         "HOST_READBACK_VERIFIED_PENDING_ORIGINAL_313")

    def test_independent_proof_rejects_changed_evidence_after_host_apply(self):
        intent=self.dynamic_fixture()
        changed={"evidence_sha256":{"demo.provider":"e"*64},
                 "verified_checks":1}
        with mock.patch.object(independent.capability_checks,
                               "verify_evidence",return_value=changed):
            with self.assertRaisesRegex(handler.NotEnrolled,
                                        "HOST_EVIDENCE_DRIFT"):
                self.call(intent=intent)

    def test_independent_proof_rejects_changed_postimage_snapshot(self):
        intent=self.dynamic_fixture()
        self.receipt["postimage_sha256"]="f"*64
        with self.assertRaisesRegex(handler.NotEnrolled,"POSTIMAGE_DRIFT"):
            self.call(intent=intent)

    def test_wrong_job_bound_grant_rejected(self):
        self.grant["original_job_id"]="b"*32
        with self.assertRaisesRegex(handler.NotEnrolled,"JOB_AND_GRANT_MISMATCH"):
            self.call()

    def test_wrong_original_312_authority_rejected(self):
        self.proof["eligible"]=False
        with self.assertRaisesRegex(original.GateDenied,"NOT_ELIGIBLE"):
            self.call()

    def test_unverified_host_incident_never_enters_original_readback(self):
        for state in ('ROLLBACK_UNVERIFIED','ABORTED_NO_MUTATION',
                      'ROLLED_BACK','APPLY_STARTED'):
            with self.subTest(state=state):
                receipt={**self.receipt,'state':state}
                with mock.patch.object(independent.release,'confirm_plan') as confirm:
                    with self.assertRaisesRegex(handler.NotEnrolled,
                                                'HOST_APPLY_NOT_VERIFIED'):
                        self.call(receipt=receipt)
                    confirm.assert_not_called()

    def test_receipt_wrong_company_rejected(self):
        receipt={**self.receipt,"company":"foreign"}
        with self.assertRaisesRegex(handler.NotEnrolled,"HOST_RECEIPT_SCOPE_MISMATCH"):
            self.call(receipt=receipt)

    def test_receipt_wrong_git_commit_rejected(self):
        receipt={**self.receipt,"git_commit":"f"*39+"e"}
        with self.assertRaisesRegex(handler.NotEnrolled,"HOST_RECEIPT_SCOPE_MISMATCH"):
            self.call(receipt=receipt)

    def test_target_digest_drift_refused(self):
        with self.assertRaisesRegex(original.GateDenied,"READBACK_DRIFT"):
            self.call(observed={next(iter(self.expected)):"0"*64})

    def test_receipt_that_self_certifies_313_refused(self):
        receipt={**self.receipt,"original_313_receipt":True}
        with self.assertRaisesRegex(original.GateDenied,"RELEASE_RECEIPT_NOT_VERIFIED"):
            self.call(receipt=receipt)

    def test_invalid_nonce_rejected(self):
        self.grant["nonce"]="../bad"
        with self.assertRaisesRegex(handler.NotEnrolled,"NONCE_INVALID"):
            self.call()

    def test_no_actual_mutations_in_independent_readback(self):
        with mock.patch.object(independent.release,"apply_release") as apply:
            self.call()
            apply.assert_not_called()


if __name__=="__main__":
    unittest.main()
