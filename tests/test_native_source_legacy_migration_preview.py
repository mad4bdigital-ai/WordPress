"""Unsigned source-only legacy plan migration preview; no Job or Grant issuance."""
from __future__ import annotations
import importlib.util
import json
from pathlib import Path
import tempfile
import types
import unittest

ROOT=Path(__file__).resolve().parents[1]
SOURCE=ROOT/"ops/native-source-publisher-v1/legacy_plan_migration_preview.py"
sp=importlib.util.spec_from_file_location("mad4b_legacy_preview_fixture",SOURCE)
preview=importlib.util.module_from_spec(sp)
sp.loader.exec_module(preview)

class Registry:
 def __init__(self,duplicate=False):
  self.manifests={
    "legacy.normal.operation":{
       "operation_id":"legacy.normal.operation",
       "provider":"legacy-normal",
       "plan_contract":"mad4b.example.plan.v1",
    },
    "legacy.other.operation":{
       "operation_id":"legacy.other.operation",
       "provider":"legacy-other",
       "plan_contract":"mad4b.other.plan.v1",
    }
  }
  if duplicate:
   self.manifests["legacy.duplicate.operation"]={
       "operation_id":"legacy.duplicate.operation",
       "provider":"legacy-duplicate",
       "plan_contract":"mad4b.example.plan.v1",
   }

class LegacyPreview(unittest.TestCase):
 def setUp(self):
  self.temp=tempfile.TemporaryDirectory(prefix="mad4b-legacy-preview-")
  self.addCleanup(self.temp.cleanup)
  self.root=Path(self.temp.name)/"store"
  self.root.mkdir(mode=0o700)
  self.candidates={
    "source.dynamic.operation":{
      "operation_id":"source.dynamic.operation",
      "provider":"source-dynamic",
      "plan_contract":"mad4b.example.plan.v1",
    }
  }
  self.reader=types.SimpleNamespace(
      checked_json=lambda path,root:json.loads(path.read_text()))
  self.admission=types.SimpleNamespace(
      checked_plan=lambda root,sha,manifest:
      json.loads((root/(sha+".json")).read_text()))
  self.registry=Registry()

 def write(self,suffix,contract):
  sha=suffix*64
  f=self.root/(sha+".json")
  f.write_text(json.dumps({
   "plan_sha256":sha,"contract":contract,
   "company":"example","environment":"production",
   "site_uuid":"12345678-1234-4123-8123-123456789abc",
   "origin":"https://example.invalid"
  }))
  f.chmod(0o600)

 def run_preview(self,registry=None):
  return preview.inventory(
    registry or self.registry,self.root,self.candidates,
    admission_module=self.admission,registry_module=self.reader)

 def test_legacy_unique_prior_operation_produces_unsigned_request(self):
  self.write("a","mad4b.example.plan.v1")
  self.write("b","mad4b.other.plan.v1")
  result=self.run_preview()
  self.assertEqual(result["existing_plan_count_on_shared_contracts"],1)
  self.assertEqual(result["uniquely_attributable_legacy_plans"],1)
  self.assertEqual(result["unresolved_legacy_plans"],0)
  req=result["requests"][0]
  self.assertEqual(req["operation_id"],"legacy.normal.operation")
  self.assertEqual(req["plan_sha256"],"a"*64)
  self.assertFalse(req["association_issued"])
  self.assertFalse(result["original_305_jobs_verified"])
  self.assertFalse(result["plan_store_modified"])
  self.assertEqual(
     preview.summary_without_plan_identifiers(result).get("requests"),None)

 def test_duplicate_old_provider_does_not_guess(self):
  self.write("a","mad4b.example.plan.v1")
  result=self.run_preview(Registry(duplicate=True))
  self.assertEqual(result["uniquely_attributable_legacy_plans"],0)
  self.assertEqual(result["unresolved_legacy_plans"],1)
  self.assertEqual(result["requests"][0]["eligible_existing_provider_count"],2)
  self.assertFalse(result["requests"][0]["association_issued"])

 def test_failed_existing_admission_is_hold_not_reassignment(self):
  self.write("a","mad4b.example.plan.v1")
  self.admission.checked_plan=lambda root,sha,manifest:(_ for _ in ()).throw(
      ValueError("plan not admitted"))
  result=self.run_preview()
  self.assertEqual(result["uniquely_attributable_legacy_plans"],0)
  self.assertEqual(result["unresolved_legacy_plans"],1)

 def test_unenrolled_contract_is_not_migrated(self):
  self.write("a","mad4b.not-in-registry.plan.v1")
  result=self.run_preview()
  self.assertEqual(result["existing_plan_count_on_shared_contracts"],0)

 def test_path_escape_and_invalid_files_denied(self):
  self.write("a","mad4b.example.plan.v1")
  path=self.root/("a"*64+".json")
  path.unlink()
  path.symlink_to("/etc/passwd")
  with self.assertRaises(preview.MigrationHold):self.run_preview()

 def test_no_keys_writes_jobs_or_signing(self):
  source=SOURCE.read_text()
  for term in ("private_key","Ed25519PrivateKey","execute_claimed(",
               "create_grant(","issue_job(","write_text(","unlink("):
   self.assertNotIn(term,source)
  self.assertNotIn("source.code.release",source)
  self.assertNotIn("wayfatours",source)

if __name__=="__main__":
 unittest.main()
