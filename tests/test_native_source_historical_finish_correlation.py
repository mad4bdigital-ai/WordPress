"""Cryptographic historical Host FINISH correlation, never a current 312 Grant."""
from __future__ import annotations
import base64
import importlib.util
import json
from pathlib import Path
import sqlite3
import tempfile
import unittest
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey
from cryptography.hazmat.primitives.serialization import Encoding,PublicFormat

ROOT=Path(__file__).resolve().parents[1]
SCRIPT=ROOT/"ops/native-source-publisher-v1/original_finish_readonly_audit.py"
sp=importlib.util.spec_from_file_location("mad4b_historical_finish_fixture",SCRIPT)
source=importlib.util.module_from_spec(sp);sp.loader.exec_module(source)


class FinishEvidence(unittest.TestCase):
 def setUp(self):
  self.temp=tempfile.TemporaryDirectory(prefix="mad4b-original-historical-")
  self.addCleanup(self.temp.cleanup)
  self.root=Path(self.temp.name)/"receipts"
  self.root.mkdir(mode=0o700)
  self.key=Ed25519PrivateKey.generate()
  self.public=base64.b64encode(self.key.public_key().public_bytes(
      Encoding.Raw,PublicFormat.Raw)).decode()
  self.command="d"*64
  self.site="12345678-1234-4123-8123-123456789abc"
  self.original={
    self.command:{
      "operation":"legacy.dynamic.operation",
      "plan":{"company":"company-one","environment":"staging",
              "origin":"https://example.invalid","site_uuid":self.site}
    }
  }
  self.journal=Path(self.temp.name)/"empty.sqlite3"
  with sqlite3.connect(self.journal) as db:
   db.execute("CREATE TABLE events (detail TEXT)")
   db.commit()

 def signed_finish(self,**change):
  doc={
    "contract":source.FINISH,"host_id":"srv2030908",
    "job_id":"a"*32,"target_id":"srv2030908",
    "grant_id":"some-original-grant","transport_id":"original-transport",
    "site_uuid":self.site,"origin":"https://example.invalid",
    "environment":"staging","command_sha256":self.command,
    "native_receipt_sha256":"b"*64,
    "expected_readback_sha256":"c"*64,
    "readback_sha256":"c"*64,"output_sha256":"c"*64,
    "exit_code":0,"issued":1794400000,"expires":1794400050,
    "nonce":"e"*32,
  }
  doc.update(change)
  document="\n".join(str(doc[field]) for field in source.FIELDS).encode()
  doc["signature"]=base64.b64encode(self.key.sign(document)).decode()
  return doc

 def put(self,doc,name=None):
  if name is None:
   name=doc["job_id"]+"-dynamic313-finish"
  path=self.root/(name+".json")
  path.write_text(json.dumps(doc))
  path.chmod(0o600)
  return path

 def summarize(self, key=None):
  return source.summarize(self.original,self.root,key or self.public,
                          journal=self.journal)

 def test_signed_historical_finish_is_not_current_job_eligibility(self):
  self.put(self.signed_finish())
  result=self.summarize()
  self.assertEqual(result["commands_with_verified_historical_signed_finish"],1)
  self.assertEqual(
   result["verified_historical_finishes_by_operation"],
   {"legacy.dynamic.operation":1}
  )
  self.assertEqual(result["commands_without_cryptographically_verified_finish"],0)
  self.assertEqual(result["commands_with_matching_dispatch_journal_events"],0)
  self.assertFalse(result["original305_current_state_verified"])
  self.assertFalse(result["original312_current_eligibility_verified"])
  self.assertFalse(result["original313_signed_final_certificate_issued_by_audit"])

 def test_tampered_signature_fails_closed(self):
  doc=self.signed_finish()
  doc["origin"]="https://different.invalid"
  self.put(doc)
  res=self.summarize()
  self.assertEqual(res["commands_with_verified_historical_signed_finish"],0)
  self.assertEqual(res["commands_with_invalid_or_conflicting_evidence"],1)

 def test_valid_signature_wrong_scope_denied(self):
  self.put(self.signed_finish(site_uuid="00000000-0000-4000-8000-000000000000"))
  result=self.summarize()
  self.assertEqual(result["commands_with_verified_historical_signed_finish"],0)
  self.assertEqual(result["commands_with_invalid_or_conflicting_evidence"],1)

 def test_invalid_exit_code_or_readback_fails_even_if_signed(self):
  for name,fields in (
    ("exit",{"exit_code":1}),
    ("readback",{"readback_sha256":"0"*64}),
    ("expiry",{"expires":1794400400}),
  ):
   with self.subTest(name=name):
    file=self.put(self.signed_finish(**fields))
    self.assertEqual(self.summarize()["commands_with_verified_historical_signed_finish"],0)
    file.unlink()

 def test_conflicting_duplicate_receipt_requires_review(self):
  self.put(self.signed_finish())
  self.put(self.signed_finish(job_id="b"*32,nonce="f"*32))
  result=self.summarize()
  self.assertEqual(result["commands_with_verified_historical_signed_finish"],0)
  self.assertEqual(result["commands_with_invalid_or_conflicting_evidence"],1)

 def test_signed_receipt_with_wrong_job_filename_is_not_trusted(self):
  self.put(self.signed_finish(),name="unrelated-receipt")
  result=self.summarize()
  self.assertEqual(result["commands_with_verified_historical_signed_finish"],0)
  self.assertEqual(result["commands_with_invalid_or_conflicting_evidence"],1)

 def test_wrong_public_key_fails_signature(self):
  self.put(self.signed_finish())
  other=base64.b64encode(Ed25519PrivateKey.generate().public_key().public_bytes(
      Encoding.Raw,PublicFormat.Raw)).decode()
  result=self.summarize(key=other)
  self.assertEqual(result["commands_with_verified_historical_signed_finish"],0)

 def test_world_writable_directory_or_receipt_denied(self):
  file=self.put(self.signed_finish())
  file.chmod(0o666)
  with self.assertRaises(source.EvidenceUnverified):self.summarize()
  file.chmod(0o600)
  self.root.chmod(0o777)
  with self.assertRaises(source.EvidenceUnverified):self.summarize()

 def test_journal_matching_digest_is_readonly_and_optional(self):
  self.put(self.signed_finish())
  with sqlite3.connect(self.journal) as db:
   db.execute("INSERT INTO events (detail) VALUES (?)",(
     json.dumps({"command_sha256":self.command,"state":"OBSERVED"}),))
   db.commit()
  self.assertEqual(
    self.summarize()["commands_with_matching_dispatch_journal_events"],1)
  self.journal.unlink()
  self.assertIsNone(
    self.summarize()["commands_with_matching_dispatch_journal_events"])

 def test_no_live_mutation_code(self):
  text=SCRIPT.read_text()
  for forbidden in ("Ed25519PrivateKey","execute_claimed(","get_option(",
                    "update_option(","wp eval","add_option("):
   self.assertNotIn(forbidden,text)
  self.assertNotIn("source.code.release",text)


if __name__=="__main__":unittest.main()
