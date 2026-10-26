#!/usr/bin/env python3
"""Hermetic checks for explicit, non-authorizing fingerprint refresh."""
import hashlib
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest

spec = importlib.util.spec_from_file_location("rebind_delivery", Path(__file__).with_name("rebind-delivery.py"))
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class FingerprintTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.repo = Path(self.temp.name)
        self.extension = self.repo / module.EXTENSION
        self.extension.mkdir(parents=True)
        self.path = "wp-content/plugins/mad4b-site-control-plane/languages/fixture.mo"
        self.source = self.repo / self.path
        self.source.parent.mkdir(parents=True)
        self.raw = b"\x00\xff\x80actual-binary-fixture"
        self.source.write_bytes(self.raw)
        self.manifest = self.extension / "g9-delivery.json"
        self.doc = {"authorizing": False, "task_status": "PARTIAL", "live_staging_acceptance": False,
                    "code_paths": [self.path], "evidence_integrity": [{"path": self.path,
                    "sha256": "0" * 64, "bytes": 0}]}
        self.save()

    def save(self):
        self.manifest.write_text(json.dumps(self.doc) + "\n", encoding="utf-8")

    def test_read_only_then_explicit_idempotent_write(self):
        before = self.manifest.read_bytes()
        self.assertEqual(module.refresh(self.repo)["drift_count"], 1)
        self.assertEqual(self.manifest.read_bytes(), before)
        report = module.refresh(self.repo, write=True)
        self.assertFalse(report["authorizing"])
        updated = json.loads(self.manifest.read_text())
        self.assertFalse(updated["live_staging_acceptance"])
        self.assertEqual(updated["task_status"], "PARTIAL")
        self.assertEqual(updated["evidence_integrity"][0]["sha256"], hashlib.sha256(self.raw).hexdigest())
        self.assertEqual(updated["evidence_integrity"][0]["bytes"], len(self.raw))
        bound = self.manifest.read_bytes()
        self.assertEqual(module.refresh(self.repo, write=True)["drift_count"], 0)
        self.assertEqual(self.manifest.read_bytes(), bound)

    def test_source_mutation_is_detected(self):
        module.refresh(self.repo, write=True)
        self.source.write_bytes(self.raw + b"changed")
        self.assertEqual(module.refresh(self.repo)["drift_count"], 1)

    def test_invalid_paths_and_self_binding_are_denied(self):
        for path in ("../outside", "/tmp/outside", ".git/config", "wp-content//plugins/alias.php",
                     self.manifest.relative_to(self.repo).as_posix()):
            with self.subTest(path=path), self.assertRaises(ValueError):
                module.source_bytes(self.repo, path, self.manifest.relative_to(self.repo).as_posix())

    def test_symlink_is_denied(self):
        alias = self.source.with_name("alias.mo")
        alias.symlink_to(self.source)
        with self.assertRaises(ValueError):
            module.source_bytes(self.repo, alias.relative_to(self.repo).as_posix(), "manifest.json")

    def test_missing_and_duplicate_inventories_are_denied(self):
        self.doc["evidence_integrity"] = []
        self.save()
        with self.assertRaisesRegex(ValueError, "inventory_missing"):
            module.refresh(self.repo)
        record = {"path": self.path, "sha256": "0" * 64}
        self.doc["evidence_integrity"] = [record, record.copy()]
        self.save()
        with self.assertRaisesRegex(ValueError, "duplicate"):
            module.refresh(self.repo)

    def test_real_archival_manifests_accept_only_exact_reviewed_successors(self):
        report = module.refresh()
        self.assertEqual(report["drift_count"], 0)
        self.assertEqual(report["manifest_count"], 10)
        self.assertEqual(report["binding_count"], 227)
        self.assertFalse(report["runtime_certification_inferred"])
        self.assertFalse(report["authorizing"])

    def test_archival_source_rejects_modified_sha_length_and_contents(self):
        repo = module.REPO
        item = json.loads((module.HERE / "g8-delivery.json").read_text(encoding="utf-8"))
        rows = [x for key in module.GROUPS for x in item.get(key, [])
                if isinstance(x, dict) and "sha256" in x]
        row = next(x for x in rows
                   if x["path"].endswith("/competitive-experience/validate.py"))
        path = row["path"]
        content = (repo / path).read_bytes()
        self.assertTrue(module.reviewed_archival_successor(
            repo, path, row["sha256"], row["bytes"], content))
        self.assertFalse(module.reviewed_archival_successor(
            repo, path, "0" * 64, row["bytes"], content))
        self.assertFalse(module.reviewed_archival_successor(
            repo, path, row["sha256"], row["bytes"] + 1, content))
        self.assertFalse(module.reviewed_archival_successor(
            repo, path, row["sha256"], row["bytes"], content + b"tampered"))
        self.assertFalse(module.reviewed_archival_successor(
            self.repo, path, row["sha256"], row["bytes"], content))
        self.assertFalse(module.reviewed_archival_successor(
            repo, path + ".unreviewed", row["sha256"], row["bytes"], content))

    def test_real_archival_manifests_cannot_be_resigned(self):
        with self.assertRaisesRegex(ValueError, "immutable_repository_delivery_rebind_forbidden"):
            module.refresh(write=True)

    def test_unreviewed_manifests_in_disposable_repo_remain_detectable(self):
        self.doc["evidence_integrity"][0]["sha256"] = "0" * 64
        self.save()
        self.assertEqual(module.refresh(self.repo)["drift_count"], 1)
        self.assertEqual(self.manifest.read_text(encoding="utf-8"), json.dumps(self.doc) + "\n")

    def test_later_invalid_manifest_cannot_partially_write(self):
        invalid = self.extension / "zz-delivery.json"
        invalid.write_text(json.dumps({"code_paths": []}), encoding="utf-8")
        before = self.manifest.read_bytes()
        with self.assertRaisesRegex(ValueError, "inventory_missing"):
            module.refresh(self.repo, write=True)
        self.assertEqual(self.manifest.read_bytes(), before)


if __name__ == "__main__":
    unittest.main()
