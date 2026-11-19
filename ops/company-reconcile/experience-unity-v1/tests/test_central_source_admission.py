from pathlib import Path
import hashlib,json
import unittest

ROOT=Path(__file__).resolve().parents[4]
RECEIPT=ROOT/'ops/company-reconcile/experience-unity-v1/central-source-integration-20261117.json'

class CentralSourceAdmission(unittest.TestCase):
    @classmethod
    def setUpClass(cls):cls.manifest=json.loads(RECEIPT.read_text())

    def test_selective_source_sha_inventory_and_approved_adaptation(self):
        m=self.manifest
        self.assertEqual(m['source_file_count'],29)
        self.assertEqual(len(m['components']),29)
        self.assertEqual(m['files_integrated_exact'],28)
        self.assertEqual(m['files_adapted_to_prevent_partial_plugin_activation'],1)
        for f in m['components']:
            p=ROOT/f['central_staged_path']
            self.assertTrue(p.is_file(),f['central_staged_path'])
            # Earlier integration receipt is immutable. The source registration
            # test was subsequently adapted again when the complete runtime
            # baseline arrived; its old hash remains recorded historically.
            if f['status']!='source_registration_adapted_to_quarantined_bootstrap':
                self.assertEqual(hashlib.sha256(p.read_bytes()).hexdigest(),f['integrated_sha256'])
                self.assertEqual(f['integrated_sha256'],f['original_source_sha256'])

    def test_historical_partial_bootstrap_remains_a_valid_prior_receipt(self):
        m=self.manifest
        self.assertEqual(len(m['commerce_missing_direct_require_once_paths']),18)
        p=ROOT/'ops/company-reconcile/price-presentation-v1/source-overlays/mad4b-commerce-bootstrap.source-candidate.php'
        self.assertTrue(p.is_file())
        self.assertFalse(m['runtime_release_ready'])
        self.assertTrue(m['incomplete_runtime_bootstrap_excluded_from_live_wp_plugin_root'])
        self.assertTrue((ROOT/'wp-content/plugins/mad4b-commerce/mad4b-commerce.php').is_file())
        # Latest closure is separate and requires own runtime-source admission.
        self.assertTrue((ROOT/'ops/company-reconcile/runtime-owner-closure-v1/live-runtime-source-manifest.json').is_file())

    def test_original313_and_fleet_release_explicitly_not_claimed(self):
        m=self.manifest
        for k in ('original313_certified','fleet_deployment_authorized','production_files_changed',
                  'critical_css_or_governance_overwritten','runtime_release_ready'):
            self.assertFalse(m[k])
        self.assertTrue(m['source_subgraph_not_merged'])
        self.assertEqual(m['central_target_branch'],'integration/pr814-821-source-converged-20261115')

if __name__=='__main__':
    unittest.main()
