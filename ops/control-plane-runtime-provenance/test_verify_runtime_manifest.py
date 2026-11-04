#!/usr/bin/env python3
"""Focused regression tests for the read-only release drift preflight."""
from __future__ import annotations

import hashlib
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

SCRIPT = Path(__file__).with_name("verify_runtime_manifest.py")
HEAD = "a" * 40


def blob(text: str) -> dict:
    data = text.encode("utf-8")
    return {"sha256": hashlib.sha256(data).hexdigest(), "bytes": len(data)}


class ManifestPreflightTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.root = Path(self.tmp.name)
        (self.root / "includes").mkdir()
        (self.root / "includes" / "component.php").write_text("<?php return true;\n")
        self.manifest = {
            "contract": "mad4b.build-provenance.v1",
            "source_commit_sha": HEAD,
            "build_fingerprint": "b" * 64,
            "package_manifest_digest": "c" * 64,
            "manifest_file_count": 1,
            "package_files": [{"path": "includes/component.php", **blob("<?php return true;\n")}],
        }
        self.save()

    def tearDown(self):
        self.tmp.cleanup()

    def save(self):
        (self.root / "MAD4B-BUILD-PROVENANCE.json").write_text(json.dumps(self.manifest))

    def check(self, head=HEAD):
        p = subprocess.run([sys.executable, str(SCRIPT), "--plugin-root", str(self.root),
                            "--expected-source-head", head], capture_output=True, text=True)
        return p.returncode, json.loads(p.stdout)

    def test_matching_manifest_passes(self):
        rc, obj = self.check()
        self.assertEqual(rc, 0)
        self.assertEqual(obj["matched"], 1)
        self.assertFalse(obj["authorization_granted"])

    def test_post_build_content_change_fails(self):
        (self.root / "includes" / "component.php").write_text("<?php return false;\n")
        rc, obj = self.check()
        self.assertEqual(rc, 1)
        self.assertEqual(obj["state"], "DRIFT")
        self.assertEqual(obj["matched"], 0)

    def test_missing_component_fails(self):
        (self.root / "includes" / "component.php").unlink()
        rc, obj = self.check()
        self.assertEqual(rc, 1)
        self.assertTrue(any("missing_or_unsafe_file" in x for x in obj["issues"]))

    def test_invalid_source_head_fails(self):
        rc, obj = self.check(head="d" * 40)
        self.assertEqual(rc, 1)
        self.assertIn("pinned_source_head_mismatch", obj["issues"])

    def test_path_traversal_fails(self):
        self.manifest["package_files"][0]["path"] = "../component.php"
        self.save()
        rc, obj = self.check()
        self.assertEqual(rc, 1)
        self.assertTrue(any("unsafe_manifest_path" in x for x in obj["issues"]))

    def test_duplicate_entry_fails(self):
        self.manifest["package_files"].append(self.manifest["package_files"][0].copy())
        self.manifest["manifest_file_count"] = 2
        self.save()
        rc, obj = self.check()
        self.assertEqual(rc, 1)
        self.assertIn("invalid_or_duplicate_file_path", obj["issues"])


if __name__ == "__main__":
    unittest.main()
