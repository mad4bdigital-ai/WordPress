"""Exact official MCP Adapter archive authority identity regression.
Offline-only: no network, artifact download or certification mutation.
"""
from pathlib import Path
import importlib.util
import json
import unittest

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "wp-content/plugins/mad4b-site-control-plane/tests/mcp-runtime-surface-profile.py"
PROFILE = ROOT / "wp-content/plugins/mad4b-site-control-plane/config/certified-provider-profiles.json"
spec = importlib.util.spec_from_file_location("mcp_runtime_surface_profile", SCRIPT)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class OfficialMcpSourceIdentity(unittest.TestCase):
    def setUp(self):
        self.profiles = json.loads(PROFILE.read_text())["providers"]["mcp_adapter"]

    def test_exact_github_release_identity(self):
        self.assertEqual(module.reviewed_source_identity(self.profiles["0.7.0"], "0.7.0"),
                         "WordPress/mcp-adapter@v0.7.0")

    def test_exact_wordpress_org_plugin_distribution_identity(self):
        self.assertEqual(module.reviewed_source_identity(self.profiles["0.7.1"], "0.7.1"),
                         "WordPress.org/mcp-adapter@0.7.1")

    def test_forged_runtime_surface_identity_cannot_rewrite_canonical_authority(self):
        candidate = dict(self.profiles["0.7.1"])
        candidate["runtime_surface"] = dict(candidate["runtime_surface"], source_identity="trusted-by-caller")
        self.assertEqual(module.reviewed_source_identity(candidate, "0.7.1"),
                         "WordPress.org/mcp-adapter@0.7.1")

    def test_identity_denies_wrong_version_and_untrusted_authority(self):
        original = self.profiles["0.7.1"]
        for url, authority, version in (
            ("https://downloads.wordpress.org/plugin/mcp-adapter.0.7.0.zip", original["certification_authority"], "0.7.1"),
            ("https://example.invalid/plugin/mcp-adapter.0.7.1.zip", original["certification_authority"], "0.7.1"),
            ("http://downloads.wordpress.org/plugin/mcp-adapter.0.7.1.zip", original["certification_authority"], "0.7.1"),
            ("https://downloads.wordpress.org/plugin/mcp-adapter.0.7.1.zip?next=1", original["certification_authority"], "0.7.1"),
            ("https://downloads.wordpress.org/plugin/mcp-adapter.0.7.1.zip", "caller claim", "0.7.1"),
            (original["package_url"], original["certification_authority"], "0.7.2"),
        ):
            with self.subTest(url=url, version=version), self.assertRaises(SystemExit):
                module.reviewed_source_identity(dict(original, package_url=url, certification_authority=authority), version)


if __name__ == "__main__":
    unittest.main()
