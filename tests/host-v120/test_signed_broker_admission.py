#!/usr/bin/env python3
"""v120 signed preclaim Broker Row drives original v119/v86 admission."""
from __future__ import annotations
import base64
import copy
import importlib.util
import sys
from pathlib import Path
import time
import unittest
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey
from cryptography.hazmat.primitives.serialization import Encoding,PublicFormat
ROOT=Path(__file__).resolve().parents[2]
def load(path,name):
    spec=importlib.util.spec_from_file_location(name,path)
    m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m);return m
bridge=load(ROOT/'ops/host-anchor-v120/broker_signed_admission.py','v120_source_bridge')
broker=load(ROOT/'ops/host-anchor-v120/broker_row_witness.py','v120_source_witness')
v119=load(ROOT/'ops/host-anchor-v119/first_anchor_runner.py','v120_source_v119')
fixture=load(ROOT/'tests/host-v119/test_first_anchor_runner.py','v120_v119_fixture')

class SignedBrokerToOriginal313Admission(unittest.TestCase):
    def setUp(self):
        self.fx=fixture.FirstAnchorRootGateTests(
            methodName='test_exact_original305_306_readback_gates_without_signing')
        self.fx.setUp()
        self.now=self.fx.t
        self.key=Ed25519PrivateKey.generate()
        self.pub=self.key.public_key().public_bytes(Encoding.Raw,PublicFormat.Raw)
        self.signed=broker.row_from_preclaim(
            self.fx.preimage,self.key,self.pub,now=self.now,nonce='b'*32)
    def admit(self):
        return bridge.admit_verified_broker_row(
            self.fx.fx.index,self.fx.preimage,self.signed,
            self.fx.claimed,self.fx.claim,self.fx.fx.plan,self.fx.current,
            now=self.now,broker_pub=self.pub,original_v120=broker,
            original_v119=v119)
    def test_signed_broker_row_accepted_by_original_v86_and_v119(self):
        r=self.admit()
        self.assertEqual(r['state'],
            'SIGNED_BROKER_ROW_AND_ORIGINAL306_CLAIM_VERIFIED')
        self.assertTrue(r['original306_signed_broker_row_verified'])
        self.assertTrue(r['original305_328_and_original318_verified'])
        self.assertFalse(r['anchor_issued'])
        self.assertFalse(r['execution_authorized'])
        self.assertFalse(r['issuer_commissioned'])
    def test_unsigned_or_forged_broker_row_refused_before_v86(self):
        self.signed['signature']=base64.b64encode(bytes(64)).decode()
        with self.assertRaises(broker.BrokerWitnessHeld):
            self.admit()
    def test_wrong_broker_key_cannot_be_passed_as_approved(self):
        foreign=Ed25519PrivateKey.generate()
        self.pub=foreign.public_key().public_bytes(Encoding.Raw,PublicFormat.Raw)
        with self.assertRaises(broker.BrokerWitnessHeld):
            self.admit()
    def test_owner_or_queued_plan_changed_after_signature_refused(self):
        self.fx.preimage['originals']['queued']['command_sha256']='f'*64
        with self.assertRaises(broker.BrokerWitnessHeld):
            self.admit()
    def test_claimed_job_drifts_after_broker_row_signed_refused(self):
        self.fx.claimed['grant_id']='f'*32
        self.fx.current['job']=copy.deepcopy(self.fx.claimed)
        with self.assertRaises(v119.FirstAnchorHeld):
            self.admit()
    def test_owner_expiry_does_not_become_authority_due_to_signature(self):
        self.fx.current['request']['expires_epoch']=self.now+5
        self.fx.preimage['originals']['request']['expires_epoch']=self.now+5
        with self.assertRaises((broker.BrokerWitnessHeld,v119.FirstAnchorHeld)):
            self.admit()
    def test_independent_original313_scope_cannot_be_changed(self):
        self.fx.current['site']['anchor_readback']['revision']=1
        with self.assertRaisesRegex(v119.FirstAnchorHeld,'PRISTINE'):
            self.admit()
    def test_source_read_only_no_issuer_or_parent_wp_write(self):
        text=(ROOT/'ops/host-anchor-v120/broker_signed_admission.py').read_text()
        self.assertNotIn('original313_first_owner_enrollment_issuer.first_party_originals(',text)
        self.assertNotIn('wp option update',text)
        self.assertNotIn('anchor-reconcile-signed',text)
        self.assertNotIn('sign_existing_original_306_claim(',text)

if __name__=='__main__':unittest.main(verbosity=2)
