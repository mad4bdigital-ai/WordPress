"""Existing signed release admission: numbered, original WordPress root MU owners."""
import sys,unittest
from pathlib import Path
root=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(root))
import company_code_release as release

class NumericWordPressMuRootOwner(unittest.TestCase):
    def test_native_root_loader_not_filename_allowlist(self):
        for file in ('415-mad4b-critical-css-orchestrator.php',
                     '406-mad4b-shared-performance-parity.php',
                     'mad4b-render-query-governor.php'):
            self.assertTrue(release.native_mad4b_mu_root_loader(file),file)
    def test_non_mad4b_and_unsafe_names_denied(self):
        for file in ('15-mad4b-short.php','415-foreign-admin.php',
                     '415-mad4b.php.php','415-mad4b-.php',
                     '../415-mad4b-code.php','not-a-plugin.php',
                     '415-mad4b-shell.sh'):
            self.assertFalse(release.native_mad4b_mu_root_loader(file),file)
    def test_explicit_single_registered_component(self):
        item={'id':'mad4b-critical-css-adaptive-native',
              'files':['wp-content/mu-plugins/415-mad4b-critical-css-orchestrator.php']}
        self.assertEqual(release.registered_component_files(item),item['files'])
    def test_unenrolled_file_is_still_denied(self):
        for name in ('415-foreign-admin.php','413-mad4b-foo.js'):
            item={'id':'mad4b-foreign',
                  'files':['wp-content/mu-plugins/'+name]}
            with self.assertRaises(release.Denied):
                release.registered_component_files(item)
    def test_no_nested_php_grants(self):
        item={'id':'mad4b-foreign',
              'files':['wp-content/mu-plugins/415-mad4b-foo.php',
                       'wp-content/mu-plugins/mad4b-foo/executor.php']}
        with self.assertRaises(release.Denied):
            release.registered_component_files(item)
    def test_exact_component_catalog_entry(self):
        import json
        cat=json.loads((root/'catalog/mad4b-release-components.json').read_text())
        selected=[c for c in cat['components'] if c.get('id')=='mad4b-critical-css-adaptive-native']
        self.assertEqual(len(selected),1)
        self.assertEqual(selected[0]['files'],
                         ['wp-content/mu-plugins/415-mad4b-critical-css-orchestrator.php'])
    def test_catalog_does_not_issue_grants(self):
        # The generic company policy still must independently enroll the
        # exact target and source immutable SHA. Source catalog is NOT a grant.
        import json
        policy=json.loads(Path('/etc/mad4b/company-code-release-policy.json').read_text())
        target='wp-content/mu-plugins/415-mad4b-critical-css-orchestrator.php'
        if 'wayfatours:production' not in policy['targets']:
            self.skipTest('site not enrolled in local root policy fixture')
        self.assertNotIn(target,policy['targets']['wayfatours:production']['allowed_targets'])

if __name__=='__main__':
    unittest.main()
