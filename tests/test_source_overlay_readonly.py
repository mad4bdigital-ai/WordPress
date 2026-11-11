"""No-live-mutation tests of exact Git source provenance overlay."""
import copy
import importlib.util
import json
from pathlib import Path
import unittest

REPO=Path(__file__).resolve().parents[1]
p=REPO/"tools/verify_source_overlay_readonly.py"
spec=importlib.util.spec_from_file_location("overlay_review",p)
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
MANIFEST=json.loads((REPO/"docs/release-coverage/source-overlay-20261111.json").read_text())

class OverlayTests(unittest.TestCase):
    def test_exact_pinned_sources_pass_without_mutation(self):
        result=m.validate(REPO,MANIFEST)
        self.assertEqual(result["source_blobs_verified"],34)
        self.assertFalse(result["live_deployment_performed"])
        self.assertFalse(result["global_release_certified"])
    def test_wrong_base_ref_rejected(self):
        row=copy.deepcopy(MANIFEST)
        row["base_commit"]="z"*40
        with self.assertRaises(m.OverlayDenied):m.validate(REPO,row)
    def test_path_traversal_rejected(self):
        row=copy.deepcopy(MANIFEST)
        row["files"][0]["path"]="../secrets"
        with self.assertRaises(m.OverlayDenied):m.validate(REPO,row)
    def test_changed_content_sha_rejected(self):
        row=copy.deepcopy(MANIFEST)
        row["files"][0]["sha256"]="a"*64
        with self.assertRaises(m.OverlayDenied):m.validate(REPO,row)
    def test_wrong_donor_oid_rejected(self):
        row=copy.deepcopy(MANIFEST)
        row["files"][0]["git_blob_sha"]="0"*40
        with self.assertRaises(m.OverlayDenied):m.validate(REPO,row)
    def test_duplicate_entry_rejected(self):
        row=copy.deepcopy(MANIFEST)
        row["files"][1]["path"]=row["files"][0]["path"]
        with self.assertRaises(m.OverlayDenied):m.validate(REPO,row)
    def test_user_outside_scope_rejected(self):
        row=copy.deepcopy(MANIFEST)
        row["files"][0]["path"]="wp-content/uploads/data.json"
        with self.assertRaises(m.OverlayDenied):m.validate(REPO,row)

if __name__=="__main__":unittest.main(verbosity=2)
