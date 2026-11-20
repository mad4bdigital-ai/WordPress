"""Source-only CAS regression: no implicit authority, no legacy provider override."""
from __future__ import annotations
import hashlib
import json
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
REG = ROOT / "ops/dynamic-operation-registry-v1"
PROVIDER = REG / "providers/company.resource.cas.apply/manifest.json"
PREVIEW = REG / "index.preview-NOT-ENROLLED.json"


class CASSourceConvergence(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.manifest = json.loads(PROVIDER.read_text())
        cls.preview = json.loads(PREVIEW.read_text())

    def test_noncommercial_scope_is_exact(self):
        scope = self.manifest["cas_scope"]
        self.assertEqual(self.manifest["operation_id"], "company.resource.cas.apply")
        self.assertEqual(scope["allowed_post_types"], ["tour-rates"])
        self.assertEqual(scope["allowed_meta_keys"], ["rate_title"])
        self.assertIs(scope["commercial_pricing_allowed"], False)

    def test_every_executable_hook_has_immutable_content_hash(self):
        for name in ("handler", "preclaim", "finalizer", "verifier", "evidence"):
            with self.subTest(name=name):
                row = self.manifest[name]
                path = REG / row["path"]
                self.assertTrue(path.is_file())
                self.assertEqual(hashlib.sha256(path.read_bytes()).hexdigest(),
                                 row["sha256"])

    def test_not_enrolled_in_source_preview(self):
        self.assertIs(self.preview["source_preview_only"], True)
        self.assertIs(self.preview["production_mutated"], False)
        paths = [r["manifest"] for r in self.preview["providers"]]
        self.assertFalse(any("company.resource.cas.apply" in p for p in paths))
        self.assertTrue(any("company.code.release" in p for p in paths))

    def test_current_worker_uses_generic_pinned_preclaim(self):
        source = (REG / "worker_adapter.py").read_text()
        self.assertIn("def provider_preclaim(", source)
        self.assertIn('registry.load_provider_hook(index,operation,"preclaim"', source)
        self.assertNotIn("if operation=='company.resource.cas.apply'", source)

    def test_current_php_has_operation_scoped_verifier(self):
        source = (REG / "php/class-mad4b-dynamic-313-wordpress.php").read_text()
        self.assertIn("function operation_enrolled(", source)
        self.assertIn("function approved_record(", source)


if __name__ == "__main__":
    unittest.main()
