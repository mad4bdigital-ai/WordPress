#!/usr/bin/env python3
"""Signed Broker ledger -> independent Original313 high-water & receipt.

Every test uses synthetic keys in a root-owned private TEMP directory.
Never accesses the live owner, private signing key or Root authority stores.
"""
from __future__ import annotations
import base64
import hashlib
import importlib.util
import os
import tempfile
import time
import unittest
from pathlib import Path
from unittest.mock import patch
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey
from cryptography.hazmat.primitives.serialization import Encoding,PublicFormat

FILE=Path(__file__).resolve().parents[2]/"ops/host-anchor-v99/original313_postwrite_transaction.py"
sp=importlib.util.spec_from_file_location("test_original313_transaction",FILE)
m=importlib.util.module_from_spec(sp);sp.loader.exec_module(m)
gate=m.load_gate()

class RootTransactionTests(unittest.TestCase):
    def setUp(self):
        self.work=tempfile.TemporaryDirectory(prefix="mad4b-v99-transaction-")
        self.root=Path(self.work.name);os.chmod(self.root,0o700)
        self.store=m.PrivateStore(self.root)
        self.broker=Ed25519PrivateKey.generate()
        self.independent=Ed25519PrivateKey.generate()
        self.brokerpub=self.broker.public_key().public_bytes(Encoding.Raw,PublicFormat.Raw)
        self.independentpub=self.independent.public_key().public_bytes(Encoding.Raw,PublicFormat.Raw)
        self.now=int(time.time())
        self.scope="all-royal-egypt--staging.json"
        self.site="49c562d1-8f2f-456f-b454-26816c6ba4cb"
        self.job=self.make_job("a"*32,m.ISSUE,0)
        self.ledger=self.make_ledger(self.job,1,0,"0"*64,"ACTIVE","1"*32)
        self.wp=self.make_wp(self.ledger)
        self.reserve_first_prewrite()

    def tearDown(self):
        self.work.cleanup()

    def make_job(self,jid,op,prior):
        return {"job_id":jid,"company":"all-royal-egypt","environment":"staging",
          "site_uuid":self.site,"target_host_id":"hosting-2",
          "ssh_principal":"u948953904",
          "job_type":"signed_remote_hostinger_wpcli_v2",
          "principal_type":"signed_host_principal_v2",
          "state":"BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY",
          "operation_id":op,"executed":False,
          "anchor_revision":prior,"grant_revision":0,
          "origin":"https://staging.allroyalegypt.com",
          "target_ssh_principal":"u948953904",
          "grant_id":"3"*32,
          "plan_sha256":"b"*64,
          "command_sha256":"c"*64,
          "expires_epoch":self.now+300}
    def make_ledger(self,job,revision,grant,previous,state,nonce):
        row={"contract":gate.LEDGER,
             "company":job["company"],"environment":job["environment"],
             "site_uuid":job["site_uuid"],"target_host_id":"hosting-2",
             "ssh_principal":"u948953904",
             "revision":revision,"grant_revision":grant,
             "anchor_nonce":nonce,"state":state,
             "event_id":job["job_id"],"previous_event_sha256":previous,
             "issued_epoch":self.now,"expires_epoch":self.now+50}
        row["signature"]=base64.b64encode(self.broker.sign(
            gate.body(row,gate.FIELDS,"MAD4B_ORIGINAL_HOST_LEDGER_V1"))).decode()
        return row
    def make_wp(self,ledger):
        state=ledger["state"]
        return {"contract":"mad4b.original318.host-anchor-current-readback.v2",
            "source":"original318_fresh_wp_readback",
            "company":ledger["company"],"environment":ledger["environment"],
            "site_uuid":ledger["site_uuid"],
            "observed_epoch":self.now,"readback_verified":True,
            "state":state,"revision":ledger["revision"],
            "anchor_nonce":ledger["anchor_nonce"],
            "signed_anchor_message_sha256":"f"*64,
            "current_anchor_signature_verified":state=="ACTIVE",
            "signed_anchor_active_in_option":state=="ACTIVE",
            "current_anchor_eligible":False,"original313_certified":False,
            "write_authorized":False,"fresh_central_highwater_verified":False,
            "prior_grants_ineligible":state=="REVOKED",
        }
    def signed_approval(self,job,nonce,revision,sha):
        row={
            "contract":m.PREWRITE_CONTRACT,
            "job_id":job["job_id"],"operation_id":job["operation_id"],
            "company":job["company"],"environment":job["environment"],
            "site_uuid":job["site_uuid"],
            "plan_sha256":job["plan_sha256"],
            "command_sha256":job["command_sha256"],
            "grant_id":job["grant_id"],
            "anchor_revision_before":revision-1,
            "anchor_revision_after":revision,
            "anchor_nonce":nonce,"anchor_message_sha256":sha,
            "issued_epoch":self.now,
            "expires_epoch":self.now+100,
        }
        row["signature"]=base64.b64encode(self.independent.sign(
            b"MAD4B_ORIGINAL313_HOST_ANCHOR_WRITE_V1\n"+
            m.canonical({f:row[f] for f in m.PREWRITE_FIELDS}))).decode()
        return row

    def reserve_first_prewrite(self):
        verifier=m.load_source(m.ENROLLMENT_VERIFIER,m.ENROLLMENT_SHA256,
                               "first_enrollment_v99_test")
        b=m.load_source(m.BOOTSTRAP_VERIFIER,m.BOOTSTRAP_SHA256,
                        "first_bootstrap_v99_test")
        self.enrollment={
          "contract":verifier.CONTRACT,"source":verifier.SOURCE,
          "company":self.job["company"],
          "environment":self.job["environment"],
          "site_uuid":self.job["site_uuid"],
          "origin":self.job["origin"],
          "target_host_id":self.job["target_host_id"],
          "ssh_principal":self.job["target_ssh_principal"],
          "enrollment_id":"4"*32,"nonce":"5"*32,
          "original306_job_id":self.job["job_id"],
          "plan_sha256":self.job["plan_sha256"],
          "command_sha256":self.job["command_sha256"],
          "current_anchor_revision":0,"current_anchor_state":"NOT_ENROLLED",
          "nonce_unspent":True,"signed_site_identity_verified":True,
          "remote_host_identity_verified":True,"independent_issuer_verified":True,
          "owner_scope_authorized":True,"enrollment_revoked":False,
          "issued_epoch":self.now,"expires_epoch":self.now+150}
        self.enrollment["signature"]=base64.b64encode(self.independent.sign(
            verifier.enrollment_message(self.enrollment))).decode()
        self.store.write_new(self.store.path("enrollments",self.scope),
                             self.enrollment)
        self.bootstrap={
          "contract":b.CONTRACT,"job_id":self.job["job_id"],
          "operation_id":m.ISSUE,"company":self.job["company"],
          "environment":self.job["environment"],
          "site_uuid":self.job["site_uuid"],
          "origin":self.job["origin"],
          "target_host_id":self.job["target_host_id"],
          "ssh_principal":self.job["target_ssh_principal"],
          "enrollment_id":self.enrollment["enrollment_id"],
          "requested_anchor_revision":1,
          "command_sha256":self.job["command_sha256"],
          "plan_sha256":self.job["plan_sha256"],
          "nonce":self.enrollment["nonce"],
          "issued_epoch":self.now,"expires_epoch":self.now+45}
        self.bootstrap["signature"]=base64.b64encode(self.broker.sign(
              b.signed_message(self.bootstrap))).decode()
        self.approval=self.signed_approval(self.job,
          self.ledger["anchor_nonce"],1,self.wp["signed_anchor_message_sha256"])
        self.assertEqual(m.reserve_signed_prewrite(
              self.job,self.enrollment,self.bootstrap,self.approval,
              self.brokerpub,self.independentpub,store=self.store,
              now=self.now)["state"],"ORIGINAL313_SIGNED_PREWRITE_RESERVED")

    def reserve_revoke_test_fixture(self):
        # Deliberately synthetic fixture, not a released revoke preparer.
        # Only tests the chain of ROOT ledger / independent final receipts.
        approval=self.signed_approval(self.job,self.ledger["anchor_nonce"],
            self.ledger["revision"],self.wp["signed_anchor_message_sha256"])
        row={
          "contract":m.PREWRITE_CONTRACT,
          "job_id":self.job["job_id"],"operation_id":m.REVOKE,
          "company":self.job["company"],"environment":self.job["environment"],
          "site_uuid":self.job["site_uuid"],
          "anchor_revision_after":self.ledger["revision"],
          "anchor_nonce":self.ledger["anchor_nonce"],
          "anchor_message_sha256":self.wp["signed_anchor_message_sha256"],
          "approval_sha256":hashlib.sha256(m.canonical(approval)).hexdigest(),
          "signed_approval":approval,
          "reserved_epoch":self.now}
        self.store.write_new(self.store.path("transactions",
            self.job["job_id"]+".json"),row)

    def finalize(self,**kwargs):
        return m.finalize(self.job,self.ledger,self.wp,self.brokerpub,
                          self.independent,store=self.store,now=self.now,**kwargs)

    def test_first_issue_seals_real_signature_format(self):
        result=self.finalize()
        self.assertEqual(result["state"],"ORIGINAL313_SIGNED_POSTWRITE_RECEIPT_PERSISTED")
        self.assertTrue(result["original313_certified"])
        self.assertEqual(result["revision"],1)
        ledger=self.store.read(self.store.path("revisions",self.scope))
        cp=self.store.read(self.store.path("checkpoints",self.scope))
        receipt=self.store.read(self.store.path("receipts",self.job["job_id"]+".json"))
        self.assertEqual(ledger,self.ledger)
        self.assertEqual(cp["event_sha256"],result["event_sha256"])
        gate.sigcheck(cp,gate.C_FIELDS,gate.CHECKPOINT,
             "MAD4B_ORIGINAL_HOST_CHECKPOINT_V1",self.independentpub,self.now)
        signature=base64.b64decode(receipt["signature"],validate=True)
        self.independent.public_key().verify(signature,
             b"MAD4B_ORIGINAL313_HOST_FINAL_RECEIPT_V1\n"+
             m.canonical({k:receipt[k] for k in m.RECEIPT_FIELDS}))
        check=gate.verify_fresh_current(
             {**self.job,"anchor_revision":1},
             self.ledger,cp,self.wp,self.brokerpub,self.independentpub,now=self.now)
        self.assertEqual(check["state"],"CURRENT_ORIGINAL_REVISION_MATCHED__NONAUTHORIZING")

    def test_replay_returns_same_certificate_and_does_not_write_new_event(self):
        self.finalize()
        receiptpath=self.store.path("receipts",self.job["job_id"]+".json")
        first=receiptpath.read_bytes()
        reused=self.finalize()
        self.assertEqual(reused["state"],"ORIGINAL313_PRIOR_SIGNED_RECEIPT_REUSED")
        self.assertEqual(first,receiptpath.read_bytes())
        self.assertEqual(len(list((self.root/"history").iterdir())),0)

    def test_forged_broker_event_denied_without_persistent_authority(self):
        self.ledger["event_id"]="b"*32
        with self.assertRaises(ValueError):
            self.finalize()
        self.assertEqual(list((self.root/"revisions").iterdir()),[])

    def test_tampered_signature_denied(self):
        self.ledger["signature"]=base64.b64encode(os.urandom(64)).decode()
        with self.assertRaisesRegex(ValueError,'SIGNED_REVISION_SIGNATURE_INVALID'):self.finalize()
        self.assertEqual(list((self.root/"revisions").iterdir()),[])

    def test_mismatched_wp_nonce_denied(self):
        self.wp["anchor_nonce"]="b"*32
        with self.assertRaises(m.TransactionHeld):self.finalize()
        self.assertEqual(list((self.root/"revisions").iterdir()),[])

    def test_lost_independent_signer_key_guard(self):
        with self.assertRaises(m.TransactionHeld):
            m.finalize(self.job,self.ledger,self.wp,self.brokerpub,self.broker,
                       store=self.store,now=self.now)

    def test_wp_stale_or_fake_self_certification_denied(self):
        for name,value in (
            ("observed_epoch",self.now-80),
            ("readback_verified",False),
            ("original313_certified",True),
            ("current_anchor_signature_verified",False),
            ("write_authorized",True),
        ):
            original=self.wp[name];self.wp[name]=value
            try:
                with self.assertRaises((gate.RevisionDenied,m.TransactionHeld)):
                    self.finalize()
            finally:self.wp[name]=original
        self.assertEqual(list((self.root/"revisions").iterdir()),[])

    def test_crash_after_ledger_write_can_only_complete_same_signed_event(self):
        original=self.store.write_new
        def crash_at_checkpoint(path,row):
            if path.parent.name=="checkpoints":
                raise RuntimeError("SIMULATED_POWER_FAILURE_AFTER_LEDGER")
            return original(path,row)
        with patch.object(self.store,"write_new",side_effect=crash_at_checkpoint):
            with self.assertRaisesRegex(RuntimeError,"SIMULATED_POWER_FAILURE"):
                self.finalize()
        self.assertIsNotNone(self.store.read(self.store.path("revisions",self.scope)))
        self.assertIsNone(self.store.read(self.store.path("checkpoints",self.scope)))
        resumed=self.finalize()
        self.assertTrue(resumed["original313_certified"])

    def test_revoke_revision_two_archives_prior_and_rejects_replay(self):
        self.finalize()
        first=self.ledger
        prevsha=hashlib.sha256(gate.body(first,gate.FIELDS,
             "MAD4B_ORIGINAL_HOST_LEDGER_V1")).hexdigest()
        self.job=self.make_job("b"*32,m.REVOKE,1)
        self.ledger=self.make_ledger(self.job,2,0,prevsha,"REVOKED","2"*32)
        self.wp=self.make_wp(self.ledger)
        self.reserve_revoke_test_fixture()
        second=self.finalize()
        self.assertTrue(second["anchor_revoked"])
        self.assertEqual(second["revision"],2)
        self.assertEqual(self.store.read(self.store.path("history","a"*32+".json")),first)
        current=self.store.read(self.store.path("revisions",self.scope))
        self.assertEqual(current["state"],"REVOKED")
        with self.assertRaises((gate.RevisionDenied,m.TransactionHeld)):
            self.job=self.make_job("c"*32,m.REVOKE,1)
            self.ledger=self.make_ledger(self.job,3,0,
               hashlib.sha256(gate.body(current,gate.FIELDS,
                "MAD4B_ORIGINAL_HOST_LEDGER_V1")).hexdigest(),"REVOKED","3"*32)
            self.wp=self.make_wp(self.ledger)
            self.finalize()

    def test_live_root_namespace_rejects_synthetic_signer_pins(self):
        # Simulate selection of the protected Root namespace without
        # modifying any real Root file, key or signing state.
        with patch.object(m,"ROOT",self.root):
            with self.assertRaisesRegex(
                m.TransactionHeld,"LIVE_SIGNER_NOT_ORIGINAL_ENROLLED_PIN"):
                m.reserve_signed_prewrite(
                    self.job,self.enrollment,self.bootstrap,self.approval,
                    self.brokerpub,self.independentpub,
                    store=self.store,now=self.now)

    def test_exact_signed_prewrite_replay_is_idempotent(self):
        path=self.store.path("transactions",self.job["job_id"]+".json")
        original=path.read_bytes()
        accepted=m.reserve_signed_prewrite(
            self.job,self.enrollment,self.bootstrap,self.approval,
            self.brokerpub,self.independentpub,store=self.store,now=self.now)
        self.assertEqual(accepted["state"],"ORIGINAL313_SIGNED_PREWRITE_RESERVED")
        self.assertEqual(path.read_bytes(),original)

    def test_different_pending_first_job_blocks_same_scope(self):
        other=self.store.path("transactions","d"*32+".json")
        self.store.write_new(other,{"contract":m.PREWRITE_CONTRACT,
            "company":"all-royal-egypt","environment":"staging"})
        with self.assertRaisesRegex(m.TransactionHeld,
                                    "DIFFERENT_FIRST_JOB_ALREADY_RESERVED"):
            m.reserve_signed_prewrite(
                self.job,self.enrollment,self.bootstrap,self.approval,
                self.brokerpub,self.independentpub,store=self.store,now=self.now)

    def test_no_prewrite_journal_no_signed_receipt(self):
        path=self.store.path("transactions",self.job["job_id"]+".json")
        path.unlink()
        with self.assertRaisesRegex(m.TransactionHeld,
                                    "SIGNED_PREWRITE_INTENT_REQUIRED"):
            self.finalize()
        self.assertIsNone(self.store.read(
            self.store.path("revisions",self.scope)))

    def test_wrong_independent_approval_signature_rejected(self):
        self.approval["signature"]=base64.b64encode(os.urandom(64)).decode()
        with self.assertRaisesRegex(m.TransactionHeld,
                                    "PREWRITE_SIGNATURE_INVALID"):
            m.reserve_signed_prewrite(
                self.job,self.enrollment,self.bootstrap,self.approval,
                self.brokerpub,self.independentpub,store=self.store,now=self.now)

    def test_tampered_prewrite_journal_rejected(self):
        path=self.store.path("transactions",self.job["job_id"]+".json")
        document=self.store.read(path)
        document["signed_approval"]["anchor_nonce"]="9"*32
        path.write_bytes(m.canonical(document)+b"\n")
        os.chmod(path,0o600)
        with self.assertRaisesRegex(m.TransactionHeld,
                                    "PREWRITE_SIGNED_APPROVAL_CHANGED"):
            self.finalize()

    def test_forged_final_receipt_cannot_be_reused(self):
        self.finalize()
        path=self.store.path("receipts",self.job["job_id"]+".json")
        receipt=self.store.read(path)
        receipt["signature"]=base64.b64encode(os.urandom(64)).decode()
        path.write_bytes(m.canonical(receipt)+b"\n")
        os.chmod(path,0o600)
        with self.assertRaisesRegex(m.TransactionHeld,
                                    "PREVIOUS_RECEIPT_SIGNATURE_INVALID"):
            self.finalize()

    def test_revoke_crash_between_ledger_and_checkpoint_is_recoverable(self):
        self.finalize()
        first_cp=self.store.read(self.store.path("checkpoints",self.scope))
        old=self.ledger
        prev=hashlib.sha256(gate.body(old,gate.FIELDS,
             "MAD4B_ORIGINAL_HOST_LEDGER_V1")).hexdigest()
        self.job=self.make_job("b"*32,m.REVOKE,1)
        self.ledger=self.make_ledger(self.job,2,0,prev,"REVOKED","2"*32)
        self.wp=self.make_wp(self.ledger)
        self.reserve_revoke_test_fixture()
        original=self.store.replace_verified
        def crash_on_checkpoint(path,row,previous):
            if path.parent.name=="checkpoints":
                raise RuntimeError("SIMULATED_REVOKE_POWER_FAILURE")
            return original(path,row,previous)
        with patch.object(self.store,"replace_verified",
                          side_effect=crash_on_checkpoint):
            with self.assertRaisesRegex(RuntimeError,
                                        "SIMULATED_REVOKE_POWER_FAILURE"):
                self.finalize()
        self.assertEqual(self.store.read(
            self.store.path("revisions",self.scope))["revision"],2)
        self.assertEqual(self.store.read(
            self.store.path("checkpoints",self.scope)),first_cp)
        self.assertTrue(self.finalize()["anchor_revoked"])
        self.assertEqual(self.store.read(
            self.store.path("checkpoints",self.scope))["revision"],2)

    def test_forbid_rollback_and_cross_site(self):
        self.finalize()
        self.job=self.make_job("b"*32,m.REVOKE,1)
        self.job["company"]="wayfatours"
        self.ledger=self.make_ledger(self.job,2,0,"0"*64,"REVOKED","2"*32)
        self.wp=self.make_wp(self.ledger)
        with self.assertRaises(m.TransactionHeld):self.finalize()

if __name__=="__main__":unittest.main(verbosity=2)
