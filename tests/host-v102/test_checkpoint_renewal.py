#!/usr/bin/env python3
"""Synthetic independent Original313 high-water renewal and crash recovery."""
from __future__ import annotations
import base64
import hashlib
import importlib.util
import os
from pathlib import Path
import unittest
from unittest.mock import patch

W=Path(__file__).resolve().parents[2]
def load(path,name):
    spec=importlib.util.spec_from_file_location(name,path)
    obj=importlib.util.module_from_spec(spec)
    spec.loader.exec_module(obj)
    return obj
m=load(W/"ops/host-anchor-v102/original313_current_checkpoint_renewal.py",
       "v102_source_renew")
# Use the exact candidate v99 during regression before the live CAS release.
m.V99=W/"ops/host-anchor-v99/original313_postwrite_transaction.py"
m.V99_SHA=hashlib.sha256(m.V99.read_bytes()).hexdigest()
v99tests=load(W/"tests/host-v99/test_original313_postwrite_transaction.py",
             "v99_test_fixture_for_v102")
v99=m.pinned(m.V99,m.V99_SHA,"v102_v99_verified")
gate=m.pinned(m.GATE,m.GATE_SHA,"v102_gate_verified")

class HighwaterRenewal(unittest.TestCase):
    def setUp(self):
        self.fx=v99tests.RootTransactionTests()
        self.fx.setUp()
        self.fx.finalize()
        self.store=self.fx.store
        self.t0=self.fx.now
        self.wp=self.fx.wp.copy()
        self.ledger=self.store.read(
             self.store.path("revisions",m.SCOPE))
        self.receipt=self.store.read(
             self.store.path("receipts",self.fx.job["job_id"]+".json"))
        self.first_checkpoint=self.store.read(
             self.store.path("checkpoints",m.SCOPE))
        self.t=self.t0+100
        self.wp["observed_epoch"]=self.t
    def tearDown(self): self.fx.tearDown()
    def renew(self):
        return m.renew_current(
            self.ledger,self.wp,self.receipt,self.fx.brokerpub,
            self.fx.independent,store=self.store,now=self.t)
    def current(self):
        return self.store.read(self.store.path("checkpoints",m.SCOPE))
    def test_expired_checkpoint_renews_same_signed_event_only(self):
        result=self.renew()
        self.assertEqual(result["state"],"ORIGINAL313_CURRENT_CHECKPOINT_RENEWED")
        self.assertFalse(result["anchor_issued"])
        self.assertFalse(result["grant_issued"])
        self.assertEqual(result["grant_revision"],0)
        cp=self.current()
        self.assertEqual(cp["revision"],1)
        self.assertEqual(cp["event_sha256"],self.first_checkpoint["event_sha256"])
        self.assertEqual(cp["anchor_nonce"],self.first_checkpoint["anchor_nonce"])
        self.assertGreater(cp["issued_epoch"],self.first_checkpoint["issued_epoch"])
        self.assertEqual(self.store.read(
            self.store.path("revisions",m.SCOPE)),self.ledger)
        self.assertEqual(self.store.read(
            self.store.path("receipts",self.fx.job["job_id"]+".json")),
            self.receipt)
        expected=m.checkpoint_digest(gate,self.first_checkpoint)
        archive=m.renewal_archive(self.store,expected)
        self.assertEqual(self.store.read(archive),self.first_checkpoint)
        original_job={**self.fx.job,"anchor_revision":1}
        state=gate.verify_fresh_current(
            original_job,self.ledger,cp,self.wp,
            self.fx.brokerpub,self.fx.independentpub,now=self.t)
        self.assertEqual(state["state"],
            "CURRENT_ORIGINAL_REVISION_MATCHED__NONAUTHORIZING")
    def test_original_v99_receipt_replay_after_renewal_succeeds(self):
        self.renew()
        result=v99.finalize(
            self.fx.job,self.ledger,self.wp,
            self.fx.brokerpub,self.fx.independent,
            store=self.store,now=self.t)
        self.assertEqual(result["state"],
            "ORIGINAL313_PRIOR_SIGNED_RECEIPT_REUSED")
        self.assertEqual(self.store.read(
            self.store.path("receipts",self.fx.job["job_id"]+".json")),
            self.receipt)

    def test_tampered_historical_checkpoint_denies_v99_receipt_replay(self):
        self.renew()
        archive=m.renewal_archive(self.store,
                 self.receipt["checkpoint_sha256"])
        bad=self.store.read(archive)
        bad["signature"]=base64.b64encode(os.urandom(64)).decode()
        archive.write_bytes(v99.canonical(bad)+b"\n")
        os.chmod(archive,0o600)
        with self.assertRaises(ValueError):
            v99.finalize(self.fx.job,self.ledger,self.wp,
                 self.fx.brokerpub,self.fx.independent,
                 store=self.store,now=self.t)

    def test_second_renewal_preserves_original_receipt_and_archive(self):
        self.renew()
        self.t+=95;self.wp["observed_epoch"]=self.t
        newer=self.renew()
        self.assertTrue(newer["new_checkpoint_signed"])
        original_sha=self.receipt["checkpoint_sha256"]
        original=m.renewal_archive(self.store,original_sha)
        self.assertEqual(self.store.read(original),self.first_checkpoint)
        self.assertEqual(self.current()["issued_epoch"],self.t)
        self.assertEqual(self.store.read(
            self.store.path("receipts",self.fx.job["job_id"]+".json")),
            self.receipt)
    def test_current_checkpoint_still_fresh_no_second_signature(self):
        self.t=self.t0+10
        self.wp["observed_epoch"]=self.t
        original=self.current()
        result=self.renew()
        self.assertEqual(result["state"],
            "ORIGINAL313_CURRENT_CHECKPOINT_ALREADY_FRESH")
        self.assertEqual(self.current(),original)
        self.assertFalse(result["new_checkpoint_signed"])
    def test_forged_original_receipt_denied(self):
        self.receipt["signature"]=base64.b64encode(os.urandom(64)).decode()
        with self.assertRaises((m.RenewalHeld,ValueError)):
            self.renew()
        self.assertEqual(self.current(),self.first_checkpoint)
    def test_mismatched_revoked_or_old_wordpress_denied(self):
        for field,bad in (("state","REVOKED"),("revision",2),
            ("anchor_nonce","f"*32),("readback_verified",False),
            ("observed_epoch",self.t-100),
            ("signed_anchor_active_in_option",False)):
            old=self.wp[field]
            self.wp[field]=bad
            try:
                with self.assertRaises(m.RenewalHeld):
                    self.renew()
                self.assertEqual(self.current(),self.first_checkpoint)
            finally:
                self.wp[field]=old
    def test_tampered_checkpoint_signed_field_denied(self):
        cpfile=self.store.path("checkpoints",m.SCOPE)
        bad=self.current()
        bad["event_sha256"]="f"*64
        cpfile.write_bytes(v99.canonical(bad)+b"\n")
        os.chmod(cpfile,0o600)
        with self.assertRaises((ValueError,m.RenewalHeld)):
            self.renew()
    def test_no_unissued_anchor_without_signed_313_receipt(self):
        receipt_path=self.store.path("receipts",self.fx.job["job_id"]+".json")
        receipt_path.unlink()
        with self.assertRaisesRegex(m.RenewalHeld,
                                    "HIGHWATER_OR_RECEIPT_CHANGED"):
            self.renew()
    def test_crash_between_archive_and_checkpoint_swap_can_resume(self):
        original=self.current()
        with patch.object(self.store,"replace_verified",
                          side_effect=RuntimeError("SIMULATED_POWER_LOSS")):
            with self.assertRaisesRegex(RuntimeError,"SIMULATED_POWER_LOSS"):
                self.renew()
        self.assertEqual(self.current(),original)
        self.assertEqual(len(list(
            (self.store.root/"checkpoint-history").glob("*.json"))),1)
        self.assertEqual(self.renew()["state"],
            "ORIGINAL313_CURRENT_CHECKPOINT_RENEWED")
    def test_rollback_to_old_checkpoint_invalidates_freshness(self):
        self.renew()
        path=self.store.path("checkpoints",m.SCOPE)
        path.write_bytes(v99.canonical(self.first_checkpoint)+b"\n")
        os.chmod(path,0o600)
        with self.assertRaises(gate.RevisionDenied):
            gate.verify_fresh_current(
                {**self.fx.job,"anchor_revision":1},
                self.ledger,self.current(),self.wp,
                self.fx.brokerpub,self.fx.independentpub,now=self.t)
    def test_no_direct_root_cli_exec(self):
        self.assertEqual(m.main(),2)
if __name__=="__main__":
    unittest.main(verbosity=2)
