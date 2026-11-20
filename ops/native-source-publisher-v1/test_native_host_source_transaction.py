"""Host CAS transaction isolated fixture acceptance; NO real authority used."""
import copy
from contextlib import ExitStack
import fcntl
import hashlib
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest import mock
from types import SimpleNamespace

import native_host_source_ownership as h
import native_host_source_transaction as t
import native_host_source_readback as rb

class IsolatedHostTransactionTests(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory(prefix='mad4b-host-tx-test-')
        self.addCleanup(self.tmp.cleanup)
        self.root=Path(self.tmp.name)
        self.repo=self.root/'repo'; self.repo.mkdir()
        (self.repo/'src').mkdir()
        (self.repo/'src'/'first.py').write_text('print("version new")\n')
        (self.repo/'src'/'second.py').write_text('print("second new")\n')
        self.targetroot=self.root/'native-operator';self.targetroot.mkdir()
        (self.targetroot/'first.py').write_text('print("first old")\n')
        (self.targetroot/'second.py').write_text('print("second old")\n')
        self.receipts=self.root/'receipts';self.receipts.mkdir(mode=0o700)

        # Only the isolated receipt fixture is impersonated as root-owned.
        # Keep original Path.stat/os.fstat, file mode/nlink, O_NOFOLLOW, CAS,
        # signing, all live first-party authority gates and SHA checks intact.
        self.receipts_owner_uid=0
        self.receipts_owner_gid=0
        self.target_owner_uid=0
        self.target_owner_gid=0
        original_stat=Path.stat
        original_fstat=os.fstat
        def owner_stat(meta,uid,gid):
            data=list(meta)
            data[4]=uid
            data[5]=gid
            return os.stat_result(data)
        def fixture_stat(path,*args,**kwargs):
            real=original_stat(path,*args,**kwargs)
            if path==self.receipts or self.receipts in path.parents:
                return owner_stat(real,self.receipts_owner_uid,self.receipts_owner_gid)
            if self.targetroot in path.parents:
                return owner_stat(real,self.target_owner_uid,self.target_owner_gid)
            return real
        def fixture_fstat(fd):
            real=original_fstat(fd)
            try:
                fd_path=Path(os.readlink('/proc/self/fd/'+str(fd)))
                if fd_path==self.receipts or self.receipts in fd_path.parents:
                    return owner_stat(real,self.receipts_owner_uid,self.receipts_owner_gid)
            except (OSError,ValueError):
                pass
            return real
        for name,callback in (('stat',fixture_stat),):
            patch=mock.patch.object(Path,name,callback)
            patch.start();self.addCleanup(patch.stop)
        patch=mock.patch.object(os,'fstat',side_effect=fixture_fstat)
        patch.start();self.addCleanup(patch.stop)
        # CI runners are unprivileged. Preserve the real atomic file writer,
        # byte/readback CAS, fsync, O_NOFOLLOW and per-file chmod; only the
        # fchown(0,0) request on a temporary file INSIDE our isolated fixture
        # root is simulated. Outside this isolated directory, use OS fchown.
        original_fchown=os.fchown
        self.fixture_fchown_count=0
        def fixture_fchown(fd,uid,gid):
            try:
                location=Path(os.readlink('/proc/self/fd/'+str(fd)))
            except (OSError,ValueError):
                return original_fchown(fd,uid,gid)
            if (os.geteuid()!=0 and self.root in location.parents
                    and uid==0 and gid==0):
                self.fixture_fchown_count+=1
                return None
            return original_fchown(fd,uid,gid)
        patch=mock.patch.object(os,'fchown',side_effect=fixture_fchown)
        patch.start();self.addCleanup(patch.stop)
        self.locks=self.root/'locks';self.locks.mkdir()
        subprocess.run(['git','-C',str(self.repo),'init','--quiet'],check=True)
        subprocess.run(['git','-C',str(self.repo),'add','.'],check=True)
        subprocess.run(['git','-C',str(self.repo),'-c','user.name=Test',
                        '-c','user.email=test@example.invalid',
                        'commit','--quiet','-m','v2'],check=True)
        self.commit=subprocess.check_output(['git','-C',str(self.repo),'rev-parse','HEAD'],text=True).strip()
        self.catalog={'contract':h.CONTRACT,'enabled':True,'git_root':str(self.repo),'owners':{
            'host-test':{'scope':'host:server.test','target_root':str(self.targetroot),
                'file_claims':[{'source':f'src/{name}','target':name}
                   for name in ('first.py','second.py')],
                'locks':['/run/lock/mad4b-original-test-1.lock',
                         '/run/lock/mad4b-original-test-2.lock']}}}
        self.req={'contract':h.REQUEST,'owner_id':'host-test','git_commit':self.commit,
            'files':[{'source':f'src/{name}','target':name,
                     'expected_before_sha256':h.digest((self.targetroot/name).read_bytes())}
                      for name in ('first.py','second.py')]}
        def root_owned(path,*,file):
            if path.is_symlink():h.deny('HOST_SOURCE_SYMLINK_DENIED')
            if not path.exists():h.deny('HOST_SOURCE_PATH_NOT_FOUND')
            if file and not path.is_file():h.deny('HOST_SOURCE_NOT_REGULAR_FILE')
            if not file and not path.is_dir():h.deny('HOST_SOURCE_NOT_DIRECTORY')
        patch=mock.patch.object(h,'HOST_OWNER_BASE',self.root)
        patch.start();self.addCleanup(patch.stop)
        patch=mock.patch.object(h,'root_owned',side_effect=root_owned)
        patch.start();self.addCleanup(patch.stop)
        self.catalog_patch=mock.patch.object(h,'checked_host_catalog',
                                              return_value=self.catalog)
        self.catalog_patch.start();self.addCleanup(self.catalog_patch.stop)
        self._origlocks=t.original_locks
        def fixtures_locks(paths,stack):
            for item in sorted(paths):
                name=self.locks/Path(item).name
                fd=os.open(name,os.O_CREAT|os.O_RDWR|os.O_NOFOLLOW,0o600)
                stack.callback(os.close,fd)
                try:fcntl.flock(fd,fcntl.LOCK_EX|fcntl.LOCK_NB)
                except BlockingIOError:t.deny('HOST_ORIGINAL_LOCK_BUSY__RETRY_LATER')
                stack.callback(fcntl.flock,fd,fcntl.LOCK_UN)
        patch=mock.patch.object(t,'original_locks',side_effect=fixtures_locks)
        patch.start();self.addCleanup(patch.stop)
        self.plan=h.create_host_plan(self.req,self.catalog)
        self.nonce='unit-cas-hostjob-unique001'
        self.original306={'job_id':'a'*32}
        self.pending306={'job_id':'a'*32}
        self.signed312={'nonce':self.nonce}
        self.signed_fixture_receipt={
           'host312_signed_nonce':self.nonce,'job_id':'a'*32,
           'plan_sha256':self.plan['plan_sha256'],
           'original_312_verified':True,'execution_authorized':False,
           'original_313_signed_receipt':False}
        # Only isolated test fixtures may emulate the separately enrolled
        # independent Host312 issuer. Live registry is never altered.
        self.test_proof_patch=mock.patch.object(
            t.publisher,'verify_original_host312_proof',
            return_value=self.signed_fixture_receipt)
        self.test_proof_patch.start()
        self.addCleanup(self.test_proof_patch.stop)
        self.master={'contract':t.publisher.HOST_MASTER_CONTRACT,
                     'plan_sha256':self.plan['plan_sha256'],'signature':'test-only'}
        self.broker={'contract':t.publisher.HOST_BROKER_CONTRACT,
                     'plan_sha256':self.plan['plan_sha256'],'signature':'test-only'}
        self.master_broker_patch=mock.patch.object(
            t.publisher,'verify_original_host_master_and_broker',return_value={
              'master_approval_verified':True,'broker_release_grant_verified':True,
              'execution_authorized':False})
        self.master_broker_patch.start()
        self.addCleanup(self.master_broker_patch.stop)
        self.policy_patch=mock.patch.object(t.publisher,'checked_policy',return_value={
            'master_approval_public_key_b64':'test-only',
            'broker_public_key_b64':'test-only'})
        self.policy_patch.start()
        self.addCleanup(self.policy_patch.stop)
        self.signature_patch=mock.patch.object(
            t.publisher,'_verify_host_signed_envelope',return_value=None)
        self.signature_patch.start()
        self.addCleanup(self.signature_patch.stop)
        self.old_first=(self.targetroot/'first.py').read_bytes()
        self.old_second=(self.targetroot/'second.py').read_bytes()

    def execute(self,admission=None,post_apply=None,nonce=None,plan=None):
        if admission is not None:
            with mock.patch.object(t.publisher,
                      'verify_original_host_master_and_broker',
                      side_effect=lambda *args,**kwargs:
                          (admission() or {
                           'master_approval_verified':True,
                           'broker_release_grant_verified':True,
                           'execution_authorized':False})):
                return self.execute(post_apply=post_apply,nonce=nonce,plan=plan)
        return t._apply_verified_job_files(plan or self.plan,self.catalog,
            self.receipts,nonce or self.nonce,
            original_306_job=self.original306,
            original_306_pending=self.pending306,
            original_312_signed_proof=self.signed312,
            signed_master_approval=self.master,
            signed_broker_grant=self.broker,
            post_apply=post_apply)

    def test_success_atomic_and_readback_pending_independent_finalizer(self):
        calls=[]
        result=self.execute(admission=lambda:calls.append('master ticket readback fixture'))
        self.assertEqual(result['state'],'APPLIED_PENDING_INDEPENDENT_313')
        self.assertFalse(result['original_313_signed_receipt'])
        self.assertFalse(result['blind_retry_allowed'])
        self.assertEqual(calls,['master ticket readback fixture'])
        self.assertEqual((self.targetroot/'first.py').read_bytes(),b'print("version new")\n')
        self.assertEqual((self.targetroot/'second.py').read_bytes(),b'print("second new")\n')
        saved=json.loads((self.receipts/(self.nonce+'.host-result.json')).read_text())
        self.assertEqual(saved,result)
        intent=json.loads(t.journal_path(self.receipts,self.plan).read_text())
        self.assertEqual(intent['files']['first.py']['sha256'],h.digest(self.old_first))
        self.assertFalse(intent['original_313_signed_receipt'])

    def test_missing_firstparty_catalog_does_not_authorize_even_with_stub(self):
        # An independent first-party Host catalog may now be installed on
        # this VPS; the missing-catalog negative must be a deterministic
        # isolated fixture, not a guess about the global runtime state.
        with mock.patch.object(h,'checked_host_catalog',side_effect=
                h.HostSourceDenied('HOST_SOURCE_PROVIDER_NOT_ENROLLED')):
            with self.assertRaisesRegex(h.HostSourceDenied,'PROVIDER_NOT_ENROLLED'):
                self.execute()
            self.assertFalse(t.journal_path(self.receipts,self.plan).exists())

    def test_missing_source_policy_denies_even_after_host312_fixture(self):
        # Never query the actual root-installed policy from an offline test:
        # availability differs between CI, an enrolled VPS and a worktree.
        self.master_broker_patch.stop()
        try:
            with mock.patch.object(t.publisher, 'checked_policy',
                    side_effect=t.publisher.Denied('SOURCE_PUBLISHER_NOT_ENROLLED')):
                with self.assertRaisesRegex(t.publisher.Denied,
                                            'SOURCE_PUBLISHER_NOT_ENROLLED'):
                    self.execute()
            self.assertFalse(t.journal_path(self.receipts,self.plan).exists())
        finally:
            self.master_broker_patch.start()

    def test_master_or_broker_not_verified_blocks_before_any_write(self):
        cases=({'master_approval_verified':False,
                 'broker_release_grant_verified':True,
                 'execution_authorized':False},
               {'master_approval_verified':True,
                'broker_release_grant_verified':False,
                'execution_authorized':False})
        for status in cases:
            with self.subTest(status=status),mock.patch.object(
                    t.publisher,'verify_original_host_master_and_broker',
                    return_value=status):
                with self.assertRaisesRegex(
                        t.HostTransactionDenied,'MASTER_AND_BROKER_NOT_VERIFIED'):
                    self.execute()
                self.assertFalse(t.journal_path(self.receipts,self.plan).exists())
                self.assertEqual(self.old_first,(self.targetroot/'first.py').read_bytes())

    def test_original_master_or_broker_signature_expiry_checked_under_lock(self):
        with mock.patch.object(t.publisher,'_verify_host_signed_envelope',
                               side_effect=t.publisher.Denied('SIGNED_EXPIRED')):
            with self.assertRaisesRegex(t.publisher.Denied,'SIGNED_EXPIRED'):
                self.execute()
            self.assertFalse(t.journal_path(self.receipts,self.plan).exists())
            self.assertEqual(self.old_first,(self.targetroot/'first.py').read_bytes())

    def test_execution_without_callable_signed_gate_is_denied(self):
        with self.assertRaisesRegex(t.HostTransactionDenied,'ORIGINAL_SIGNED_ADMISSION_REQUIRED'):
            t._apply_verified_job_files(self.plan,self.catalog,self.receipts,self.nonce,
                                        original_306_job=None,
                                        original_306_pending={},
                                        original_312_signed_proof={},
                                        signed_master_approval={},
                                        signed_broker_grant={})
        self.assertFalse(t.journal_path(self.receipts,self.plan).exists())

    def test_admission_denied_never_writes_or_journals(self):
        def block():t.deny('SIGNED_VERIFIER_DENIED')
        with self.assertRaisesRegex(t.HostTransactionDenied,'SIGNED_VERIFIER_DENIED'):
            self.execute(admission=block)
        self.assertFalse(t.journal_path(self.receipts,self.plan).exists())
        self.assertEqual(self.old_first,(self.targetroot/'first.py').read_bytes())

    def test_failure_after_write_rolls_back_batch_and_keeps_recovery_journal(self):
        def fail(_):raise RuntimeError('synthetic postapply failure')
        result=self.execute(post_apply=fail)
        self.assertEqual(result['state'],'ROLLED_BACK_RECONCILIATION_REQUIRED')
        self.assertEqual((self.targetroot/'first.py').read_bytes(),self.old_first)
        self.assertEqual((self.targetroot/'second.py').read_bytes(),self.old_second)
        self.assertTrue(t.journal_path(self.receipts,self.plan).is_file())
        with self.assertRaisesRegex(h.HostSourceDenied,'NONCE_REPLAY_DENIED|RECOVERY_REQUIRED_EXISTING_INTENT'):
            self.execute()

    def test_nonce_replay_blocks_duplicate_result(self):
        self.execute()
        with self.assertRaisesRegex(h.HostSourceDenied,'PREIMAGE_CHANGED|NONCE_REPLAY_DENIED'):
            self.execute()

    def test_prewrite_drift_denies_before_signed_admission(self):
        (self.targetroot/'first.py').write_bytes(b'operator changed live\n')
        with self.assertRaisesRegex(h.HostSourceDenied,'PREIMAGE_CHANGED'):
            self.execute()
        self.assertFalse(t.journal_path(self.receipts,self.plan).exists())

    def test_derived_changed_lock_paths_are_not_trusted(self):
        fake=copy.deepcopy(self.plan)
        fake['original_lock_paths']=['/tmp/unsafe.lock']
        with self.assertRaisesRegex(h.HostSourceDenied,'PLAN_TAMPERED'):
            self.execute(plan=fake)

    def test_existing_intent_requires_manual_reconciliation(self):
        t.new_intent(t.journal_path(self.receipts,self.plan),b'PREPARED')
        with self.assertRaisesRegex(h.HostSourceDenied,'RECOVERY_REQUIRED_EXISTING_INTENT'):
            self.execute()

    def test_public_apply_is_disabled_until_original_312_313_admission(self):
        with self.assertRaisesRegex(t.HostTransactionDenied,'PROVIDER_NOT_ENROLLED'):
            t.first_party_execute(self.plan)

    def test_incorrect_nonce_is_denied(self):
        with self.assertRaisesRegex(t.HostTransactionDenied,'JOB_NONCE_INVALID'):
            self.execute(nonce='short')

    def test_lock_contention_fails_closed_before_admission_or_write(self):
        held=self.locks/'mad4b-original-test-1.lock'
        fd=os.open(held,os.O_CREAT|os.O_RDWR|os.O_NOFOLLOW,0o600)
        fcntl.flock(fd,fcntl.LOCK_EX|fcntl.LOCK_NB)
        try:
            called=[]
            with self.assertRaisesRegex(t.HostTransactionDenied,'LOCK_BUSY'):
                self.execute(admission=lambda:called.append('unexpected'))
            self.assertEqual(called,['unexpected'])
            self.assertFalse(t.journal_path(self.receipts,self.plan).exists())
            self.assertEqual((self.targetroot/'first.py').read_bytes(),self.old_first)
        finally:
            fcntl.flock(fd,fcntl.LOCK_UN);os.close(fd)

    def test_cas_drift_during_original_admission_denied_without_journal(self):
        def concurrent():
            (self.targetroot/'first.py').write_text('print("foreign new edit")\n')
        with self.assertRaisesRegex(h.HostSourceDenied,'PREIMAGE_CHANGED'):
            self.execute(admission=concurrent)
        self.assertFalse(t.journal_path(self.receipts,self.plan).exists())
        self.assertEqual((self.targetroot/'first.py').read_text(),
                         'print("foreign new edit")\n')

    def test_python_syntax_denied_before_intent(self):
        (self.repo/'src'/'first.py').write_bytes(b'if (\n')
        subprocess.run(['git','-C',str(self.repo),'add','.'],check=True)
        subprocess.run(['git','-C',str(self.repo),'-c','user.name=Test',
            '-c','user.email=test@example.invalid','commit','--quiet','-m','bad'],
            check=True)
        new_commit=subprocess.check_output(['git','-C',str(self.repo),'rev-parse','HEAD'],
                                           text=True).strip()
        req=copy.deepcopy(self.req);req['git_commit']=new_commit
        plan=h.create_host_plan(req,self.catalog)
        self.signed_fixture_receipt['plan_sha256']=plan['plan_sha256']
        self.master['plan_sha256']=plan['plan_sha256']
        self.broker['plan_sha256']=plan['plan_sha256']
        with self.assertRaises(SyntaxError):
            self.execute(plan=plan)
        self.assertFalse(t.journal_path(self.receipts,plan).exists())
        self.assertEqual(self.old_first,(self.targetroot/'first.py').read_bytes())

    def test_unprivileged_fixture_never_claims_root_ownership_outside_sandbox(self):
        # The only permitted fchown simulation is a temp fd under our root.
        self.assertEqual(self.fixture_fchown_count,0)
        if os.geteuid()!=0:
            fd=os.open('/dev/null',os.O_RDONLY)
            try:
                with self.assertRaises(PermissionError):
                    os.fchown(fd,0,0)
            finally:
                os.close(fd)
        self.assertFalse(t.journal_path(self.receipts,self.plan).exists())

    def test_target_with_foreign_owner_denied_without_writing_original_files(self):
        self.target_owner_uid=1001
        result=self.execute()
        self.assertEqual(result['state'],'ROLLED_BACK_RECONCILIATION_REQUIRED')
        self.assertEqual(result['changed_files'],[])
        self.assertIn('HOST_EXPECTED_ROOT_FILE_OWNER_CHANGED',result['failure'])
        self.assertEqual(self.old_first,(self.targetroot/'first.py').read_bytes())
        self.assertEqual(self.old_second,(self.targetroot/'second.py').read_bytes())

    def test_receipts_with_foreign_owner_are_denied_before_any_signed_admission(self):
        self.receipts_owner_uid=1001
        with self.assertRaisesRegex(t.HostTransactionDenied,'HOST_RECEIPTS_NOT_ROOT_PRIVATE'):
            self.execute()
        self.assertFalse(t.journal_path(self.receipts,self.plan).exists())
        self.assertEqual(self.old_first,(self.targetroot/'first.py').read_bytes())

    def test_receipts_with_foreign_group_are_denied_before_any_signed_admission(self):
        self.receipts_owner_gid=1001
        with self.assertRaisesRegex(t.HostTransactionDenied,'HOST_RECEIPTS_NOT_ROOT_PRIVATE'):
            self.execute()
        self.assertFalse(t.journal_path(self.receipts,self.plan).exists())
        self.assertEqual(self.old_first,(self.targetroot/'first.py').read_bytes())

    def test_receipt_dir_with_world_writable_mode_denied(self):
        self.receipts.chmod(0o777)
        with self.assertRaisesRegex(t.HostTransactionDenied,'RECEIPTS_NOT_ROOT_PRIVATE'):
            self.execute()

    def test_signed_lease_revalidation_denies_inside_lock_before_any_write(self):
        with mock.patch.object(t.publisher,'verify_original_host312_proof',
                side_effect=[self.signed_fixture_receipt,
                             t.HostTransactionDenied('LEASE_EXPIRED')]):
            with self.assertRaisesRegex(t.HostTransactionDenied,'LEASE_EXPIRED'):
                self.execute()
        self.assertFalse(t.journal_path(self.receipts,self.plan).exists())
        self.assertEqual(self.old_first,(self.targetroot/'first.py').read_bytes())

    def test_absent_original_host312_enrollment_denies_even_with_master_callback(self):
        self.test_proof_patch.stop()
        try:
            # Test the missing enrollment against an isolated fake Registry.
            # An actual enrolled VPS MUST NOT supply this test's authority.
            with mock.patch.object(t.publisher, 'original_registry',
                    return_value=SimpleNamespace(manifests={})):
                with self.assertRaisesRegex(
                        t.publisher.Denied,'HOST312_UNIQUE_ORIGINAL_PROVIDER_NOT_ENROLLED'):
                    self.execute()
            self.assertFalse(t.journal_path(self.receipts,self.plan).exists())
        finally:
            self.test_proof_patch.start()

    def test_host312_signed_nonce_must_match_transaction_nonce(self):
        bad=dict(self.signed_fixture_receipt,host312_signed_nonce='f'*32)
        with mock.patch.object(t.publisher,'verify_original_host312_proof',
                               return_value=bad):
            with self.assertRaisesRegex(t.HostTransactionDenied,
                                        'HOST_ORIGINAL312_NONCE_NOT_BOUND_TO_JOB'):
                self.execute()
        self.assertFalse(t.journal_path(self.receipts,self.plan).exists())

    def test_independent_readback_requires_actual_original_job_and_intent(self):
        self.execute()
        state=rb.independent_readback(self.plan,self.catalog,self.receipts,self.nonce)
        self.assertEqual(state['state'],'HOST_READBACK_VERIFIED_PENDING_ORIGINAL_313')
        self.assertFalse(state['original_313_signed_receipt'])
        self.assertFalse(state['publication_authorized'])
        self.assertEqual(state['file_count'],2)
        self.assertEqual(state['plan_sha256'],self.plan['plan_sha256'])

    def test_independent_readback_rejects_changed_live_bytes(self):
        self.execute()
        (self.targetroot/'first.py').write_text('print("unverified change")\n')
        with self.assertRaisesRegex(rb.HostReadbackDenied,'LIVE_CONTENT_MISMATCH'):
            rb.independent_readback(self.plan,self.catalog,self.receipts,self.nonce)

    def test_independent_readback_rejects_tampered_result(self):
        self.execute()
        path=self.receipts/(self.nonce+'.host-result.json')
        result=json.loads(path.read_text())
        result['state']='APPLIED_VERIFIED_AND_SIGNED'
        path.write_text(json.dumps(result))
        with self.assertRaisesRegex(rb.HostReadbackDenied,'RESULT_NOT_SUCCESSFUL'):
            rb.independent_readback(self.plan,self.catalog,self.receipts,self.nonce)

    def test_independent_readback_rejects_changed_owner_catalog(self):
        self.execute()
        altered=copy.deepcopy(self.catalog)
        altered['owners']['host-test']['locks']=['/run/lock/changed-original-owner.lock']
        with self.assertRaisesRegex(rb.HostReadbackDenied,'ORIGINAL_OWNER_DRIFT'):
            rb.independent_readback(self.plan,altered,self.receipts,self.nonce)

    def test_independent_readback_rejects_bad_backup(self):
        self.execute()
        path=t.journal_path(self.receipts,self.plan)
        record=json.loads(path.read_text())
        record['files']['first.py']['data_b64']='ABCD'
        path.write_text(json.dumps(record))
        with self.assertRaisesRegex(rb.HostReadbackDenied,'BACKUP_DIGEST_MISMATCH'):
            rb.independent_readback(self.plan,self.catalog,self.receipts,self.nonce)

    def test_independent_readback_rejects_rolled_back_result(self):
        self.execute(post_apply=lambda _:(_ for _ in ()).throw(RuntimeError('failure')))
        with self.assertRaisesRegex(rb.HostReadbackDenied,'RESULT_NOT_SUCCESSFUL'):
            rb.independent_readback(self.plan,self.catalog,self.receipts,self.nonce)

    def test_post_apply_cannot_alter_receipt_signed_status(self):
        def check(plan):
            self.assertNotIn('original_313_signed_receipt',plan)
        result=self.execute(post_apply=check)
        self.assertFalse(result['original_313_signed_receipt'])

if __name__=='__main__':unittest.main()
