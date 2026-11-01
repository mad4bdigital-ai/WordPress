"""Hermetic release evidence tests; no WordPress or GitHub writes."""
import json
import os
from pathlib import Path
import shutil
import tempfile
import unittest
import zipfile
from tools import mad4b_content_hub_package as h

HEAD = "a" * 40


class PackageTests(unittest.TestCase):
    def setUp(self):
        self.tmp = Path(tempfile.mkdtemp(prefix="content-hub-release-"))
        self.root = self.tmp / h.SOURCE
        self.root.mkdir(parents=True)
        (self.root / "includes").mkdir()
        for i in range(42):
            f = self.root / ("includes/file-%02d.php" % i)
            f.write_text("<?php\n// TEST %s\n" % i)
        (self.root / "mad4b-content-hub.php").write_text("<?php // QA plugin\n")
        self.out = self.tmp / "dist"

    def tearDown(self):
        shutil.rmtree(self.tmp)

    def package(self):
        receipt = h.build(self.tmp, self.out, HEAD)
        return self.out / receipt['archive_name'], self.out / "CONTENT-HUB-CANONICAL-RECEIPT.json"

    def test_reproducible_archive_and_original_manifest(self):
        a, r = self.package()
        digest = h.digest(a.read_bytes())
        a2, r2 = self.package()
        self.assertEqual(digest, h.digest(a2.read_bytes()))
        self.assertTrue(h.verify(self.tmp, a, r, HEAD)["verified"])
        self.assertEqual(len(h.source_files(self.tmp)), 43)

    def test_mismatched_exact_commit_rejected(self):
        a, r = self.package()
        with self.assertRaises(ValueError):
            h.verify(self.tmp, a, r, "b" * 40)

    def test_mutated_source_rejected(self):
        a, r = self.package()
        (self.root / "includes/file-03.php").write_text("changed")
        with self.assertRaises(ValueError):
            h.verify(self.tmp, a, r, HEAD)

    def test_wrong_archive_digest_rejected(self):
        a, r = self.package()
        x=json.loads(r.read_text())
        x['archive_sha256']="0"*64
        r.write_text(json.dumps(x))
        with self.assertRaises(ValueError):
            h.verify(self.tmp, a, r, HEAD)

    def test_forged_release_authority_denied(self):
        a, r = self.package()
        x=json.loads(r.read_text())
        x['deployment_authorized']=True
        r.write_text(json.dumps(x))
        with self.assertRaises(ValueError):
            h.verify(self.tmp, a, r, HEAD)

    def test_secret_like_source_rejected(self):
        (self.root / "includes/foo.pem").write_text("secret")
        with self.assertRaises(ValueError):
            h.build(self.tmp, self.out, HEAD)

    def test_symlink_source_rejected(self):
        os.symlink(self.root / "mad4b-content-hub.php",self.root / "includes/link.php")
        with self.assertRaises(ValueError):
            h.build(self.tmp, self.out, HEAD)

    def test_tampered_zip_payload_rejected(self):
        a, r = self.package()
        with zipfile.ZipFile(a,'a') as zip:
            zip.writestr('mad4b-content-hub/includes/rogue.php',"bad")
        with self.assertRaises(ValueError):
            h.verify(self.tmp, a, r, HEAD)

    def test_path_traversal_guard(self):
        self.assertFalse(h.valid_relative("mad4b-content-hub/../escape.php"))
        self.assertFalse(h.valid_relative("/etc/passwd"))


if __name__=="__main__":
    unittest.main()
