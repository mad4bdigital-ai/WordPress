"""Read-only legacy plan migration inventory, no Job claims or grant issuance."""
from __future__ import annotations
from copy import deepcopy
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest

ROOT=Path(__file__).resolve().parents[1]
FILE=ROOT/"ops/native-source-publisher-v1/plan_store_compatibility_audit.py"
spec=importlib.util.spec_from_file_location("mad4b_source_plan_compat_offline",FILE)
compat=importlib.util.module_from_spec(spec)
spec.loader.exec_module(compat)

class MockRegistry:
    def __init__(self):
        self.manifests={
            "company.code.release":{"operation_id":"company.code.release",
              "plan_contract":"mad4b.company-code-release.plan.v1"},
            "company.receipt.restore":{"operation_id":"company.receipt.restore",
              "plan_contract":"mad4b.receipt.plan.v1"}
        }

class PlanStoreRisk(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory(prefix="source-legacy-plan-")
        self.addCleanup(self.tmp.cleanup)
        self.root=Path(self.tmp.name)/"plans"
        self.root.mkdir(mode=0o700)
        self.registry=MockRegistry()
        self.candidates={
          "source.code.release":{
             "operation_id":"source.code.release",
             "plan_contract":"mad4b.company-code-release.plan.v1"
          },
          "other.source.deploy":{
             "operation_id":"other.source.deploy",
             "plan_contract":"mad4b.no-existing-plan.v1"
          }
        }

    def write(self,stem,contract):
        p=self.root/(stem*64+".json")
        p.write_text(json.dumps({
           "plan_sha256":stem*64,"contract":contract,
           "company":"example","environment":"staging"
        }))
        p.chmod(0o600)

    def test_shared_contract_detected_from_metadata_only(self):
        self.write("a","mad4b.company-code-release.plan.v1")
        self.write("b","mad4b.receipt.plan.v1")
        state=compat.summarize(self.registry,self.root,self.candidates)
        self.assertEqual(state["state"],"HOLD__PENDING_LEGACY_CONFLICT_RECONCILIATION")
        self.assertEqual(state["plan_count"],2)
        self.assertEqual(state["ambiguous_existing_plan_count"],1)
        self.assertEqual(state["legacy_plans_with_one_prior_provider"],1)
        self.assertEqual(state["legacy_plans_with_multiple_prior_providers"],0)
        self.assertTrue(state["migration_signed_association_required"])
        self.assertEqual(state["affected_existing_provider_ids"],
                         ["company.code.release"])
        self.assertEqual(state["candidate_colliding_provider_ids"],
                         ["source.code.release"])
        self.assertFalse(state["provider_enrolled"])
        self.assertFalse(state["plan_store_modified"])

    def test_preexisting_multi_provider_ambiguity_is_reported(self):
        self.write("a","mad4b.company-code-release.plan.v1")
        self.registry.manifests["another.enrolled.legacy"]={
            "operation_id":"another.enrolled.legacy",
            "plan_contract":"mad4b.company-code-release.plan.v1"
        }
        state=compat.summarize(self.registry,self.root,self.candidates)
        self.assertEqual(state["legacy_plans_with_one_prior_provider"],0)
        self.assertEqual(state["legacy_plans_with_multiple_prior_providers"],1)
        self.assertTrue(state["migration_signed_association_required"])
        self.assertIn("another.enrolled.legacy",state["affected_existing_provider_ids"])

    def test_absent_shared_contract_has_no_migration_collision(self):
        self.write("a","mad4b.receipt.plan.v1")
        result=compat.summarize(self.registry,self.root,self.candidates)
        self.assertEqual(result["ambiguous_existing_plan_count"],0)
        self.assertEqual(result["state"],"NO_SHARED_CONTRACT_PLAN_CONFLICT_OBSERVED")

    def test_source_can_scan_new_unlisted_provider(self):
        self.write("a","mad4b.company-code-release.plan.v1")
        new=deepcopy(self.candidates)
        new["dynamic.operation.four"]={
            "operation_id":"dynamic.operation.four",
            "plan_contract":"mad4b.company-code-release.plan.v1"
        }
        report=compat.summarize(self.registry,self.root,new)
        self.assertEqual(report["candidate_colliding_provider_ids"],
                         ["dynamic.operation.four","source.code.release"])

    def test_invalid_candidate_and_existing_provider_replacement_denied(self):
        bad=deepcopy(self.candidates)
        bad["source.code.release"]["operation_id"]="wrong.operation"
        with self.assertRaises(compat.CompatibilityDenied):
            compat.summarize(self.registry,self.root,bad)
        replaced=deepcopy(self.candidates)
        replaced["company.code.release"]={
            "operation_id":"company.code.release",
            "plan_contract":"mad4b.company-code-release.plan.v1"
        }
        with self.assertRaises(compat.CompatibilityDenied):
            compat.summarize(self.registry,self.root,replaced)

    def test_broken_plan_id_and_path_are_rejected(self):
        self.write("a","mad4b.company-code-release.plan.v1")
        p=self.root/("a"*64+".json")
        data=json.loads(p.read_text())
        data["plan_sha256"]="b"*64
        p.write_text(json.dumps(data))
        with self.assertRaises(compat.CompatibilityDenied):
            compat.summarize(self.registry,self.root,self.candidates)
        p.unlink()
        (self.root/("a"*64+".json")).symlink_to("/etc/passwd")
        with self.assertRaises(compat.CompatibilityDenied):
            compat.summarize(self.registry,self.root,self.candidates)

    def test_plan_store_world_writable_denied(self):
        self.root.chmod(0o777)
        with self.assertRaises(compat.CompatibilityDenied):
            compat.summarize(self.registry,self.root,self.candidates)

    def test_live_original_plan_store_is_read_only_for_this_audit(self):
        self.assertNotIn("write_text(",FILE.read_text())
        self.assertNotIn("unlink(",FILE.read_text())
        self.assertNotIn("execute_claimed(",FILE.read_text())

if __name__=="__main__":
    unittest.main()
