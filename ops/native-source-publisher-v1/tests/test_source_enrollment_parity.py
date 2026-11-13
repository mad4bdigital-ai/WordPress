"""Source-preview manifest must pin exact current bytes of every dependency.

This test is NOT enrollment; a signed Original authority is still mandatory.
"""
import importlib.util
import hashlib
import json
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]
OPS = ROOT / "ops"
PATH = OPS / "native-source-publisher-v1"
s = importlib.util.spec_from_file_location(
    "mad4b_source_manifest_v2", PATH / "build_enrollment_candidate.py")
mod = importlib.util.module_from_spec(s)
s.loader.exec_module(mod)


class SourceManifestSourceParity(unittest.TestCase):
    def test_candidate_manifest_matches_actual_source_and_all_pinned_files(self):
        manifests=mod.build_all()
        self.assertGreaterEqual(len(manifests),1)
        for operation, document in manifests.items():
            with self.subTest(operation=operation):
                path=PATH / "catalog/generated" / (operation+".manifest.json")
                self.assertTrue(path.is_file(),operation)
                recorded=json.loads(path.read_text())
                self.assertEqual(recorded,document)
                records=[document["handler"],document["verifier"],
                         document["evidence"],*document["dependencies"]]
                for row in records:
                    with self.subTest(path=row["path"]):
                        target=OPS/row["path"]
                        self.assertTrue(target.is_file() and not target.is_symlink())
                        self.assertEqual(hashlib.sha256(target.read_bytes()).hexdigest(),
                                         row["sha256"])
        self.assertFalse(any(
            r.get("installed_by_first_party") is True for r in manifests.values()
        ))


if __name__ == "__main__":
    unittest.main()
