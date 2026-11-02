#!/usr/bin/env python3
"""Hermetic tests: no WordPress runtime, network, DB, or credentials required."""
import copy
import datetime as dt
import importlib.util
import json
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location("company_reconcile", ROOT / "reconcile.py")
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
POLICY = json.loads((ROOT / "company-start-reconcile-policy.json").read_text())
NOW = dt.datetime(2026, 11, 3, 0, 0, tzinfo=dt.timezone.utc)


def fixture():
    target = {
        "company_id": "company-a", "site_uuid": "site-a", "canonical_origin": "https://a.example",
        "environment": "production", "profile_revision": 3,
    }
    manifest = {
        "source_snapshot_digest": "src123", "eligible_object_manifest_digest": "objects123",
        "language_scope": ["en", "es"], "source_company": "neutral-demo",
        "target_company": "company-a", "template_build_digest": "build123",
    }
    return {
        "contract": module.OBSERVATION_CONTRACT, "target": target, "manifest": manifest,
        "mode": "template_clone", "source_origin": "https://neutral.example",
        "public_serving": False, "observations": {
            cid: {**target, "manifest_digest": "objects123", "observed_at": NOW.isoformat(),
                  "receipt_id": "provider-" + cid, "state": "PASS",
                  "observed_digest": "result-" + cid}
            for cid in POLICY["checks"]
        },
    }


class ReconcileTests(unittest.TestCase):
    def assess(self, evidence):
        return module.assess(POLICY, evidence, now=NOW)

    def test_all_pass_before_public_serving(self):
        out = self.assess(fixture())
        self.assertEqual(out["status"], "READY_TO_PUBLISH")
        self.assertTrue(out["ready_to_publish"])
        self.assertFalse(out["mutation_performed"])

    def test_all_pass_live(self):
        f = fixture()
        f["public_serving"] = True
        self.assertEqual(self.assess(f)["status"], "COMMERCIAL_READY")

    def test_missing_hero_blocks_live_even_if_http200(self):
        f = fixture()
        f["public_serving"] = True
        del f["observations"]["css_assets_hero"]
        out = self.assess(f)
        self.assertEqual(out["status"], "LIVE_WITH_GAPS_P0")
        self.assertFalse(out["ready_to_publish"])
        self.assertIn("css_assets_hero", out["stage_results"]["publish"]["missing"])

    def test_wpml_not_transferred_blocks_ready(self):
        f = fixture()
        f["observations"]["wpml_language_scope"]["state"] = "FAIL"
        self.assertEqual(self.assess(f)["status"], "RECONCILING_FUNCTIONAL")

    def test_identity_wrong_site_fails_closed(self):
        f = fixture()
        f["observations"]["identity_binding"]["site_uuid"] = "legacy-site"
        o = self.assess(f)
        self.assertEqual(o["checks"]["identity_binding"]["state"], "WRONG_TARGET")
        self.assertEqual(o["status"], "BLOCKED_BOOTSTRAP")
        self.assertTrue(all(j["authorized"] is False for j in o["job_proposals"]))
        self.assertTrue(all(j["auto_remediation_candidate"] is False for j in o["job_proposals"]))

    def test_stale_receipt_cannot_pass(self):
        f = fixture()
        f["observations"]["elementor_template_conditions"]["observed_at"] = (NOW-dt.timedelta(hours=3)).isoformat()
        self.assertEqual(self.assess(f)["checks"]["elementor_template_conditions"]["state"], "STALE_EVIDENCE")

    def test_future_receipt_cannot_pass(self):
        f = fixture()
        f["observations"]["menus_destinations"]["observed_at"] = (NOW+dt.timedelta(minutes=30)).isoformat()
        self.assertFalse(self.assess(f)["ready_to_publish"])

    def test_changed_manifest_is_stale(self):
        f = fixture()
        f["observations"]["eligible_content_inventory"]["manifest_digest"] = "different"
        self.assertEqual(self.assess(f)["checks"]["eligible_content_inventory"]["state"], "STALE_MANIFEST")

    def test_grant_never_inferred_from_connectivity(self):
        f = fixture()
        f["observations"]["authority_chain"]["state"] = "PENDING"
        o = self.assess(f)
        self.assertEqual(o["status"], "BLOCKED_BOOTSTRAP")
        self.assertFalse(o["authority_issued"])

    def test_na_default_rejected(self):
        f = fixture()
        f["observations"]["form_definitions"]["state"] = "NOT_APPLICABLE"
        self.assertEqual(self.assess(f)["checks"]["form_definitions"]["state"], "UNVERIFIED")

    def test_na_only_if_signed_and_explicitly_disabled(self):
        f = fixture()
        f["disabled_features"] = ["form_definitions"]
        f["observations"]["form_definitions"]["state"] = "NOT_APPLICABLE"
        f["observations"]["form_definitions"]["signed_feature_disabled"] = True
        f["observations"]["form_definitions"]["reason"] = "forms disabled in company Site Profile"
        self.assertTrue(self.assess(f)["ready_to_publish"])

    def test_source_equal_target_denied_for_clone(self):
        f = fixture()
        f["source_origin"] = f["target"]["canonical_origin"]
        with self.assertRaises(ValueError):
            self.assess(f)

    def test_incomplete_target_denied(self):
        f = fixture()
        del f["target"]["site_uuid"]
        with self.assertRaises(ValueError):
            self.assess(f)

    def test_missing_manifest_denied(self):
        f = fixture()
        del f["manifest"]["template_build_digest"]
        with self.assertRaises(ValueError):
            self.assess(f)

    def test_commerce_pending_does_not_block_public_with_safe_checkout(self):
        f = fixture()
        f["public_serving"] = True
        f["observations"]["checkout_and_notifications"]["state"] = "PENDING"
        o = self.assess(f)
        self.assertEqual(o["status"], "LIVE_VERIFIED_COMMERCE_PENDING")
        self.assertTrue(o["ready_to_publish"])
        self.assertFalse(o["commercial_ready"])

    def test_jobs_idempotent(self):
        f = fixture()
        f["observations"]["jsf_index_local"]["state"] = "FAIL"
        a = self.assess(f)
        b = self.assess(copy.deepcopy(f))
        self.assertEqual(a["job_proposals"], b["job_proposals"])
        self.assertEqual(a["job_proposals"][0]["authorized"], False)

    def test_private_data_exclusion_blocks_auto_dispatch(self):
        f = fixture()
        f["observations"]["private_data_exclusion"]["state"] = "FAIL"
        o = self.assess(f)
        self.assertEqual(o["status"], "BLOCKED_BOOTSTRAP")
        self.assertTrue(all(j["auto_remediation_candidate"] is False for j in o["job_proposals"]))

    def test_no_false_pass_from_one_http_status(self):
        f = fixture()
        f["public_serving"] = True
        for name in ("elementor_template_conditions", "form_definitions", "menus_destinations",
                     "jsf_index_local", "wpml_language_scope"):
            del f["observations"][name]
        self.assertEqual(self.assess(f)["status"], "LIVE_WITH_GAPS_P0")


if __name__ == "__main__":
    unittest.main()
