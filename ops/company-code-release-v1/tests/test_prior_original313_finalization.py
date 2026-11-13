"""A Host Apply receipt never silently authorizes the next overlapping release."""
import json
import unittest
from pathlib import Path

from test_release import CodeReleaseTests, release
guard=release.release_finalization


class PriorFinalizationTests(unittest.TestCase):
    setUp=CodeReleaseTests.setUp
    tearDown=CodeReleaseTests.tearDown
    grant=CodeReleaseTests.grant
    authorized_apply=CodeReleaseTests.authorized_apply

    def previous_host_receipt(self):
        record={
            "contract":release.RECEIPT_CONTRACT,
            "state":"APPLIED_VERIFIED",
            "nonce":"prior_host_313_nonce_2026",
            "site_uuid":self.plan["site_uuid"],
            "plan_sha256":"a"*64,
            "company":self.plan["company"],
            "environment":self.plan["environment"],
            "files":[row["target"] for row in self.plan["files"]],
            "original_313_receipt":False,
        }
        path=self.receipts/(record["nonce"]+".json")
        path.write_text(json.dumps(record))
        path.chmod(0o600)
        return path

    def require_finalizer(self):
        self.policy["targets"]["demo:staging"]["finalization_policy"]={
            "require_prior_original313":True}
        self.plan=release.make_plan(self.request,self.policy)

    def test_previous_host_apply_blocks_before_first_write(self):
        self.require_finalizer()
        self.previous_host_receipt()
        with self.assertRaisesRegex(guard.PendingFinalization,
                                    "VERIFIER_NOT_ENROLLED"):
            self.authorized_apply()
        self.assertIn("OLD",self.target.read_text())
        self.assertFalse(list(self.receipts.glob("*.intent.json")))
        self.assertFalse(list(self.receipts.glob("*.backup.json")))

    def test_host_self_certification_does_not_count(self):
        self.require_finalizer()
        path=self.previous_host_receipt()
        record=json.loads(path.read_text())
        record["original_313_receipt"]=True
        path.write_text(json.dumps(record))
        with self.assertRaisesRegex(guard.PendingFinalization,
                                    "HOST_SELF_CERTIFICATION"):
            self.authorized_apply()

    def test_signed_original313_bridge_must_match_exact_host_bytes(self):
        self.require_finalizer()
        self.previous_host_receipt()
        def verifier(record):
            return {
                "contract":guard.CERTIFICATION_BRIDGE,
                "independent_original313_signature_verified":True,
                "plan_sha256":record["plan_sha256"],
                "site_uuid":record["site_uuid"],
                "nonce":record["nonce"],
                "host_receipt_sha256":record["host_receipt_sha256"]}
        with self.assertRaisesRegex(guard.PendingFinalization,
                                    "INDEPENDENT_CERTIFICATION_MISSING"):
            guard.admit(
                self.plan,self.policy["targets"]["demo:staging"],
                self.receipts,
                lambda path:(json.loads(path.read_bytes()),path.read_bytes()),
                first_party_verifier=lambda _: {**verifier(_),
                                               "host_receipt_sha256":"f"*64})
        # This is only an injected first-party *test stub*, not real Original313.
        result=guard.admit(
            self.plan,self.policy["targets"]["demo:staging"],self.receipts,
            lambda path:(json.loads(path.read_bytes()),path.read_bytes()),
            first_party_verifier=verifier)
        self.assertEqual(result["verified_host_applies"],1)

    def test_disjoint_prior_host_apply_does_not_block_next_release(self):
        self.require_finalizer()
        path=self.previous_host_receipt()
        record=json.loads(path.read_text())
        record["files"]=["wp-content/mu-plugins/mad4b-unrelated.php"]
        path.write_text(json.dumps(record))
        result=self.authorized_apply()
        self.assertEqual(result["state"],"APPLIED_VERIFIED")

    def test_bound_independent_adapter_admits_real_host_apply_call(self):
        self.require_finalizer()
        self.previous_host_receipt()
        def fixture_verified_by_original(record):
            # Synthetic fixture: production requires the original signed
            # verifier; this function never creates or signs credentials.
            return {
                "contract":guard.CERTIFICATION_BRIDGE,
                "independent_original313_signature_verified":True,
                "plan_sha256":record["plan_sha256"],
                "site_uuid":record["site_uuid"],
                "nonce":record["nonce"],
                "host_receipt_sha256":record["host_receipt_sha256"]}
        result=release.apply_release(
            self.plan,self.policy,self.grant(),self.receipts,
            runtime_check=lambda *_:None,
            validate_code=lambda *_:None,
            preflight_code=lambda *_:None,
            verify_prior_original313=fixture_verified_by_original)
        self.assertEqual(result["state"],"APPLIED_VERIFIED")
        self.assertIs(result["original_313_receipt"],False)

    def test_policy_change_invalidates_planned_release(self):
        self.plan=release.make_plan(self.request,self.policy)
        self.policy["targets"]["demo:staging"]["finalization_policy"]={
            "require_prior_original313":True}
        with self.assertRaisesRegex(release.Denied,"PRIOR_ORIGINAL313_POLICY_DRIFT"):
            release.confirm_plan(self.plan,self.policy,expect_preimage=True)

    def test_unrelated_site_and_nonoverlapping_release_are_ignored(self):
        self.require_finalizer()
        path=self.previous_host_receipt()
        record=json.loads(path.read_text())
        record["site_uuid"]="b"*36
        path.write_text(json.dumps(record))
        result=self.authorized_apply()
        self.assertEqual(result["state"],"APPLIED_VERIFIED")

if __name__=="__main__":
    unittest.main()
