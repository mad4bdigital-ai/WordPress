"""Fail-closed local Git source-owner provenance; never authorizes release."""
from pathlib import Path
import hashlib
import json
import unittest

ROOT = Path(__file__).resolve().parents[4]
MANIFEST = ROOT / 'ops/company-reconcile/portfolio-source-owner-recovery-20261117.json'
CATALOG = ROOT / 'wp-content/plugins/mad4b-site-control-plane/config/wordpress-addon-catalog.json'

class OriginalPortfolioOwnerSource(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.manifest = json.loads(MANIFEST.read_text(encoding='utf-8'))
        cls.catalog = json.loads(CATALOG.read_text(encoding='utf-8'))

    def test_original_site_owner_exact_code_bytes(self):
        m = self.manifest
        self.assertEqual(m['source_file_count'], 86)
        self.assertEqual(m['source_php_file_count'], 82)
        files = m['files']
        self.assertEqual(len(files), len(set(f['path'] for f in files)))
        for item in files:
            with self.subTest(path=item['path']):
                p = ROOT / item['path']
                self.assertTrue(p.is_file() and not p.is_symlink())
                self.assertEqual(p.stat().st_size, item['bytes'])
                self.assertEqual(hashlib.sha256(p.read_bytes()).hexdigest(), item['sha256'])

    def test_backup_runtime_artifacts_excluded(self):
        m = self.manifest
        self.assertEqual(len(m['excluded_inactive_backups']), 18)
        for x in m['excluded_inactive_backups']:
            self.assertFalse((ROOT/'wp-content/plugins/mad4b-company-portfolio-manager'/x['path']).exists())
        self.assertTrue(all(v == 0 for v in m['secret_pattern_scan'].values()))

    def test_addon_source_root_present_but_release_still_uncertified(self):
        items = [v for v in self.catalog['addons'] if v.get('addon_id') == 'mad4b-company-management']
        self.assertEqual(len(items), 1)
        source = items[0]
        self.assertEqual(source['source_root'], 'wp-content/plugins/mad4b-company-portfolio-manager')
        self.assertEqual(source['release_ring'], 'dynamic')
        self.assertEqual(source['certified_pairs'], [])
        self.assertEqual(source['release_policy']['unknown_pair_fallback'], 'shadow')
        self.assertFalse(source['release_policy']['production_authorized'])
        self.assertTrue((ROOT/source['source_root']/source['plugin_file'].split('/')[-1]).is_file())
        self.assertFalse(self.manifest['signed_original313_deployment_authority'])
        self.assertFalse(self.manifest['production_site_deployment'])

if __name__ == '__main__':
    unittest.main()
