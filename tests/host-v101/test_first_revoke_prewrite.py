#!/usr/bin/env python3
"""Synthetic independently signed revoke prewrite -> v99 final receipt tests."""
import base64
import hashlib
import importlib.util
import os
import tempfile
import time
import unittest
from pathlib import Path
from unittest.mock import patch

HERE=Path(__file__).resolve().parent
def read(path,name):
    sp=importlib.util.spec_from_file_location(name,path)
    module=importlib.util.module_from_spec(sp);sp.loader.exec_module(module)
    return module
fixture=read(HERE/"test_first_revoke_signed.py","_v101_revoke_test_fixture")
src=Path(__file__).resolve().parents[2]/"ops/host-anchor-v101"
pre=read(src/"original313_first_revoke_prewrite.py","_v101_revoke_prewrite")
# Pin the candidate SOURCE during hermetic tests; production expects Root copy.
pre.V101=src/"original312_first_revoke_bundle.py"
v99=pre.load(pre.V99,pre.V99_SHA,"_v101_v99_test")
gate=pre.load(pre.GATE,pre.GATE_SHA,"_v101_gate_test")

class RevokePrewrite(unittest.TestCase):
    def setUp(self):
        self.base=fixture.FirstRevokeSigned()
        self.base.setUp()
        self.tmp=tempfile.TemporaryDirectory(prefix="mad4b-v101-prewrite-")
        self.root=Path(self.tmp.name);os.chmod(self.root,0o700)
        self.store=v99.PrivateStore(self.root)
        self.scope="all-royal-egypt--staging.json"
        self.store.write_new(self.store.path("revisions",self.scope),self.base.ledger)
        self.store.write_new(self.store.path("checkpoints",self.scope),self.base.cp)
        self.bundle=self.base.build()
        j=self.base.job
        j.update(broker_host_id="srv2030908",target_scope="host",
                 command_format="host.anchor.ref.v2",
                 command_sha256="c"*64,grant_id="d"*32,
                 claimed_epoch=self.base.now-5,
                 ssh_principal="u948953904")
        self.claim={"contract":"mad4b.central-vps-broker.v1",
          "job_id":j["job_id"],"host_id":"srv2030908",
          "nonce":"e"*32,"claimed_epoch":j["claimed_epoch"],
          "state":j["state"],"production_mutation_performed":False}
        self.row={"job_id":j["job_id"],"broker_host_id":"srv2030908",
          "target_id":"hosting-2","command_sha256":j["command_sha256"]}
        self.approval={
          "contract":v99.PREWRITE_CONTRACT,
          "job_id":j["job_id"],"operation_id":fixture.m.REVOKE,
          "company":j["company"],"environment":j["environment"],
          "site_uuid":j["site_uuid"],
          "plan_sha256":j["plan_sha256"],
          "command_sha256":j["command_sha256"],"grant_id":j["grant_id"],
          "anchor_revision_before":1,"anchor_revision_after":2,
          "anchor_nonce":self.bundle["anchor"]["nonce"],
          "anchor_message_sha256":self.bundle["anchor_message_sha256"],
          "issued_epoch":self.base.now,"expires_epoch":self.base.now+100}
        self.sign_approval()
    def tearDown(self):self.tmp.cleanup()
    def sign_approval(self):
        self.approval["signature"]=base64.b64encode(self.base.independent.sign(
            b"MAD4B_ORIGINAL313_HOST_ANCHOR_WRITE_V1\n"+
            v99.canonical({k:self.approval[k] for k in v99.PREWRITE_FIELDS})
        )).decode()
    def reserve(self):
        return pre.reserve_revoke(
            self.base.job,self.base.plan,self.claim,self.row,
            self.base.ledger,self.base.cp,self.base.wp,
            self.bundle,self.approval,self.base.bpub,self.base.ipub,
            store=self.store,now=self.base.now)
    def test_signed_zero_grant_first_revoke_prewrite(self):
        result=self.reserve()
        self.assertEqual(result["state"],"ORIGINAL313_SIGNED_FIRST_REVOKE_PREWRITE_RESERVED")
        self.assertFalse(result["anchor_revoked"])
        record=self.store.read(self.store.path(
            "transactions",self.base.job["job_id"]+".json"))
        self.assertEqual(record["anchor_revision_after"],2)
        self.assertEqual(record["operation_id"],self.base.job["operation_id"])
        self.assertEqual(record["previous_event_sha256"],self.base.cp["event_sha256"])

    def test_replay_is_idempotent_and_cannot_overwrite(self):
        self.reserve()
        path=self.store.path("transactions",self.base.job["job_id"]+".json")
        first=path.read_bytes()
        self.reserve()
        self.assertEqual(path.read_bytes(),first)
        self.approval["anchor_nonce"]="f"*32
        self.sign_approval()
        with self.assertRaises(pre.RevokePrewriteHeld):
            self.reserve()
        self.assertEqual(path.read_bytes(),first)

    def test_forged_independent_approval_denied(self):
        self.approval["signature"]=base64.b64encode(bytes(64)).decode()
        with self.assertRaisesRegex(pre.RevokePrewriteHeld,
                                     "REVOKE_INDEPENDENT_APPROVAL_INVALID"):
            self.reserve()

    def test_altered_broker_revoke_anchor_denied(self):
        self.bundle["anchor"]["signature"]=base64.b64encode(bytes(64)).decode()
        with self.assertRaises(pre.RevokePrewriteHeld):self.reserve()

    def test_tampered_real_claim_denied(self):
        for k,x in (("job_id","f"*32),("state","QUEUED_FOR_VPS_BROKER_CLAIM"),
                    ("nonce","invalid"),("host_id","unknown")):
            old=self.claim[k];self.claim[k]=x
            try:
                with self.assertRaises(pre.RevokePrewriteHeld):self.reserve()
            finally:self.claim[k]=old

    def test_same_scope_second_revoke_reservation_is_denied(self):
        self.reserve()
        other=self.store.path("transactions","f"*32+".json")
        self.store.write_new(other,{
          "contract":v99.PREWRITE_CONTRACT,
          "operation_id":fixture.m.REVOKE,
          "company":"all-royal-egypt","environment":"staging",
          "job_id":"f"*32})
        with self.assertRaisesRegex(
              pre.RevokePrewriteHeld,"CONCURRENT_REVOKE_RESERVATION_DENIED"):
            self.reserve()

    def test_reject_nonhex_claim_nonce_and_stale_claim(self):
        old=self.claim["nonce"]
        self.claim["nonce"]="z"*32
        with self.assertRaises(pre.RevokePrewriteHeld):self.reserve()
        self.claim["nonce"]=old
        old=self.claim["claimed_epoch"]
        self.claim["claimed_epoch"]=self.base.now-500
        self.base.job["claimed_epoch"]=self.claim["claimed_epoch"]
        with self.assertRaises(pre.RevokePrewriteHeld):self.reserve()
        self.claim["claimed_epoch"]=old
        self.base.job["claimed_epoch"]=old

    def test_stale_or_wrong_root_highwater_denied(self):
        self.base.cp["event_sha256"]="f"*64
        with self.assertRaises(ValueError):self.reserve()

    def test_postwrite_revoke_finalizer_with_actual_signed_prewrite(self):
        self.reserve()
        event=self.bundle["revision_event"]
        wp=dict(self.base.wp)
        wp.update(state="REVOKED",revision=2,anchor_nonce=event["anchor_nonce"],
                  current_anchor_signature_verified=False,
                  signed_anchor_active_in_option=False,
                  current_anchor_eligible=False,prior_grants_ineligible=True,
                  signed_anchor_message_sha256=self.bundle["anchor_message_sha256"])
        result=v99.finalize(self.base.job,event,wp,
            self.base.bpub,self.base.independent,store=self.store,now=self.base.now)
        self.assertTrue(result["original313_certified"])
        self.assertTrue(result["anchor_revoked"])
        self.assertEqual(result["revision"],2)
        self.assertEqual(self.store.read(self.store.path("revisions",self.scope)),event)
        self.assertEqual(self.store.read(self.store.path("checkpoints",self.scope))["revision"],2)
        self.assertEqual(v99.finalize(self.base.job,event,wp,
              self.base.bpub,self.base.independent,store=self.store,
              now=self.base.now)["state"],"ORIGINAL313_PRIOR_SIGNED_RECEIPT_REUSED")

if __name__=="__main__":unittest.main(verbosity=2)
