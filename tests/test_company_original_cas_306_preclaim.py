"""Source-only 306 provider preclaim contract tests, no live Original claims."""
from __future__ import annotations
import base64
import copy
import hashlib
import importlib.util
import json
from pathlib import Path
import re
import sys
import tempfile
import time
import unittest
from unittest.mock import patch
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey

ROOT=Path(__file__).resolve().parents[1]/"ops/dynamic-operation-registry-v1"
PRECLAIM=ROOT/"executor-time-cas-v1/preclaim.py"
def imported(path,name):
 spec=importlib.util.spec_from_file_location(name,str(path))
 out=importlib.util.module_from_spec(spec)
 spec.loader.exec_module(out)
 return out

cas=imported(PRECLAIM,"mad4b_cas_original306_preclaim_fixture")


class Original306Preclaim(unittest.TestCase):
 def setUp(self):
  folder=tempfile.TemporaryDirectory()
  self.addCleanup(folder.cleanup)
  self.root=Path(folder.name)/"enrolled"
  self.root.mkdir()
  self.grants=Path(folder.name)/"grants"
  self.grants.mkdir(mode=0o700)
  selected=[
    "registry.py","admission.py",
    "executor-time-cas-v1/host_handler.py",
    "executor-time-cas-v1/independent_readback.py",
    "executor-time-cas-v1/preclaim.py",
    "executor-time-cas-v1/finalizer.py",
    "providers/company.resource.cas.apply/manifest.json",
    "providers/company.resource.cas.apply/evidence-schema.json",
  ]
  for name in selected:
   target=self.root/name
   target.parent.mkdir(parents=True,exist_ok=True)
   target.write_bytes((ROOT/name).read_bytes())
   target.chmod(0o600)
  self.signer=Ed25519PrivateKey.generate()
  self.pub=Path(folder.name)/"broker-public.b64"
  self.pub.write_bytes(base64.b64encode(self.signer.public_key().public_bytes_raw()))
  self.pub.chmod(0o600)
  m=self.root/"providers/company.resource.cas.apply/manifest.json"
  self.index_data={
   "contract":"mad4b.dynamic-operation-index.v1",
   "installed_by_first_party":True,
   "independent_verifier_public_key_b64":
       base64.b64encode(self.signer.public_key().public_bytes_raw()).decode(),
   "providers":[{"manifest":"providers/company.resource.cas.apply/manifest.json",
                 "sha256":hashlib.sha256(m.read_bytes()).hexdigest()}],
  }
  (self.root/"index.json").write_text(json.dumps(self.index_data))
  (self.root/"index.json").chmod(0o600)
  sys.path.insert(0,str(self.root))
  self.addCleanup(lambda:sys.path.remove(str(self.root))
                  if str(self.root) in sys.path else None)
  import registry
  self.registry=registry
  self.index=registry.Registry(self.root)
  self.op=cas.OPERATION
  self.job="a"*32
  self.plan={
   "contract":"mad4b.company-resource.executor-cas-plan.v1",
   "company":"wayfatours","environment":"production",
   "site_uuid":"a3a7cc36-db7e-4a14-8456-b41857def05c",
   "origin":"https://wayfatours.com",
   "source_plan_sha256":"b"*64,
   "resource_type":"wordpress.post","resource_id":"61152",
   "post_type":"tour-rates","meta_key":"rate_title","meta_id":902,
   "expected_before_sha256":"c"*64,
   "expected_manifest_sha256":"d"*64,
   "expected_company_preservation_sha256":"e"*64,
   "expected_meta_value_sha256":hashlib.sha256(b"old").hexdigest(),
   "new_meta_value_sha256":hashlib.sha256(b"new").hexdigest(),
   "expected_after_sha256":"f"*64,
   "old_meta_value":"old","new_meta_value":"new",
  }
  self.plan["plan_sha256"]=registry.sha(registry.canonical(self.plan))
  self.digest=self.index.command_sha256(self.op,self.plan["plan_sha256"])
  self.now=int(time.time())
  self.j={
   "job_id":self.job,"company":self.plan["company"],
   "environment":self.plan["environment"],
   "site_uuid":self.plan["site_uuid"],
   "canonical_origin":self.plan["origin"],
   "operation":"company.remote.wpcli.full","target_host_id":"srv2030908",
   "broker_host_id":"srv2030908","target_scope":"wordpress",
   "job_type":"signed_vps_wpcli","command_format":"wpcli.argv.json.v1",
   "command_sha256":self.digest,
   "grant_id":"1"*32,"transport_id":"2"*32,
   "domain_intent":"operations.write",
   "state":"QUEUED_FOR_VPS_BROKER_CLAIM",
   "executed":False,"expires_epoch":self.now+1000
  }
  self.response={
      "job":self.j,
      "cas_finalizer_enrolled":True,
      "native_dynamic_313_supported":True,
  }
  self.grant=self.signed_grant()

 def signed_grant(self,**changed):
  grant={
   "contract":cas.GRANT_CONTRACT,
   "issuer":"mad4b-company-broker","action":"apply",
   "original_job_id":self.job,"operation_id":self.op,
   "company":self.plan["company"],
   "environment":self.plan["environment"],
   "site_uuid":self.plan["site_uuid"],
   "origin":self.plan["origin"],
   "grant_id":self.j["grant_id"],
   "transport_id":self.j["transport_id"],
   "plan_sha256":self.plan["plan_sha256"],
   "source_plan_sha256":self.plan["source_plan_sha256"],
   "command_sha256":self.digest,
   "resource_key":"wordpress.post:61152",
   "meta_key":"rate_title",
   "expected_before_sha256":self.plan["expected_before_sha256"],
   "expected_after_sha256":self.plan["expected_after_sha256"],
   "expected_company_preservation_sha256":
       self.plan["expected_company_preservation_sha256"],
   "issued_epoch":self.now,"expires_epoch":self.now+190,
   "nonce":"f"*32,
  }
  grant.update(changed)
  raw=self.registry.canonical({k:grant[k] for k in cas.FIELDS})
  grant["signature"]=base64.b64encode(self.signer.sign(raw)).decode()
  return grant

 def saved_grant(self,row=None):
  p=self.grants/(self.job+".json")
  p.write_text(json.dumps(self.grant if row is None else row))
  p.chmod(0o600)

 def grant_verifier(self,job,sha,plan,original,now):
  with patch.object(cas,"GRANTS",self.grants),\
       patch.object(cas,"BROKER_PUB",self.pub):
   return cas.verify_grant(job,sha,plan,original,now=now)

 def run_preclaim(self,reader=None):
  return cas.verify_claimable(
   self.index,self.plan,self.job,self.digest,
   reader=reader if reader is not None else
     lambda job,operation:self.response,
   grant_verifier=self.grant_verifier,
   now=self.now,
  )

 def test_cas_manifest_has_source_pinned_preclaim(self):
  man=self.index.resolve(self.op)
  self.assertEqual(man["preclaim"]["path"],
     "executor-time-cas-v1/preclaim.py")
  self.assertEqual(man["preclaim"]["sha256"],
     hashlib.sha256(PRECLAIM.read_bytes()).hexdigest())

 def test_fully_signed_scope_and_ready_finalizer_is_admissible(self):
  self.saved_grant()
  row=self.run_preclaim()
  self.assertEqual(row["state"],
     "REGISTERED_PROVIDER_ORIGINAL_305_EXACT_SIGNED_PRECLAIM")
  self.assertFalse(row["new_job_issued"])
  self.assertFalse(row["original_313_certified"])

 def test_missing_signed_grant_blocks_preclaim(self):
  with self.assertRaises(FileNotFoundError):
   self.run_preclaim()

 def test_bad_grant_signature_denies_preclaim(self):
  grant=dict(self.grant)
  grant["signature"]=base64.b64encode(b"z"*64).decode()
  self.saved_grant(grant)
  with self.assertRaisesRegex(cas.Hold,"SIGNATURE_INVALID"):
   self.run_preclaim()

 def test_grant_for_wrong_meta_field_denied(self):
  self.saved_grant(self.signed_grant(meta_key="single_price"))
  with self.assertRaisesRegex(cas.Hold,"SCOPE_OR_TIME_CHANGED"):
   self.run_preclaim()

 def test_grant_for_wrong_tenant_denied(self):
  self.saved_grant(self.signed_grant(company="other"))
  with self.assertRaises(cas.Hold):self.run_preclaim()

 def test_grant_for_wrong_305_job_denied(self):
  self.saved_grant(self.signed_grant(original_job_id="b"*32))
  with self.assertRaises(cas.Hold):self.run_preclaim()

 def test_grant_for_wrong_plan_denied(self):
  self.saved_grant(self.signed_grant(plan_sha256="0"*64))
  with self.assertRaises(cas.Hold):self.run_preclaim()

 def test_grant_expired_denied(self):
  self.saved_grant(self.signed_grant(expires_epoch=self.now-1))
  with self.assertRaises(cas.Hold):self.run_preclaim()

 def test_grant_without_root_permissions_denied(self):
  self.saved_grant()
  (self.grants/(self.job+".json")).chmod(0o666)
  with self.assertRaisesRegex(cas.Hold,"NOT_TRUSTED"):
   self.run_preclaim()

 def test_no_finalizer_enrollment_refuses_306_claim(self):
  self.saved_grant()
  row={**self.response,"cas_finalizer_enrolled":False}
  with self.assertRaisesRegex(cas.Hold,"FINALIZER_NOT_ENROLLED"):
   self.run_preclaim(lambda job,op:row)

 def test_no_native313_refuses_306_claim(self):
  self.saved_grant()
  row={**self.response,"native_dynamic_313_supported":False}
  with self.assertRaises(cas.Hold):
   self.run_preclaim(lambda job,op:row)

 def test_queued_job_becomes_claimed_denies_preclaim(self):
  self.saved_grant()
  row={**self.response,"job":{**self.j,
    "state":"BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY"}}
  with self.assertRaises(cas.Hold):
   self.run_preclaim(lambda job,op:row)

 def test_original_job_site_uuid_changed_denied(self):
  self.saved_grant()
  row={**self.response,"job":{**self.j,"site_uuid":"0"*36}}
  with self.assertRaises(cas.Hold):
   self.run_preclaim(lambda job,op:row)

 def test_original305_delegation_intent_not_unregistered_provider_name(self):
  self.saved_grant()
  row={**self.response,"job":{**self.j,"domain_intent":self.op}}
  with self.assertRaises(cas.Hold):self.run_preclaim(lambda job,op:row)

 def test_original305_existing_write_lane_is_supported(self):
  self.saved_grant()
  result=self.run_preclaim()
  self.assertTrue(result["provider_release_grant_verified"])

 def test_original_job_command_changed_denied(self):
  self.saved_grant()
  row={**self.response,"job":{**self.j,"command_sha256":"0"*64}}
  with self.assertRaises(cas.Hold):
   self.run_preclaim(lambda job,op:row)

 def test_job_window_too_short_denied(self):
  self.saved_grant()
  row={**self.response,"job":{**self.j,"expires_epoch":self.now+100}}
  with self.assertRaises(cas.Hold):
   self.run_preclaim(lambda job,op:row)

 def test_plan_payload_tamper_denied(self):
  self.saved_grant()
  self.plan["new_meta_value"]="unauthorized"
  with self.assertRaisesRegex(cas.Hold,"PLAN_PREIMAGE_TAMPERED"):
   self.run_preclaim()

 def test_provider_price_field_denied_before_grant_check(self):
  self.saved_grant()
  self.plan["meta_key"]="single_price"
  with self.assertRaises(cas.Hold):self.run_preclaim()

 def test_registry_preclaim_file_hash_drift_blocks(self):
  p=self.root/"executor-time-cas-v1/preclaim.py"
  p.write_text(p.read_text()+"\n# altered\n")
  with self.assertRaisesRegex(Exception,"DYNAMIC_PROVIDER_COMPONENT_DRIFT"):
   self.registry.Registry(self.root)

 def test_source_does_not_issue_new_grants_or_jobs(self):
  self.assertFalse(hasattr(cas,"create_grant"))
  self.assertFalse(hasattr(cas,"issue_job"))
  self.assertFalse(hasattr(cas,"claim_original_306"))

if __name__=="__main__":
 unittest.main()
