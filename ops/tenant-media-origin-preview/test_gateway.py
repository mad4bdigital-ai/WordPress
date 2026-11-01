#!/usr/bin/env python3
"""Offline regression for the tenant-bound media catalog gateway."""
import copy
import hashlib
import importlib.util
import tempfile
import unittest
from pathlib import Path

MODULE_PATH = Path(__file__).with_name("build_gateway.py")
spec = importlib.util.spec_from_file_location("gateway", MODULE_PATH)
gateway = importlib.util.module_from_spec(spec)
spec.loader.exec_module(gateway)

class GatewayTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.image = self.root / "2026/10/fixture.png"
        self.image.parent.mkdir(parents=True)
        self.image.write_bytes(b"\x89PNG\r\n\x1a\nfixture")
        self.catalog = {
            "contract": "mad4b.tenant-media-catalog.preview.v1",
            "mode": "loopback-only",
            "tenants": [
                {"key": "etg", "host": "media.alpha.example", "assets": [
                    {"path": "2026/10/fixture.png", "mime": "image/png",
                     "sha256": hashlib.sha256(self.image.read_bytes()).hexdigest(),
                     "preview_approved": True, "public_approved": False}
                ]},
                {"key": "are", "host": "media.beta.example", "assets": []}
            ]
        }

    def generate(self, data=None):
        return gateway.generate(data or self.catalog, {"etg": str(self.root)})

    def reject(self, change):
        data = copy.deepcopy(self.catalog)
        change(data)
        with self.assertRaises(ValueError):
            self.generate(data)

    def test_bound_hosts_and_exact_asset_only(self):
        conf, summary = self.generate()
        self.assertEqual(summary["catalog_images"], 1)
        self.assertEqual(summary["tenants"], 2)
        self.assertIn("listen 8080 default_server; server_name _; return 421;", conf)
        self.assertIn("server_name media.alpha.example;", conf)
        self.assertIn("server_name media.beta.example;", conf)
        self.assertEqual(conf.count("location = /2026/10/fixture.png"), 1)
        self.assertNotIn("location /2026/", conf)
        self.assertIn("location / { return 404; }", conf)

    def test_dangerous_host(self):
        self.reject(lambda x: x["tenants"][0].update(host="bad;host"))

    def test_duplicate_host(self):
        self.reject(lambda x: x["tenants"][1].update(host="media.alpha.example"))

    def test_traversal_path(self):
        self.reject(lambda x: x["tenants"][0]["assets"][0].update(path="../wp-config.php"))

    def test_percent_path(self):
        self.reject(lambda x: x["tenants"][0]["assets"][0].update(path="2026/%2e%2e/p.png"))

    def test_hidden_file(self):
        self.reject(lambda x: x["tenants"][0]["assets"][0].update(path=".htaccess"))

    def test_not_image(self):
        self.reject(lambda x: x["tenants"][0]["assets"][0].update(path="2026/10/data.sql"))

    def test_digest_mismatch(self):
        self.reject(lambda x: x["tenants"][0]["assets"][0].update(sha256="a"*64))

    def test_public_mode_rejected(self):
        self.reject(lambda x: x.update(mode="public"))

    def test_public_asset_rejected(self):
        self.reject(lambda x: x["tenants"][0]["assets"][0].update(public_approved=True))

    def test_cross_tenant_volume_isolation(self):
        self.reject(lambda x: x["tenants"][1]["assets"].append(copy.deepcopy(x["tenants"][0]["assets"][0])))

    def test_duplicate_asset(self):
        self.reject(lambda x: x["tenants"][0]["assets"].append(copy.deepcopy(x["tenants"][0]["assets"][0])))

    def test_symlink_asset_rejected(self):
        linked = self.root / "2026/10/linked.png"
        linked.symlink_to(self.image)
        a = self.catalog["tenants"][0]["assets"][0]
        a["path"] = "2026/10/linked.png"
        with self.assertRaises(ValueError):
            self.generate()

if __name__ == "__main__":
    unittest.main(verbosity=2)
