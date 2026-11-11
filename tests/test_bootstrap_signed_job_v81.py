#!/usr/bin/env python3
"""Real ephemeral Ed25519 signature tests; no enrollment or live keys used."""
import importlib.util
import pathlib
import sys
import time
import unittest
from base64 import b64encode
from copy import deepcopy
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey
from cryptography.hazmat.primitives import serialization

F = pathlib.Path(__file__).resolve().parents[1]/"deploy/host-authority/v81/bootstrap_signed_job.py"
spec = importlib.util.spec_from_file_location("bootstrap_v81", F)
m = importlib.util.module_from_spec(spec)
sys.modules[spec.name] = m
spec.loader.exec_module(m)

class TestBootstrap(unittest.TestCase):
    def setUp(self):
        now = int(time.time())
        self.priv = Ed25519PrivateKey.generate()
        self.pub = self.priv.public_key().public_bytes(
            encoding=serialization.Encoding.Raw, format=serialization.PublicFormat.Raw)
        self.p = {
            "contract": m.CONTRACT, "job_id": "a"*32,
            "operation_id": m.ISSUE, "company": "all-royal-egypt",
            "environment": "production",
            "site_uuid": "0384b6ce-4004-4b4e-b701-7d77cb6f6ff0",
            "origin": "https://www.allroyalegypt.com",
            "target_host_id": "hosting-2", "ssh_principal": "u948953904",
            "enrollment_id": "e"*32, "requested_anchor_revision": 1,
            "command_sha256": "b"*64, "plan_sha256": "c"*64, "nonce":"d"*32,
            "issued_epoch": now, "expires_epoch": now+45,
        }
        self.claim = {k:self.p[k] for k in ("job_id","operation_id","company",
            "environment","site_uuid","origin","target_host_id","command_sha256",
            "plan_sha256")}
        self.claim.update(target_ssh_principal=self.p["ssh_principal"],
            job_type="signed_remote_hostinger_wpcli_v2",
            principal_type="signed_host_principal_v2",
            state="BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY",
            executed=False)
        self.enrolled = {k:self.p[k] for k in ("company","environment","site_uuid",
            "origin","target_host_id","ssh_principal","enrollment_id","nonce")}
        self.enrolled.update(contract="mad4b.original-host-enrollment-authority.v1",
            source="first_party_independent_enrollment_registry",
            signed_site_identity_verified=True,remote_host_identity_verified=True,
            independent_issuer_verified=True,owner_scope_authorized=True,
            enrollment_revoked=False,current_anchor_revision=0,
            current_anchor_state="NOT_ENROLLED",nonce_unspent=True)
        self.sign()
    def sign(self):
        self.p["signature"] = b64encode(
            self.priv.sign(m.signed_message(self.p))).decode()
    def test_valid_bootstrap_never_authorizes(self):
        result=m.verify_bootstrap_job(self.p,self.pub,self.claim,self.enrolled)
        self.assertTrue(result["bootstrap_signature_verified"])
        self.assertFalse(result["execution_authorized"])
        self.assertFalse(result["anchor_issued"])
        self.assertFalse(result["regular_305_grant_used"])
    def test_tampered_signature(self):
        self.p["company"]="another-company"
        self.denied()
    def test_cross_tenant_signed(self):
        self.p["company"]="another-company";self.sign();self.denied()
    def test_first_revision_only(self):
        self.p["requested_anchor_revision"]=2;self.sign();self.denied()
    def test_existing_anchor(self):
        self.enrolled["current_anchor_revision"]=1;self.denied()
    def test_false_issuer(self):
        self.enrolled["independent_issuer_verified"]=False;self.denied()
    def test_revoked_enrollment(self):
        self.enrolled["enrollment_revoked"]=True;self.denied()
    def test_nonce_replay(self):
        self.enrolled["nonce_unspent"]=False;self.denied()
    def test_wrong_job_type(self):
        self.claim["job_type"]="signed_vps_wpcli";self.denied()
    def test_invalid_job_scope(self):
        self.claim["target_host_id"]="hosting-3";self.denied()
    def test_wrong_purpose(self):
        self.p["operation_id"]="company.host-authority.anchor.revoke";self.sign();self.denied()
    def test_expired_proof(self):
        self.p["issued_epoch"]-=240;self.p["expires_epoch"]-=240;self.sign();self.denied()
    def test_signed_305_proof_not_interchangeable(self):
        self.p["contract"]="mad4b.original312.host-principal.exact-job.v2";self.sign();self.denied()
    def denied(self):
        with self.assertRaises(m.BootstrapDenied):
            m.verify_bootstrap_job(self.p,self.pub,self.claim,self.enrolled)
if __name__=="__main__":unittest.main(verbosity=2)
