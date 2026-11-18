#!/usr/bin/env python3
"""Repository-only reviewed-parent regression. Never grants deployment authority."""
import copy
import json
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DIR = ROOT / "specs/007-content-intelligence-workflow-platform"
EXPECTED = "83cbe5ef992e3c99151891a0a55561a98139b948"
ARCHIVED = "3785892316d3c395ad144500fdecc6836ed2c176"

def agrees(capfab, feature, closure):
    return (capfab.get("baseline_merge_commit")
            == feature.get("last_reviewed_master_parent_sha")
            == (closure.get("repository_baseline") or {}).get("last_reviewed_parent_sha"))

class Feature007CapabilityFabricParent(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.capfab = json.loads((DIR / "post-merge-capability-fabric-closure.json").read_text(encoding="utf8"))
        cls.feature = json.loads((DIR / "feature.json").read_text(encoding="utf8"))
        cls.closure = json.loads((DIR / "implementation-closure.json").read_text(encoding="utf8"))

    def test_current_reviewed_parent_matches_all_three_mirrors(self):
        self.assertEqual(self.capfab["baseline_merge_commit"], EXPECTED)
        self.assertTrue(agrees(self.capfab, self.feature, self.closure))
        review = self.capfab["current_repository_parent_review"]
        self.assertEqual(review["reviewed_master_parent"], EXPECTED)
        self.assertFalse(review["authorizing"])
        self.assertFalse(review["production_authorized"])
        self.assertFalse(review["live_acceptance_claimed"])

    def test_historical_review_preserved_as_history(self):
        prior = self.capfab["baseline_source_review"]
        self.assertEqual(prior["reconciled_repository_parent"], ARCHIVED)
        self.assertIs(prior["authorizing"], False)
        self.assertTrue(self.capfab["current_repository_parent_review"]["historic_phase37_review_retained"])

    def test_independent_negative_parent_drifts_denied(self):
        for changed in ("capfab", "feature", "closure"):
            with self.subTest(changed=changed):
                capfab, feature, closure = map(copy.deepcopy, (self.capfab, self.feature, self.closure))
                if changed == "capfab":
                    capfab["baseline_merge_commit"] = ARCHIVED
                elif changed == "feature":
                    feature["last_reviewed_master_parent_sha"] = ARCHIVED
                else:
                    closure["repository_baseline"]["last_reviewed_parent_sha"] = ARCHIVED
                self.assertFalse(agrees(capfab, feature, closure))

    def test_release_and_runtime_authority_unchanged(self):
        for key in ("production_authorized", "breakglass_widened",
                    "generic_shell_authorized", "generic_raw_sql_authorized"):
            self.assertIs(self.capfab[key], False)
        self.assertEqual(self.capfab["completeness_claim"], "NOT_YET_PROVEN")
        self.assertTrue(self.capfab["critical_kernel_terminal_gate_unchanged"])
        self.assertIs(self.feature["production_activation_authorized"], False)
        self.assertEqual(self.feature["runtime_release_identity"]["state"], "RECAPTURE_REQUIRED")

if __name__ == "__main__":
    unittest.main()
