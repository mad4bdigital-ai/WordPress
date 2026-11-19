#!/usr/bin/env python3
"""Hermetic Original306 preclaim witness: no real Owner / Broker / Root writes."""
from __future__ import annotations
import copy
import importlib.util
import os
from pathlib import Path
import stat
import sys
import tempfile
import time
import unittest
from unittest.mock import patch

ROOT=Path(__file__).resolve().parents[2]
def load(path,name):
    spec=importlib.util.spec_from_file_location(name,path)
    m=importlib.util.module_from_spec(spec)
    spec.loader.exec_module(m)
    return m
v118=load(ROOT/'ops/host-anchor-v118/queued_original_capture.py','v118_queued')
fixture=load(ROOT/'tests/host-v95/test_original306_first_claim.py','v118_original_v95_fixture')

class QueuedRootCaptureTests(unittest.TestCase):
    def setUp(self):
        self.v=fixture.FirstClaimTests(
            methodName='test_exact_first_claim_is_non_authorizing')
        self.v.setUp()
        now=int(time.time())
        self.v.job["expires_epoch"]=now+450
        self.v.job["host_anchor_issuer_commissioned"]=False
        self.v.pending["expires_epoch"]=self.v.job["expires_epoch"]
        self.v.request["expires_epoch"]=now+450
        self.original={
            "queued":self.v.job,
            "pending":[self.v.pending],
            "request":self.v.request,
            "grant":self.v.grant,
            "parent":self.v.parent,
            "transport":self.v.transport,
            "site":self.v.site,
        }
        self.temp=tempfile.TemporaryDirectory(prefix="v118-queued-test-")
        self.root=Path(self.temp.name)/"queued-evidence"
        os.chmod(self.temp.name,0o700)
    def tearDown(self):
        self.temp.cleanup()
    def proof(self):
        return v118.validate_queued(self.v.index,self.original,
            self.v.plan,self.v.job["job_id"])
    def test_genuine_pinned_original305_and_306_queued_accepted(self):
        b=self.proof()
        self.assertEqual(b["contract"],v118.CONTRACT)
        self.assertEqual(b["job_id"],self.v.job["job_id"])
        self.assertFalse(b["anchor_issued"])
        self.assertFalse(b["issuer_execution_authorized"])
        self.assertEqual(b["originals"]["queued"]["state"],
                         "QUEUED_FOR_VPS_BROKER_CLAIM")
    def test_root_snapshot_atomic_idempotent_with_verified_digest(self):
        b=self.proof()
        self.assertEqual(v118.store_preimage(b,self.root),
            "ORIGINAL306_QUEUED_PREIMAGE_CAPTURED")
        self.assertEqual(v118.read_preimage(b["job_id"],self.root),b)
        self.assertEqual(v118.store_preimage(b,self.root),
            "ORIGINAL306_QUEUED_PREIMAGE_ALREADY_STORED")
        # Retry after a process/clock tick: same original QUEUED evidence
        # can be used, even though the new observation time differs.
        retry=copy.deepcopy(b);retry["captured_epoch"]+=1
        self.assertEqual(v118.store_preimage(retry,self.root),
            "ORIGINAL306_QUEUED_PREIMAGE_ALREADY_STORED")
        self.assertEqual(stat.S_IMODE(self.root.stat().st_mode),0o700)
        self.assertEqual(stat.S_IMODE((self.root/(b["job_id"]+".json")).stat().st_mode),0o600)
        mutation=copy.deepcopy(b)
        mutation["originals"]["queued"]["command_sha256"]="f"*64
        with self.assertRaisesRegex(v118.CaptureHeld,"DUPLICATE_QUEUE_CAPTURE_CHANGED"):
            v118.store_preimage(mutation,self.root)
    def test_queued_evidence_rejects_tampered_file(self):
        b=self.proof()
        v118.store_preimage(b,self.root)
        path=self.root/(b["job_id"]+".json")
        d=path.read_text()
        path.write_text(d.replace('"anchor_issued":false','"anchor_issued":true'))
        path.chmod(0o600)
        with self.assertRaises(v118.CaptureHeld):
            v118.read_preimage(b["job_id"],self.root)
    def test_original_claimed_cannot_be_recreated_as_queued(self):
        self.original["queued"]["state"]="BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY"
        with self.assertRaises(v118.CaptureHeld):
            self.proof()
    def test_expired_queued_or_owner_request_rejected(self):
        now=int(time.time())
        self.original["queued"]["expires_epoch"]=now+1
        with self.assertRaises(v118.CaptureHeld):
            self.proof()
        self.original["queued"]["expires_epoch"]=now+450
        self.original["pending"][0]["expires_epoch"]=now+450
        self.original["request"]["expires_epoch"]=now+1
        with self.assertRaises(v118.CaptureHeld):
            self.proof()
    def test_wrong_issuer_company_plan_or_original328_rejected(self):
        changes=[
           (self.original["queued"],"company","wayfatours"),
           (self.original["queued"],"host_anchor_issuer_commissioned",True),
           (self.original["queued"],"plan_sha256","0"*64),
           (self.original["site"],"original328_active",False),
           (self.original["grant"],"central_principal_id",124),
           (self.original["request"],"grant_id","9"*32),
        ]
        for target,key,bad in changes:
            good=target[key]
            target[key]=bad
            try:
                with self.assertRaises((v118.CaptureHeld,ValueError),
                                       msg=f"{key} changed"):
                    self.proof()
            finally:
                target[key]=good
    def test_missing_pending_or_untrusted_file_perms(self):
        self.original["pending"]=[]
        with self.assertRaises(v118.CaptureHeld):self.proof()
        self.original["pending"]=[self.v.pending]
        b=self.proof()
        v118.store_preimage(b,self.root)
        p=self.root/(b["job_id"]+".json")
        p.chmod(0o644)
        with self.assertRaises(v118.CaptureHeld):
            v118.read_preimage(b["job_id"],self.root)
    def test_no_spoofed_caller_store_at_live_read(self):
        with patch.object(v118,"owner_state",return_value=self.original):
            # Plan is chosen only through original job's SHA and registry
            # original checked_json; no arbitrary caller dictionaries.
            import registry
            original_read=registry.checked_json
            def root_only_plan(path,root):
                if Path(path).name==self.v.job["plan_sha256"]+".json":
                    return self.v.plan
                return original_read(path,root)
            with patch("registry.checked_json",side_effect=root_only_plan):
                with patch.object(v118,"STORE",self.root):
                    result=v118.capture_live(self.v.job["job_id"])
        self.assertEqual(result,"ORIGINAL306_QUEUED_PREIMAGE_CAPTURED")
        self.assertEqual(v118.read_preimage(self.v.job["job_id"],self.root)["originals"],
                         self.original)
    def test_no_automatic_live_signature_or_broker_claim(self):
        source=(ROOT/'ops/host-anchor-v118/queued_original_capture.py').read_text()
        self.assertNotIn("Ed25519PrivateKey",source)
        self.assertNotIn("sign_existing_original_306_claim(",source)
        self.assertNotIn("reconcile_signed_anchor_v95(",source)
        self.assertNotIn("wp option update",source)
        self.assertNotIn("original313",v118.CONTRACT)
        self.assertEqual(self.proof()["anchor_issued"],False)

if __name__=='__main__':
    unittest.main(verbosity=2)
