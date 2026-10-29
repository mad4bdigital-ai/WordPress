#!/usr/bin/env python3
"""Exact non-executable RC118 post-package drift source/CI evidence only."""
from pathlib import Path
import json, hashlib, unittest, difflib

ROOT = Path(__file__).resolve().parents[1]
INBOX = ROOT / "ops/qa31/rc118-source-intake"
ACTIVE = ROOT / "wp-content/plugins/mad4b-site-control-plane"
PINNED = {
 "config/cross-plugin-sources.json": ("c6edc0909e01996f53ce957e519cb54f6e9017df4fa325b501c17cea3536a156","d469041ce8aff754c94ff6cd754fa0b21dc2ee0da98a8355fd4ecfe0f5038fbb",29,2,True),
 "includes/class-mad4b-scp-adapter-registry.php": ("efa2c762bd91f2d6ba1964d7b53993c3fadc6ba01977e0232142eb3e4198b782","bf761a66478374f4002817065d87a68519db7b6b0a2365fed45aefc98989c794",9,2,True),
 "includes/class-mad4b-scp-market-profiles.php": ("3b2abf3dab1eea550859a7fa06f0a848702d30be061cdd0c0b14b1165bff7c60","5489499dbd9ecbaef3ac37f04c3e9bfc26fa3481cb412efa24a04a12d3bdcd67",5,2,True),
 "mad4b-site-control-plane.php": ("1a299f83f24efe2e91ae682e1e97d06a0809216c47d698bbf3085830f7898681","b67a6be43d4057d2061b980cc3abb0e2b2605c954a4fccabf38d6daa682ef4e3",5,3,False),
}

class DriftContract(unittest.TestCase):
 @classmethod
 def setUpClass(cls):
  cls.meta=json.loads((INBOX/"runtime-drift-lineage.json").read_text())
 def test_closed_identity_and_scope(self):
  m=self.meta
  self.assertEqual(m["contract"],"mad4b.qa31.rc118-post-package-source-lineage.v1")
  self.assertEqual((m["manifest_file_count"],m["manifest_matching_files"],m["manifest_changed_files"],m["manifest_missing_files"]),(877,873,4,0))
  self.assertEqual(m["manifest_source_commit_sha"],"0e83ec7ecfdb02ff2f946753721921e4e45523c7")
  self.assertEqual(m["manifest_digest"],"1aec439fd266dc1bc3dc866096d2e5c3aa701de78505e95564dd448a964ad001")
  self.assertEqual(set(v["path"] for v in m["candidate_files"]),set(PINNED))
  self.assertEqual(len(m["candidate_files"]),4)
 def test_non_authority(self):
  m=self.meta
  for key in ("authorizing","staging_install_authorized","production_install_authorized","provider_certified","staging_mutation_performed"):
   self.assertIs(m[key],False)
  for key in ("non_executable_source_intake","source_candidate_only","requires_independent_source_lineage_review","snapshot_main_is_not_original_package_manifest"):
   self.assertIs(m[key],True)
  self.assertEqual(m["release_status"],"BLOCKED_SOURCE_RECONCILIATION")
 def test_exact_eight_files_and_diff(self):
  for row in self.meta["candidate_files"]:
   name=row["path"];oldsha,newsha,adds,removes,historically_exact=PINNED[name]
   ext=".txt" if name.endswith(".php") else ""
   a="lineage/snapshot-20261028/"+name+ext
   b="lineage/observed-staging-20261029/"+name+ext
   self.assertEqual(row["source_candidate_snapshot_path"],a)
   self.assertEqual(row["source_candidate_observed_path"],b)
   self.assertEqual((row["snapshot_sha256"],row["observed_sha256"]),(oldsha,newsha))
   self.assertIs(row["snapshot_matches_package_manifest"],historically_exact)
   self.assertIs(row["observed_matches_package_manifest"],False)
   self.assertIs(row["source_authenticity_independently_confirmed"],False)
   self.assertIs(row["semantic_review_completed"],False)
   p,q=INBOX/a,INBOX/b
   self.assertTrue(p.is_file() and q.is_file() and not p.is_symlink() and not q.is_symlink())
   self.assertTrue(p.is_relative_to(INBOX) and q.is_relative_to(INBOX))
   self.assertFalse(p.is_relative_to(ACTIVE) or q.is_relative_to(ACTIVE))
   before,after=p.read_bytes(),q.read_bytes()
   self.assertEqual((hashlib.sha256(before).hexdigest(),hashlib.sha256(after).hexdigest()),(oldsha,newsha))
   self.assertEqual((len(before),len(after)),(row["snapshot_bytes"],row["observed_bytes"]))
   diff=list(difflib.unified_diff(before.decode().splitlines(),after.decode().splitlines(),n=0))
   self.assertEqual((sum(x.startswith("+") and not x.startswith("+++") for x in diff),sum(x.startswith("-") and not x.startswith("---") for x in diff)),(adds,removes))
   self.assertEqual((row["diff_added_lines"],row["diff_removed_lines"]),(adds,removes))
 def test_main_snapshot_not_original_package(self):
  m=next(x for x in self.meta["candidate_files"] if x["path"]=="mad4b-site-control-plane.php")
  self.assertNotEqual(m["snapshot_sha256"],m["package_recorded_sha256"])
  self.assertEqual(m["package_recorded_sha256"],"32d3aa84b15b0fa549c6dd8ffc131c2c292640e5b63991dab1a94f3f0e97cc00")
  self.assertIn("Version: 0.4.0-rc.119",(INBOX/m["source_candidate_snapshot_path"]).read_text())
  self.assertIn("Version: 0.4.0-rc.118",(INBOX/m["source_candidate_observed_path"]).read_text())
  self.assertFalse((ACTIVE/"includes/class-mad4b-scp-company-tenant-migration.php").exists())

if __name__=="__main__":
 unittest.main()
