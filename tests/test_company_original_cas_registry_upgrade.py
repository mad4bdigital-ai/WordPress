"""Version-neutral, read-only registry-upgrade proposal tests."""
from __future__ import annotations
import copy
import hashlib
import importlib.util
import json
from pathlib import Path
import unittest

SRC=Path(__file__).resolve().parents[1]/"ops/dynamic-operation-registry-v1"
FILE=SRC/"executor-time-cas-v1/integrations/plan_original_registry_upgrade.py"
spec=importlib.util.spec_from_file_location("mad4b_cas_registry_readonly_upgrade",FILE)
upgrade=importlib.util.module_from_spec(spec)
spec.loader.exec_module(upgrade)


class RegistryUpgrade(unittest.TestCase):
 def setUp(self):
  self.manifest=json.loads(
     (SRC/"providers/company.resource.cas.apply/manifest.json").read_text())
  self.index={
    "contract":"mad4b.dynamic-operation-index.v1",
    "installed_by_first_party":True,
    "independent_verifier_public_key_b64":"no_key_read_by_proposal",
    "providers":[
      {"manifest":"existing/vendor.operation/manifest.json","sha256":"a"*64},
      {"manifest":"existing/another.operation/manifest.json","sha256":"b"*64},
    ]
  }

 def plan(self):
  return upgrade.catalog_upgrade(self.index,self.manifest,SRC)

 def test_adds_one_operation_without_replacing_existing(self):
  before=copy.deepcopy(self.index)
  candidate=self.plan()
  self.assertEqual(candidate["registered_operations_before"],2)
  self.assertEqual(candidate["registered_operations_after"],3)
  self.assertEqual(self.index,before)
  self.assertEqual(candidate["candidate_index"]["providers"][:2],
                   before["providers"])
  self.assertFalse(candidate["root_registry_mutated"])
  self.assertFalse(candidate["original_313_certificate_issued"])
  self.assertFalse(candidate["original_worker_mutated"])

 def test_unchanged_existing_independent_public_trust(self):
  candidate=self.plan()
  self.assertEqual(candidate["candidate_index"]["independent_verifier_public_key_b64"],
                   self.index["independent_verifier_public_key_b64"])

 def test_signed_worker_operation_not_added_without_provider(self):
  candidate=self.plan()
  self.assertEqual(candidate["state"],"CANDIDATE_ONLY_GOVERNED_RELEASE_REQUIRED")

 def test_existing_duplicate_operation_manifest_path_rejected(self):
  self.index["providers"].append({
    "manifest":upgrade.CAS_INDEX_RELATIVE,"sha256":"0"*64})
  with self.assertRaises(upgrade.Refused):self.plan()

 def test_commercial_price_field_cannot_be_added(self):
  self.manifest["cas_scope"]["allowed_meta_keys"]=["single_price"]
  with self.assertRaises(upgrade.Refused):self.plan()

 def test_commercial_pricing_flag_cannot_be_activated(self):
  self.manifest["cas_scope"]["commercial_pricing_allowed"]=True
  with self.assertRaises(upgrade.Refused):self.plan()

 def test_original_registry_not_first_party_rejected(self):
  self.index["installed_by_first_party"]=False
  with self.assertRaises(upgrade.Refused):self.plan()

 def test_preclaim_source_hash_drift_rejected(self):
  self.manifest["preclaim"]["sha256"]="0"*64
  with self.assertRaises(upgrade.Refused):self.plan()

 def test_finalizer_source_hash_drift_rejected(self):
  self.manifest["finalizer"]["sha256"]="0"*64
  with self.assertRaises(upgrade.Refused):self.plan()

 def test_exact_source_manifest_equal_to_candidate_required(self):
  self.manifest["version"]=777
  with self.assertRaises(upgrade.Refused):self.plan()

 def test_no_key_or_grant_generation_workflow(self):
  source=FILE.read_text()
  self.assertNotIn("Ed25519PrivateKey",source)
  self.assertNotIn("def install(",source)
  self.assertNotIn("def apply(",source)
  self.assertNotIn("issue_grant(",source)

if __name__=="__main__":
 unittest.main()
