"""CI-only exact MCP Adapter profile resolver; never downloads or enrolls."""
import importlib.util
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / 'tools/resolve-certified-mcp-adapter-profile.py'
CONFIG = ROOT / 'wp-content/plugins/mad4b-site-control-plane/config'
spec = importlib.util.spec_from_file_location('mad4b_ci_mcp_profile', SCRIPT)
resolver = importlib.util.module_from_spec(spec)
sys.modules[spec.name] = resolver
spec.loader.exec_module(resolver)

class CertifiedMCPAdapterCI(unittest.TestCase):
    def test_all_certified_versions_resolve_by_matrix_label_without_fixed_list(self):
        catalog = json.loads((CONFIG / 'certified-provider-profiles.json').read_text())
        profiles = catalog['providers']['mcp_adapter']
        self.assertTrue(profiles)
        for version, profile in profiles.items():
            with self.subTest(version=version):
                result = resolver.resolve(CONFIG, 'target-' + version)
                self.assertEqual(result['TARGET_VERSION'], version)
                self.assertEqual(result['TARGET_SHA'], profile['archive_sha256'])
                self.assertEqual(result['TARGET_AUTOLOAD_SHA'],
                                 profile['critical_files']['vendor/autoload_packages.php'])
                self.assertEqual(result['TARGET_BYTES'], str(profile['archive_bytes']))
                self.assertEqual(result['TARGET_URL'], profile['package_url'])

    def test_target_version_not_read_from_retired_runtime_policy_field(self):
        policy = json.loads((CONFIG / 'runtime-release-policy.json').read_text())
        self.assertNotIn('target_adapter_version', policy)
        workflow = (ROOT / '.github/workflows/mad4b-local-oauth-standalone.yml').read_text()
        self.assertNotIn("policy['target_adapter_version']", workflow)
        self.assertIn('tools/resolve-certified-mcp-adapter-profile.py', workflow)
        self.assertIn('[[ "$ADAPTER_PROFILE" == target-* ]]', workflow)

    def test_unrecognized_or_unpinned_profile_fails_closed(self):
        for label in ('', '0.7.0', 'bundled-0.6.1', 'target-none',
                      'target-999.1.0', 'target-0.7.0;evil', 'target-../../tmp'):
            with self.subTest(label=label), self.assertRaises(resolver.ProfileDenied):
                resolver.resolve(CONFIG, label)

    def test_modified_certification_policy_digest_or_archive_denied(self):
        with tempfile.TemporaryDirectory() as tmp:
            target = Path(tmp)
            base_policy = json.loads((CONFIG / 'runtime-release-policy.json').read_text())
            base_profiles = json.loads((CONFIG / 'certified-provider-profiles.json').read_text())
            for label, mutate in (
                ('policy', lambda p, c: p.update({'adapter_release_target_source':'unreviewed'})),
                ('archive', lambda p, c: c['providers']['mcp_adapter']['0.7.0'].update({'archive_sha256':'bad'})),
                ('autoload', lambda p, c: c['providers']['mcp_adapter']['0.7.0']['critical_files'].pop('vendor/autoload_packages.php')),
                ('bytes', lambda p, c: c['providers']['mcp_adapter']['0.7.0'].update({'archive_bytes':False})),
                ('url', lambda p, c: c['providers']['mcp_adapter']['0.7.0'].update({'package_url':'http://untrusted.invalid/archive.zip'})),
            ):
                with self.subTest(case=label):
                    policy = json.loads(json.dumps(base_policy))
                    catalog = json.loads(json.dumps(base_profiles))
                    mutate(policy, catalog)
                    (target / 'runtime-release-policy.json').write_text(json.dumps(policy))
                    (target / 'certified-provider-profiles.json').write_text(json.dumps(catalog))
                    with self.assertRaises(resolver.ProfileDenied):
                        resolver.resolve(target, 'target-0.7.0')

    def test_shell_output_is_exactly_quoted_assignments_without_execution(self):
        result = subprocess.run([sys.executable, str(SCRIPT), '--profile', 'target-0.7.0'],
                                capture_output=True, text=True, check=True)
        names = [line.split('=', 1)[0] for line in result.stdout.splitlines()]
        self.assertEqual(names, list(resolver.resolve(CONFIG, 'target-0.7.0')))
        self.assertEqual(result.stderr, '')

if __name__ == '__main__':
    unittest.main()
