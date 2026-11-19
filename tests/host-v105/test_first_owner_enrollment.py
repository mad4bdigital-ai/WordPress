#!/usr/bin/env python3
"""Original313 first-Anchor independent signer, no real Root modifications."""
import base64
import hashlib
import importlib.util
from pathlib import Path
import subprocess
import unittest
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey
from cryptography.hazmat.primitives.serialization import Encoding,PublicFormat
from cryptography.exceptions import InvalidSignature

source=Path(__file__).resolve().parents[2]/"ops/host-anchor-v105/original313_first_owner_enrollment_issuer.py"
sp=importlib.util.spec_from_file_location("host_v105_pure_signer",source)
m=importlib.util.module_from_spec(sp);sp.loader.exec_module(m)
v89=m.pinned(m.V89,m.V89_SHA,"v105_test_v89")

class SignedFirstOwnerEnrollment(unittest.TestCase):
 def setUp(self):
  self.t=1770000000
  self.key=Ed25519PrivateKey.from_private_bytes(bytes.fromhex("33"*32))
  self.pub=self.key.public_key().public_bytes(Encoding.Raw,PublicFormat.Raw)
  self.j={
    "job_id":"b"*32,
    "operation_id":m.OPERATION,"operation":m.OPERATION,
    "company":"all-royal-egypt","environment":"staging",
    "site_uuid":"49c562d1-8f2f-456f-b454-26816c6ba4cb",
    "origin":"https://staging.allroyalegypt.com",
    "target_host_id":"hosting-2","target_ssh_principal":"u948953904",
    "plan_sha256":"a"*64,"command_sha256":"c"*64,
    "job_type":"signed_remote_hostinger_wpcli_v2",
    "principal_type":"signed_host_principal_v2",
    "state":"BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY",
    "host_v2_bootstrap_only":True,"authority_mode":"bootstrap",
    "executed":False,"expires_epoch":self.t+120}
  self.owner={
    "state":"VERIFIED_ORIGINAL305_OWNER_BOOTSTRAP__CLAIM_ONLY",
    "job_id":self.j["job_id"],"plan_sha256":self.j["plan_sha256"],
    "original328_active":True,"execution_authorized":False}
  self.claim={
    "state":"HOST_V2_CLAIM_MATCHED__SIGNED_ORIGINAL312_ISSUER_PENDING",
    "original306_claim_matched":True,"execution_authorized":False,
    "original313_certified":False,"anchor_issued":False,
    "job_id":self.j["job_id"],"plan_sha256":self.j["plan_sha256"],
    "command_sha256":self.j["command_sha256"]}
 def sign(self):
  return m.signed_enrollment(claimed=self.j,
      verified_owner=self.owner,verified_claim=self.claim,signer=self.key,
      independent_pub=self.pub,now=self.t,nonce="d"*32,enrollment_id="e"*32)
 def test_signed_enrollment_matches_exact_original_v89(self):
  result=self.sign()
  self.assertEqual(list(result),list(v89.SIGNED_FIELDS)+["signature"])
  self.assertEqual(result["original306_job_id"],self.j["job_id"])
  self.assertTrue(result["owner_scope_authorized"])
  self.assertTrue(result["independent_issuer_verified"])
  self.assertEqual(result["expires_epoch"],self.t+110)
  self.assertTrue(v89.verify_enrollment(result,self.pub,self.j,now=self.t)
                  ["independent_enrollment_signature_verified"])
 def test_forged_signature_refused_by_original_v89(self):
  result=self.sign()
  result["signature"]=base64.b64encode(bytes(64)).decode()
  with self.assertRaises(v89.BootstrapIssuerHold):
   v89.verify_enrollment(result,self.pub,self.j,now=self.t)
 def test_wrong_owner_status_never_signs(self):
  self.owner["state"]="OWNER_REST_SESSION_UNVERIFIED"
  with self.assertRaises(m.EnrollmentHeld):self.sign()
 def test_owner_job_plan_binding_mismatch(self):
  self.owner["plan_sha256"]="0"*64
  with self.assertRaises(m.EnrollmentHeld):self.sign()
 def test_claim_and_broker_command_binding_mismatch(self):
  self.claim["command_sha256"]="0"*64
  with self.assertRaises(m.EnrollmentHeld):self.sign()
 def test_other_tenant_and_production_held(self):
  for k,bad in (("company","wayfatours"),("environment","production"),
                ("target_host_id","hosting-3"),("authority_mode","operational"),
                ("host_v2_bootstrap_only",False),("executed",True)):
   good=self.j[k];self.j[k]=bad
   try:
    with self.assertRaises(m.EnrollmentHeld):self.sign()
   finally:self.j[k]=good
 def test_short_expiry_and_stale_claim_denied(self):
  self.j["expires_epoch"]=self.t+3
  with self.assertRaises(m.EnrollmentHeld):self.sign()
 def test_unenrolled_signer_denied(self):
  foreign=Ed25519PrivateKey.generate()
  self.key=foreign
  with self.assertRaises(m.EnrollmentHeld):self.sign()
 def test_forged_owner_claim_boolean_denied(self):
  self.owner["execution_authorized"]=True
  with self.assertRaises(m.EnrollmentHeld):self.sign()
 def test_nonce_collision_shape_denied(self):
  with self.assertRaises(m.EnrollmentHeld):
   m.signed_enrollment(claimed=self.j,verified_owner=self.owner,
       verified_claim=self.claim,signer=self.key,
       independent_pub=self.pub,now=self.t,nonce="z")
 def test_first_party_root_method_accepts_no_caller_store(self):
  with self.assertRaises(m.EnrollmentHeld):
   m.first_party_originals(None,{}, {}, {}, {}, {}, {}, {}, {}, {}, {}, {},
       store=object())
 def test_standalone_execution_does_not_create_signature(self):
  p=subprocess.run(["python3","-B",str(source)],text=True,
                   capture_output=True,timeout=8)
  self.assertEqual(p.returncode,2)
  self.assertIn('"enrollment_issued": false',p.stdout)
if __name__=="__main__":unittest.main(verbosity=2)
