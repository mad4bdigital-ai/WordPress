"""Root-private historical grant signatures and distinct-plan accounting."""
from __future__ import annotations
import base64
from copy import deepcopy
from datetime import datetime,timezone,timedelta
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey
from cryptography.hazmat.primitives.serialization import Encoding,PublicFormat

ROOT=Path(__file__).resolve().parents[1]
FILE=ROOT/"ops/native-source-publisher-v1/original_historical_grants_audit.py"
spec=importlib.util.spec_from_file_location("mad4b_historical_grants_offline",FILE)
audit=importlib.util.module_from_spec(spec);spec.loader.exec_module(audit)

class GrantHistory(unittest.TestCase):
 def setUp(self):
  self.temp=tempfile.TemporaryDirectory(prefix="mad4b-historical-grant-")
  self.addCleanup(self.temp.cleanup)
  self.root=Path(self.temp.name)/"grants"
  self.root.mkdir(mode=0o700)
  self.key=Ed25519PrivateKey.generate()
  self.public=base64.b64encode(self.key.public_key().public_bytes(
    Encoding.Raw,PublicFormat.Raw)).decode()
  self.contract="mad4b.company-code-release.auth.v1"
  self.plans={
    "a"*64:{"plan_sha256":"a"*64,"company":"tours-central",
      "environment":"staging","site_uuid":"12345678-1234-4123-8123-123456789abc",
      "origin":"https://example.invalid"},
    "b"*64:{"plan_sha256":"b"*64,"company":"company-two",
      "environment":"production","site_uuid":"00000000-0000-4000-8000-000000000000",
      "origin":"https://two.example.invalid"},
  }
  self.now=datetime(2026,11,12,tzinfo=timezone.utc)

 def message(self,g):
  return (self.contract+"\n").encode()+json.dumps(
    {k:v for k,v in g.items() if k!="signature"},
    sort_keys=True,separators=(",",":")
  ).encode()

 def grant(self,sha,job,minutes_before=45,**changes):
  plan=self.plans[sha]
  issued=self.now-timedelta(minutes=minutes_before)
  doc={
    "contract":self.contract,"action":"apply",
    "issuer":"mad4b-company-broker","original_job_id":job,
    "plan_sha256":sha,
    "company":plan["company"],"environment":plan["environment"],
    "site_uuid":plan["site_uuid"],"origin":plan["origin"],
    "issued_at":issued.isoformat(),
    "expires_at":(issued+timedelta(minutes=15)).isoformat()
  }
  doc.update(changes)
  doc["signature"]=base64.b64encode(self.key.sign(self.message(doc))).decode()
  return doc

 def put(self,doc):
  path=self.root/(doc["original_job_id"]+".json")
  path.write_text(json.dumps(doc))
  path.chmod(0o600)
  return path

 def scan(self,public=None):
  return audit.summarize(self.plans,self.root,
   broker_public_key_b64=self.public if public is None else public,
   signed_message=self.message,expected_contract=self.contract,now=self.now)

 def test_three_valid_files_only_two_distinct_plans(self):
  for doc in (self.grant("a"*64,"1"*32),
              self.grant("a"*64,"2"*32),
              self.grant("b"*64,"3"*32)):
   self.put(doc)
  r=self.scan()
  self.assertEqual(r["matched_grant_file_count"],3)
  self.assertEqual(r["distinct_plans_with_valid_grant_evidence"],2)
  self.assertEqual(r["plans_with_multiple_historical_grant_files"],1)
  self.assertEqual(r["additional_historical_grant_files_due_to_retries"],1)
  self.assertEqual(r["historical_grants_expired"],3)
  self.assertFalse(r["current_original305_job_verified"])
  self.assertFalse(r["fresh_original312_verified"])
  self.assertFalse(r["grants_extended_or_issued"])

 def test_missing_grant_counts_distinct_plan(self):
  self.put(self.grant("a"*64,"1"*32))
  result=self.scan()
  self.assertEqual(result["distinct_plans_without_matching_grant_evidence"],1)
  self.assertEqual(result["matched_grant_file_count"],1)

 def test_invalid_signature_denies_plan_coverage(self):
  doc=self.grant("a"*64,"1"*32)
  doc["site_uuid"]="untrusted-site"
  self.put(doc)
  result=self.scan()
  self.assertEqual(result["distinct_plans_with_valid_grant_evidence"],0)
  self.assertEqual(result["plans_with_invalid_or_conflicting_grant_files"],1)

 def test_invalid_duplicate_blocks_a_previously_valid_plan(self):
  self.put(self.grant("a"*64,"1"*32))
  bad=self.grant("a"*64,"2"*32)
  bad["signature"]="AQ=="
  self.put(bad)
  result=self.scan()
  self.assertEqual(result["matched_grant_file_count"],2)
  self.assertEqual(result["distinct_plans_with_valid_grant_evidence"],0)
  self.assertEqual(result["plans_with_invalid_or_conflicting_grant_files"],1)

 def test_unexpired_grant_never_auto_authorizes(self):
  self.put(self.grant("a"*64,"1"*32,minutes_before=5))
  result=self.scan()
  self.assertEqual(result["historical_grants_unexpired_not_authorized"],1)
  self.assertFalse(result["fresh_original312_verified"])

 def test_wrong_issuer_key_and_foreign_contract(self):
  self.put(self.grant("a"*64,"1"*32))
  other=base64.b64encode(Ed25519PrivateKey.generate().public_key().public_bytes(
    Encoding.Raw,PublicFormat.Raw)).decode()
  self.assertEqual(self.scan(other)["distinct_plans_with_valid_grant_evidence"],0)
  doc=self.grant("a"*64,"2"*32,contract="wrong.contract")
  self.put(doc)
  self.assertEqual(self.scan()["distinct_plans_with_valid_grant_evidence"],0)

 def test_bad_filename_symlink_and_permissions_denied(self):
  doc=self.grant("a"*64,"1"*32)
  path=self.put(doc)
  path.chmod(0o666)
  with self.assertRaises(audit.HistoricalGrantDenied):self.scan()
  path.chmod(0o600)
  path.unlink()
  path.symlink_to("/etc/passwd")
  with self.assertRaises(audit.HistoricalGrantDenied):self.scan()
  path.unlink()
  self.root.chmod(0o777)
  with self.assertRaises(audit.HistoricalGrantDenied):self.scan()

 def test_grant_signed_time_window_over_30_minutes_denied(self):
  doc=self.grant("a"*64,"1"*32)
  doc["expires_at"]=(self.now+timedelta(minutes=90)).isoformat()
  doc["signature"]=base64.b64encode(self.key.sign(self.message(doc))).decode()
  self.put(doc)
  self.assertEqual(self.scan()["historical_grants_valid_signature_and_scope"],0)

 def test_no_signing_or_issuing_in_source(self):
  source=FILE.read_text()
  self.assertNotIn("Ed25519PrivateKey",source)
  self.assertNotIn("wp eval",source)
  self.assertNotIn("write_text(",source)
  self.assertNotIn("source.code.release",source)

if __name__=="__main__":unittest.main()
