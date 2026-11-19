#!/usr/bin/env python3
"""Expires ACTIVE Anchor, keeps all grants forbidden, permits signed revoke."""
from __future__ import annotations
import base64
import hashlib
import importlib.util
import os
from pathlib import Path
import time
import unittest

W=Path(__file__).resolve().parents[2]
def module(path,name):
    spec=importlib.util.spec_from_file_location(name,path)
    result=importlib.util.module_from_spec(spec);spec.loader.exec_module(result)
    return result
v101=module(W/"ops/host-anchor-v101/original312_first_revoke_bundle.py",
            "_expired_v101_source")
fixture=module(W/"tests/host-v101/test_first_revoke_signed.py",
               "_expired_v101_fixture")
renewal=module(W/"ops/host-anchor-v102/original313_current_checkpoint_renewal.py",
               "_expired_v102_source")
tests102=module(W/"tests/host-v102/test_checkpoint_renewal.py",
                "_expired_v102_fixture")
class SignedExpiredRevocation(unittest.TestCase):
    def setUp(self):
        self.fx=fixture.FirstRevokeSigned()
        self.fx.setUp()
        self.fx.prior["issued"]=self.fx.now-1600
        self.fx.prior["expires"]=self.fx.now-700
        self.fx.prior["signature"]=self.fx.sign(
            self.fx.broker,v101.anchor_message({**self.fx.prior,"signature":""}))
        self.fx.wp["signed_anchor_message_sha256"]=hashlib.sha256(
            v101.anchor_message(self.fx.prior)).hexdigest()
        self.fx.wp.update(
           state="EXPIRED",signed_anchor_active_in_option=False,
           current_anchor_signature_verified=True,prior_grants_ineligible=True,
           current_anchor_eligible=False,write_authorized=False,
           original313_certified=False,readback_verified=True)

    def test_expired_signed_anchor_may_be_revoked_but_never_activated(self):
        proof=self.fx.verify()
        self.assertEqual(proof["previous_grant_revision"],0)
        self.assertFalse(proof["execution_authorized"])
        event=self.fx.build()
        self.assertEqual(event["anchor"]["state"],
                         "REVOKED_INDEPENDENT_HOST_AUTHORITY")
        self.assertEqual(event["revision_event"]["revision"],2)
        self.assertFalse(event["anchor_revoked"])
        self.assertFalse(event["execution_authorized"])

    def test_expired_grants_never_eligible(self):
        self.assertTrue(self.fx.wp["prior_grants_ineligible"])
        self.assertFalse(self.fx.wp["current_anchor_eligible"])
        self.assertFalse(self.fx.wp["signed_anchor_active_in_option"])
        self.assertFalse(self.fx.wp["write_authorized"])

    def test_modified_signed_anchor_denied(self):
        self.fx.prior["nonce"]="f"*32
        with self.assertRaises(ValueError):
            self.fx.build()

    def test_expired_readback_requires_current_independent_highwater(self):
        self.fx.cp["signature"]=base64.b64encode(os.urandom(64)).decode()
        with self.assertRaises(ValueError):self.fx.verify()

    def test_unverified_expired_wp_is_not_a_revoke_proof(self):
        for k,bad in (
            ("readback_verified",False),
            ("prior_grants_ineligible",False),
            ("signed_anchor_active_in_option",True),
            ("current_anchor_signature_verified",False),
            ("write_authorized",True),
            ("state","ACTIVE"),
            ("observed_epoch",self.fx.now-90)):
            orig=self.fx.wp[k];self.fx.wp[k]=bad
            try:
                with self.assertRaises(ValueError):self.fx.verify()
            finally:self.fx.wp[k]=orig

    def test_expired_first_anchor_cannot_change_company_or_revision(self):
        for key,bad in (("site_uuid","0"*36),("revision",2),
                        ("anchor_nonce","f"*32)):
            orig=self.fx.wp[key];self.fx.wp[key]=bad
            try:
                with self.assertRaises(ValueError):self.fx.verify()
            finally:self.fx.wp[key]=orig

class ExpiredHighwaterRenewal(unittest.TestCase):
    def setUp(self):
        self.fx=tests102.HighwaterRenewal()
        self.fx.setUp()
        self.fx.wp.update(state="EXPIRED",prior_grants_ineligible=True,
            signed_anchor_active_in_option=False,
            current_anchor_signature_verified=True,
            current_anchor_eligible=False,write_authorized=False,
            original313_certified=False)
    def tearDown(self):self.fx.tearDown()
    def test_expired_anchor_can_refresh_independent_highwater_not_anchor(self):
        result=self.fx.renew()
        self.assertEqual(result["state"],"ORIGINAL313_CURRENT_CHECKPOINT_RENEWED")
        self.assertTrue(result["new_checkpoint_signed"])
        self.assertFalse(result["anchor_issued"])
        self.assertFalse(result["grant_issued"])
        self.assertEqual(self.fx.current()["revision"],1)
        self.assertEqual(self.fx.current()["grant_revision"],0)
    def test_expired_anchor_without_ineligible_state_denied(self):
        self.fx.wp["prior_grants_ineligible"]=False
        with self.assertRaises(ValueError):self.fx.renew()

if __name__=="__main__":
    unittest.main(verbosity=2)
