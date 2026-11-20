#!/usr/bin/env python3
import base64
import hashlib
import importlib.util
import pathlib
import sys
import time
import unittest
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey
from cryptography.hazmat.primitives import serialization

file=pathlib.Path(__file__).resolve().parents[1]/"deploy/host-authority/v81/current_revision_gate.py"
spec=importlib.util.spec_from_file_location("rev_gate81",file)
m=importlib.util.module_from_spec(spec);sys.modules[spec.name]=m;spec.loader.exec_module(m)

class Test(unittest.TestCase):
    def setUp(self):
        self.now=int(time.time())
        self.sk=Ed25519PrivateKey.generate()
        self.csk=Ed25519PrivateKey.generate()
        self.cpk=self.csk.public_key().public_bytes(
            encoding=serialization.Encoding.Raw,
            format=serialization.PublicFormat.Raw)
        self.pk=self.sk.public_key().public_bytes(
            encoding=serialization.Encoding.Raw,
            format=serialization.PublicFormat.Raw)
        self.job=dict(job_id="a"*32,operation_id="company.remote.wpcli.full",
           company="all-royal-egypt",environment="production",
           site_uuid="0384b6ce-4004-4b4e-b701-7d77cb6f6ff0",
           target_host_id="hosting-2",ssh_principal="u948953904",
           anchor_revision=3,grant_revision=2)
        self.ledger=dict(
           contract=m.LEDGER,
           company=self.job["company"],environment=self.job["environment"],
           site_uuid=self.job["site_uuid"],target_host_id=self.job["target_host_id"],
           ssh_principal=self.job["ssh_principal"],revision=3,grant_revision=2,
           anchor_nonce="b"*32,state="ACTIVE",event_id="c"*32,
           previous_event_sha256="d"*64,issued_epoch=self.now,
           expires_epoch=self.now+45)
        self.checkpoint={k:self.ledger[k] for k in m.C_FIELDS
                          if k in self.ledger}
        self.checkpoint.update(contract=m.CHECKPOINT,
           event_sha256="",issued_epoch=self.now,expires_epoch=self.now+45)
        self.wp=dict(contract="mad4b.original318.host-anchor-current-readback.v2",
           source="original318_fresh_wp_readback",readback_verified=True,
           observed_epoch=self.now,current_anchor_signature_verified=True,
           original_reserved_job_id=self.job["job_id"],
           reservation_precedes_readback=True,
           current_anchor_eligible=True,prior_grants_ineligible=False)
        self.wp.update({k:self.ledger[k] for k in (
           "company","environment","site_uuid","revision","anchor_nonce","state")})
        self.sign()
    def sign(self):
        self.ledger["signature"]=base64.b64encode(self.sk.sign(
            m.body(self.ledger,m.FIELDS,"MAD4B_ORIGINAL_HOST_LEDGER_V1"))).decode()
        self.checkpoint["event_sha256"]=hashlib.sha256(
            m.body(self.ledger,m.FIELDS,"MAD4B_ORIGINAL_HOST_LEDGER_V1")).hexdigest()
        self.checkpoint["signature"]=base64.b64encode(self.csk.sign(
            m.body(self.checkpoint,m.C_FIELDS,"MAD4B_ORIGINAL_HOST_CHECKPOINT_V1"))).decode()
    def current(self,reserved=False):
        return m.verify_fresh_current(self.job,self.ledger,self.checkpoint,
                                       self.wp,self.pk,self.cpk,reserved=reserved,now=self.now)
    def denied(self,reserved=False):
        with self.assertRaises(m.RevisionDenied):self.current(reserved)
    def test_active_signature_only_precondition(self):
        self.assertFalse(self.current()["execution_authorized"])
        self.assertTrue(self.current(reserved=True)["checked_after_reservation"])
    def test_common_signer_not_independent(self):
        with self.assertRaises(m.RevisionDenied):
            m.verify_fresh_current(self.job,self.ledger,self.checkpoint,
                                   self.wp,self.pk,self.pk,now=self.now)
    def test_revision_stale_job(self):
        self.job["anchor_revision"]=2;self.denied()
    def test_grant_revision_stale(self):
        self.job["grant_revision"]=1;self.denied()
    def test_signed_ledger_tamper(self):
        self.ledger["state"]="REVOKED";self.denied()
    def test_signed_witness_tamper(self):
        self.checkpoint["revision"]=99;self.denied()
    def test_witness_mismatched_highwater(self):
        self.checkpoint["revision"]=4
        self.checkpoint["signature"]=base64.b64encode(self.csk.sign(
            m.body(self.checkpoint,m.C_FIELDS,"MAD4B_ORIGINAL_HOST_CHECKPOINT_V1"))).decode()
        self.denied()
    def test_old_signed_ledger_with_newer_checkpoint(self):
        self.checkpoint["revision"]=4;self.checkpoint["state"]="REVOKED"
        self.checkpoint["signature"]=base64.b64encode(self.csk.sign(
            m.body(self.checkpoint,m.C_FIELDS,"MAD4B_ORIGINAL_HOST_CHECKPOINT_V1"))).decode()
        self.denied()
    def test_no_postreserve(self):
        self.wp["original_reserved_job_id"]="f"*32;self.denied(reserved=True)
    def test_revoked_anchor_no_execute(self):
        self.ledger["state"]="REVOKED";self.checkpoint["state"]="REVOKED";self.wp["state"]="REVOKED"
        self.sign();self.denied()
    def test_cross_site(self):
        self.job["site_uuid"]="11111111-1111-4111-8111-111111111111";self.denied()
    def test_signed_historic_event_survives_expiry_only_with_fresh_witness(self):
        self.ledger["issued_epoch"]=self.now-900
        self.ledger["expires_epoch"]=self.now-840
        self.sign()
        self.assertFalse(self.current()["execution_authorized"])
        self.checkpoint["issued_epoch"]=self.now-900
        self.denied()
    def test_expired_checkpoint_even_with_historic_signed_ledger(self):
        self.ledger["issued_epoch"]=self.now-900
        self.ledger["expires_epoch"]=self.now-840
        self.checkpoint["issued_epoch"]=self.now-900
        self.checkpoint["expires_epoch"]=self.now-840
        self.sign()
        self.denied()
    def test_stale_wp_original318(self):
        self.wp["observed_epoch"]-=100;self.denied()
    def test_revoke_postcondition_signed(self):
        self.job["operation_id"]="company.host-authority.anchor.revoke"
        self.ledger["revision"]=4;self.ledger["state"]="REVOKED"
        self.checkpoint["revision"]=4;self.checkpoint["state"]="REVOKED"
        self.wp.update(revision=4,state="REVOKED",
            current_anchor_eligible=False,prior_grants_ineligible=True)
        self.sign()
        result=m.verify_revoked_postcondition(
            self.job,self.ledger,self.checkpoint,self.wp,self.pk,self.cpk,now=self.now)
        self.assertFalse(result["receipt_signed"])
        self.assertFalse(result["original313_certified"])
    def test_revoke_requires_prior_grants_ineligible(self):
        self.job["operation_id"]="company.host-authority.anchor.revoke"
        self.ledger["revision"]=4;self.ledger["state"]="REVOKED"
        self.checkpoint["revision"]=4;self.checkpoint["state"]="REVOKED"
        self.wp.update(revision=4,state="REVOKED",
            current_anchor_eligible=False,prior_grants_ineligible=False)
        self.sign()
        with self.assertRaises(m.RevisionDenied):
            m.verify_revoked_postcondition(
                self.job,self.ledger,self.checkpoint,self.wp,self.pk,self.cpk,now=self.now)
    def test_revoke_after_grant_not_a_receipt(self):
        self.job["operation_id"]="company.host-authority.anchor.revoke"
        self.ledger["revision"]=4;self.ledger["state"]="REVOKED"
        self.checkpoint["revision"]=4;self.checkpoint["state"]="REVOKED"
        self.wp.update(revision=4,state="REVOKED",
            current_anchor_eligible=False,prior_grants_ineligible=True)
        self.sign()
        self.assertFalse(m.verify_revoked_postcondition(
            self.job,self.ledger,self.checkpoint,self.wp,self.pk,self.cpk,now=self.now
            )["original313_certified"])
if __name__=="__main__":unittest.main(verbosity=2)
