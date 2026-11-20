#!/usr/bin/env python3
"""Host v125 live Staging dependency source parity — safe, hermetic tests.

Loads no WordPress or dynamic PHP, performs no network requests, uses no keys.
Only checks exact, non-autoloaded byte snapshots and the documented constraints.
"""
from pathlib import Path
import hashlib
import json
import re
import unittest

ROOT=Path(__file__).resolve().parents[2]
MANIFEST=ROOT/"governance/host-authority/live-dependency-source-parity-v125.json"
LOADER=ROOT/"governance/host-authority/runtime-closure-v125.json"
EVIDENCE=ROOT/"governance/host-authority/live-staging-runtime-evidence-v125.json"
SOURCE_PREFIX="deploy/host-authority/reconciliation/v125/live-dependency-sources/"
SECURITY=[
 re.compile(rb"-----BEGIN (?:[A-Z ]+ )?PRIVATE KEY-----",re.I),
 re.compile(rb"(?:sk_live_|sk_test_|rk_live_|ghp_|gho_|github_pat_|xox[baprs]-|whsec_)[A-Za-z0-9_/-]{14,}",re.I),
 re.compile(rb"AKIA[0-9A-Z]{16}"),
 re.compile(rb"Bearer[ \t]+[a-zA-Z0-9._=-]{45,}",re.I)
]
PHP_DECL=re.compile(r"\b(?:(?:final|abstract|readonly)\s+)?(?:class|trait|interface)\s+(MAD4B_[A-Za-z0-9_]+)\b")
EXPECTED_SCOPE={"company":"all-royal-egypt","environment":"staging","host":"hosting-2"}
class LiveSourceManifest(unittest.TestCase):
 @classmethod
 def setUpClass(cls):
  cls.m=json.loads(MANIFEST.read_text())
  cls.closure=json.loads(LOADER.read_text())
  cls.evidence=json.loads(EVIDENCE.read_text())
  cls.files=cls.m["files"]
 def test_01_inert_source_only_runtime_not_enabled(self):
  d=self.m
  self.assertEqual(d["contract"],"mad4b.host-v125.live-dependency-snapshot.v1")
  self.assertEqual(d["master_base_sha"],"1ed359a52ecd1c94574ddfd31c9327de5779b856")
  self.assertEqual(d["mode"],"SOURCE_REVIEW_ONLY_NOT_ACTIVATED")
  for x in ("wordpress_autoload","activate_plugins","insert_root_keys","production_release"):
   self.assertIs(d["release_policy"][x],False)
  self.assertIs(d["release_policy"]["requires_exact_head_owner_attestation_for_executable_release"],True)
  self.assertEqual(self.closure["scope"],EXPECTED_SCOPE)
  self.assertEqual(self.closure["eligibility"],"HOLD_RUNTIME_ACTIVATION")
 def test_02_all_71_files_are_exact_sha256_pinned_in_review_only(self):
  self.assertEqual(len(self.files),71)
  self.assertEqual(len({x["snapshot_path"] for x in self.files}),71)
  self.assertEqual(len({x["live_path"] for x in self.files}),71)
  for f in self.files:
   with self.subTest(path=f["live_path"]):
    path=ROOT/f["snapshot_path"]
    self.assertTrue(f["snapshot_path"].startswith(SOURCE_PREFIX))
    self.assertNotIn("/../",f["snapshot_path"])
    self.assertFalse(path.is_symlink())
    b=path.read_bytes()
    self.assertEqual(len(b),f["byte_size"])
    self.assertEqual(hashlib.sha256(b).hexdigest(),f["sha256"])
    self.assertGreater(len(b),100)
    self.assertFalse(any(p.search(b) for p in SECURITY))
 def test_03_evidence_counts_are_not_inflated(self):
  c=self.m["counts"]
  self.assertEqual(c["portfolio_php_included"],64)
  self.assertEqual(c["supplementary_files"],6)
  self.assertEqual(c["php_source_files"],70)
  self.assertEqual(c["json_contract_files"],1)
  self.assertEqual(c["selected_total"],71)
  self.assertEqual(c["missing_from_master"],70)
  self.assertEqual(c["master_equal_live"],0)
  self.assertEqual(c["total_bytes"],sum(f["byte_size"] for f in self.files))
  self.assertEqual(sum(f["master_present"] for f in self.files),1)
 def test_04_portfolio_is_all_64_wpcli_included_source_files(self):
  files=[x for x in self.files if x["category"]=="portfolio_manager"]
  self.assertEqual(len(files),64)
  self.assertTrue(all(x["included_in_wpcli"] for x in files))
  self.assertEqual(len({x["staging_load_index"] for x in files}),64)
  self.assertTrue(all(x["live_path"].startswith("wp-content/plugins/mad4b-company-portfolio-manager/") for x in files))
  self.assertEqual(sum(x["byte_size"] for x in files),1168184)
  self.assertTrue(any(x["live_path"].endswith("/mad4b-company-portfolio-manager.php") for x in files))
 def test_05_13_existing_external_classes_have_provenance(self):
  e={x["class"]:x for x in self.evidence["external_classes"]}
  self.assertEqual(len(e),13)
  for name,row in e.items():
   self.assertTrue(row["loaded_on_staging"])
   if name=="MAD4B_SCP_Audit":
    # master/SCP file already carries the exact live audit source SHA.
    self.assertTrue(row["master_matches_staging"])
    continue
   f=next(x for x in self.files if x["live_path"]==row["staging_file"])
   self.assertEqual(f["sha256"],row["staging_sha256"])
  self.assertEqual(sum(x["master_matches_staging"] is True for x in e.values()),1)
 def test_06_site_profile_master_must_not_be_overwritten(self):
  e={x["class"]:x for x in self.evidence["external_classes"]}
  p=e["MAD4B_SCP_Site_Profile"]
  self.assertTrue(p["master_file_present"])
  self.assertFalse(p["master_matches_staging"])
  self.assertNotEqual(p["master_sha256"],p["staging_sha256"])
  f=next(x for x in self.files if x["live_path"]==p["staging_file"])
  self.assertNotEqual(f["sha256"],p["master_sha256"])
 def test_07_mu_map_matches_exact_integrity_contract(self):
  root=ROOT/SOURCE_PREFIX/"wp-content/plugins/mad4b-platform/config"
  d=json.loads((root/"ordered-mu-map-contract.json").read_text())
  self.assertEqual(d["contract"],"mad4b.mu-ordered-map-integrity.v1")
  self.assertEqual(d["expected_count"],379)
  self.assertEqual(len(d["ordered_entries"]),379)
  self.assertEqual(d["by_family"],{"mad4b-platform":320,"mad4b-commerce":59})
  payload=json.dumps(d["ordered_entries"],separators=(",",":"),ensure_ascii=False).encode()
  self.assertEqual(hashlib.sha256(payload).hexdigest(),d["ordered_entries_sha256"])
  self.assertEqual(self.m["counts"]["expected_mu_entries"],379)
 def test_08_not_yet_a_full_runtime_dependency_closure(self):
  self.assertEqual(self.closure["selected_file_count"],32)
  self.assertEqual(len(self.closure["outside_plugin_classes"]),13)
  self.assertTrue(all(x["resolved"] is False for x in self.closure["outside_plugin_classes"]))
  self.assertFalse((ROOT/"wp-content/plugins/mad4b-company-portfolio-manager").exists())
  self.assertFalse((ROOT/"wp-content/mu-plugins/00-0-mad4b-runtime-router.php").exists())
  self.assertTrue(any("conditional dynamic includes" in x for x in self.m["gates"]))
 def test_09_source_classes_have_no_competing_original305_sealed_v2(self):
  m=self.closure
  alt=m["collision_definitions"][0]["not_mapped"][0]
  self.assertFalse(any(x["live_path"].endswith(alt) for x in self.files))
  self.assertFalse(self.evidence["runtime_include_observation"]["alternate_sealed_class_definition_included"])
 def test_10_all_selected_php_files_are_static_source_only(self):
  files=[x for x in self.files if x["live_path"].endswith(".php")]
  self.assertEqual(len(files),70)
  self.assertTrue(all((ROOT/x["snapshot_path"]).read_bytes().startswith(b"<?php") for x in files))
  self.assertTrue(all(x["snapshot_path"].startswith("deploy/") for x in files))
 def test_11_dynamic_entrypoint_and_map_digest_are_separate(self):
  main=[x for x in self.files if x["live_path"].endswith("/mad4b-company-portfolio-manager.php")]
  mu=[x for x in self.files if x["live_path"].endswith("/00-0-mad4b-runtime-router.php")]
  self.assertEqual(len(main),1)
  self.assertEqual(len(mu),1)
  self.assertNotEqual(main[0]["sha256"],mu[0]["sha256"])
  self.assertEqual(mu[0]["category"],"mu_bootstrap")
 def test_12_known_missing_originals_remain_unloaded_from_master(self):
  self.assertFalse(self.m["release_policy"]["wordpress_autoload"])
  self.assertTrue(self.closure["activation"]["new_signed_owner_approval_needed_for_live_release"])
class StagingAnonymousPermissionEvidence(unittest.TestCase):
 @classmethod
 def setUpClass(cls):
  cls.e=json.loads((ROOT/"governance/host-authority/anonymous-authorization-readback-v125.json").read_text())
 def test_13_anonymous_admin_permission_is_denied(self):
  a=self.e["staging_readback"]
  self.assertEqual(self.e["contract"],"mad4b.host-v125.anonymous-staging-authorization-readback.v1")
  self.assertEqual(a["user_id"],0)
  self.assertEqual(a["site_env"],"staging")
  self.assertTrue(a["company_manager_loaded"])
  self.assertEqual(a["anonymous_admin_denied_code"],"mad4b_manager_admin_required")
 def test_14_anonymous_read_permission_is_denied(self):
  a=self.e["staging_readback"]
  self.assertEqual(a["anonymous_read_denied_code"],"mad4b_manager_login_required")
  self.assertTrue(a["manager_staging_identity"])
  self.assertTrue(a["no_write_actions"])
 def test_15_read_only_routes_seen_but_not_authorized(self):
  a=self.e["staging_readback"]
  self.assertEqual(a["route_discovery_count"],13)
  self.assertFalse(self.e["owner_token_used"])
  self.assertFalse(self.e["permission_writes"])
  self.assertFalse(self.e["request_replayed"])
 def test_16_false_approval_not_inferred_from_staging_status(self):
  a=self.e["staging_readback"]
  self.assertTrue(a["manager_staging_identity"])
  self.assertEqual(a["anonymous_admin_denied_code"],"mad4b_manager_admin_required")

if __name__=="__main__":
 unittest.main(verbosity=2)
