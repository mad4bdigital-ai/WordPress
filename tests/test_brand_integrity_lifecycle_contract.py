"""Source-level invariants preventing historical publication guard regressions.

This is a supplementary contract test, not a substitute for native WP-CLI QA.
"""
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
INC = ROOT / "wp-content/plugins/mad4b-site-control-plane/includes"


class BrandIntegrityLifecycleContracts(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.brand = (INC / "class-mad4b-scp-brand-integrity.php").read_text()
        cls.runtime = (INC / "class-mad4b-scp-content-experience-runtime.php").read_text()
        cls.quality = (INC / "class-mad4b-scp-brand-experience-quality.php").read_text()
        cls.readiness_facade = (INC / "class-mad4b-scp-content-readiness-alerts.php").read_text()
        cls.readiness_scan = (INC / "trait-mad4b-scp-content-readiness-scanning.php").read_text()
        cls.readiness_reconcile = (INC / "trait-mad4b-scp-content-readiness-reconciliation.php").read_text()
        # Require both actual loader/use bindings. Mere text in orphan traits is not proof.
        for name, marker in (
            ("scanning", "MAD4B_SCP_Content_Readiness_Scanning_Trait"),
            ("reconciliation", "MAD4B_SCP_Content_Readiness_Reconciliation_Trait"),
        ):
            cls_name = f"trait-mad4b-scp-content-readiness-{name}.php"
            assert f"require_once __DIR__.'/{cls_name}';" in cls.readiness_facade
            assert f"use {marker};" in cls.readiness_facade
        assert "trait MAD4B_SCP_Content_Readiness_Scanning_Trait" in cls.readiness_scan
        assert "trait MAD4B_SCP_Content_Readiness_Reconciliation_Trait" in cls.readiness_reconcile
        cls.readiness = cls.readiness_facade + cls.readiness_scan + cls.readiness_reconcile
        cls.story = (ROOT / "wp-content/mu-plugins/mad4b-story-observability.php").read_text()

    def test_no_alternate_global_wordpress_writer(self):
        self.assertNotIn("add_action('save_post", self.brand)
        self.assertNotIn("wp_update_post(", self.brand)
        self.assertNotIn("update_post_meta(", self.brand)

    def test_preflight_precedes_live_create_and_update(self):
        proposed = self.runtime.index("::payload_preflight($normalized)")
        creation = self.runtime.index("wp_insert_post( wp_slash( $postarr )")
        update = self.runtime.index("wp_update_post( wp_slash( $update )")
        self.assertLess(proposed, creation)
        self.assertLess(proposed, update)
        self.assertIn("$stage_live_create ? 'draft' :", self.runtime)
        self.assertIn("if($protected_create)", self.runtime)

    def test_post_write_recheck_uses_original_rollback(self):
        guard = self.runtime.index("::live_update_preflight(")
        rollback = self.runtime.index("self::compensated_error($brand_update_guard,$before)", guard)
        self.assertLess(guard, rollback)
        self.assertIn("$brand_update_baseline", self.runtime)
        self.assertIn("::publication_preflight($post_id)", self.runtime)

    def test_pagination_and_coverage_proof(self):
        self.assertIn("MAX_DISCOVERY_BATCHES", self.brand)
        self.assertIn("'complete'=>$covered&&$gaps===0", self.brand)
        self.assertIn("'coverage_gap_count'=>$gaps", self.brand)
        self.assertIn("'brand_integrity_coverage'=>array(", self.quality)
        self.assertIn("'brand_integrity_coverage'=>(array)", self.readiness)
        self.assertIn("'brand_integrity'=>array(", self.readiness)

    def test_brand_identity_does_not_have_competitor_denylist(self):
        # Synthetic test names are allowed in tests; production detector must
        # derive identity from Brand Context and provider settings.
        for forbidden in ("Donna", "All Royal Egypt", "Egypt Tour Gates", "Almallah"):
            self.assertNotIn(forbidden, self.brand)
        self.assertIn("approved_aliases", self.brand)
        self.assertIn("approved_domains", self.brand)

    def test_story_signal_keeps_declared_severity(self):
        self.assertIn("'story_browser_popup_failure'=>'high'", self.story)
        self.assertIn("$severity=$code==='story_browser_popup_failure'?'high':'medium'", self.story)


if __name__ == "__main__":
    unittest.main()
