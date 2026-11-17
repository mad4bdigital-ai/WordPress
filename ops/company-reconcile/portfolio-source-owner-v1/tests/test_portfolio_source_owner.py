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


    def test_restored_write_surface_cannot_be_silently_reclassified(self):
        """Pin the unapproved first-party write inventory, not execution authority.

        The Pre-Staging scanner must continue denying the original ADDON's
        raw mutating primitives until the exact owner/operation guards have
        been independently reviewed.  A reviewed source SHA is not a grant.
        """
        import re
        from collections import Counter

        patterns = {
            'direct_content_or_option_mutation':
                r'\b(?:wp_update_post|update_option|add_option|delete_option)\s*\(',
            'direct_wpdb_query':
                r'\$wpdb\s*->\s*(?:query|insert|update|delete)\s*\(',
            'direct_filesystem_write':
                r'\b(?:file_put_contents|fwrite|unlink|rename)\s*\(',
            'direct_outbound_http':
                r'\bwp_remote_(?:get|post|request|head)\s*\(',
            'shell_exec': r'\bshell_exec\s*\(',
            'proc_open': r'\bproc_open\s*\(',
            'passthru': r'\bpassthru\s*\(',
            'eval': r'\beval\s*\(',
        }
        counts = Counter()
        affected_files = set()
        php_count = 0
        for item in self.manifest['files']:
            if not item['path'].endswith('.php'):
                continue
            php_count += 1
            code = (ROOT / item['path']).read_text(encoding='utf-8')
            for kind, pattern in patterns.items():
                n = len(re.findall(pattern, code))
                counts[kind] += n
                if n:
                    affected_files.add(item['path'])

        self.assertEqual(php_count, 82)
        self.assertEqual(len(affected_files), 33)
        self.assertEqual(dict(counts), {
            'direct_content_or_option_mutation': 74,
            'direct_wpdb_query': 12,
            'direct_filesystem_write': 11,
            'direct_outbound_http': 2,
            'shell_exec': 0,
            'proc_open': 0,
            'passthru': 0,
            'eval': 0,
        })
        self.assertEqual(sum(counts.values()), 99)

        # This deliberately includes non-executing text, method declarations
        # and CLI-only fixtures. Removing those is NOT permission to allow
        # genuine company/site, provider or host mutations.
        catalog_item = next(
            x for x in self.catalog['addons']
            if x.get('addon_id') == 'mad4b-company-management'
        )
        self.assertEqual(catalog_item['certified_pairs'], [])
        self.assertFalse(catalog_item['release_policy']['production_authorized'])
        self.assertFalse(self.manifest['signed_original313_deployment_authority'])
        scanner = (ROOT / 'tools/mad4b_pre_staging_hybrid_audit.py').read_text(
            encoding='utf-8'
        )
        self.assertIn('if addon_static_hits:', scanner)
        self.assertIn('fail("addon_registry", "forbidden direct execution/mutation primitives:', scanner)

if __name__ == '__main__':
    unittest.main()
