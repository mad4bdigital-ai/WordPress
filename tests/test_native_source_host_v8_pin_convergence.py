"""Original Host Provider V8 exact-source parity audit. CI-only; no running Host calls."""
from __future__ import annotations
import ast
import hashlib
import json
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MANIFEST = ROOT / "ops/dynamic-operation-registry-v1/providers/host.source.release/manifest.v8.CANDIDATE.json"
REPORT = ROOT / "docs/release-coverage/HOST_PROVIDER_V8_PINNED_SOURCE_REVIEW_20261116.json"
ACTIVE_PREVIEW = ROOT / "ops/dynamic-operation-registry-v1/index.preview-NOT-ENROLLED.json"


def sha(source: Path) -> str:
    return hashlib.sha256(source.read_bytes()).hexdigest()


class ProviderV8PinnedSource(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.manifest = json.loads(MANIFEST.read_text())
        cls.report = json.loads(REPORT.read_text())

    def test_manifest_is_exact_reviewed_v8_bytes(self):
        self.assertEqual(sha(MANIFEST),
                         "be9457e6ea2a93f7b6ccc1083e9c68aa2cc9cd29832a7754ea23503570c248bc")
        self.assertEqual(self.manifest["operation_id"], "host.source.release")
        self.assertEqual(self.manifest["version"], 8)
        self.assertEqual(self.report["source_manifest_sha256"], sha(MANIFEST))

    def test_all_27_pins_are_examined_and_only_one_newer_release_is_held(self):
        pins = {r["path"]: r["sha256"] for r in self.manifest["dependencies"]}
        for kind in ("handler", "verifier", "finalizer", "evidence"):
            item = self.manifest[kind]
            self.assertIn(item["path"], pins if item["path"] in pins else {item["path"]: item["sha256"]})
            if item["path"] in pins: self.assertEqual(pins[item["path"]], item["sha256"])
            pins[item["path"]] = item["sha256"]
        self.assertEqual(len(pins), 27)
        exact = self.report["exact_source_components"]
        drift = self.report["canonical_source_divergence"]
        self.assertEqual(len(exact), 26)
        self.assertEqual(len(drift), 1)
        self.assertEqual({r["path"] for r in drift}, {"company-code-release-v1/company_code_release.py"})
        self.assertEqual({r["path"] for r in exact + drift}, set(pins))
        for item in exact + drift:
            with self.subTest(path=item["path"]):
                relative = Path(item["path"])
                self.assertNotIn("..", relative.parts)
                self.assertFalse(relative.is_absolute())
                path = ROOT / "ops" / relative
                self.assertTrue(path.is_file())
                self.assertEqual(item["provider_pinned_sha256"], pins[item["path"]])
                self.assertEqual(item["current_sha256"], sha(path))
                if item in exact:
                    self.assertEqual(sha(path), pins[item["path"]])
                    if path.suffix == ".py": ast.parse(path.read_text())
                else:
                    self.assertNotEqual(sha(path), pins[item["path"]])

    def test_no_implicit_activation_or_approval(self):
        self.assertFalse((MANIFEST.parent / "manifest.json").exists())
        preview = json.loads(ACTIVE_PREVIEW.read_text())
        self.assertIs(preview["source_preview_only"], True)
        self.assertIs(preview["production_mutated"], False)
        self.assertFalse(any("host.source.release" in p["manifest"] for p in preview["providers"]))
        for name in ("source_only",):
            self.assertIs(self.report[name], True)
        for name in ("root_registry_modified", "wordpress_production_modified",
                     "new_master_admin_approval", "new_original313_receipt",
                     "production_authorized"):
            self.assertIs(self.report[name], False)
        self.assertEqual(self.report["verdict"], "SOURCE_PINNED_FILES_RESTORED__ONE_NEWER_CANONICAL_CODE_RELEASE_HOLD")


if __name__ == "__main__":
    unittest.main()
