import hashlib
import importlib.util
import json
import pathlib
import re
import tempfile
import unittest

BASE = pathlib.Path(__file__).resolve().parent.parent


def import_worker():
    path = BASE / "bin" / "company-pricing-cache-path-invalidation-v1.py"
    spec = importlib.util.spec_from_file_location("mad4b_pricing_worker", path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


class PricingCacheSourceTests(unittest.TestCase):
    def test_pinned_original_blueprints(self):
        release = json.loads((BASE / "RELEASE-MANIFEST.json").read_text())
        self.assertFalse(release["price_quotes_cached"])
        self.assertFalse(release["rate_html_cached"])
        self.assertEqual(release["default_blueprint_ttl_seconds"], 1800)
        self.assertEqual(release["maximum_ttl_seconds"], 2400)
        for name, expected in release["pinned_php_files_sha256"].items():
            path = BASE / "overlays" / name
            self.assertEqual(hashlib.sha256(path.read_bytes()).hexdigest(), expected, name)

    def test_worker_event_contract_denies_untrusted(self):
        worker = import_worker()
        event = {
            "contract": "mad4b.pricing-cache-policy-change.v1",
            "event_id": "11111111-2222-3333-4444-555555555555",
            "scope": "published_public_price_surfaces",
            "reason_classes": ["pricing_policy_option"],
            "created_utc": "2026-11-08T00:00:00Z",
        }
        with tempfile.TemporaryDirectory() as td:
            f = pathlib.Path(td) / "event.json"
            f.write_text(json.dumps(event))
            self.assertEqual(worker.safe_event(f)["event_id"], event["event_id"])
            for update in [
                {"contract": "unknown-contract"},
                {"event_id": "shell-command"},
                {"scope": "customer_quote"},
                {"reason_classes": ["shell_execution"]},
            ]:
                bad = event | update
                f.write_text(json.dumps(bad))
                self.assertIsNone(worker.safe_event(f))
            f.write_text(json.dumps(event) + " " * 4096)
            self.assertIsNone(worker.safe_event(f))

    def test_portable_package_never_contains_private_information(self):
        for path in BASE.rglob("*"):
            if not path.is_file() or path.suffix not in (
                ".php", ".py", ".json", ".yml", ".md", ".service", ".path", ".timer"
            ):
                continue
            data = path.read_text(errors="replace")
            self.assertIsNone(re.search(r"-{5}BEGIN (?:OPENSSH|RSA|EC) PRIVATE KEY-{5}",data), path.name)

    def test_price_source_originals_remain_available(self):
        source = (BASE / "overlays/mu-plugins/shared-product-core/pricing/source-resolution-policy.php").read_text()
        mode = (BASE / "overlays/mu-plugins/shared-product-core/pricing/jetengine-policy-provider.php").read_text()
        self.assertIn("function shared_product_price_source_policy_uncached(", source)
        self.assertIn("function shared_product_mode_policies_uncached(", mode)
        self.assertIn("memoize_source_policy", source)
        self.assertIn("memoize_mode_policies", mode)


if __name__ == "__main__":
    unittest.main()
