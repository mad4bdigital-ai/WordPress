#!/usr/bin/env python3
"""v120 Broker-signed original preclaim witness tests (synthetic Ed25519 only)."""
from __future__ import annotations
import base64
import copy
import importlib.util
import json
import os
from pathlib import Path
import stat
import sys
import tempfile
import time
import unittest
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey
from cryptography.hazmat.primitives.serialization import Encoding,PublicFormat
ROOT=Path(__file__).resolve().parents[2]
def load(path,name):
    spec=importlib.util.spec_from_file_location(name,path)
    m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m);return m
v120=load(ROOT/'ops/host-anchor-v120/broker_row_witness.py','_v120_broker_test')
fixture=load(ROOT/'tests/host-v95/test_original306_first_claim.py','_v120_v95_fixture')
v118=load(ROOT/'ops/host-anchor-v118/queued_original_capture.py','_v120_original_queue')

class BrokerPreclaimWitnessTests(unittest.TestCase):
    def setUp(self):
        self.now=int(time.time())
        self.fx=fixture.FirstClaimTests(methodName='test_exact_first_claim_is_non_authorizing')
        self.fx.setUp()
        self.fx.job.update(
            host_v2_bootstrap_only=True,authority_mode='bootstrap',
            expires_epoch=self.now+500)
        self.fx.request['expires_epoch']=self.now+500
        self.fx.pending['expires_epoch']=self.now+500
        self.fx.site['anchor_readback']['observed_epoch']=self.now
        self.preimage={
           'contract':v118.CONTRACT,
           'job_id':self.fx.job['job_id'],
           'company':'all-royal-egypt','environment':'staging',
           'plan_sha256':self.fx.plan['plan_sha256'],
           'captured_epoch':self.now-5,
           'originals':{
              'queued':copy.deepcopy(self.fx.job),
              'pending':[copy.deepcopy(self.fx.pending)],
              'request':copy.deepcopy(self.fx.request),
              'grant':copy.deepcopy(self.fx.grant),
              'parent':copy.deepcopy(self.fx.parent),
              'transport':copy.deepcopy(self.fx.transport),
              'site':copy.deepcopy(self.fx.site),
           },
           'issuer_execution_authorized':False,'anchor_issued':False
        }
        self.key=Ed25519PrivateKey.generate()
        self.pub=self.key.public_key().public_bytes(Encoding.Raw,PublicFormat.Raw)
        self.alt=Ed25519PrivateKey.generate()
        self.temp=tempfile.TemporaryDirectory(prefix='v120-broker-test-')
        self.root=Path(self.temp.name)/'broker-row-witness'
        os.chmod(self.temp.name,0o700)
    def tearDown(self):
        self.temp.cleanup()
    def signed(self):
        return v120.row_from_preclaim(
            self.preimage,self.key,self.pub,now=self.now,nonce='a'*32)
    def verify(self,witness,now=None):
        return v120.verified_broker_row(
            witness,self.preimage,self.pub,now=self.now if now is None else now)
    def test_exact_broker_signed_snapshot_provides_independent_row_for_original_v86(self):
        witness=self.signed();row=self.verify(witness)
        self.assertEqual(tuple(row),v120.ROW_FIELDS)
        self.assertEqual(row['target_id'],'hosting-2')
        self.assertEqual(row['job_id'],self.preimage['job_id'])
        self.assertEqual(row['command_sha256'],
            self.preimage['originals']['queued']['command_sha256'])
        self.assertNotIn('private_key',witness)
        self.assertGreaterEqual(witness['expires_epoch'],self.now+45)
    def test_changed_row_command_or_company_refused(self):
        witness=self.signed()
        for field,bad in [('command_sha256','f'*64),('company','wayfatours'),
                          ('target_id','hosting-3'),('grant_id','d'*32),
                          ('transport_id','b'*32)]:
            wrong=copy.deepcopy(witness);wrong[field]=bad
            with self.subTest(field=field),self.assertRaises(v120.BrokerWitnessHeld):
                self.verify(wrong)
    def test_other_signer_and_domain_cannot_validate_original_broker_row(self):
        witness=self.signed()
        foreign=self.alt.public_key().public_bytes(
            Encoding.Raw,PublicFormat.Raw)
        with self.assertRaisesRegex(v120.BrokerWitnessHeld,'SIGNATURE_INVALID'):
            v120.verified_broker_row(witness,self.preimage,foreign,now=self.now)
        tamper=copy.deepcopy(witness)
        raw={k:tamper[k] for k in v120.FIELDS}
        tamper['signature']=base64.b64encode(
            self.key.sign(b'MAD4B_OTHER_DOMAIN\n'+v120.canonical(raw))).decode()
        with self.assertRaisesRegex(v120.BrokerWitnessHeld,'SIGNATURE_INVALID'):
            self.verify(tamper)
    def test_original_queue_not_claimed_or_expired(self):
        self.preimage['originals']['queued']['state']='BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY'
        with self.assertRaisesRegex(v120.BrokerWitnessHeld,'PRECLAIM_REQUIRED'):
            self.signed()
        self.preimage['originals']['queued']['state']='QUEUED_FOR_VPS_BROKER_CLAIM'
        self.preimage['originals']['queued']['expires_epoch']=self.now+20
        with self.assertRaisesRegex(v120.BrokerWitnessHeld,'PRECLAIM_REQUIRED'):
            self.signed()
    def test_old_capture_and_changed_preimage_detected(self):
        witness=self.signed()
        self.preimage['captured_epoch']=self.now-50
        with self.assertRaisesRegex(v120.BrokerWitnessHeld,'PRECLAIM_REQUIRED'):
            self.verify(witness)
        self.preimage['captured_epoch']=self.now-5
        self.preimage['originals']['grant']['state']='REVOKED'
        with self.assertRaisesRegex(v120.BrokerWitnessHeld,'SCOPE_OR_FRESHNESS_CHANGED'):
            self.verify(witness)
    def test_witness_expiry_cannot_sign_later(self):
        witness=self.signed()
        with self.assertRaisesRegex(v120.BrokerWitnessHeld,'PRECLAIM_REQUIRED|SCOPE_OR_FRESHNESS_CHANGED'):
            self.verify(witness,now=self.now+96)
    def test_immutable_root_0600_store_and_safe_idempotency(self):
        w=self.signed()
        self.assertEqual(v120.store_witness(w,self.root),
            'ORIGINAL_BROKER_QUEUED_WITNESS_SIGNED_AND_STORED')
        self.assertEqual(v120.store_witness(w,self.root),
            'ORIGINAL_BROKER_QUEUED_WITNESS_ALREADY_STORED')
        self.assertEqual(v120.read_witness(w['job_id'],self.root),w)
        self.assertEqual(stat.S_IMODE(self.root.stat().st_mode),0o700)
        self.assertEqual(stat.S_IMODE((self.root/(w['job_id']+'.json')).stat().st_mode),0o600)
        changed=copy.deepcopy(w);changed['signature']=base64.b64encode(bytes(64)).decode()
        with self.assertRaisesRegex(v120.BrokerWitnessHeld,'REPLAY_CHANGED'):
            v120.store_witness(changed,self.root)
    def test_tampered_file_or_public_store_refused(self):
        w=self.signed();v120.store_witness(w,self.root)
        p=self.root/(w['job_id']+'.json')
        p.chmod(0o644)
        with self.assertRaisesRegex(v120.BrokerWitnessHeld,'NOT_TRUSTED'):
            v120.read_witness(w['job_id'],self.root)
        p.chmod(0o600)
        self.root.chmod(0o755)
        with self.assertRaisesRegex(v120.BrokerWitnessHeld,'NOT_ROOT_ONLY'):
            v120.read_witness(w['job_id'],self.root)
    def test_signed_envelope_order_and_source_only_runtime(self):
        w=self.signed()
        self.assertEqual(tuple(w),v120.FIELDS+('signature',))
        self.assertFalse('broker_secret' in w)
        src=(ROOT/'ops/host-anchor-v120/broker_row_witness.py').read_text()
        self.assertNotIn('wp option update',src)
        self.assertNotIn('anchor-reconcile-signed',src)
        self.assertNotIn('original313_first_owner_enrollment_issuer.first_party_originals(',src)
        self.assertEqual(v120.main(),2)
    def test_broker_signer_requires_exact_public_key(self):
        with self.assertRaisesRegex(v120.BrokerWitnessHeld,'SIGNER_MISMATCH'):
            v120.row_from_preclaim(
                self.preimage,self.key,
                self.alt.public_key().public_bytes(Encoding.Raw,PublicFormat.Raw),
                now=self.now,nonce='c'*32)
    def test_real_preclaim_code_cannot_auto_capture_after_mutated_job(self):
        pre=copy.deepcopy(self.preimage)
        pre['originals']['pending'][0]['job_id']='f'*32
        with self.assertRaisesRegex(v120.BrokerWitnessHeld,'PENDING_BROKER_QUEUE_REQUIRED'):
            v120.row_from_preclaim(pre,self.key,self.pub,now=self.now,nonce='a'*32)

if __name__=='__main__':unittest.main(verbosity=2)
