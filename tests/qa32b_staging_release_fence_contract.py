#!/usr/bin/env python3
"""Hermetic negative regression for QA32B and RC15 candidate-vs-certification boundary."""
import json
import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "tools"))
from qa32b_staging_release_fence import check, LEGACY, NEUTRAL, SOURCE_SHA, SOURCE_VERSION

def row(source_id):
    return {
        "id": source_id, "provider_id": source_id,
        "review_state": "source_reviewed_artifact_pending",
        "candidate_source_commit_sha": SOURCE_SHA,
        "candidate_artifact_version": SOURCE_VERSION,
        "candidate_state": "REVIEW_ONLY_SOURCE_PINNED",
        "package_certification": "pending",
        "source_commit_sha": "", "artifact_sha256": "", "artifact_version": "",
        "production_promotion_attestation": "",
    }

class RC15BoundaryTests(unittest.TestCase):
    def setUp(self):
        self.review = {"contract":"mad4b.cross-plugin-sources.v1",
                       "default_execution": "deny_until_exact_artifact_certified",
                       "sources": [row(LEGACY), row(NEUTRAL)]}
        self.live = {"contract":"mad4b.cross-plugin-sources.v1",
                     "sources": [dict(self.review["sources"][0])]}
        self.qa31 = {"status":"EXPLAINED_UNCERTIFIED_SOURCE_DRIFT",
                     "staging_rollout_authorized":False}

    def status(self):
        return check(self.qa31, self.review, self.live)

    def test_missing_deployed_neutral_is_still_no_go(self):
        a=self.status()
        self.assertEqual(a["state"], "SOURCE_INTAKE_VALIDATED_WITH_SEPARATE_GATES")
        self.assertIn("neutral_source_not_deployed_to_control_plane",a["blockers"])
        self.assertFalse(a["provider_certified"])
        self.assertFalse(a["can_install_dfsb"])
        self.assertFalse(a["selected_head_update_authorized"])
        self.assertFalse(a["safe_boot_override"])
        self.assertFalse(a["production_authorized"])

    def test_added_same_unapproved_source_is_no_go(self):
        self.live["sources"].append(row(NEUTRAL))
        a=self.status()
        self.assertEqual(a["state"], "SOURCE_INTAKE_VALIDATED_WITH_SEPARATE_GATES")
        self.assertIn("dfsb_independent_digest_bound_certificate_missing",a["blockers"])
        self.assertFalse(a["control_plane_rollout_approved"])

    def test_legacy_change_denied(self):
        self.live["sources"][0]["candidate_artifact_version"]="invalid"
        self.assertEqual(self.status()["state"], "BLOCKED")

    def test_trust_flags_not_forged(self):
        self.review["sources"][1]["package_certification"]="certified"
        self.assertEqual(self.status()["state"],"BLOCKED")

    def test_cert_digest_not_fabricated(self):
        self.review["sources"][1]["artifact_sha256"]="a"*64
        self.assertEqual(self.status()["state"],"BLOCKED")

    def test_source_change_denied(self):
        self.review["sources"][1]["candidate_source_commit_sha"]="b"*40
        self.assertEqual(self.status()["state"],"BLOCKED")

    def test_version_change_denied(self):
        self.review["sources"][1]["candidate_artifact_version"]="alpha-unknown"
        self.assertEqual(self.status()["state"],"BLOCKED")

    def test_duplicate_source_denied(self):
        self.review["sources"].append(row(NEUTRAL))
        self.assertEqual(self.status()["state"],"BLOCKED")

    def test_missing_qa31_evidence_denied(self):
        self.qa31["status"]="BLOCKED"
        self.assertEqual(self.status()["state"],"BLOCKED")

    def test_missing_default_execution_denied(self):
        self.review["default_execution"]="allow"
        self.assertEqual(self.status()["state"],"BLOCKED")

    def test_live_mismatched_candidate_denied(self):
        self.live["sources"].append(row(NEUTRAL))
        self.live["sources"][1]["candidate_artifact_version"]="other"
        self.assertEqual(self.status()["state"],"BLOCKED")

    def test_invalid_registry_contract_denied(self):
        self.review["contract"]="unsupported"
        self.assertEqual(self.status()["state"],"BLOCKED")

if __name__=="__main__":
    unittest.main()
