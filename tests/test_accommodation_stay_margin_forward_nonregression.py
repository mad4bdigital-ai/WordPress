#!/usr/bin/env python3
"""Source-intake guard; not a live WordPress or supplier-approval test."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
MU = ROOT / 'wp-content/mu-plugins'
CORE = (MU / 'mad4b-accommodation-weight-policies-v1.php').read_text()
REGISTRY = (MU / 'mad4b-unified-pricing-policy-registry-v1.php').read_text()
SEASON = (ROOT / 'wp-content/plugins/mad4b-commerce/legacy-mu-tree/shared-product-core/pricing/rate-date-eligibility-readonly-v1.php').read_text()
PLANNER = (ROOT / 'wp-content/plugins/mad4b-commerce/legacy-mu-tree/shared-product-core/pricing/rate-planner.php').read_text()

class LiveForwardContracts(unittest.TestCase):
    def test_native_margin_class_and_scopes_not_second_engine(self):
        self.assertIn('final class MAD4B_Accommodation_Stay_Margin_V1', CORE)
        self.assertIn("private const SCOPES=['global','property_type','destination','property','unit','rate'];", CORE)
        self.assertIn("public const META='_mad4b_accommodation_stay_margin_v1';", CORE)

    def test_rate_price_never_double_marked_up(self):
        self.assertIn('native_selling_rate_takes_precedence', CORE)
        self.assertIn('native_selling_price_takes_precedence', CORE)
        self.assertIn("'supplier_cost_approved'=>false", CORE)
        self.assertIn("'season_verified'=>false", CORE)
        self.assertIn("'price_written'=>false,'checkout_ready'=>false", CORE)
        margin = CORE.split('final class MAD4B_Accommodation_Stay_Margin_V1', 1)[1]
        self.assertNotIn('wp_insert_post(', margin)
        self.assertNotIn('wp_update_post(', margin)

    def test_source_control_uses_original_panel_dependency(self):
        self.assertTrue((MU / 'assets/mad4b-accommodation-stay-margin-v1.js').is_file())
        self.assertTrue((MU / 'assets/mad4b-accommodation-stay-margin-v1.css').is_file())
        self.assertIn("['mad4b-accommodation-weight-policy']", CORE)
        self.assertIn('mad4b-accommodation-stay-margin', CORE)

    def test_margin_visible_but_not_authorized_in_native_registry(self):
        self.assertIn("$sources['accommodation_stay_margin']", REGISTRY)
        self.assertIn('MAD4B_Accommodation_Stay_Margin_V1::inventory()', REGISTRY)
        self.assertIn("'supplier_cost_approval_required'=>true", REGISTRY)
        self.assertIn("'commercial_authority'=>false", REGISTRY)
        self.assertIn("'accommodation_night_margin_preview_requires_approved_supplier_cost'", REGISTRY)

    def test_single_season_full_window_1031_is_kept(self):
        self.assertIn('shared_product_booking_v2_rate_covers_window', SEASON)
        self.assertGreaterEqual(PLANNER.count('shared_product_booking_v2_rate_covers_window'), 2)
        self.assertIn('season_assignment_missing_or_ambiguous', SEASON)

if __name__ == '__main__':
    unittest.main()
