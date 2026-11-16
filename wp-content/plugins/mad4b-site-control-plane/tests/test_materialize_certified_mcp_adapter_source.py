#!/usr/bin/env python3
"""No-network negative contract for the exact official MCP Adapter CI source."""
from __future__ import annotations

import importlib.util
from pathlib import Path
import unittest

MODULE = Path(__file__).with_name("materialize-certified-mcp-adapter.py")
spec = importlib.util.spec_from_file_location("mad4b_certified_adapter_materializer", MODULE)
assert spec is not None and spec.loader is not None
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class CertifiedArchiveOriginTest(unittest.TestCase):
    def setUp(self):
        self.github = {
            "package_url": "https://github.com/WordPress/mcp-adapter/releases/download/v0.7.0/mcp-adapter.zip",
            "certification_authority": "github.com/WordPress/mcp-adapter/releases/tag/v0.7.0",
        }
        self.wp_org = {
            "package_url": "https://downloads.wordpress.org/plugin/mcp-adapter.0.7.1.zip",
            "certification_authority": "https://wordpress.org/plugins/mcp-adapter/ (official 0.7.1 distribution)",
        }

    def test_both_exact_official_distribution_profiles(self):
        self.assertEqual(module.reviewed_source(self.github, "0.7.0"),
                         module.OFFICIAL_GITHUB_REDIRECT_HOSTS)
        self.assertEqual(module.reviewed_source(self.wp_org, "0.7.1"),
                         module.OFFICIAL_WP_ORG_REDIRECT_HOSTS)
        self.assertNotIn("github.com", module.OFFICIAL_WP_ORG_REDIRECT_HOSTS)
        self.assertNotIn("downloads.wordpress.org", module.OFFICIAL_GITHUB_REDIRECT_HOSTS)

    def test_wrong_version_or_authority_must_fail(self):
        for original, version in ((self.github, "0.7.0"), (self.wp_org, "0.7.1")):
            for modified in (
                {**original, "certification_authority": "self-signed"},
                {**original, "package_url": original["package_url"] + "?trusted=true"},
                {**original, "package_url": original["package_url"] + "#bypass"},
                {**original, "package_url": original["package_url"].replace("https://", "http://")},
                {**original, "package_url": original["package_url"].replace("https://", "https://user:pass@")},
                {**original, "package_url": original["package_url"].replace(".org/", ".org:8443/").replace(".com/", ".com:8443/")},
                {**original, "package_url": original["package_url"].replace("mcp-adapter.", "other-adapter.")},
            ):
                with self.subTest(version=version, profile=modified):
                    with self.assertRaises(SystemExit):
                        module.reviewed_source(modified, version)
            with self.assertRaises(SystemExit):
                module.reviewed_source(original, "9.9.9")

    def test_redirect_before_request_is_bounded(self):
        wp = module.CertifiedRedirectPolicy(module.OFFICIAL_WP_ORG_REDIRECT_HOSTS)
        gh = module.CertifiedRedirectPolicy(module.OFFICIAL_GITHUB_REDIRECT_HOSTS)
        for handler, target in (
            (wp, "https://example.invalid/plugin/mcp-adapter.zip"),
            (wp, "https://github.com/WordPress/mcp-adapter"),
            (gh, "https://downloads.wordpress.org/plugin/mcp-adapter.0.7.1.zip"),
            (wp, "http://downloads.wordpress.org/plugin/mcp-adapter.0.7.1.zip"),
            (wp, "https://downloads.wordpress.org:443/plugin/mcp-adapter.0.7.1.zip"),
            (wp, "https://downloads.wordpress.org/plugin/mcp-adapter.0.7.1.zip#frag"),
        ):
            with self.subTest(target=target):
                with self.assertRaises(SystemExit):
                    handler.redirect_request(None, None, 302, "Redirect", {}, target)

    def test_url_parse_fails_closed_for_credentials_and_ports(self):
        for url in (
            "", "ftp://downloads.wordpress.org/x", "https://u@downloads.wordpress.org/x",
            "https://downloads.wordpress.org:invalid/x",
            "https://downloads.wordpress.org:443/x", "https://downloads.wordpress.org/x?x=1",
        ):
            self.assertIsNone(module.trusted_url_parts(url))


if __name__ == "__main__":
    unittest.main(verbosity=2)
