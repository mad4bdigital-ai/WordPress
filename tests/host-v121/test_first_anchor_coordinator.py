#!/usr/bin/env python3
"""v121 real Original313 v99 integration + one-write crash/receipt contracts.

Synthetic original v100/v99 signers and root-only ephemeral directories only.
No live signing key, WordPress mutation or real Owner Request.
"""
from __future__ import annotations
import copy
import fcntl
import hashlib
import importlib.util
import os
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

ROOT=Path(__file__).resolve().parents[2]
def load(path,name):
    s=importlib.util.spec_from_file_location(name,path)
    m=importlib.util.module_from_spec(s);s.loader.exec_module(m);return m
v121=load(ROOT/'ops/host-anchor-v121/first_anchor_coordinator.py',
          '_v121_test_coordinator')
fixture=load(ROOT/'tests/host-v119/test_original_end_to_end_synthetic.py',
             '_v121_original_v99_fixture')

class RootCoordinatorTests(unittest.TestCase):
    def setUp(self):
        self.fx=fixture.OriginalVersionsInteroperability(
            methodName='test_real_original_v99_prewrite_then_original318_proof_then_receipt')
        self.fx.setUp()
        self.temp=tempfile.TemporaryDirectory(prefix='host-v121-runner-')
        self.private=Path(self.temp.name)
        self.private.chmod(0o700)
        self.docs=self.private/'first-issue-documents'
        self.intents=self.private/'first-issue-send-intents'
        self.patches=[
            patch.object(v121,'PRIVATE',self.private),
            patch.object(v121,'DOCS',self.docs),
            patch.object(v121,'INTENTS',self.intents),
        ]
        for pt in self.patches:pt.start()
    def tearDown(self):
        for pt in reversed(self.patches):pt.stop()
        self.temp.cleanup()
        self.fx.tearDown()
    def alias(self):
        real=copy.deepcopy(self.fx.issue.j)
        real.pop('ssh_principal',None)  # Actual WordPress Original306 format
        self.assertTrue('target_ssh_principal' in real)
        return real
    def test_original306_canonical_principal_alias_needed_by_v99(self):
        actual=self.alias()
        self.assertNotIn('ssh_principal',actual)
        result=v121.original313_finalizer_job(
            actual,self.fx.doc['revision_event'])
        self.assertEqual(result['ssh_principal'],actual['target_ssh_principal'])
        self.assertEqual(actual.get('ssh_principal'),None)
        self.fx.reserve()
        finish=self.fx.v99.finalize(
            result,self.fx.doc['revision_event'],self.fx.postwrite(),
            self.fx.brokerpub,self.fx.ind,store=self.fx.store,now=self.fx.t)
        self.assertEqual(finish['revision'],1)
        self.assertTrue(finish['original313_certified'])
    def test_wrong_or_reassigned_ssh_principal_fails_closed(self):
        job=self.alias()
        for mutation in ('wrong_principal','wrong_host','alternate_user',
                         'unexpected_old_alias','wrong_site'):
            bad=copy.deepcopy(job)
            if mutation=='wrong_principal':bad['target_ssh_principal']='u100000001'
            if mutation=='wrong_host':bad['target_host_id']='hosting-3'
            if mutation=='alternate_user':bad['target_ssh_principal']='root'
            if mutation=='unexpected_old_alias':bad['ssh_principal']='u100000002'
            if mutation=='wrong_site':bad['site_uuid']='bad'
            with self.subTest(mutation=mutation),self.assertRaisesRegex(
                v121.CoordinatorHeld,'PRINCIPAL_PROVENANCE_CHANGED'):
                v121.original313_finalizer_job(
                    bad,self.fx.doc['revision_event'])
    def test_signed_docs_stored_immutable_with_provenance(self):
        job=self.alias()
        context={'current':{'job':job}}
        saved=v121.save_signed_documents(context,self.fx.doc,self.fx.approval)
        again=v121.get_docs_for_recovery(job['job_id'])
        self.assertEqual(saved,again)
        self.assertEqual(saved['claimed_original_sha256'],
            hashlib.sha256(v121.canonical(job)).hexdigest())
        self.assertEqual((self.docs/(job['job_id']+'.json')).stat().st_mode&0o777,0o600)
        self.assertEqual(self.docs.stat().st_mode&0o777,0o700)
        different=copy.deepcopy(self.fx.approval)
        different['nonce']='do-not-overwrite'
        with self.assertRaisesRegex(v121.CoordinatorHeld,
              'REPLAY_CHANGED'):
            v121.save_signed_documents(context,self.fx.doc,different)
    def test_single_armed_original318_send_intent_disables_replay(self):
        job=self.alias()
        proof={
          'contract':'mad4b.original318.anchor-reconcile-signed.v1',
          'job_id':job['job_id'],
          'operation_id':v121.OP,
          'anchor':self.fx.doc['anchor'],
          'approval':self.fx.approval,
        }
        self.assertTrue(v121.send_intent(job['job_id'],proof))
        self.assertFalse(v121.send_intent(job['job_id'],proof))
        with patch.object(v121,'module',side_effect=AssertionError(
            'Root signer or WPCLI must not be touched twice')):
            with self.assertRaisesRegex(v121.CoordinatorHeld,
                  'NO_REPLAY'):
                v121.invoke_wordpress_original318(job['job_id'],proof)
        self.assertEqual(len(list(self.intents.glob('*.json'))),1)
    def test_changed_signed_wp_proof_cannot_reuse_armed_intent(self):
        jid=self.fx.issue.j['job_id']
        proof={
          'contract':'mad4b.original318.anchor-reconcile-signed.v1',
          'job_id':jid,
          'operation_id':v121.OP,
          'anchor':self.fx.doc['anchor'],
          'approval':self.fx.approval,
        }
        self.assertTrue(v121.send_intent(jid,proof))
        changed=copy.deepcopy(proof)
        changed['anchor']['signature']='fake'
        with self.assertRaisesRegex(v121.CoordinatorHeld,
              'REPLAY_CHANGED'):
            v121.send_intent(jid,changed)
    def test_uncommissioned_root_source_never_calls_live_signer(self):
        with patch.object(v121,'inspect',side_effect=AssertionError(
              'No Owner evidence checks allowed before commissioning')):
            with self.assertRaisesRegex(v121.CoordinatorHeld,
                   'NOT_COMMISSIONED'):
                v121.issue(self.fx.issue.j['job_id'])
    def test_missing_postwrite_proof_never_attempts_rewrite(self):
        jid=self.fx.issue.j['job_id']
        # A valid job ID without a durable record cannot be resumed.
        self.assertIsNone(v121.get_docs_for_recovery(jid))
        # Actual resume itself is blocked by missing root commissioning.
        with patch.object(v121,'root_gate',return_value=None):
            with self.assertRaisesRegex(v121.CoordinatorHeld,
                  'NO_DURABLE'):
                v121.resume(jid)
    def test_same_first_anchor_lock_serializes_worker_and_standalone(self):
        jid=self.fx.issue.j['job_id']
        lock=self.private/'.first-issue-runner.lock'
        fd=os.open(lock,os.O_CREAT|os.O_RDWR,0o600)
        fcntl.flock(fd,fcntl.LOCK_EX|fcntl.LOCK_NB)
        try:
            with patch.object(v121,'issue',side_effect=AssertionError(
                    'Never run competing Original318 issue')):
                with self.assertRaisesRegex(v121.CoordinatorHeld,
                      'ANOTHER_EXACT_FIRST_ANCHOR_ISSUER_ACTIVE'):
                    v121.execute_locked(jid,'issue')
        finally:
            fcntl.flock(fd,fcntl.LOCK_UN)
            os.close(fd)
        with patch.object(v121,'issue',
            return_value={'state':'SIGNED_ONLY_IN_TEST'}) as run:
            result=v121.execute_locked(jid,'issue')
            self.assertEqual(result['state'],'SIGNED_ONLY_IN_TEST')
            run.assert_called_once_with(jid)
        with patch.object(v121,'resume',
            return_value={'state':'FINAL_RECEIPT_REPLAY_IN_TEST'}) as post:
            result=v121.execute_locked(jid,'resume')
            self.assertEqual(result['state'],'FINAL_RECEIPT_REPLAY_IN_TEST')
            post.assert_called_once_with(jid)
    def test_lock_rejects_alternate_job_or_unapproved_action(self):
        jid=self.fx.issue.j['job_id']
        for action in ('revoke','owner-reset','plugin.install',None):
            with self.subTest(action=action),self.assertRaisesRegex(
                  v121.CoordinatorHeld,'EXACT_ISSUER_ACTION'):
                v121.execute_locked(jid,action)
        with self.assertRaisesRegex(v121.CoordinatorHeld,'EXACT_ISSUER_ACTION'):
            v121.execute_locked('not-a-job-id','issue')

    def test_no_original313_enrollment_created_by_module_import(self):
        self.assertFalse((self.docs/self.fx.issue.j['job_id']).exists())
        src=(ROOT/'ops/host-anchor-v121/first_anchor_coordinator.py').read_text()
        self.assertNotIn('wp option update',src)
        self.assertNotIn('subprocess.Popen(',src)
        self.assertNotIn('update_option(',src)

if __name__=='__main__':
    unittest.main(verbosity=2)
