#!/usr/bin/env python3
"""Source-owned cross-tenant Package commercial fail-closed regression."""
import pathlib
import unittest
ROOT=pathlib.Path(__file__).resolve().parents[1]
def read(path):
    p=ROOT/path
    assert p.is_file(), 'Missing source path: '+path
    return p.read_text()

class PackageCommercialSourceIntake(unittest.TestCase):
    def test_transport_existing_rate_identity_and_provider_modules(self):
        main=read('wp-content/plugins/mad4b-pricing-registry/mad4b-pricing-registry.php')
        for file in ('transport-cost-bridge','transport-package-workflow','supplier-sheet-reference',
                     'package-component-readiness','package-seasonal-assignment'):
            self.assertIn('includes/'+file+'.php',main)
            read('wp-content/plugins/mad4b-pricing-registry/includes/'+file+'.php')
        admission=read('wp-content/mu-plugins/mad4b-package-supplier-admission-v1.php')
        for name in ('public static function linkedRateRoutes','related_tour_id',
                     'transport_route_stage_binding_missing','transport_route_cost_unapproved'):
            self.assertIn(name,admission)
    def test_receipts_preserve_cryptographic_authority_and_concrete_cost(self):
        gate=read('wp-content/mu-plugins/mad4b-package-cost-receipt-gate-v1.php')
        admission=read('wp-content/mu-plugins/mad4b-package-supplier-admission-v1.php')
        for reason in ('receipt_signed_authority_unverified','receipt_service_date_not_covered',
                       'receipt_currency_mismatch_or_unverified_fx',
                       'receipt_occupancy_mismatch','receipt_availability_not_verified_or_expired',
                       'receipt_train_timetable_invalid','self_supplier_actual_cost_document_missing'):
            self.assertIn(reason,gate)
        self.assertIn("apply_filters('mad4b/package-cost-receipt/verify-authority',false",gate)
        for token in ('MAD4B_Package_Cost_Receipt_Gate_V1::validate',
                      'supplier_cost_receipt_qualified','cost_receipt_validator_missing',
                      'stay_supplier_cost_receipt_missing','guideEvidenceReady','source_cost_fingerprint',
                      'not_added_to_retail_price',"'rate_generation_ready'=>false","'checkout_ready'=>false"):
            self.assertIn(token,admission)
    def test_station_identity_is_local_not_source_site_literal(self):
        source=read('wp-content/mu-plugins/mad4b-package-stage-sourcing-v1.php')
        self.assertIn('private static function ramsesStation()',source)
        self.assertIn('self::ramsesStation()',source)
        self.assertNotIn('nativeStationIds[54231]',source)
        self.assertIn('bashtilStation()',source)
    def test_night_stage_and_season_are_separate_from_cost_approval(self):
        guard=read('wp-content/plugins/mad4b-commerce/legacy-mu-tree/shared-product-core/pricing/package-stage-rate-guard-v1.php')
        season=read('wp-content/plugins/mad4b-pricing-registry/includes/package-seasonal-assignment.php')
        for token in ('MAD4B_Package_Seasonal_Assignment_V1::decision',
                      'stage_all_candidates_excluded_by_season_or_calendar',
                      'cruise_rate_stage_native_provider_not_connected',
                      'sleeper_train_rate_stage_native_provider_not_connected'):
            self.assertIn(token,guard)
        self.assertIn("current_user_can('manage_options')",season)
        self.assertIn('seasonal_assignment_stale_revision',season)
        self.assertIn("'checkout_authorized'=>false",season)
    def test_native_pricing_lab_persists_one_inspector(self):
        native=read('wp-content/plugins/mad4b-jetengine/modules/functional-modes/class-pricing-lab.php')
        lab=read('wp-content/mu-plugins/mad4b-package-pricing-lab-readiness-v1.php')
        css=read('wp-content/mu-plugins/assets/mad4b-package-pricing-lab-readiness-v1.css')
        self.assertIn('MAD4B_PRICING_LAB_NATIVE_SLOT_READY',native)
        self.assertIn("do_action('mad4b/pricing-lab/before-root')",native)
        self.assertIn("current_user_can('edit_post',$tour)",lab)
        self.assertIn('mad4b-pcl-source-unit-drafts',css)
        self.assertIn('mad4b-pcl-source-scout',css)

if __name__=='__main__':unittest.main()
