"""Exact tourism CPT review: no blanket site-neutral scanner exception."""
from pathlib import Path
import importlib.util
import json
import sys
import unittest
ROOT=Path(__file__).resolve().parents[1]
POLICY=ROOT/'wp-content/plugins/mad4b-site-control-plane/tests/site-neutrality-policy.json'
SCAN=ROOT/'tools/check_plugin_site_neutrality.py'
spec=importlib.util.spec_from_file_location('mad4b_neutral_scan_fixture',SCAN)
scan=importlib.util.module_from_spec(spec)
sys.modules[spec.name]=scan
spec.loader.exec_module(scan)

class TourHighlightsExplicitTourModel(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.policy=json.loads(POLICY.read_text())
        cls.reviewed=(
          'includes/class-mad4b-scp-tour-highlights-v1.php',
          'includes/class-mad4b-scp-tour-highlights-presentation-v1.php',
        )

    def test_only_domain_specific_exact_file_models_are_reviewed(self):
        self.assertFalse(self.policy['authorizing'])
        for name in self.reviewed:
            with self.subTest(file=name):
                row=self.policy['reviewed_business_models'][name]
                self.assertEqual(row['models'],['tours-and-activities'])
                self.assertIn('optional tourism-domain',row['rationale'])
                self.assertNotIn('FIXED_BUSINESS_MODEL',scan.inspect(name,
                    (ROOT/'wp-content/plugins/mad4b-site-control-plane'/name).read_text(),self.policy))
        self.assertNotIn('includes/class-mad4b-scp-site-profile.php',
                         self.policy['reviewed_business_models'])

    def test_generic_model_and_unreviewed_tour_taxonomies_still_rejected(self):
        for name in self.reviewed:
            self.assertIn('FIXED_BUSINESS_MODEL',
                scan.inspect(name,"'tour_type'",self.policy))
            self.assertIn('TENANT_DOMAIN',
                scan.inspect(name,"'https://staging.allroyalegypt.com'",self.policy))
        self.assertIn('FIXED_BUSINESS_MODEL',scan.inspect(
            'includes/class-mad4b-scp-site-profile.php',
            "'tours-and-activities'",self.policy))

    def test_tour_specific_editor_write_scope_not_widened_by_review(self):
        resolver=(ROOT/'wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-tour-highlights-v1.php').read_text()
        self.assertIn("get_post_type($post)!=='tours-and-activities'",resolver)
        self.assertIn("MAD4B_Company_Term_Eligibility_V1::decide(",resolver)
        self.assertIn("'tour_highlight_status'=>'needs_review'",resolver)
        self.assertIn("update_post_meta($post,'tour_highlights_items',wp_slash($new),$old)",resolver)
        self.assertNotIn('wp_remote_post(',resolver)

if __name__=='__main__':unittest.main()
