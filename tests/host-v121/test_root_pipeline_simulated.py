#!/usr/bin/env python3
"""v121 no-live-I/O simulated Root issuer sequencing/failure injection."""
from __future__ import annotations
from pathlib import Path
import copy,importlib.util,tempfile,unittest
from unittest.mock import patch
ROOT=Path(__file__).resolve().parents[2]
def load(path,name):
    sp=importlib.util.spec_from_file_location(name,path)
    m=importlib.util.module_from_spec(sp);sp.loader.exec_module(m);return m
v121=load(ROOT/'ops/host-anchor-v121/first_anchor_coordinator.py',
          'v121_simulated')
fixture=load(ROOT/'tests/host-v119/test_original_end_to_end_synthetic.py',
             'v121_simulated_originals')

class FakeV99:
 def __init__(self,store,steps,original):
    self.store=store;self.steps=steps;self.original=original
    self.ENROLLMENT_VERIFIER=original.ENROLLMENT_VERIFIER
    self.ENROLLMENT_SHA256=original.ENROLLMENT_SHA256
 def load_source(self,*args):
    return self.original.load_source(*args)
 def PrivateStore(self):
    return self.store
 def reserve_signed_prewrite(self,job,enrollment,bootstrap,approval,broker,independent):
    self.steps.append('original313_prewrite_persist')
    # Simulates original v99 transaction before a possible crash.
    transaction=self.store.path("transactions",job["job_id"]+".json")
    if self.store.read(transaction) is None:
      self.store.write_new(transaction,{"job_id":job["job_id"],"state":"PREWRITE"})
    return {'state':'ORIGINAL313_SIGNED_PREWRITE_RESERVED','job_id':job['job_id']}

class FirstAnchorIssuePipeline(unittest.TestCase):
 def setUp(self):
    self.f=fixture.OriginalVersionsInteroperability(
        methodName='test_real_original_v99_prewrite_then_original318_proof_then_receipt')
    self.f.setUp()
    self.temp=tempfile.TemporaryDirectory(prefix='v121-issue-pipeline-')
    self.root=Path(self.temp.name);self.root.chmod(0o700)
    self.storage=self.f.store
    self.steps=[]
    self.job=copy.deepcopy(self.f.issue.j)
    self.job.pop('ssh_principal',None)
    self.jid=self.job['job_id']
    self.ctx={
      'current':{
         'job':self.job,'claim':{'job_id':self.jid},
         'request':{},'grant':{},'parent':{},'transport':{},'site':{}
      },
      'historic':{'originals':{
        'queued':copy.deepcopy(self.job),
        'pending':[{'job_id':self.jid}]
      }},
      'broker_row':{'job_id':self.jid},
      'plan':self.f.issue.p,
      'index':type('FakeIndex',(),{
           'independent_verifier_public_key':self.f.indpub,
           'host_anchor_312_public_key':self.f.brokerpub})(),
    }
    self.fake99=FakeV99(self.storage,self.steps,self.f.v99)
    # Begin the simulation before Original313 Owner Enrollment.
    self.storage.path("enrollments","all-royal-egypt--staging.json").unlink()
    scope='all-royal-egypt--staging.json'
    self.original_enrollment=self.f.enrollment
    class Fake105:
      def __init__(inner,steps,store,enrollment,scope):
        inner.steps=steps;inner.store=store;inner.enrollment=enrollment;inner.scope=scope
      def first_party_originals(inner,*args):
        inner.steps.append('original313_enrollment_checked')
        inner.store.write_new(
            inner.store.path('enrollments',inner.scope),inner.enrollment)
    class Fake100:
      def __init__(inner,steps,docs):
        inner.steps=steps;inner.docs=docs
      def from_original_claim(inner,*args):
        inner.steps.append('original312_signed_docs')
        return inner.docs
    class FakePre:
      def __init__(inner,steps,approval):
        inner.steps=steps;inner.approval=approval
      def prepare_approval(inner,*args,**kwargs):
        inner.steps.append('original313_signed_approval')
        return inner.approval
      def build_original318_proof(inner,*args,**kwargs):
        inner.steps.append('original313_signed_prewrite_verified')
        return {'contract':'mad4b.original318.anchor-reconcile-signed.v1',
                'job_id':self.jid,'anchor':self.f.doc['anchor'],
                'approval':self.f.approval}
    self.modules={
      'v105':Fake105(self.steps,self.storage,self.f.enrollment,scope),
      'v99':self.fake99,
      'v100':Fake100(self.steps,self.f.doc),
      'prewrite':FakePre(self.steps,self.f.approval),
    }
    self.patches=[
      patch.object(v121,'root_gate',return_value=None),
      patch.object(v121,'inspect',return_value=self.ctx),
      patch.object(v121,'module',side_effect=lambda name:self.modules[name]),
      patch.object(v121,'signed_proof_key',return_value=self.f.ind),
      patch.object(v121,'PRIVATE',self.root),
      patch.object(v121,'DOCS',self.root/'first-issue-documents'),
      patch.object(v121,'INTENTS',self.root/'first-issue-send-intents'),
    ]
    for p in self.patches:p.start()
 def tearDown(self):
    for p in reversed(self.patches):p.stop()
    self.temp.cleanup();self.f.tearDown()
 def apply(self,fail_at=None):
    def saved(ctx,docs,approval):
      self.steps.append('immutable_original312_313_docs_saved')
      return v121.save_signed_documents(ctx,docs,approval)
    # save real method must be saved before patch to avoid self-call loop
    original_save=v121.save_signed_documents
    def save(ctx,docs,approval):
      self.steps.append('immutable_original312_313_docs_saved')
      return original_save(ctx,docs,approval)
    def read():
      self.steps.append('original318_pristine_readback')
      return {'anchor_readback':{
        'revision':0,'state':'MISSING_OR_INVALID',
        'write_authorized':False,'original313_certified':False,
      }}
    def send(jid,proof):
      self.steps.append('original318_single_signed_write')
      if fail_at=='ambiguous_write':
        v121.send_intent(jid,proof)
        raise v121.CoordinatorHeld('V121_ORIGINAL318_WRITE_RESULT_AMBIGUOUS_READBACK_ONLY')
      return {'state':'WORDPRESS_SIGNED_ANCHOR_POSTWRITE_VERIFIED__ROOT_313_RECEIPT_PENDING'}
    def final(jid,record):
      self.steps.append('original313_checkpoint_and_receipt')
      return {'state':'FIRST_ANCHOR_REVISION_1_SIGNED_RECEIPT_AND_HIGHWATER_VERIFIED',
              'job_id':jid,'revision':1,
              'original313_receipt_verified':True}
    with patch.object(v121,'save_signed_documents',side_effect=save), \
         patch.object(v121,'read_wp_anchor',side_effect=read), \
         patch.object(v121,'invoke_wordpress_original318',side_effect=send), \
         patch.object(v121,'independent_postwrite',side_effect=final):
      return v121.issue(self.jid)
 def test_strict_first_party_issuer_order_then_one_signed_write(self):
    result=self.apply()
    self.assertEqual(result['revision'],1)
    self.assertEqual(self.steps,[
      'original313_enrollment_checked',
      'original312_signed_docs',
      'original313_signed_approval',
      'immutable_original312_313_docs_saved',
      'original313_prewrite_persist',
      'original313_signed_prewrite_verified',
      'original318_pristine_readback',
      'original318_single_signed_write',
      'original313_checkpoint_and_receipt',
    ])
    self.assertIsNotNone(v121.get_docs_for_recovery(self.jid))
 def test_failed_original312_cannot_authorize_original318(self):
    class Broken:
      def from_original_claim(inner,*args):
        self.steps.append('original312_failed')
        raise v121.CoordinatorHeld('ORIGINAL312_UNAVAILABLE')
    self.modules['v100']=Broken()
    with self.assertRaisesRegex(v121.CoordinatorHeld,'ORIGINAL312_UNAVAILABLE'):
      self.apply()
    self.assertNotIn('original318_single_signed_write',self.steps)
    self.assertFalse((self.root/'first-issue-send-intents').exists())
 def test_ambiguous_write_does_not_finalize_or_auto_retry(self):
    with self.assertRaisesRegex(v121.CoordinatorHeld,'AMBIGUOUS_WRITE|WRITE_RESULT_AMBIGUOUS'):
      self.apply('ambiguous_write')
    self.assertNotIn('original313_checkpoint_and_receipt',self.steps)
    self.assertEqual(self.steps.count('original318_single_signed_write'),1)
    # Intent is on disk before an ambiguous write. Never send again,
    # even if WordPress is currently busy or the Root worker restarts.
    self.assertEqual(len(list((self.root/'first-issue-send-intents').glob('*.json'))),1)
    with self.assertRaisesRegex(v121.CoordinatorHeld,'PRIOR_WRITE_INTENT'):
      self.apply()
    self.assertEqual(self.steps.count('original318_single_signed_write'),1)
 def test_resume_after_signed_enrollment_and_docs_never_resigns(self):
    scope='all-royal-egypt--staging.json'
    self.storage.write_new(
        self.storage.path('enrollments',scope),self.f.enrollment)
    v121.save_signed_documents(self.ctx,self.f.doc,self.f.approval)
    class NoMoreSigner:
      def from_original_claim(self,*args):
        raise AssertionError('Original312 must not re-sign')
      def first_party_originals(self,*args):
        raise AssertionError('Original313 must not issue a second enrollment')
    self.modules['v105']=NoMoreSigner()
    self.modules['v100']=NoMoreSigner()
    result=self.apply()
    self.assertEqual(result['revision'],1)
    self.assertNotIn('original312_signed_docs',self.steps)
    self.assertNotIn('original313_signed_approval',self.steps)
    self.assertEqual(self.steps.count('original313_prewrite_persist'),1)
 def test_resume_after_durable_prewrite_skips_reservation_and_signing(self):
    scope='all-royal-egypt--staging.json'
    self.storage.write_new(
        self.storage.path('enrollments',scope),self.f.enrollment)
    v121.save_signed_documents(self.ctx,self.f.doc,self.f.approval)
    self.storage.write_new(self.storage.path('transactions',self.jid+'.json'),
       {'job_id':self.jid,'state':'PREWRITE'})
    class NoMoreSigner:
      def from_original_claim(self,*args):
        raise AssertionError('Original312 must not re-sign')
      def first_party_originals(self,*args):
        raise AssertionError('Original313 must not reissue enrollment')
    self.modules['v105']=NoMoreSigner()
    self.modules['v100']=NoMoreSigner()
    result=self.apply()
    self.assertEqual(result['revision'],1)
    self.assertNotIn('original313_prewrite_persist',self.steps)
    self.assertEqual(self.steps.count('original318_single_signed_write'),1)
 def test_resume_after_recorded_original318_intent_is_postwrite_only(self):
    v121.save_signed_documents(self.ctx,self.f.doc,self.f.approval)
    proof={'contract':'mad4b.original318.anchor-reconcile-signed.v1',
        'job_id':self.jid,'anchor':self.f.doc['anchor'],
        'approval':self.f.approval}
    v121.send_intent(self.jid,proof)
    with patch.object(v121,'independent_postwrite',
        return_value={'state':'FIRST_ANCHOR_REVISION_1_SIGNED_RECEIPT_AND_HIGHWATER_VERIFIED',
            'revision':1}) as postwrite:
      with patch.object(v121,'invoke_wordpress_original318',
        side_effect=AssertionError('Never repeat original318')):
        result=v121.resume(self.jid)
    self.assertEqual(result['revision'],1)
    self.assertEqual(postwrite.call_count,1)
 def test_enrollment_failure_prevents_writes(self):
    class Broken:
      def first_party_originals(inner,*args):
        raise v121.CoordinatorHeld('ORIGINAL313_ENROLLMENT_HELD')
    self.modules['v105']=Broken()
    with self.assertRaisesRegex(v121.CoordinatorHeld,'ORIGINAL313_ENROLLMENT_HELD'):
      self.apply()
    self.assertEqual(self.steps,[])
 def test_valid_owner_scope_does_not_bypass_empty_private_readback(self):
    self.assertIsNone(v121.get_docs_for_recovery(self.jid))
    with self.assertRaisesRegex(v121.CoordinatorHeld,
        'NO_DURABLE_ORIGINAL312_313_DOCUMENTS'):
      v121.resume(self.jid)

if __name__=='__main__':
 unittest.main(verbosity=2)
