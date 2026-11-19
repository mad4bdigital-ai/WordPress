from pathlib import Path
import json,unittest

ROOT=Path(__file__).resolve().parents[4]
P=ROOT/'wp-content/plugins'
RECEIPT=ROOT/'ops/company-reconcile/runtime-owner-closure-v1/live-runtime-source-manifest.json'

class RuntimeOwnerSafetyTests(unittest.TestCase):
    def test_original313_authority_not_backfilled_from_source(self):
        x=json.loads(RECEIPT.read_text())
        self.assertEqual(x['status'],'SOURCE_OWNER_CLOSURE_VERIFIED_RELEASE_HOLD')
        self.assertFalse(x['operational_release_ready'])
        self.assertFalse(x['original313_signed_release_certified'])
        self.assertTrue(x['source_runtime_plugin_activation_not_performed'])
        self.assertFalse(x['live_site_files_mutated'])

    def test_central_platform_is_not_company_auto_installer(self):
        src=(P/'mad4b-platform/mad4b-platform.php').read_text()
        self.assertIn('Central-only control plane',src)
        self.assertIn('MU bootstrap',src)
        self.assertTrue((P/'mad4b-platform/config/ordered-mu-map.php').is_file())

    def test_priced_tour_tags_have_duplicate_registration_guard(self):
        boot=(P/'mad4b-commerce/mad4b-commerce.php').read_text()
        self.assertIn("function_exists('tour_component_structured_price_value')",boot)
        self.assertIn('MAD4B_PRESENTATION_RUNTIME_ENABLED',boot)
        self.assertIn('MAD4B_COMMERCE_RUNTIME_ENABLED',boot)
        self.assertIn('362-mad4b-booking-pickup-fields.php',boot)
        self.assertIn('require_once $module',boot)

if __name__=='__main__':
    unittest.main()
