import copy
import unittest
import control_plane_release_preflight as p

ORIGIN="https://staging.egypttourgates.com"
UUID="d745d81f-6fc4-5c6a-99dd-d953c92137bf"
PLAN={
 "contract":"mad4b.etg-staging-provenance-reconciliation-plan.v1",
 "target_site":ORIGIN,"site_uuid":UUID,"environment":"staging",
 "production_mutation":False,"authorization":"NOT_GRANTED_BY_THIS_PLAN",
 "source_commit_sha":"a"*40,
 "candidate_manifest_sha256":"a"*64,"candidate_archive_sha256":"b"*64,
 "manifest_digest":"c"*64
}
SITE={"site_uuid":UUID,"canonical_origin":ORIGIN,"environment":"staging",
      "origin_match":True,"deployment_binding_match":True}
SOURCE={"source_commit_sha":"a"*40,"archive_sha256":"b"*64,
        "manifest_digest":"c"*64,"independent_repository_verified":True,
        "git_source_registered":True,"local_snapshot_only":False}
GRANT={"capability":p.CAPABILITY,"site_uuid":UUID,"origin":ORIGIN,
       "environment":"staging","archive_sha256":"b"*64,"original_native_policy_verified":True,
       "revoked":False,"signature_verified":True,"bound_to_current_operation":True}
EXEC={"site_uuid":UUID,"origin":ORIGIN,"registered":True,
      "native_original_operator":True,"readback_supported":True,
      "rollback_supported":True,"supports_exact_release_apply":True}
RECEIPT={"state":"VERIFIED","execution_performed":True,"mutation_performed":True,
         "rollback_available":True,"site_uuid":UUID,"origin":ORIGIN,
         "environment":"staging","operation":p.CAPABILITY,
         "archive_sha256":"b"*64,"manifest_digest":"c"*64,
         "runtime_manifest_match":True,"stale":False,"independent_readback":True,
         "readback_sha256":"d"*64,"production_mutation":False}


class ReleasePreflightTests(unittest.TestCase):
    def check(self,plan=None,site=None,source=None,grant=None,executor=None):
        return p.preflight(plan if plan is not None else PLAN,
                           site if site is not None else SITE,
                           source if source is not None else SOURCE,
                           grant if grant is not None else GRANT,
                           executor if executor is not None else EXEC)
    def test_exact_evidence_allows_submission_but_never_authorizes(self):
        r=self.check()
        self.assertTrue(r["can_submit_to_native_operator"])
        self.assertFalse(r["native_operation_authorized"])
        self.assertFalse(r["execution_performed"])
    def test_local_snapshot_is_not_authoritative_source(self):
        r=self.check(source={**SOURCE,"local_snapshot_only":True})
        self.assertIn("source_provenance_not_independently_verified",r["blockers"])
    def test_wrong_sha_source_is_refused(self):
        self.assertFalse(self.check(source={**SOURCE,"archive_sha256":"f"*64})["can_submit_to_native_operator"])
    def test_missing_original_native_grant_is_refused(self):
        self.assertIn("exact_native_release_grant_not_verified",self.check(grant={})["blockers"])
    def test_revoked_grant_is_refused(self):
        self.assertFalse(self.check(grant={**GRANT,"revoked":True})["can_submit_to_native_operator"])
    def test_grant_for_wrong_site_is_refused(self):
        self.assertFalse(self.check(grant={**GRANT,"site_uuid":"another"})["can_submit_to_native_operator"])
    def test_grant_for_wrong_artifact_is_refused(self):
        self.assertFalse(self.check(grant={**GRANT,"archive_sha256":"f"*64})["can_submit_to_native_operator"])
    def test_wrong_environment_is_refused(self):
        self.assertFalse(self.check(plan={**PLAN,"environment":"production"})["can_submit_to_native_operator"])
    def test_wrong_origin_is_refused(self):
        self.assertFalse(self.check(site={**SITE,"canonical_origin":"https://other.example"})["can_submit_to_native_operator"])
    def test_unbound_executor_is_refused(self):
        self.assertFalse(self.check(executor={**EXEC,"registered":False})["can_submit_to_native_operator"])
    def test_fallback_requires_same_exact_executor_scope(self):
        self.assertFalse(self.check(executor={**EXEC,"origin":"https://another.example"})["can_submit_to_native_operator"])
    def test_rollback_is_required(self):
        self.assertFalse(self.check(executor={**EXEC,"rollback_supported":False})["can_submit_to_native_operator"])
    def test_executed_and_read_back_receipt(self):
        self.assertTrue(p.verify_external_readback(PLAN,RECEIPT))
    def test_pending_receipt_is_not_success(self):
        self.assertFalse(p.verify_external_readback(PLAN,{**RECEIPT,"execution_performed":False}))
    def test_mismatched_receipt_is_not_success(self):
        self.assertFalse(p.verify_external_readback(PLAN,{**RECEIPT,"manifest_digest":"f"*64}))
    def test_bad_runtime_readback_is_not_success(self):
        self.assertFalse(p.verify_external_readback(PLAN,{**RECEIPT,"runtime_manifest_match":False}))
    def test_production_mutation_receipt_is_rejected(self):
        self.assertFalse(p.verify_external_readback(PLAN,{**RECEIPT,"production_mutation":True}))
    def test_invalid_plan_blocks(self):
        self.assertFalse(self.check(plan={})["can_submit_to_native_operator"])

if __name__=="__main__":
    unittest.main()
