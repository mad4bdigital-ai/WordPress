"""Unsigned-source CAS finalizer proof tests with ephemeral test signer only."""
from __future__ import annotations
import base64
import hashlib
import importlib.util
import json
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import patch
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey

BASE=Path(__file__).resolve().parents[1]/"ops/dynamic-operation-registry-v1"


def load(path,name):
 spec=importlib.util.spec_from_file_location(name,path)
 mod=importlib.util.module_from_spec(spec)
 spec.loader.exec_module(mod)
 return mod


fin=load(BASE/"executor-time-cas-v1/finalizer.py","mad4b_original313_test")


class Original313CAS(unittest.TestCase):
 def setUp(self):
  temp=tempfile.TemporaryDirectory()
  self.addCleanup(temp.cleanup)
  root=Path(temp.name)
  self.registry_dir=root/"enrolled"
  self.registry_dir.mkdir()
  names=[
    "registry.py","evidence.py",
    "executor-time-cas-v1/host_handler.py",
    "executor-time-cas-v1/independent_readback.py",
    "executor-time-cas-v1/preclaim.py",
    "executor-time-cas-v1/finalizer.py",
    "providers/company.resource.cas.apply/evidence-schema.json",
    "providers/company.resource.cas.apply/manifest.json",
  ]
  for name in names:
   p=self.registry_dir/name
   p.parent.mkdir(parents=True,exist_ok=True)
   p.write_bytes((BASE/name).read_bytes())
   p.chmod(0o600)
  self.test_signer=Ed25519PrivateKey.generate()
  raw_public=self.test_signer.public_key().public_bytes_raw()
  manifest=self.registry_dir/"providers/company.resource.cas.apply/manifest.json"
  index={
   "contract":"mad4b.dynamic-operation-index.v1",
   "installed_by_first_party":True,
   "independent_verifier_public_key_b64":
       base64.b64encode(raw_public).decode(),
   "providers":[{"manifest":"providers/company.resource.cas.apply/manifest.json",
                 "sha256":hashlib.sha256(manifest.read_bytes()).hexdigest()}]
  }
  (self.registry_dir/"index.json").write_text(json.dumps(index))
  (self.registry_dir/"index.json").chmod(0o600)
  sys.path.insert(0,str(self.registry_dir))
  self.addCleanup(lambda:sys.path.remove(str(self.registry_dir))
                  if str(self.registry_dir) in sys.path else None)
  import registry
  self.registry=registry
  self.index=registry.Registry(self.registry_dir)
  self.manifest=self.index.resolve(fin.OPERATION)
  self.job="a"*32
  self.plan={
   "contract":"mad4b.company-resource.executor-cas-plan.v1",
   "company":"wayfatours","environment":"production",
   "site_uuid":"a3a7cc36-db7e-4a14-8456-b41857def05c",
   "origin":"https://wayfatours.com",
   "source_plan_sha256":"b"*64,"resource_type":"wordpress.post",
   "resource_id":"61152","post_type":"tour-rates","meta_key":"rate_title",
   "meta_id":902,"expected_before_sha256":"c"*64,
   "expected_manifest_sha256":"d"*64,
   "expected_company_preservation_sha256":"e"*64,
   "expected_meta_value_sha256":hashlib.sha256(b"old").hexdigest(),
   "new_meta_value_sha256":hashlib.sha256(b"new").hexdigest(),
   "expected_after_sha256":"f"*64,
   "old_meta_value":"old","new_meta_value":"new",
  }
  self.plan["plan_sha256"]=registry.sha(registry.canonical(self.plan))
  self.auth={
   "grant_id":"1"*32,"transport_id":"2"*32,
   "company":"wayfatours","environment":"production",
   "site_uuid":self.plan["site_uuid"],"origin":self.plan["origin"],
   "job_id":self.job,
   "command_sha256":self.index.command_sha256(
       fin.OPERATION,self.plan["plan_sha256"])
  }
  self.evidence={
   "index":self.index,"manifest":self.manifest,
   "plan":self.plan,"authority":self.auth,
   "original_job_id":self.job,
   "command_sha256":self.auth["command_sha256"],
   "native_receipt_sha256":"9"*64,
   "independent":{
     "contract":"mad4b.company-resource-cas.original313-independent-readback.v1",
     "state":"INDEPENDENT_TARGET_CAS_POSTIMAGE_VERIFIED_UNSIGNED",
     "company":"wayfatours","environment":"production",
     "site_uuid":self.plan["site_uuid"],"origin":self.plan["origin"],
     "resource_key":"wordpress.post:61152",
     "before_sha256":self.plan["expected_before_sha256"],
     "after_sha256":self.plan["expected_after_sha256"],
     "company_preservation_sha256":
       self.plan["expected_company_preservation_sha256"],
     "original_313_signed":False,
     "native_receipt_sha256":"9"*64,
     "independent_source_readback_verified":True
   }
  }

 def proof(self):
  return fin.make_signed_dynamic_proof(self.evidence,self.test_signer)

 def test_registered_finalizer_is_sha_pinned(self):
  self.assertEqual(self.manifest["finalizer"]["path"],
       "executor-time-cas-v1/finalizer.py")
  self.assertEqual(self.manifest["finalizer"]["sha256"],
       hashlib.sha256((BASE/"executor-time-cas-v1/finalizer.py").read_bytes()).hexdigest())

 def test_signed_dynamic313_uses_explicit_postimage_and_grant(self):
  proof=self.proof()
  self.assertEqual(proof["operation_id"],fin.OPERATION)
  self.assertEqual(proof["evidence_schema"],"resource_cas.v1")
  self.assertEqual(proof["payload"]["readback_sha256"],
                   self.plan["expected_after_sha256"])
  self.assertEqual(proof["payload"]["grant_id"],self.auth["grant_id"])
  self.assertFalse(proof["payload"]["original_313_signed"])
  self.test_signer.public_key().verify(
    base64.b64decode(proof["signature"]),
    self.registry.canonical({k:v for k,v in proof.items() if k!="signature"}))

 def test_signed_v4_proof_bound_to_dynamic_evidence(self):
  proof=self.proof()
  v4=fin.make_signed_native_v4(proof,self.evidence,self.test_signer)
  self.assertEqual(v4["observed_operation"],fin.OPERATION)
  self.assertEqual(v4["dynamic_evidence_sha256"],proof["evidence_sha256"])
  self.assertEqual(v4["postcondition_key"],"resource_cas.v1")
  self.assertEqual(v4["postcondition_type"],"enrolled_dynamic_evidence")
  signed="\n".join(str(v4[k]) for k in fin.NATIVE_V4_FIELDS).encode()
  self.test_signer.public_key().verify(
    base64.b64decode(v4["signature"]),signed)

 def test_wrong_postimage_denies_any_signature(self):
  self.evidence["independent"]["after_sha256"]="0"*64
  with self.assertRaises(fin.FinalizerHold):self.proof()

 def test_wrong_preservation_fingerprint_denies(self):
  self.evidence["independent"]["company_preservation_sha256"]="0"*64
  with self.assertRaises(fin.FinalizerHold):self.proof()

 def test_unsigned_host_receipt_does_not_count_as_313(self):
  self.evidence["independent"]["original_313_signed"]=True
  with self.assertRaises(fin.FinalizerHold):self.proof()

 def test_missing_verified_independent_wordpress_readback_denies(self):
  self.evidence["independent"]["independent_source_readback_verified"]=False
  with self.assertRaises(fin.FinalizerHold):self.proof()

 def test_changed_readback_digest_denies_v4(self):
  proof=self.proof()
  proof["payload"]["readback_sha256"]="0"*64
  with self.assertRaises(fin.FinalizerHold):
   fin.make_signed_native_v4(proof,self.evidence,self.test_signer)

 def test_changed_native_receipt_denies_v4(self):
  proof=self.proof()
  proof["payload"]["native_receipt_sha256"]="0"*64
  with self.assertRaises(fin.FinalizerHold):
   fin.make_signed_native_v4(proof,self.evidence,self.test_signer)

 def test_unknown_provider_finalizer_run_not_accepted(self):
  self.evidence["manifest"]["operation_id"]="fake.cas.write"
  with self.assertRaises(fin.FinalizerHold):self.proof()

 def test_readonly_mode_does_not_import_or_use_signer(self):
  with patch.object(fin,"readback_approved_original",
                    return_value=self.evidence),\
       patch.object(fin,"pinned_module",side_effect=RuntimeError("SIGNER_NOT_ALLOWED")):
   out=fin.finalize(self.job,self.plan["plan_sha256"],read_only=True)
  self.assertEqual(out["state"],"EXACT_CAS_INDEPENDENT_POSTIMAGE_READY_UNSIGNED")
  self.assertFalse(out["signed_original_313"])

 def test_no_job_or_grant_creation_in_finalizer_source(self):
  source=(BASE/"executor-time-cas-v1/finalizer.py").read_text()
  self.assertNotIn("issue_original_305",source)
  self.assertNotIn("create_original_job",source)
  self.assertNotIn("rollback_release(",source)

if __name__=="__main__":
 unittest.main()
