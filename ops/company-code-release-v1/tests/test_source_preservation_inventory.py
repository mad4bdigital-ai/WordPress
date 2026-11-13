"""Fail CI if a previously observed VPS-only integration disappears."""
import hashlib
import json
from pathlib import Path, PurePosixPath
import unittest

OPS=Path(__file__).resolve().parents[2]
MANIFEST=OPS/"company-code-release-v1/catalog/source-preservation-inventory.json"


class PreserveOriginalSource(unittest.TestCase):
    def test_all_reviewed_live_source_file_bytes_still_present(self):
        config=json.loads(MANIFEST.read_text())
        self.assertEqual(config["contract"],
                         "mad4b.release-source-preservation-inventory.v1")
        self.assertTrue(config["files"])
        visited=set()
        for item in config["files"]:
            with self.subTest(path=item["path"]):
                name=item["path"]
                rel=PurePosixPath(name)
                self.assertFalse(rel.is_absolute())
                self.assertNotIn("..",rel.parts)
                self.assertTrue(name.startswith("ops/"))
                self.assertNotIn(name,visited)
                visited.add(name)
                path=OPS.parent/name
                self.assertFalse(path.is_symlink())
                self.assertTrue(path.is_file(),name)
                self.assertEqual(hashlib.sha256(path.read_bytes()).hexdigest(),
                                 item["sha256"])


if __name__=="__main__":
    unittest.main()
