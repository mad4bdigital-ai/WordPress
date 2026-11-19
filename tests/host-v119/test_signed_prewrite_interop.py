#!/usr/bin/env python3
"""v119 pure signed prewrite interoperability: synthetic keys, temp root only."""
from __future__ import annotations
import base64,copy,hashlib,importlib.util,os,sys,tempfile,time,unittest
from pathlib import Path
from unittest.mock import patch
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey
from cryptography.hazmat.primitives.serialization import Encoding,PublicFormat
ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'ops/host-anchor-v119'))
def load(path,name):
    spec=importlib.util.spec_from_file_location(name,path)
    m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
    return m
p=load(ROOT/'ops/host-anchor-v119/prewrite_admission.py','v119_prewrite')
fixture=load(ROOT/'tests/host-v100/test_issue_documents.py','v119_issue_fixture')

class SignedPrewriteTests(unittest.TestCase):
    def setUp(self):
        self.f=fixture.SignedIssue(methodName='test_signed_issue_and_root_ledger')
        self.f.setUp()
        self.f.n=int(time.time())
        self.f.b['issued_epoch']=self.f.n
        self.f.b['expires_epoch']=self.f.n+45
        self.f.b['signature']=base64.b64encode(self.f.key.sign(
            fixture.bootmod.signed_message(self.f.b))).decode()
        self.f.snapshot['anchor_readback']['observed_epoch']=self.f.n
        self.f.j['expires_epoch']=self.f.n+260
        self.f.j['grant_id']='3'*32
        self.docs=self.f.compose()
        self.independent=Ed25519PrivateKey.generate()
        self.independent_pub=self.independent.public_key().public_bytes(
            Encoding.Raw,PublicFormat.Raw)
        self.temp=tempfile.TemporaryDirectory(prefix='mad4b-v119-prewrite-test-')
        self.v99=p.runner.pin('v99')
        self.store=self.v99.PrivateStore(Path(self.temp.name))
    def tearDown(self):
        self.temp.cleanup()
    def compose(self):
        return p.prepare_approval(self.f.j,self.docs,self.independent,
            self.independent_pub,self.f.pub,now=self.f.n)
    def recorded(self,approval):
        return {
            'contract':self.v99.PREWRITE_CONTRACT,
            'job_id':self.f.j['job_id'],
            'operation_id':self.v99.ISSUE,
            'company':self.f.j['company'],
            'environment':self.f.j['environment'],
            'site_uuid':self.f.j['site_uuid'],
            'anchor_revision_after':1,
            'anchor_nonce':approval['anchor_nonce'],
            'anchor_message_sha256':approval['anchor_message_sha256'],
            'plan_sha256':approval['plan_sha256'],
            'command_sha256':approval['command_sha256'],
            'grant_id':approval['grant_id'],
            'approval_sha256':hashlib.sha256(
                self.v99.canonical(approval)).hexdigest(),
            'signed_approval':approval,
            'reserved_epoch':self.f.n,
        }
    def receipt(self):
        return {
            'state':'ORIGINAL313_SIGNED_PREWRITE_RESERVED',
            'job_id':self.f.j['job_id'],'anchor_issued':False,
            'original313_certified':False,
        }
    def proof(self,approval,*,receipt=None):
        original_pin=p.runner.pin
        def pinned_for_hermetic_temp(name):
            return self.v99 if name=='v99' else original_pin(name)
        # The real runner always uses the installed v99 PrivateStore type.
        # Tests pin the same module instance to their isolated synthetic store.
        with patch.object(p.runner,'pin',side_effect=pinned_for_hermetic_temp):
            return p.build_original318_proof(self.f.j,self.docs,approval,
                self.receipt() if receipt is None else receipt,
                independent_pub=self.independent_pub,broker_pub=self.f.pub,
                now=self.f.n,store=self.store)
    def test_pure_approval_is_independently_signed_and_exact_php_schema(self):
        approval=self.compose()
        self.assertEqual(tuple(approval)[:-1],self.v99.PREWRITE_FIELDS)
        self.assertEqual(approval['anchor_revision_before'],0)
        self.assertEqual(approval['anchor_revision_after'],1)
        self.assertEqual(approval['anchor_nonce'],self.docs['anchor']['nonce'])
        self.assertEqual(approval['anchor_message_sha256'],
                         self.docs['anchor_message_sha256'])
        self.assertEqual(approval['job_id'],self.f.j['job_id'])
        self.independent.public_key().verify(
            base64.b64decode(approval['signature']),
            b'MAD4B_ORIGINAL313_HOST_ANCHOR_WRITE_V1\n'+
            self.v99.canonical({k:approval[k] for k in self.v99.PREWRITE_FIELDS}))
        self.assertFalse(self.docs['anchor_issued'])
    def test_no_proof_before_durable_original313_reservation(self):
        approval=self.compose()
        with self.assertRaisesRegex(p.PrewriteHeld,'DURABLE_STORE_MISMATCH'):
            self.proof(approval)
        self.store.write_new(self.store.path(
            'transactions',self.f.j['job_id']+'.json'),self.recorded(approval))
        proof=self.proof(approval)
        self.assertEqual(list(proof),['contract','job_id','operation_id',
                                     'anchor','approval'])
        self.assertEqual(proof['contract'],
                         'mad4b.original318.anchor-reconcile-signed.v1')
    def test_prewrite_reservation_receipt_cannot_be_forged(self):
        approval=self.compose()
        self.store.write_new(self.store.path(
            'transactions',self.f.j['job_id']+'.json'),self.recorded(approval))
        fake=self.receipt();fake['original313_certified']=True
        with self.assertRaisesRegex(p.PrewriteHeld,'NOT_DURABLE'):
            self.proof(approval,receipt=fake)
    def test_forged_independent_or_broker_signature_rejected(self):
        approval=self.compose()
        bad=copy.deepcopy(approval)
        bad['signature']=base64.b64encode(bytes(64)).decode()
        self.store.write_new(self.store.path(
            'transactions',self.f.j['job_id']+'.json'),self.recorded(bad))
        with self.assertRaisesRegex(p.PrewriteHeld,'DUAL_SIGNED_PROOF_INVALID'):
            self.proof(bad)
        self.store.read(self.store.path('transactions',self.f.j['job_id']+'.json'))
    def test_source_signer_or_claimed_window_changed_refused(self):
        foreign=Ed25519PrivateKey.generate()
        original=self.independent
        self.independent=foreign
        with self.assertRaisesRegex(p.PrewriteHeld,'SIGNER_IDENTITY_CHANGED'):
            self.compose()
        self.independent=original
        self.f.j['expires_epoch']=self.f.n+2
        with self.assertRaisesRegex(p.PrewriteHeld,'NOT_FRESH_ENOUGH'):
            self.compose()
    def test_broker_anchor_and_bootstrap_signatures_verified(self):
        bad=copy.deepcopy(self.docs)
        bad['anchor']['signature']=base64.b64encode(bytes(64)).decode()
        with self.assertRaises(p.PrewriteHeld):
            p.prepare_approval(self.f.j,bad,self.independent,
                self.independent_pub,self.f.pub,now=self.f.n)
        bad=copy.deepcopy(self.docs)
        bad['bootstrap']['signature']=base64.b64encode(bytes(64)).decode()
        with self.assertRaises(p.PrewriteHeld):
            p.prepare_approval(self.f.j,bad,self.independent,
                self.independent_pub,self.f.pub,now=self.f.n)
    def test_wrong_job_or_plan_cannot_get_original318_envelope(self):
        approval=self.compose()
        self.store.write_new(self.store.path(
            'transactions',self.f.j['job_id']+'.json'),self.recorded(approval))
        bad=copy.deepcopy(approval);bad['plan_sha256']='f'*64
        with self.assertRaisesRegex(p.PrewriteHeld,'PROOF_BINDING_CHANGED'):
            self.proof(bad)
        self.docs['anchor']['nonce']='f'*32
        with self.assertRaisesRegex(p.PrewriteHeld,'PROOF_BINDING_CHANGED'):
            self.proof(approval)

if __name__=='__main__':
    unittest.main(verbosity=2)
