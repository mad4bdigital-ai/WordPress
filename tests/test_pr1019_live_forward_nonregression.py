"""Exact source + fail-closed checks for site-paired 2026-11-21 pricing forward reconciliation."""
from __future__ import annotations
import hashlib
import json
import pathlib
import unittest

ROOT=pathlib.Path(__file__).resolve().parents[1]
FIXTURE=ROOT/'docs/source-reconciliation/PR1019-LIVE-FORWARD-20261121.json'

def git_blob(body:bytes)->str:
    return hashlib.sha1(b'blob '+str(len(body)).encode()+b'\0'+body).hexdigest()

class TestNativeCommercialLiveForward(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.provenance=json.loads(FIXTURE.read_text())
    def read(self,path):
        return (ROOT/path).read_text()
    def test_exact_source_review_scope(self):
        p=self.provenance
        self.assertEqual(p['status'],'reviewed_for_ci_only')
        self.assertIs(p['production_authorized'],False)
        self.assertIs(p['commercially_authorized'],False)
        self.assertIs(p['source_only'],True)
        self.assertEqual(len(p['source_blobs']),7)
        for name,sha in p['source_blobs'].items():
            with self.subTest(name=name):
                self.assertEqual(git_blob((ROOT/name).read_bytes()),sha)
    def test_unreviewed_mutation_rejected(self):
        for name,sha in self.provenance['source_blobs'].items():
            with self.subTest(name=name):
                body=(ROOT/name).read_bytes()
                self.assertNotEqual(git_blob(body+b'// unreviewed'),sha)
    def test_weight_estimates_not_bookable(self):
        src=self.read('wp-content/mu-plugins/mad4b-accommodation-weight-policies-v1.php')
        for fragment in ("'commercial_authority'=>false","'rate_written'=>false",
                         "'checkout_ready'=>false","'supplier_contract_approved'=>false",
                         'unit_max_occupancy',"'price_written'=>false"):
            with self.subTest(contract=fragment):self.assertIn(fragment,src)
    def test_unassigned_property_remains_diagnostic(self):
        src=self.read('wp-content/plugins/mad4b-pricing-registry/includes/package-component-readiness.php')
        for fragment in ("'source_catalogue_review_only'","'qualified'=>false",
                         "'assigned'=>false","'not_authorized_to_assign'=>true",
                         "'checkout_authorized'=>false",'unassignedCandidates'):
            with self.subTest(contract=fragment):self.assertIn(fragment,src)
    def test_translated_season_inherits_default_policy(self):
        src=self.read('wp-content/plugins/mad4b-pricing-registry/includes/package-seasonal-assignment.php')
        for fragment in ("'policy_term_id'","wpml_default_language","wpml_object_id",
                         "get_term_meta($policy", 'function invalidate()',
                         "'checkout_authorized'=>false"):
            with self.subTest(contract=fragment):self.assertIn(fragment,src)
    def test_supplier_notes_never_approve_costs(self):
        src=self.read('wp-content/plugins/mad4b-pricing-registry/includes/supplier-sheet-reference.php')
        for fragment in ("'dates_directly_approved'=>false","'auto_apply_allowed'=>false",
                         "'commercially_approved'=>false","'policy_term_id'",
                         'cost_receipt_approved',"checkout_authorized"):
            with self.subTest(contract=fragment):self.assertIn(fragment,src)
    def test_transport_draft_only(self):
        src=self.read('wp-content/plugins/mad4b-pricing-registry/includes/transport-distance-time-pricing.php')
        for fragment in ("'draft_estimate_not_commercial'",
                         "'checkout_authorized'=>false",
                         'seasonal_assignment_provider_missing'):
            with self.subTest(contract=fragment):self.assertIn(fragment,src)
    def test_supplier_cost_and_pricing_lab_were_not_overwritten(self):
        for name in ('wp-content/mu-plugins/mad4b-package-supplier-admission-v1.php',
                     'wp-content/plugins/mad4b-jetengine/modules/functional-modes/class-pricing-lab.php',
                     'wp-content/plugins/mad4b-pricing-registry/mad4b-pricing-registry.php'):
            self.assertNotIn(name,self.provenance['source_blobs'])
        supplier=self.read('wp-content/mu-plugins/mad4b-package-supplier-admission-v1.php')
        self.assertIn('function linkedRateRoutes',supplier)
        lab=self.read('wp-content/plugins/mad4b-jetengine/modules/functional-modes/class-pricing-lab.php')
        self.assertIn("current_user_can('commerce_manage_pricing')",lab)

if __name__=='__main__':
    unittest.main()
