#!/usr/bin/env python3
"""v119 cross-version first-Anchor issuance simulation, synthetic-only.

Uses original v100 signed Broker issue documents + original v99 independent
prewrite reservation and postwrite checkpoint/final receipt in a private
temporary store. No live WP, Root signer or real Owner operation is touched.
"""
from __future__ import annotations
import base64,hashlib,importlib.util,os,sys,tempfile,time,unittest
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
prewrite=load(ROOT/'ops/host-anchor-v119/prewrite_admission.py','v119_cross_source')
issue_fixture=load(ROOT/'tests/host-v100/test_issue_documents.py','v119_cross_v100')
class OriginalVersionsInteroperability(unittest.TestCase):
    def setUp(self):
        self.t=int(time.time())
        self.issue=issue_fixture.SignedIssue(methodName='test_signed_issue_and_root_ledger')
        self.issue.setUp()
        self.issue.n=self.t
        self.issue.b['issued_epoch']=self.t
        self.issue.b['expires_epoch']=self.t+45
        self.issue.b['signature']=base64.b64encode(self.issue.key.sign(
            issue_fixture.bootmod.signed_message(self.issue.b))).decode()
        self.issue.snapshot['anchor_readback']['observed_epoch']=self.t
        self.issue.j.update({
          'expires_epoch':self.t+260,
          'grant_id':'3'*32,
          'ssh_principal':self.issue.p['ssh_principal'],
          'anchor_revision':0,
          'grant_revision':0
        })
        self.doc=self.issue.compose()
        self.ind=Ed25519PrivateKey.generate()
        self.indpub=self.ind.public_key().public_bytes(Encoding.Raw,PublicFormat.Raw)
        self.brokerpub=self.issue.pub
        self.v99=prewrite.runner.pin('v99')
        self.temp=tempfile.TemporaryDirectory(prefix='v119-e2e-synthetic-')
        self.store=self.v99.PrivateStore(Path(self.temp.name))
        self.approval=prewrite.prepare_approval(
            self.issue.j,self.doc,self.ind,self.indpub,self.brokerpub,now=self.t)
        verifier=self.v99.load_source(self.v99.ENROLLMENT_VERIFIER,
            self.v99.ENROLLMENT_SHA256,'_v119_original_v89_test')
        self.verifier=verifier
        self.enrollment={
          'contract':verifier.CONTRACT,'source':verifier.SOURCE,
          'company':self.issue.j['company'],
          'environment':self.issue.j['environment'],
          'site_uuid':self.issue.j['site_uuid'],
          'origin':self.issue.j['origin'],
          'target_host_id':'hosting-2',
          'ssh_principal':self.issue.j['target_ssh_principal'],
          'enrollment_id':self.issue.p['enrollment_id'],
          'nonce':self.issue.p['enrollment_nonce'],
          'original306_job_id':self.issue.j['job_id'],
          'plan_sha256':self.issue.j['plan_sha256'],
          'command_sha256':self.issue.j['command_sha256'],
          'current_anchor_revision':0,
          'current_anchor_state':'NOT_ENROLLED',
          'nonce_unspent':True,
          'signed_site_identity_verified':True,
          'remote_host_identity_verified':True,
          'independent_issuer_verified':True,
          'owner_scope_authorized':True,
          'enrollment_revoked':False,
          'issued_epoch':self.t,
          'expires_epoch':self.t+140,
        }
        self.enrollment['signature']=base64.b64encode(self.ind.sign(
            verifier.enrollment_message(self.enrollment))).decode()
        self.store.write_new(
            self.store.path('enrollments','all-royal-egypt--staging.json'),
            self.enrollment)
    def tearDown(self):
        self.temp.cleanup()
    def reserve(self):
        return self.v99.reserve_signed_prewrite(
            self.issue.j,self.enrollment,self.doc['bootstrap'],self.approval,
            self.brokerpub,self.indpub,store=self.store,now=self.t)
    def postwrite(self):
        led=self.doc['revision_event']
        return {
           'contract':'mad4b.original318.host-anchor-current-readback.v2',
           'source':'original318_fresh_wp_readback',
           'company':self.issue.j['company'],
           'environment':self.issue.j['environment'],
           'site_uuid':self.issue.j['site_uuid'],
           'observed_epoch':self.t,
           'readback_verified':True,
           'state':led['state'],
           'revision':1,
           'anchor_nonce':led['anchor_nonce'],
           'signed_anchor_message_sha256':self.doc['anchor_message_sha256'],
           'current_anchor_signature_verified':True,
           'signed_anchor_active_in_option':True,
           'current_anchor_eligible':False,
           'original313_certified':False,
           'write_authorized':False,
           'fresh_central_highwater_verified':False,
           'prior_grants_ineligible':False,
        }
    def proof(self,receipt):
        source=prewrite.runner.pin
        def pin_same(name):
            return self.v99 if name=='v99' else source(name)
        with patch.object(prewrite.runner,'pin',side_effect=pin_same):
            return prewrite.build_original318_proof(
                self.issue.j,self.doc,self.approval,receipt,
                independent_pub=self.indpub,broker_pub=self.brokerpub,
                now=self.t,store=self.store)
    def test_real_original_v99_prewrite_then_original318_proof_then_receipt(self):
        r=self.reserve()
        self.assertEqual(r['state'],'ORIGINAL313_SIGNED_PREWRITE_RESERVED')
        proof=self.proof(r)
        self.assertEqual(proof['approval'],self.approval)
        self.assertEqual(proof['anchor'],self.doc['anchor'])
        result=self.v99.finalize(
            self.issue.j,self.doc['revision_event'],self.postwrite(),
            self.brokerpub,self.ind,store=self.store,now=self.t)
        self.assertEqual(result['state'],
            'ORIGINAL313_SIGNED_POSTWRITE_RECEIPT_PERSISTED')
        self.assertEqual(result['revision'],1)
        self.assertTrue(result['original313_certified'])
        cp=self.store.read(self.store.path(
            'checkpoints','all-royal-egypt--staging.json'))
        final=self.store.read(self.store.path(
            'receipts',self.issue.j['job_id']+'.json'))
        self.assertEqual(cp['revision'],1)
        self.assertEqual(final['job_id'],self.issue.j['job_id'])
        self.assertEqual(final['anchor_nonce'],self.doc['anchor']['nonce'])
        self.assertEqual(final['checkpoint_sha256'],hashlib.sha256(
            self.v99.load_gate().body(cp,
                self.v99.load_gate().C_FIELDS,
                'MAD4B_ORIGINAL_HOST_CHECKPOINT_V1')).hexdigest())
        # Re-reading a previously complete exact event is not a second
        # signed issuance. A fresh independent check is still required.
        again=self.v99.finalize(self.issue.j,self.doc['revision_event'],
            self.postwrite(),self.brokerpub,self.ind,
            store=self.store,now=self.t)
        self.assertEqual(again['state'],
            'ORIGINAL313_PRIOR_SIGNED_RECEIPT_REUSED')
    def test_missing_prewrite_blocks_postwrite_finalizer(self):
        with self.assertRaises(self.v99.TransactionHeld):
            self.v99.finalize(self.issue.j,self.doc['revision_event'],
                self.postwrite(),self.brokerpub,self.ind,
                store=self.store,now=self.t)
        self.assertIsNone(self.store.read(self.store.path(
            'receipts',self.issue.j['job_id']+'.json')))
        self.assertIsNone(self.store.read(self.store.path(
            'revisions','all-royal-egypt--staging.json')))
    def test_bad_postwrite_readback_retains_prewrite_and_no_final_receipt(self):
        reserve=self.reserve()
        self.assertEqual(reserve['state'],'ORIGINAL313_SIGNED_PREWRITE_RESERVED')
        stale=self.postwrite();stale['revision']=0
        with self.assertRaises(self.v99.TransactionHeld):
            self.v99.finalize(self.issue.j,self.doc['revision_event'],
                stale,self.brokerpub,self.ind,store=self.store,now=self.t)
        self.assertIsNone(self.store.read(self.store.path(
            'receipts',self.issue.j['job_id']+'.json')))
        # Crash/readback repair: we re-run Original313 finalization against
        # the *same* original signed event and good live-shaped observation.
        # No second WordPress write is issued by this method.
        result=self.v99.finalize(self.issue.j,self.doc['revision_event'],
            self.postwrite(),self.brokerpub,self.ind,store=self.store,now=self.t)
        self.assertEqual(result['state'],
            'ORIGINAL313_SIGNED_POSTWRITE_RECEIPT_PERSISTED')
    def test_repeat_prewrite_reservation_cannot_create_a_second_intent(self):
        a=self.reserve();b=self.reserve()
        self.assertEqual(a['state'],'ORIGINAL313_SIGNED_PREWRITE_RESERVED')
        self.assertEqual(b['state'],'ORIGINAL313_SIGNED_PREWRITE_RESERVED')
        pending=list((self.store.root/'transactions').glob('*.json'))
        self.assertEqual(len(pending),1)

    def test_signed_reservation_does_not_generate_anchor_on_its_own(self):
        proof=self.reserve()
        self.assertFalse(proof['anchor_issued'])
        self.assertIsNone(self.store.read(self.store.path(
            'revisions','all-royal-egypt--staging.json')))
        self.assertIsNone(self.store.read(self.store.path(
            'checkpoints','all-royal-egypt--staging.json')))
        self.assertIsNone(self.store.read(self.store.path(
            'receipts',self.issue.j['job_id']+'.json')))
if __name__=='__main__':
    unittest.main(verbosity=2)
