#!/usr/bin/env python3
"""Contract tests: source provenance and runtime owners are not deploy grants."""
from __future__ import annotations
import copy
import importlib.util
import json
import shutil
import tempfile
from pathlib import Path
import unittest

SCRIPT = Path(__file__).with_name("verify.py")
spec = importlib.util.spec_from_file_location("mad4b_site_owner_verify", SCRIPT)
module = importlib.util.module_from_spec(spec)
assert spec and spec.loader
spec.loader.exec_module(module)


class SiteOwnerRegistryTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.original_repo = SCRIPT.resolve().parents[3]
        cls.canonical_registry = cls.original_repo / module.REGISTRY

    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.repo = Path(self.temp.name) / "repo"
        self.repo.mkdir()
        self.manifest = self.repo / module.REGISTRY
        self.manifest.parent.mkdir(parents=True)
        self.payload = json.loads(self.canonical_registry.read_text())
        for item in self.iter_variants():
            relative = item["source_artifact"]
            original = self.original_repo / relative
            dest = self.repo / relative
            dest.parent.mkdir(parents=True, exist_ok=True)
            if not dest.exists():
                shutil.copy2(original, dest)
        self.save()

    def iter_variants(self):
        for cap in self.payload["capabilities"]:
            yield from cap["variants"]

    def save(self):
        self.manifest.write_text(json.dumps(self.payload, indent=2) + "\n")

    def check(self, runtime=None):
        return module.validate(self.repo, self.manifest, runtime)

    def test_correct_snapshots_and_non_deployable_fence(self):
        result = self.check()
        self.assertEqual(result["variants"],
                         sum(len(cap["variants"]) for cap in self.payload["capabilities"]))
        self.assertFalse(result["release_deployable"])

    def test_changed_source_is_denied(self):
        path = self.repo / next(self.iter_variants())["source_artifact"]
        path.write_bytes(path.read_bytes() + b"\n// malicious drift\n")
        with self.assertRaisesRegex(module.InvalidProvenance, "SOURCE_BYTES_DRIFT"):
            self.check()

    def test_duplicate_owner_per_site_is_denied(self):
        entry = copy.deepcopy(self.payload["capabilities"][0]["variants"][0])
        self.payload["capabilities"][0]["variants"].append(entry)
        self.save()
        with self.assertRaisesRegex(module.InvalidProvenance,
                                    "MULTIPLE_ACTIVE_CAPABILITY_OWNERS"):
            self.check()

    def test_same_function_in_two_capabilities_is_denied(self):
        self.payload["capabilities"][1]["runtime_symbol"] = \
            self.payload["capabilities"][0]["runtime_symbol"]
        self.save()
        with self.assertRaisesRegex(module.InvalidProvenance, "DUPLICATE_FUNCTION_OWNER"):
            self.check()

    def test_non_deployable_fence_cannot_be_overridden(self):
        self.payload["release_boundary"]["deployable"] = True
        self.save()
        with self.assertRaisesRegex(module.InvalidProvenance,
                                    "RELEASE_FENCE_MUST_REMAIN_SOURCE_ONLY"):
            self.check()

    def test_path_traversal_is_denied(self):
        self.payload["capabilities"][0]["variants"][0]["source_artifact"] = \
            "ops/company-code-release-v1/site-owner-sources/files/../../private.php"
        self.save()
        with self.assertRaisesRegex(module.InvalidProvenance, "UNSAFE_OR_UNSCOPED_PATH"):
            self.check()

    def test_symlinked_source_is_denied(self):
        path = self.repo / self.payload["capabilities"][1]["variants"][0]["source_artifact"]
        path.rename(path.with_suffix(".other"))
        path.symlink_to(path.with_suffix(".other"))
        with self.assertRaisesRegex(module.InvalidProvenance, "SYMLINK_DENIED"):
            self.check()

    def test_runtime_drift_is_denied_even_if_source_matches(self):
        site = "wayfatours"
        root = Path(self.temp.name) / "site"
        root.mkdir()
        for item in self.iter_variants():
            if item["site"] == site:
                runtime_file = root / item["runtime_path"]
                runtime_file.parent.mkdir(parents=True, exist_ok=True)
                runtime_file.write_bytes((self.repo / item["source_artifact"]).read_bytes())
        self.assertEqual(self.check({site:root})["runtime_variants_checked"], 2)
        first = next(item for item in self.iter_variants() if item["site"] == site)
        (root / first["runtime_path"]).write_text("<?php // replaced\n")
        with self.assertRaisesRegex(module.InvalidProvenance, "LIVE_OWNER_BYTES_DRIFT"):
            self.check({site:root})

    def test_runtime_site_must_be_registered(self):
        root = Path(self.temp.name) / "unregistered"
        root.mkdir()
        with self.assertRaisesRegex(module.InvalidProvenance, "UNREGISTERED_RUNTIME_SITE"):
            self.check({"other-site":root})

    def test_wrong_owner_kind_and_path_are_denied(self):
        self.payload["capabilities"][1]["variants"][0]["owner_kind"] = "native-plugin"
        self.save()
        with self.assertRaisesRegex(module.InvalidProvenance, "UNSAFE_OR_UNSCOPED_PATH"):
            self.check()

    def test_unregistered_variant_fields_are_denied(self):
        self.payload["capabilities"][0]["variants"][0]["fake_grant"] = True
        self.save()
        with self.assertRaisesRegex(module.InvalidProvenance, "VARIANT_SHAPE_INVALID"):
            self.check()

    def test_future_environment_name_is_supported(self):
        self.payload["capabilities"][0]["variants"][0]["environment"] = "preprod_eu_v2"
        self.save()
        self.assertEqual(self.check()["status"], "SOURCE_OWNER_PROVENANCE_PASS")

    def test_duplicate_manifest_keys_denied(self):
        bad = self.manifest.read_text().replace(
            '"contract": "mad4b.site-owner-source-registry.v1",',
            '"contract": "mad4b.site-owner-source-registry.v1", "contract": "anything",'
        )
        self.manifest.write_text(bad)
        with self.assertRaisesRegex(module.InvalidProvenance, "DUPLICATE_JSON_FIELD"):
            self.check()


if __name__ == "__main__":
    unittest.main()
