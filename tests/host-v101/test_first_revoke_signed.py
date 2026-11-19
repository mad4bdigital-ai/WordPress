#!/usr/bin/env python3
"""Pure exact original312 first-revoke interop tests; no live keys/writes."""
import base64
import hashlib
import importlib.util
import time
import unittest
from pathlib import Path
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey
from cryptography.hazmat.primitives.serialization import Encoding,PublicFormat

SRC=Path(__file__).resolve().parents[2]/"ops/host-anchor-v101/original312_first_revoke_bundle.py"
sp=importlib.util.spec_from_file_location("v101_first_revoke_source",SRC)
m=importlib.util.module_from_spec(sp);sp.loader.exec_module(m)
g=m.module(m.GATE,m.GATE_SHA,"v101gate")
p=m.module(m.PLAN,m.PLAN_SHA,"v101plan")

class FirstRevokeSigned(unittest.TestCase):
    def setUp(self):
        self.now=int(time.time())
        self.broker=Ed25519PrivateKey.generate()
        self.independent=Ed25519PrivateKey.generate()
        self.bpub=self.broker.public_key().public_bytes(Encoding.Raw,PublicFormat.Raw)
        self.ipub=self.independent.public_key().public_bytes(Encoding.Raw,PublicFormat.Raw)
        self.site="49c562d1-8f2f-456f-b454-26816c6ba4cb"
        self.plan={
          "contract":p.CONTRACTS[m.REVOKE],"operation_id":m.REVOKE,
          "authority_mode":"operational","company":"all-royal-egypt",
          "environment":"staging","site_uuid":self.site,
          "origin":"https://staging.allroyalegypt.com",
          "target_host_id":"hosting-2","ssh_principal":"u948953904",
          "policy_sha256":"1"*64,"profile_digest":"2"*64,
          "host_key_sha256":"3"*64,"ssh_root_sha256":"4"*64,
          "registry_sha256":"5"*64,"grant_revision":0,
          "anchor_revision":1,"target_anchor_nonce":"6"*32}
        self.update_plan_digest()
        self.job={
          "job_id":"b"*32,"operation_id":m.REVOKE,"operation":m.REVOKE,
          "company":"all-royal-egypt","environment":"staging",
          "site_uuid":self.site,"origin":self.plan["origin"],
          "target_host_id":"hosting-2","target_ssh_principal":"u948953904",
          "job_type":"signed_remote_hostinger_wpcli_v2",
          "principal_type":"signed_host_principal_v2",
          "state":"BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY",
          "authority_mode":"operational","host_v2_bootstrap_only":False,
          "executed":False,"plan_sha256":self.plan["plan_sha256"],
          "expires_epoch":self.now+180}
        self.ledger={
          "contract":g.LEDGER,"company":"all-royal-egypt",
          "environment":"staging","site_uuid":self.site,
          "target_host_id":"hosting-2","ssh_principal":"u948953904",
          "revision":1,"grant_revision":0,"anchor_nonce":"6"*32,
          "state":"ACTIVE","event_id":"a"*32,
          "previous_event_sha256":"0"*64,
          "issued_epoch":self.now-10,"expires_epoch":self.now+35}
        self.ledger["signature"]=self.sign(self.broker,
            g.body(self.ledger,g.FIELDS,"MAD4B_ORIGINAL_HOST_LEDGER_V1"))
        digest=hashlib.sha256(g.body(
            self.ledger,g.FIELDS,"MAD4B_ORIGINAL_HOST_LEDGER_V1")).hexdigest()
        self.cp={
          "contract":g.CHECKPOINT,"company":"all-royal-egypt",
          "environment":"staging","site_uuid":self.site,
          "revision":1,"grant_revision":0,"anchor_nonce":"6"*32,
          "state":"ACTIVE","event_sha256":digest,
          "issued_epoch":self.now-10,"expires_epoch":self.now+35}
        self.cp["signature"]=self.sign(self.independent,
            g.body(self.cp,g.C_FIELDS,"MAD4B_ORIGINAL_HOST_CHECKPOINT_V1"))
        self.prior={
          "contract":"mad4b.company-host-authority-anchor.v2",
          "company":"all-royal-egypt","environment":"staging",
          "site_uuid":self.site,"canonical_origin":self.plan["origin"],
          "profile_digest":self.plan["profile_digest"],
          "host_id":"srv2030908","ssh_target":"hosting-2",
          "ssh_principal":"u948953904",
          "ssh_root_sha256":self.plan["ssh_root_sha256"],
          "host_key_sha256":self.plan["host_key_sha256"],
          "registry_sha256":self.plan["registry_sha256"],
          "policy_sha256":self.plan["policy_sha256"],
          "revision":1,"issued":self.now-10,"expires":self.now+800,
          "nonce":"6"*32,"state":"ACTIVE_INDEPENDENT_HOST_AUTHORITY",
          "revoked":False,"source":"original_approved_host_authority_reconcile"}
        self.prior["signature"]=self.sign(self.broker,m.anchor_message({**self.prior,"signature":""}))
        self.wp={
          "contract":"mad4b.original318.host-anchor-current-readback.v2",
          "source":"original318_fresh_wp_readback",
          "company":"all-royal-egypt","environment":"staging",
          "site_uuid":self.site,"observed_epoch":self.now,
          "readback_verified":True,"state":"ACTIVE","revision":1,
          "anchor_nonce":"6"*32,
          "current_anchor_signature_verified":True,
          "signed_anchor_active_in_option":True,
          "current_anchor_eligible":False,"original313_certified":False,
          "write_authorized":False,
          "signed_anchor_message_sha256":hashlib.sha256(m.anchor_message(self.prior)).hexdigest()}

    def update_plan_digest(self):
        self.plan["plan_sha256"]=hashlib.sha256(
          p.canonical({k:v for k,v in self.plan.items() if k!="plan_sha256"})).hexdigest()
    @staticmethod
    def sign(key,body):
        return base64.b64encode(key.sign(body)).decode()
    def verify(self):
        return m.verify_first_revoke(self.plan,self.job,self.ledger,self.cp,self.wp,
                                      self.bpub,self.ipub,now=self.now)
    def build(self):
        return m.signed_revoke_documents(self.plan,self.job,self.ledger,
             self.cp,self.wp,self.prior,self.broker,self.bpub,self.ipub,
             now=self.now,nonce="7"*32)

    def test_baseline_rejects_zero_but_exact_signed_v101_verifies(self):
        with self.assertRaisesRegex(p.PlanDenied,"HOST_OPERATION_CURRENT_REVISIONS_REQUIRED"):
            p.verify_plan(self.plan,m.REVOKE)
        a=self.verify()
        self.assertEqual(a["previous_grant_revision"],0)
        self.assertTrue(a["current_signed_highwater_verified"])
        self.assertFalse(a["execution_authorized"])

    def test_signed_revoke_links_two_distinct_keys_and_prior_event(self):
        out=self.build()
        a=out["anchor"];ledger=out["revision_event"]
        self.assertFalse(out["anchor_revoked"])
        self.assertFalse(out["original313_final_receipt_signed"])
        self.assertTrue(a["revoked"])
        self.assertEqual(a["state"],"REVOKED_INDEPENDENT_HOST_AUTHORITY")
        self.assertEqual(a["revision"],2)
        self.assertEqual(ledger["grant_revision"],0)
        self.assertEqual(ledger["revision"],2)
        self.assertEqual(ledger["event_id"],self.job["job_id"])
        self.assertEqual(ledger["previous_event_sha256"],self.cp["event_sha256"])
        self.broker.public_key().verify(
            base64.b64decode(a["signature"]),m.anchor_message(a))
        g.sigcheck(ledger,g.FIELDS,g.LEDGER,
                   "MAD4B_ORIGINAL_HOST_LEDGER_V1",self.bpub,self.now)
        self.assertEqual(out["anchor_message_sha256"],
            hashlib.sha256(m.anchor_message(a)).hexdigest())

    def test_invalid_root_checkpoint_signature_denied(self):
        self.cp["signature"]=base64.b64encode(bytes(64)).decode()
        with self.assertRaises(ValueError):self.verify()

    def test_wrong_plan_nonce_even_if_hash_resigned_denied(self):
        self.plan["target_anchor_nonce"]="8"*32
        self.update_plan_digest()
        self.job["plan_sha256"]=self.plan["plan_sha256"]
        with self.assertRaisesRegex(m.RevokeHeld,"CURRENT_HIGHWATER_MISSING"):
            self.verify()

    def test_claim_mismatch_stale_and_wrong_tenant_denied(self):
        for k,v in (("state","QUEUED_FOR_VPS_BROKER_CLAIM"),
                    ("operation_id",m.ISSUE),("host_v2_bootstrap_only",True),
                    ("company","wayfatours"),("environment","production"),
                    ("executed",True),("expires_epoch",self.now+1)):
            old=self.job[k];self.job[k]=v
            try:
                with self.assertRaises((m.RevokeHeld,g.RevisionDenied)):
                    self.verify()
            finally:self.job[k]=old

    def test_forged_prior_anchor_signature_denied(self):
        self.prior["signature"]=base64.b64encode(bytes(64)).decode()
        with self.assertRaisesRegex(m.RevokeHeld,"PREVIOUS_BROKER_ANCHOR_SIGNATURE_INVALID"):
            self.build()

    def test_prior_anchor_changed_option_denied(self):
        self.prior["policy_sha256"]="a"*64
        self.prior["signature"]=self.sign(self.broker,m.anchor_message(self.prior))
        with self.assertRaisesRegex(m.RevokeHeld,"PREVIOUS_SIGNED_WORDPRESS_OPTION_DRIFT"):
            self.build()

    def test_stale_wordpress_readback_denied(self):
        self.wp["observed_epoch"]=self.now-90
        with self.assertRaises(ValueError):self.verify()

    def test_reuse_previous_nonce_denied(self):
        with self.assertRaisesRegex(m.RevokeHeld,"NONCE_REPLAY"):
            m.signed_revoke_documents(self.plan,self.job,self.ledger,
                self.cp,self.wp,self.prior,self.broker,self.bpub,self.ipub,
                now=self.now,nonce=self.ledger["anchor_nonce"])

    def test_missing_highwater_signature_does_not_enable_zero_grant(self):
        self.cp["event_sha256"]="c"*64
        self.cp["signature"]=self.sign(self.independent,
            g.body(self.cp,g.C_FIELDS,"MAD4B_ORIGINAL_HOST_CHECKPOINT_V1"))
        with self.assertRaises(ValueError):self.verify()

if __name__=="__main__":unittest.main(verbosity=2)
