"""Hermetic Git-to-tenant release tests: no live WordPress or remote mutations."""
import base64
import datetime as dt
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest
import sys

from cryptography.hazmat.primitives import serialization
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey

MODULE = Path(__file__).resolve().parents[1] / "company_code_release.py"
spec = importlib.util.spec_from_file_location("company_code_release", MODULE)
release = importlib.util.module_from_spec(spec)
sys.modules[spec.name] = release
spec.loader.exec_module(release)


def shell(*args):
    return subprocess.check_output(args, stderr=subprocess.DEVNULL).decode().strip()


class CodeReleaseTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        base = Path(self.tmp.name)
        self.repo = base / "git-source"
        self.site = base / "wordpress"
        self.receipts = base / "receipts"
        self.repo.mkdir()
        self.site.mkdir()
        self.receipts.mkdir(mode=0o700)
        # Production still requires root (UID 0); hermetic CI tests running as
        # an unprivileged runner exercise the exact SAME path/mode/CAS guard,
        # swapping only the expected owner UID in test scope.
        self.real_receipt_guard = release.assert_pinned_receipt_directory
        if os.geteuid() != 0:
            from unittest.mock import patch
            self.owner_fixture = patch.object(
                release, 'assert_pinned_receipt_directory',
                lambda policy, receipts: release._verify_receipt_directory_owner(
                    policy, receipts, required_uid=os.geteuid()))
            self.owner_fixture.start()
            self.addCleanup(self.owner_fixture.stop)
        (self.repo / "ops" / "artifacts").mkdir(parents=True)
        (self.site / "wp-content" / "mu-plugins").mkdir(parents=True)
        self.source = self.repo / "ops" / "artifacts" / "approved.php"
        self.source.write_text("<?php echo 'NEW';\n")
        self.target = self.site / "wp-content" / "mu-plugins" / "mad4b-approved.php"
        self.target.write_text("<?php echo 'OLD';\n")
        shell("git", "-C", str(self.repo), "init", "-q")
        shell("git", "-C", str(self.repo), "-c", "user.name=Test",
              "-c", "user.email=test@example.invalid", "add", ".")
        shell("git", "-C", str(self.repo), "-c", "user.name=Test",
              "-c", "user.email=test@example.invalid", "commit", "-qm", "fixture")
        self.commit = shell("git", "-C", str(self.repo), "rev-parse", "HEAD")
        self.private = Ed25519PrivateKey.generate()
        public = self.private.public_key().public_bytes(
            serialization.Encoding.Raw, serialization.PublicFormat.Raw
        )
        self.policy = {
            "contract": release.POLICY_CONTRACT, "source_git_root": str(self.repo),
            "receipt_dir": str(self.receipts),
            "broker_public_key_b64": base64.b64encode(public).decode(),
            "targets": {"demo:staging": {
                "root": str(self.site), "container": "demo-php",
                "site_uuid": "c01aa1c0-1111-4444-8888-0123456789ab",
                "origin": "https://demo.example.invalid",
                "allowed_targets": [
                    "wp-content/mu-plugins/mad4b-approved.php",
                    "wp-content/mu-plugins/mad4b-new.php",
                ],
            }},
        }
        self.request = {
            "contract": release.SPEC_CONTRACT,
            "company": "demo", "environment": "staging",
            "site_uuid": self.policy["targets"]["demo:staging"]["site_uuid"],
            "git_commit": self.commit,
            "files": [{
                "source": "ops/artifacts/approved.php",
                "target": "wp-content/mu-plugins/mad4b-approved.php",
                "expected_before_sha256": release.digest(self.target.read_bytes()),
            }],
        }
        self.plan = release.make_plan(self.request, self.policy)

    def tearDown(self):
        self.tmp.cleanup()

    def grant(self, action="apply"):
        item = {
            "contract": release.AUTH_CONTRACT,
            "issuer": "mad4b-company-broker",
            "action": action, "plan_sha256": self.plan["plan_sha256"],
            "company": "demo", "environment": "staging",
            "site_uuid": self.request["site_uuid"],
            "nonce": "fixture_nonce_0123456789",
            "expires_at": (dt.datetime.now(dt.timezone.utc)
                           + dt.timedelta(minutes=10)).isoformat(),
        }
        item["signature"] = base64.b64encode(
            self.private.sign(release.grant_message(item))
        ).decode()
        return item

    def authorized_apply(self, grant=None, *, validator=None, preflight=None):
        return release.apply_release(self.plan, self.policy, grant or self.grant(),
                                     self.receipts,
                                     runtime_check=lambda p, cfg: None,
                                     validate_code=validator or (lambda p: None),
                                     preflight_code=preflight or (lambda p: None))

    def test_original_receipt_guard_never_trusts_unprivileged_ci_uid(self):
        if os.geteuid() == 0:
            self.real_receipt_guard(self.policy, self.receipts)
        else:
            with self.assertRaisesRegex(release.Denied,
                                        'RECEIPTS_MUST_BE_ROOT_PRIVATE'):
                self.real_receipt_guard(self.policy, self.receipts)

    def signed_grant_with_nonce(self, action: str, nonce: str) -> dict:
        data = self.grant(action=action)
        data["nonce"] = nonce
        data["signature"] = base64.b64encode(
            self.private.sign(release.grant_message(data))).decode()
        return data

    def two_file_transaction(self):
        second = self.site / "wp-content/mu-plugins/mad4b-new.php"
        second.write_text("<?php echo 'ORIGINAL SECOND';\n")
        candidate = self.repo / "ops/artifacts/second.php"
        candidate.write_text("<?php echo 'NEW SECOND';\n")
        shell("git", "-C", str(self.repo), "add", ".")
        shell("git", "-C", str(self.repo), "-c", "user.name=Test",
              "-c", "user.email=test@example.invalid", "commit", "-qm", "two files")
        self.request["git_commit"] = shell("git", "-C", str(self.repo), "rev-parse", "HEAD")
        self.request["files"].append({
            "source": "ops/artifacts/second.php",
            "target": "wp-content/mu-plugins/mad4b-new.php",
            "expected_before_sha256": release.digest(second.read_bytes()),
        })
        self.plan = release.make_plan(self.request, self.policy)
        return second

    def crash_after_first_written(self):
        second = self.two_file_transaction()
        original_atomic = release.atomic_write
        def crash(path, data, mode=0o644,**kwargs):
            result = original_atomic(path, data, mode, **kwargs)
            if path == self.target:
                raise KeyboardInterrupt('simulated killed worker after first file')
            return result
        from unittest.mock import patch
        with patch.object(release, 'atomic_write', side_effect=crash):
            with self.assertRaisesRegex(KeyboardInterrupt, 'simulated killed worker'):
                self.authorized_apply()
        self.assertIn('NEW', self.target.read_text())
        self.assertIn('ORIGINAL SECOND', second.read_text())
        return second

    def test_preflight_rejects_candidate_before_first_target_write(self):
        def reject(_):
            raise release.Denied('CANDIDATE_PHP_SYNTAX_NOT_PROVEN')
        with self.assertRaisesRegex(release.Denied,
                                    'CANDIDATE_PHP_SYNTAX_NOT_PROVEN'):
            self.authorized_apply(preflight=reject)
        self.assertIn('OLD', self.target.read_text())
        self.assertFalse(list(self.receipts.glob('*.intent.json')))
        self.assertFalse([f for f in self.receipts.glob('*.json')
                          if not f.name.endswith('.backup.json')])

    def test_php_candidate_preflight_uses_exact_git_blob_and_bound_php(self):
        from unittest.mock import patch
        with patch.object(release.subprocess, 'run') as runner:
            runner.return_value = subprocess.CompletedProcess(
                args=[],returncode=0,stdout=b'No syntax errors detected in Standard input code',stderr=b'')
            with patch.object(release, 'git_bytes',
                              return_value=b"<?php echo 'NEW';\n"):
                release.preflight_candidate_php(self.plan)
            kwargs=runner.call_args.kwargs
            self.assertEqual(runner.call_args.args[0],
                ['docker','exec','-i',self.plan['container'],'php','-l'])
            self.assertEqual(kwargs['input'],b"<?php echo 'NEW';\n")
            self.assertFalse(kwargs['check'])
        self.assertIn('OLD',self.target.read_text())

    def test_php_candidate_preflight_rejects_invalid_php(self):
        from unittest.mock import patch
        with patch.object(release.subprocess, 'run') as runner:
            runner.return_value = subprocess.CompletedProcess(
                args=[],returncode=255,stdout=b'Errors parsing Standard input code',stderr=b'parse error')
            with patch.object(release, 'git_bytes',
                              return_value=b"<?php echo 'NEW';\n"):
                with self.assertRaisesRegex(release.Denied,'CANDIDATE_PHP_SYNTAX_NOT_PROVEN'):
                    release.preflight_candidate_php(self.plan)
        self.assertIn('OLD',self.target.read_text())

    def test_jit_cas_stops_external_writer_before_first_file(self):
        from unittest.mock import patch
        original = release.assert_target_preimage
        def external_edit(root, row):
            self.target.write_text('<?php echo "foreign newest";')
            return original(root, row)
        with patch.object(release,'assert_target_preimage',side_effect=external_edit):
            outcome = self.authorized_apply()
        self.assertEqual(outcome['state'],'ABORTED_NO_MUTATION',outcome)
        self.assertIn('TARGET_JIT_PREIMAGE_DRIFT',outcome['failed_reason'])
        self.assertIn('foreign newest',self.target.read_text())
        self.assertFalse(outcome['original_313_receipt'])

    def test_jit_cas_blocks_external_writer_mid_multi_file_release(self):
        second = self.two_file_transaction()
        original_atomic=release.atomic_write
        from unittest.mock import patch
        def write_with_external_edit(path,data,mode=0o644,**kwargs):
            value=original_atomic(path,data,mode,**kwargs)
            if path==self.target:
                second.write_text('<?php echo "foreign second file";')
            return value
        with patch.object(release,'atomic_write',side_effect=write_with_external_edit):
            result=self.authorized_apply()
        self.assertEqual(result['state'],'ROLLBACK_UNVERIFIED',result)
        self.assertIn('TARGET_JIT_PREIMAGE_DRIFT',result['failed_reason'])
        self.assertIn('foreign second file',second.read_text())
        self.assertIn('NEW',self.target.read_text())
        self.assertTrue((self.receipts/(self.grant()['nonce']+'.intent.json')).exists())
        self.assertFalse(result['original_313_receipt'])

    def test_interrupted_apply_has_signed_crash_recovery(self):
        second = self.crash_after_first_written()
        open_intents = release.open_apply_intents(self.plan,self.receipts)
        self.assertEqual(len(open_intents),1)
        self.assertEqual(open_intents[0]['state'],'APPLY_STARTED')
        with self.assertRaisesRegex(release.Denied,'GRANT_ALREADY_USED'):
            self.authorized_apply()
        new_apply=self.signed_grant_with_nonce('apply','fresh_same_plan_signed_nonce_2026')
        with self.assertRaisesRegex(release.Denied,'UNRESOLVED_APPLY_INTENT'):
            self.authorized_apply(grant=new_apply)
        rollback=self.signed_grant_with_nonce('rollback','recovery_rollback_nonce_2026')
        result=release.rollback_release(self.plan,self.policy,rollback,self.receipts,
                                        runtime_check=lambda p,c:None)
        self.assertEqual(result['state'],'RECOVERY_ROLLBACK_VERIFIED',result)
        self.assertEqual(result['recovered_apply_nonce'],self.grant()['nonce'])
        self.assertIn('OLD',self.target.read_text())
        self.assertIn('ORIGINAL SECOND',second.read_text())
        self.assertEqual(release.open_apply_intents(self.plan,self.receipts),[])
        self.assertFalse(result['original_313_receipt'])
        again=self.signed_grant_with_nonce('rollback','second_recovery_nonce_2026')
        with self.assertRaisesRegex(release.Denied,'NO_VERIFIED_APPLY_TO_ROLLBACK'):
            release.rollback_release(self.plan,self.policy,again,self.receipts,
                                     runtime_check=lambda p,c:None)

    def test_signed_recovery_refuses_foreign_concurrent_bytes(self):
        second=self.crash_after_first_written()
        second.write_text('<?php echo "foreign after crash";')
        rollback=self.signed_grant_with_nonce('rollback','recovery_rejects_foreign_2026')
        with self.assertRaisesRegex(release.Denied,'TARGET_CONTENT_DRIFT'):
            release.rollback_release(self.plan,self.policy,rollback,self.receipts,
                                     runtime_check=lambda p,c:None)
        self.assertIn('NEW',self.target.read_text())
        self.assertIn('foreign after crash',second.read_text())
        self.assertEqual(len(release.open_apply_intents(self.plan,self.receipts)),1)

    def test_signed_recovery_refuses_tampered_backup_evidence(self):
        self.crash_after_first_written()
        path=release.backup_path(self.receipts,self.plan)
        path.write_text(path.read_text()+'\n')
        rollback=self.signed_grant_with_nonce('rollback','tamper_backup_check_2026')
        with self.assertRaisesRegex(release.Denied,'APPLY_INTENT_BACKUP_CHANGED'):
            release.rollback_release(self.plan,self.policy,rollback,self.receipts,
                                     runtime_check=lambda p,c:None)
        self.assertIn('NEW',self.target.read_text())

    def crash_during_signed_rollback(self):
        second=self.two_file_transaction()
        result=self.authorized_apply()
        self.assertEqual(result['state'],'APPLIED_VERIFIED',result)
        from unittest.mock import patch
        atomic_original=release.atomic_write
        def crash(path,data,mode=0o644,**kwargs):
            response=atomic_original(path,data,mode,**kwargs)
            if path==second:
                raise KeyboardInterrupt('simulate SIGKILL during rollback')
            return response
        first_rollback=self.signed_grant_with_nonce('rollback',
            'first_rollback_crash_nonce_2026')
        with patch.object(release,'atomic_write',side_effect=crash):
            with self.assertRaisesRegex(KeyboardInterrupt,'simulate SIGKILL'):
                release.rollback_release(self.plan,self.policy,first_rollback,
                    self.receipts,runtime_check=lambda p,c:None)
        self.assertIn('NEW',self.target.read_text())
        self.assertIn('ORIGINAL SECOND',second.read_text())
        return second,first_rollback

    def test_signed_rollback_interruption_is_recoverable_without_old_grant_replay(self):
        second,old_grant=self.crash_during_signed_rollback()
        open_intents=release.open_rollback_intents(self.plan,self.receipts)
        self.assertEqual(len(open_intents),1)
        self.assertEqual(open_intents[0]['state'],'ROLLBACK_STARTED')
        with self.assertRaisesRegex(release.Denied,'GRANT_ALREADY_USED'):
            release.rollback_release(self.plan,self.policy,old_grant,
                self.receipts,runtime_check=lambda p,c:None)
        new_grant=self.signed_grant_with_nonce('rollback',
            'second_signed_rollback_nonce_2026')
        result=release.rollback_release(self.plan,self.policy,new_grant,
            self.receipts,runtime_check=lambda p,c:None)
        self.assertEqual(result['state'],'ROLLBACK_VERIFIED',result)
        self.assertIn(old_grant['nonce'],result['recovered_rollback_nonces'])
        self.assertEqual(release.open_rollback_intents(self.plan,self.receipts),[])
        self.assertIn('OLD',self.target.read_text())
        self.assertIn('ORIGINAL SECOND',second.read_text())
        self.assertFalse(result['original_313_receipt'])

    def test_rollback_crash_recovery_rejects_external_concurrent_code(self):
        second,_=self.crash_during_signed_rollback()
        self.target.write_text('<?php echo "external after crash";')
        grant=self.signed_grant_with_nonce('rollback',
            'rollback_crash_foreign_code_2026')
        with self.assertRaisesRegex(release.Denied,'TARGET_CONTENT_DRIFT'):
            release.rollback_release(self.plan,self.policy,grant,self.receipts,
                runtime_check=lambda p,c:None)
        self.assertIn('external after crash',self.target.read_text())
        self.assertEqual(len(release.open_rollback_intents(self.plan,self.receipts)),1)

    def test_failed_rollback_unverified_is_recoverable_after_external_reconcile(self):
        def fail_with_external_edit(_):
            self.target.write_text('<?php echo "external conflict";')
            raise RuntimeError('validator failure after external edit')
        outcome=self.authorized_apply(validator=fail_with_external_edit)
        self.assertEqual(outcome['state'],'ROLLBACK_UNVERIFIED',outcome)
        self.assertEqual(len(release.open_apply_intents(self.plan,self.receipts)),1)
        # A human reconciles the foreign edit back to exactly the signed
        # postimage. No success is inferred until an independent signed rollback.
        self.target.write_bytes(self.source.read_bytes())
        signed=self.signed_grant_with_nonce('rollback',
            'rollback_unverified_recovery_2026')
        result=release.rollback_release(self.plan,self.policy,signed,self.receipts,
            runtime_check=lambda p,c:None)
        self.assertEqual(result['state'],'RECOVERY_ROLLBACK_VERIFIED',result)
        self.assertIn('OLD',self.target.read_text())
        self.assertFalse(release.open_apply_intents(self.plan,self.receipts))

    def test_durable_atomic_write_syncs_file_and_parent_directory(self):
        from unittest.mock import patch
        target=Path(self.tmp.name)/'durable-test-receipt.json'
        fsyncs=[]
        actual=release.os.fsync
        def tracked(fd):
            fsyncs.append(fd)
            return actual(fd)
        with patch.object(release.os,'fsync',side_effect=tracked):
            release.atomic_write(target,b'{"durable":true}\n',0o600)
        self.assertEqual(target.read_bytes(),b'{"durable":true}\n')
        self.assertGreaterEqual(len(fsyncs),2)

    def test_signed_rollback_uses_jit_cas_and_preserves_foreign_writer(self):
        second,_=self.crash_during_signed_rollback()
        original=release.atomic_write
        from unittest.mock import patch
        def concurrent(path,data,mode=0o644,**kwargs):
            result=original(path,data,mode,**kwargs)
            if path==second:
                self.target.write_text('<?php echo "foreign while restoring";')
            return result
        grant=self.signed_grant_with_nonce('rollback',
            'jit_rollback_guard_nonce_2026')
        with patch.object(release,'atomic_write',side_effect=concurrent):
            with self.assertRaisesRegex(release.Denied,'ROLLBACK_JIT_CONCURRENT_DRIFT'):
                release.rollback_release(self.plan,self.policy,grant,self.receipts,
                    runtime_check=lambda p,c:None)
        self.assertIn('foreign while restoring',self.target.read_text())
        self.assertIn('ORIGINAL SECOND',second.read_text())
        self.assertGreaterEqual(len(release.open_rollback_intents(self.plan,self.receipts)),2)

    def test_apply_intent_cannot_cross_company_identity(self):
        self.crash_after_first_written()
        file=release.apply_intent_path(self.receipts,self.grant()['nonce'])
        item=json.loads(file.read_text())
        item['company']='different-site'
        file.write_text(json.dumps(item))
        with self.assertRaisesRegex(release.Denied,'APPLY_INTENT_IDENTITY_DRIFT'):
            release.open_apply_intents(self.plan,self.receipts)
        self.assertIn('NEW',self.target.read_text())

    def test_apply_and_signed_rollback_preserve_tenant_file_owner_and_mode(self):
        import os, stat
        os.chmod(self.target,0o664)
        self.plan=release.make_plan(self.request,self.policy)
        before=self.target.stat()
        outcome=self.authorized_apply()
        self.assertEqual(outcome['state'],'APPLIED_VERIFIED',outcome)
        after=self.target.stat()
        self.assertEqual((after.st_uid,after.st_gid,stat.S_IMODE(after.st_mode)),
                         (before.st_uid,before.st_gid,0o664))
        signed=self.signed_grant_with_nonce('rollback',
            'tenant_metadata_rollback_2026')
        result=release.rollback_release(self.plan,self.policy,signed,self.receipts,
                                       runtime_check=lambda p,c:None)
        self.assertEqual(result['state'],'ROLLBACK_VERIFIED',result)
        restored=self.target.stat()
        self.assertEqual((restored.st_uid,restored.st_gid,
                          stat.S_IMODE(restored.st_mode)),
                         (before.st_uid,before.st_gid,0o664))
        self.assertIn('OLD',self.target.read_text())

    def test_signed_plan_blocks_tenant_chmod_without_content_change(self):
        import os
        os.chmod(self.target,0o664)
        with self.assertRaisesRegex(release.Denied,'TARGET_METADATA_DRIFT'):
            self.authorized_apply()
        self.assertIn('OLD',self.target.read_text())
        self.assertFalse(list(self.receipts.glob('*.intent.json')))

    def test_jit_cas_blocks_metadata_edit_after_backup(self):
        import os
        from unittest.mock import patch
        actual=release.assert_target_preimage
        def changed(root,row):
            os.chmod(self.target,0o664)
            return actual(root,row)
        with patch.object(release,'assert_target_preimage',side_effect=changed):
            result=self.authorized_apply()
        self.assertEqual(result['state'],'ABORTED_NO_MUTATION',result)
        self.assertIn('TARGET_JIT_METADATA_DRIFT',result['failed_reason'])
        self.assertIn('OLD',self.target.read_text())

    def test_rollback_of_new_file_fsyncs_deletion_directory(self):
        from unittest.mock import patch
        new_file=self.site/'wp-content/mu-plugins/mad4b-new.php'
        request=json.loads(json.dumps(self.request))
        request['files'][0]['target']='wp-content/mu-plugins/mad4b-new.php'
        request['files'][0]['expected_before_sha256']=None
        self.plan=release.make_plan(request,self.policy)
        result=self.authorized_apply()
        self.assertEqual(result['state'],'APPLIED_VERIFIED',result)
        self.assertTrue(new_file.exists())
        original=release.fsync_parent_directory
        seen=[]
        def observed(path):
            seen.append(path)
            return original(path)
        signed=self.signed_grant_with_nonce('rollback','signed_new_file_rollback_2026')
        with patch.object(release,'fsync_parent_directory',side_effect=observed):
            rolled=release.rollback_release(self.plan,self.policy,signed,self.receipts,
                                           runtime_check=lambda p,c:None)
        self.assertEqual(rolled['state'],'ROLLBACK_VERIFIED',rolled)
        self.assertFalse(new_file.exists())
        self.assertIn(new_file,seen)

    def test_other_signed_plan_cannot_overwrite_interrupted_file(self):
        self.crash_after_first_written()
        other_request=json.loads(json.dumps(self.request))
        other_request['files'][0]['expected_before_sha256']=release.digest(
            self.target.read_bytes())
        next_plan=release.make_plan(other_request,self.policy)
        self.assertNotEqual(next_plan['plan_sha256'],self.plan['plan_sha256'])
        self.plan=next_plan
        signed=self.signed_grant_with_nonce('apply','different_plan_nonce_2026')
        with self.assertRaisesRegex(release.Denied,
                                    'OVERLAPPING_UNRESOLVED_RELEASE_TRANSACTION'):
            self.authorized_apply(grant=signed)
        self.assertIn('NEW',self.target.read_text())
        self.assertFalse((self.receipts/(signed['nonce']+'.intent.json')).exists())

    def test_unrelated_signed_plan_can_continue_without_touching_paused_release(self):
        self.crash_after_first_written()
        rel='wp-content/mu-plugins/mad4b-independent.php'
        source=self.repo/'ops/artifacts/independent.php'
        source.write_text('<?php echo "independent";\n')
        self.policy['targets']['demo:staging']['allowed_targets'].append(rel)
        shell('git','-C',str(self.repo),'add','.')
        shell('git','-C',str(self.repo),'-c','user.name=Test',
              '-c','user.email=test@example.invalid','commit','-qm','independent')
        other_request=json.loads(json.dumps(self.request))
        other_request['git_commit']=shell('git','-C',str(self.repo),'rev-parse','HEAD')
        other_request['files']=[{'source':'ops/artifacts/independent.php',
                                 'target':rel,'expected_before_sha256':None}]
        self.plan=release.make_plan(other_request,self.policy)
        signed=self.signed_grant_with_nonce('apply','unrelated_file_nonce_2026')
        outcome=self.authorized_apply(grant=signed)
        self.assertEqual(outcome['state'],'APPLIED_VERIFIED',outcome)
        self.assertEqual((self.site/rel).read_text(),source.read_text())
        self.assertIn('NEW',self.target.read_text())

    def test_other_plan_blocks_while_rollback_is_interrupted(self):
        self.crash_during_signed_rollback()
        changed=json.loads(json.dumps(self.request))
        changed['files'][0]['expected_before_sha256']=release.digest(self.target.read_bytes())
        self.plan=release.make_plan(changed,self.policy)
        signed=self.signed_grant_with_nonce('apply','other_during_rollback_2026')
        with self.assertRaisesRegex(release.Denied,
                                    'OVERLAPPING_UNRESOLVED_RELEASE_TRANSACTION'):
            self.authorized_apply(grant=signed)

    def test_site_uuid_blocks_overlapping_plan_under_different_environment_label(self):
        self.crash_after_first_written()
        self.policy['targets']['demo:qa'] = dict(
            self.policy['targets']['demo:staging'])
        next_req=json.loads(json.dumps(self.request))
        next_req['environment']='qa'
        next_req['files'][0]['expected_before_sha256']=release.digest(
            self.target.read_bytes())
        other=release.make_plan(next_req,self.policy)
        self.assertNotEqual(other['environment'],self.plan['environment'])
        self.assertEqual(other['site_uuid'],self.plan['site_uuid'])
        self.plan=other
        grant=self.signed_grant_with_nonce('apply','same_site_qa_alias_nonce_2026')
        grant['environment']='qa'
        grant['signature']=base64.b64encode(
            self.private.sign(release.grant_message(grant))).decode()
        with self.assertRaisesRegex(release.Denied,
                                    'OVERLAPPING_UNRESOLVED_RELEASE_TRANSACTION'):
            self.authorized_apply(grant=grant)
        self.assertIn('NEW',self.target.read_text())

    def test_closed_previous_plan_does_not_block_follow_up(self):
        original_plan=self.plan
        outcome=self.authorized_apply()
        self.assertEqual(outcome['state'],'APPLIED_VERIFIED',outcome)
        request=json.loads(json.dumps(self.request))
        request['files'][0]['expected_before_sha256']=release.digest(self.target.read_bytes())
        self.plan=release.make_plan(request,self.policy)
        self.assertNotEqual(self.plan['plan_sha256'],original_plan['plan_sha256'])
        self.assertFalse(release.overlapping_unresolved_transactions(
            self.plan,self.receipts))
        signed=self.signed_grant_with_nonce('apply','fresh_after_success_2026')
        result=self.authorized_apply(grant=signed)
        self.assertEqual(result['state'],'APPLIED_VERIFIED',result)

    def test_postrename_fsync_failure_must_restore_real_changes(self):
        # The atomic rename has already updated the target when fsync raises.
        # A failed atomic_write() return is NOT evidence of zero mutations.
        from unittest.mock import patch
        original=release.fsync_parent_directory
        triggered=[]
        def fail_after_rename(path):
            if path==self.target and not triggered:
                triggered.append(True)
                raise OSError('disk sync unavailable after atomic rename')
            return original(path)
        with patch.object(release,'fsync_parent_directory',side_effect=fail_after_rename):
            result=self.authorized_apply()
        self.assertEqual(result['state'],'ROLLED_BACK',result)
        self.assertIn('disk sync unavailable',result['failed_reason'])
        self.assertIn('OLD',self.target.read_text())
        self.assertTrue(triggered)

    def test_postrename_fsync_and_rollback_failure_reports_incident(self):
        from unittest.mock import patch
        original=release.fsync_parent_directory
        def fail_after_rename(path):
            if path==self.target:
                raise OSError('storage sync interrupted')
            return original(path)
        with patch.object(release,'fsync_parent_directory',side_effect=fail_after_rename):
            result=self.authorized_apply()
        self.assertEqual(result['state'],'ROLLBACK_UNVERIFIED',result)
        # Bytes can look restored after atomic rename, but directory fsync
        # failed: do not report a verified durable rollback.
        self.assertIn('OLD',self.target.read_text())
        self.assertIn('rollback=',result['failed_reason'])
        self.assertEqual(len(release.open_apply_intents(self.plan,self.receipts)),1)

    def test_cross_plan_recovery_closed_after_signed_restore(self):
        self.crash_after_first_written()
        old_plan=self.plan
        recovery=self.signed_grant_with_nonce('rollback','signed_cross_plan_recovery_2026')
        result=release.rollback_release(self.plan,self.policy,recovery,self.receipts,
                                        runtime_check=lambda p,c:None)
        self.assertEqual(result['state'],'RECOVERY_ROLLBACK_VERIFIED',result)
        request=json.loads(json.dumps(self.request))
        request['files']=request['files'][:1]
        next_plan=release.make_plan(request,self.policy)
        self.assertNotEqual(next_plan['plan_sha256'],old_plan['plan_sha256'])
        self.assertEqual(release.overlapping_unresolved_transactions(
            next_plan,self.receipts),[])
        self.plan=next_plan
        signed=self.signed_grant_with_nonce('apply','apply_after_recovered_plan_2026')
        outcome=self.authorized_apply(grant=signed)
        self.assertEqual(outcome['state'],'APPLIED_VERIFIED',outcome)

    def test_plan_is_non_mutating_and_from_git_commit(self):
        self.assertEqual(self.target.read_text(), "<?php echo 'OLD';\n")
        self.source.write_text("<?php echo 'uncommitted';\n")
        self.assertEqual(self.plan["files"][0]["source_sha256"],
                         release.digest(b"<?php echo 'NEW';\n"))
        self.assertEqual(self.target.read_text(), "<?php echo 'OLD';\n")

    def test_exact_scope_authorized_apply_and_readback(self):
        result = self.authorized_apply()
        self.assertEqual(result["state"], "APPLIED_VERIFIED")
        self.assertEqual(self.target.read_text(), "<?php echo 'NEW';\n")
        self.assertEqual(release.check_sha_readback(self.plan, self.policy)["state"],
                         "READBACK_VERIFIED")
        self.assertTrue(list(self.receipts.glob("*.json")))
        self.assertTrue(result["broker_grant_signature_verified"])
        self.assertFalse(result["broker_receipt_verified"])
        self.assertFalse(result["original_313_receipt"])
        with self.assertRaisesRegex(release.Denied, "GRANT_ALREADY_USED"):
            self.authorized_apply()

    def test_invalid_broker_signature_rejected(self):
        grant = self.grant()
        grant["signature"] = base64.b64encode(b"invalid").decode()
        with self.assertRaisesRegex(release.Denied, "BROKER_SIGNATURE"):
            self.authorized_apply(grant)
        self.assertIn("OLD", self.target.read_text())

    def test_nonce_replay_blocked_in_alternate_private_receipt_directory(self):
        self.authorized_apply()
        alternate = self.repo / "fresh-receipts"
        alternate.mkdir(mode=0o700)
        with self.assertRaisesRegex(release.Denied,
                                    "RECEIPT_DIRECTORY_NOT_PINNED"):
            release.apply_release(
                self.plan, self.policy, self.grant(), alternate,
                runtime_check=lambda p, cfg: None,
                validate_code=lambda p: None
            )
        self.assertEqual(self.target.read_text(), "<?php echo 'NEW';\n")

    def test_missing_receipt_root_policy_denied(self):
        self.policy.pop("receipt_dir")
        with self.assertRaisesRegex(release.Denied,
                                    "RECEIPT_ROOT_NOT_CONFIGURED"):
            self.authorized_apply()
        self.assertEqual(self.target.read_text(), "<?php echo 'OLD';\n")

    def test_broker_action_scope_rejected(self):
        grant = self.grant()
        grant["company"] = "other"
        with self.assertRaisesRegex(release.Denied, "BROKER_GRANT_NOT_BOUND"):
            self.authorized_apply(grant)

    def test_preimage_drift_blocks_write(self):
        self.target.write_text("<?php echo 'someone else changed this';")
        with self.assertRaisesRegex(release.Denied, "TARGET_CONTENT_DRIFT"):
            self.authorized_apply()
        self.assertIn("someone else", self.target.read_text())

    def test_validator_failure_rolls_back_and_records_receipt(self):
        def fail(_):
            raise RuntimeError("syntax failure fixture")
        result = self.authorized_apply(validator=fail)
        self.assertEqual(result["state"], "ROLLED_BACK")
        self.assertEqual(self.target.read_text(), "<?php echo 'OLD';\n")
        self.assertFalse(result["original_313_receipt"])

    def test_unenrolled_target_denied(self):
        request = json.loads(json.dumps(self.request))
        request["files"][0]["target"] = "wp-content/plugins/other/unsafe.php"
        with self.assertRaisesRegex(release.Denied, "TARGET_FILE_NOT_ALLOWED"):
            release.make_plan(request, self.policy)

    def test_symlink_denied(self):
        self.target.unlink()
        self.target.symlink_to(self.source)
        with self.assertRaisesRegex(release.Denied, "SYMLINK_PATH_DENIED"):
            release.make_plan(self.request, self.policy)

    def test_target_uuid_mismatch_denied(self):
        request = json.loads(json.dumps(self.request))
        request["site_uuid"] = "00000000-0000-0000-0000-000000000000"
        with self.assertRaisesRegex(release.Denied, "SITE_UUID_DRIFT"):
            release.make_plan(request, self.policy)

    def test_no_new_file_without_explicit_allowlist(self):
        self.request["files"][0]["target"] = "wp-content/mu-plugins/mad4b-new.php"
        self.request["files"][0]["expected_before_sha256"] = None
        plan = release.make_plan(self.request, self.policy)
        self.assertEqual(plan["changed_count"], 1)
        self.assertFalse((self.site / "wp-content/mu-plugins/mad4b-new.php").exists())

    def capability_portability_fixture(self):
        """The example class moved to a plugin; a MU path isn't a permanent owner."""
        plugin = self.site / "wp-content/plugins/mad4b-experience"
        relative = "src/presentation/modules/374-mad4b-owner-bridge.php"
        replacement = plugin / relative
        replacement.parent.mkdir(parents=True)
        replacement.write_text("<?php echo 'PLUGIN';\n")
        manifest = plugin / "mad4b-portability.json"
        manifest.write_text(json.dumps({
            "contract": "mad4b.plugin-portability-profile.v1",
            "plugin": "mad4b-experience",
            "company_specific_legacy_mu_replacements": [{
                "legacy_file": "mad4b-approved.php",
                "replacement": relative,
                "reconcile_contract": "mad4b.option-owner-v1",
            }],
        }))
        target = "wp-content/plugins/mad4b-experience/" + relative
        self.policy["targets"]["demo:staging"]["allowed_targets"].append(target)
        return manifest, replacement, target

    def test_legacy_mu_file_is_not_permanently_protected(self):
        manifest, new_file, target = self.capability_portability_fixture()
        # Legitimate legacy maintenance may continue while the canonical
        # module is parked. The record does not infer the active hook owner.
        plan = release.make_plan(self.request, self.policy)
        self.assertEqual(len(plan["owner_reconciliation"]["relations"]), 1)
        entry = plan["owner_reconciliation"]["relations"][0]
        self.assertEqual(entry["selected_role"], "legacy")
        self.assertTrue(entry["replacement_present"])
        self.assertFalse(entry["runtime_owner_inferred"])
        self.assertFalse(entry["retirement_authorized"])
        release.confirm_plan(plan, self.policy, expect_preimage=True)

    def test_replacement_with_existing_legacy_owner_requires_proof(self):
        _, new_file, target = self.capability_portability_fixture()
        request = json.loads(json.dumps(self.request))
        request["files"][0]["target"] = target
        request["files"][0]["expected_before_sha256"] = release.digest(
            new_file.read_bytes())
        with self.assertRaisesRegex(release.Denied,
                                    "OWNER_TRANSITION_RUNTIME_PROOF_REQUIRED"):
            release.make_plan(request, self.policy)
        self.assertIn("OLD", self.target.read_text())

    def test_retired_mu_file_does_not_block_canonical_plugin_update(self):
        _, new_file, target = self.capability_portability_fixture()
        self.target.unlink()  # Retirement is represented, not performed by planner.
        request = json.loads(json.dumps(self.request))
        request["files"][0]["target"] = target
        request["files"][0]["expected_before_sha256"] = release.digest(
            new_file.read_bytes())
        plan = release.make_plan(request, self.policy)
        relation = plan["owner_reconciliation"]["relations"][0]
        self.assertFalse(relation["legacy_present"])
        self.assertTrue(relation["replacement_present"])
        self.assertEqual(relation["selected_role"], "canonical")
        release.confirm_plan(plan, self.policy, expect_preimage=True)
        # A concurrent reintroduction of the old MU owner MUST invalidate
        # the pinned release even when the plugin's own preimage is unchanged.
        self.target.write_text("<?php echo 'unexpected second owner';")
        with self.assertRaisesRegex(release.Denied,
                                    "OWNER_TRANSITION_RUNTIME_PROOF_REQUIRED"):
            release.confirm_plan(plan, self.policy, expect_preimage=True)

    def test_cannot_transfer_both_owners_via_unsigned_file_pair(self):
        _, new_file, target = self.capability_portability_fixture()
        request = json.loads(json.dumps(self.request))
        request["files"].append({
            "source": "ops/artifacts/approved.php", "target": target,
            "expected_before_sha256": release.digest(new_file.read_bytes()),
        })
        with self.assertRaisesRegex(release.Denied,
                                    "DUAL_OWNER_MIGRATION_NOT_CERTIFIED"):
            release.make_plan(request, self.policy)

    def test_portability_manifest_drift_blocks_original_release(self):
        manifest, _, _ = self.capability_portability_fixture()
        plan = release.make_plan(self.request, self.policy)
        record = json.loads(manifest.read_text())
        record["company_specific_legacy_mu_replacements"][0][
            "reconcile_contract"] = "later-owner-revision"
        manifest.write_text(json.dumps(record))
        with self.assertRaisesRegex(release.Denied,
                                    "CAPABILITY_OWNER_STATE_DRIFT"):
            release.confirm_plan(plan, self.policy, expect_preimage=True)

    def test_unrelated_mu_patch_does_not_impose_legacy_file_retention(self):
        self.capability_portability_fixture()
        request = json.loads(json.dumps(self.request))
        request["files"][0]["target"] = "wp-content/mu-plugins/mad4b-new.php"
        request["files"][0]["expected_before_sha256"] = None
        plan = release.make_plan(request, self.policy)
        self.assertEqual(plan["owner_reconciliation"]["relations"], [])
        release.confirm_plan(plan, self.policy, expect_preimage=True)

    def source_portability_fixture(self):
        """An immutable source can declare MU -> plugin migration before Wayfa does."""
        root = self.repo / "wp-content/plugins/mad4b-experience"
        root.mkdir(parents=True, exist_ok=True)
        replacement = "src/presentation/modules/374-mad4b-owner-bridge.php"
        path = root / "mad4b-portability.json"
        path.write_text(json.dumps({
            "contract": "mad4b.plugin-portability-profile.v1",
            "plugin": "mad4b-experience",
            "company_specific_legacy_mu_replacements": [{
                "legacy_file": "mad4b-approved.php",
                "replacement": replacement,
                "reconcile_contract": "mad4b.owner-transition-v1",
            }],
        }))
        shell("git", "-C", str(self.repo), "add", ".")
        shell("git", "-C", str(self.repo), "-c", "user.name=Test",
              "-c", "user.email=test@example.invalid", "commit", "-qm", "profile")
        self.commit = shell("git", "-C", str(self.repo), "rev-parse", "HEAD")
        self.request["git_commit"] = self.commit
        target = "wp-content/plugins/mad4b-experience/" + replacement
        self.policy["targets"]["demo:staging"]["allowed_targets"].append(target)
        return path, target

    def test_git_source_only_migration_blocks_late_target_plugin_copy(self):
        _, target = self.source_portability_fixture()
        self.assertFalse((self.site / "wp-content/plugins/mad4b-experience/mad4b-portability.json").exists())
        request = json.loads(json.dumps(self.request))
        request["files"][0]["target"] = target
        request["files"][0]["expected_before_sha256"] = None
        with self.assertRaisesRegex(release.Denied,
                                    "OWNER_TRANSITION_RUNTIME_PROOF_REQUIRED"):
            release.make_plan(request, self.policy)
        # Source Git is a candidate lineage, never permission to take over
        # an existing MU hook owner on the target tenant.
        self.assertIn("OLD", self.target.read_text())

    def test_source_only_owner_mapping_is_discovered_without_global_mu_block(self):
        _, _ = self.source_portability_fixture()
        plan = release.make_plan(self.request, self.policy)
        migration = plan["owner_reconciliation"]["relations"][0]
        self.assertEqual(migration["selected_role"], "legacy")
        self.assertIsNotNone(migration["source_manifest_sha256"])
        self.assertIsNone(migration["target_manifest_sha256"])
        release.confirm_plan(plan, self.policy, expect_preimage=True)
        # Working-tree edits cannot rewrite the source proof pinned by Git.
        source = self.repo / "wp-content/plugins/mad4b-experience/mad4b-portability.json"
        source.write_text("{bad uncommitted JSON}")
        release.confirm_plan(plan, self.policy, expect_preimage=True)

    def test_divergent_source_and_tenant_successor_is_not_silently_overridden(self):
        self.source_portability_fixture()
        target = self.site / "wp-content/plugins/mad4b-experience"
        target.mkdir(parents=True, exist_ok=True)
        (target / "mad4b-portability.json").write_text(json.dumps({
            "contract": "mad4b.plugin-portability-profile.v1",
            "plugin": "mad4b-experience",
            "company_specific_legacy_mu_replacements": [{
                "legacy_file": "mad4b-approved.php",
                "replacement": "src/presentation/modules/another-owner.php",
                "reconcile_contract": "mad4b.owner-transition-v1",
            }],
        }))
        with self.assertRaisesRegex(release.Denied,
                                    "PORTABILITY_OWNER_MIGRATION_CONFLICT"):
            release.make_plan(self.request, self.policy)

    def test_canonical_plugin_release_readback_after_legacy_retirement(self):
        _, new_file, target = self.capability_portability_fixture()
        self.target.unlink()
        request = json.loads(json.dumps(self.request))
        request["files"][0]["target"] = target
        request["files"][0]["expected_before_sha256"] = release.digest(new_file.read_bytes())
        plan = release.make_plan(request, self.policy)
        self.plan = plan
        result = self.authorized_apply()
        self.assertEqual(result["state"], "APPLIED_VERIFIED")
        self.assertEqual(new_file.read_text(), "<?php echo 'NEW';\n")
        release.confirm_plan(plan, self.policy, expect_preimage=False)
        self.assertFalse(self.target.exists())

    def test_cannot_install_source_only_owner_manifest_over_active_mu(self):
        _, _ = self.source_portability_fixture()
        target = "wp-content/plugins/mad4b-experience/mad4b-portability.json"
        self.policy["targets"]["demo:staging"]["allowed_targets"].append(target)
        request = json.loads(json.dumps(self.request))
        request["files"][0].update(source=target, target=target,
                                   expected_before_sha256=None)
        with self.assertRaisesRegex(release.Denied,
                                    "OWNER_PROFILE_MIGRATION_REQUIRES_PROOF"):
            release.make_plan(request, self.policy)

    def test_portability_manifest_can_install_after_mu_retired(self):
        _, _ = self.source_portability_fixture()
        (self.site / "wp-content/plugins/mad4b-experience").mkdir(parents=True)
        target = "wp-content/plugins/mad4b-experience/mad4b-portability.json"
        self.policy["targets"]["demo:staging"]["allowed_targets"].append(target)
        self.target.unlink()
        request = json.loads(json.dumps(self.request))
        request["files"][0].update(source=target, target=target,
                                   expected_before_sha256=None)
        plan = release.make_plan(request, self.policy)
        self.assertEqual(plan["owner_reconciliation"]["relations"][0][
            "selected_role"], "profile")
        self.assertTrue(plan["owner_reconciliation"]["relations"][0][
            "manifest_target_selected"])
        release.confirm_plan(plan, self.policy, expect_preimage=True)
        self.plan = plan
        result = self.authorized_apply()
        self.assertEqual(result["state"], "APPLIED_VERIFIED", result)
        release.confirm_plan(plan, self.policy, expect_preimage=False)

    def test_existing_dynamic_catalog_stays_functional_with_owner_gate(self):
        # The original registered host Provider has a newer dynamic catalog
        # than the source branch. The feature must preserve that authority
        # contract, not regress to a fixed MU allowlist.
        target = "wp-content/mu-plugins/mad4b-catalog-migration-test.php"
        source = self.repo / target
        source.parent.mkdir(parents=True, exist_ok=True)
        source.write_text("<?php echo 'catalog target';\n")
        catalog = self.repo / "ops/company-code-release-v1/catalog/mad4b-release-components.json"
        catalog.parent.mkdir(parents=True, exist_ok=True)
        catalog.write_text(json.dumps({
            "contract": "mad4b.company-release-components.v1",
            "components": [{"id": "mad4b-catalog-source-test", "files": [target]}],
        }))
        shell("git", "-C", str(self.repo), "add", ".")
        shell("git", "-C", str(self.repo), "-c", "user.name=Test",
              "-c", "user.email=test@example.invalid", "commit", "-qm", "catalog")
        self.commit = shell("git", "-C", str(self.repo), "rev-parse", "HEAD")
        self.policy["targets"]["demo:staging"]["release_catalog"] = (
            "ops/company-code-release-v1/catalog/mad4b-release-components.json")
        request = json.loads(json.dumps(self.request))
        request["git_commit"] = self.commit
        request["files"][0].update(source=target, target=target,
                                   expected_before_sha256=None)
        plan = release.make_plan(request, self.policy)
        self.assertEqual(plan["release_catalog"],
                         self.policy["targets"]["demo:staging"]["release_catalog"])
        self.assertEqual(plan["owner_reconciliation"]["relations"], [])
        release.confirm_plan(plan, self.policy, expect_preimage=True)
        self.plan = plan
        result = self.authorized_apply()
        self.assertEqual(result["state"], "APPLIED_VERIFIED", result)
        self.assertEqual((self.site / target).read_text(), source.read_text())

    def test_retired_legacy_mu_cannot_be_recreated_by_ordinary_release(self):
        self.capability_portability_fixture()
        self.target.unlink()
        request = json.loads(json.dumps(self.request))
        request["files"][0]["expected_before_sha256"] = None
        with self.assertRaisesRegex(release.Denied,
                                    "LEGACY_OWNER_REINTRODUCTION_NOT_CERTIFIED"):
            release.make_plan(request, self.policy)

    def test_plugin_new_install_with_legacy_absent_keeps_cas_readback(self):
        _, canonical, target = self.capability_portability_fixture()
        self.target.unlink()
        canonical.unlink()  # A new owner module has not been installed yet.
        request = json.loads(json.dumps(self.request))
        request["files"][0]["target"] = target
        request["files"][0]["expected_before_sha256"] = None
        plan = release.make_plan(request, self.policy)
        self.assertFalse(plan["owner_reconciliation"]["relations"][0][
            "replacement_present"])
        self.plan = plan
        outcome = self.authorized_apply()
        self.assertEqual(outcome["state"], "APPLIED_VERIFIED", outcome)
        self.assertTrue(canonical.is_file())
        release.confirm_plan(plan, self.policy, expect_preimage=False)

    def test_second_source_successor_cannot_take_tenant_owner(self):
        path, canonical, target = self.capability_portability_fixture()
        root = self.repo / "wp-content/plugins/mad4b-alternate"
        root.mkdir(parents=True,exist_ok=True)
        (root / "mad4b-portability.json").write_text(json.dumps({
            "contract": "mad4b.plugin-portability-profile.v1",
            "plugin": "mad4b-alternate",
            "company_specific_legacy_mu_replacements": [{
                "legacy_file":"mad4b-approved.php",
                "replacement":"src/other-owner.php",
                "reconcile_contract":"mad4b.option-owner-v1",
            }]
        }))
        shell("git", "-C", str(self.repo), "add", ".")
        shell("git", "-C", str(self.repo), "-c", "user.name=Test",
              "-c", "user.email=test@example.invalid", "commit", "-qm", "alternate")
        self.request["git_commit"] = shell("git", "-C", str(self.repo), "rev-parse", "HEAD")
        request = json.loads(json.dumps(self.request))
        request["files"][0]["target"] = target
        request["files"][0]["expected_before_sha256"] = release.digest(canonical.read_bytes())
        with self.assertRaisesRegex(release.Denied,
                                    "PORTABILITY_OWNER_MIGRATION_CONFLICT"):
            release.make_plan(request, self.policy)

    def test_unrelated_portability_feature_label_changes_not_owner_transfer(self):
        profile, canonical, target = self.capability_portability_fixture()
        contents=json.loads(profile.read_text())
        contents["features"]=["new-unrelated-feature"]
        profile.write_text(json.dumps(contents))
        # Do not infer an ownership migration from unrelated feature metadata.
        source_profile = self.repo / "wp-content/plugins/mad4b-experience/mad4b-portability.json"
        source_profile.parent.mkdir(parents=True,exist_ok=True)
        source_profile.write_text(json.dumps({
            "contract":"mad4b.plugin-portability-profile.v1",
            "plugin":"mad4b-experience",
            "features":["a-different-feature"],
            "company_specific_legacy_mu_replacements":contents[
                "company_specific_legacy_mu_replacements"],
        }))
        shell("git", "-C", str(self.repo), "add", ".")
        shell("git", "-C", str(self.repo), "-c", "user.name=Test",
              "-c", "user.email=test@example.invalid", "commit", "-qm", "unrelated")
        self.request["git_commit"] = shell("git", "-C", str(self.repo), "rev-parse", "HEAD")
        self.policy["targets"]["demo:staging"]["allowed_targets"].append(
            "wp-content/plugins/mad4b-experience/mad4b-portability.json")
        request=json.loads(json.dumps(self.request))
        request["files"][0].update(
            source="wp-content/plugins/mad4b-experience/mad4b-portability.json",
            target="wp-content/plugins/mad4b-experience/mad4b-portability.json",
            expected_before_sha256=release.digest(profile.read_bytes()))
        plan = release.make_plan(request, self.policy)
        self.assertEqual(plan["owner_reconciliation"]["relations"][0][
            "selected_role"], "profile")
        self.plan=plan
        result=self.authorized_apply()
        self.assertEqual(result["state"], "APPLIED_VERIFIED", result)

    def test_owner_manifest_behavior_contract_change_needs_independent_proof(self):
        profile,_,_=self.capability_portability_fixture()
        target= "wp-content/plugins/mad4b-experience/mad4b-portability.json"
        self.policy["targets"]["demo:staging"]["allowed_targets"].append(target)
        remote=self.repo / target
        remote.parent.mkdir(parents=True, exist_ok=True)
        data=json.loads(profile.read_text())
        data["company_specific_legacy_mu_replacements"][0][
            "replacement_activation_check"]="must-prove-runtime-reflection"
        remote.write_text(json.dumps(data))
        shell("git", "-C", str(self.repo), "add", ".")
        shell("git", "-C", str(self.repo), "-c", "user.name=Test",
              "-c", "user.email=test@example.invalid", "commit", "-qm", "behavior-change")
        self.request["git_commit"] = shell("git", "-C", str(self.repo), "rev-parse", "HEAD")
        request=json.loads(json.dumps(self.request))
        request["files"][0].update(source=target,target=target,
            expected_before_sha256=release.digest(profile.read_bytes()))
        with self.assertRaisesRegex(release.Denied,
                                    "OWNER_PROFILE_MIGRATION_REQUIRES_PROOF"):
            release.make_plan(request, self.policy)

    def source_literal_include_fixture(self):
        prefix="wp-content/plugins/mad4b-commerce"
        source = prefix + "/modules/compat/shared-core-loader.php"
        dependency = prefix + "/runtime-phases/00-foundation.php"
        old_loader=self.site / source
        old_loader.parent.mkdir(parents=True,exist_ok=True)
        old_loader.write_text("<?php echo 'old loader';\n")
        new_loader=self.repo / source
        new_loader.parent.mkdir(parents=True,exist_ok=True)
        new_loader.write_text("<?php\nrequire __DIR__ . '/../../runtime-phases/00-foundation.php';\n")
        shell("git", "-C", str(self.repo), "add", ".")
        shell("git", "-C", str(self.repo), "-c", "user.name=Test",
              "-c", "user.email=test@example.invalid", "commit", "-qm", "source-load")
        self.request["git_commit"]=shell("git", "-C", str(self.repo), "rev-parse", "HEAD")
        self.policy["targets"]["demo:staging"]["allowed_targets"].extend(
            [source,dependency])
        request=json.loads(json.dumps(self.request))
        request["files"][0].update(source=source,target=source,
            expected_before_sha256=release.digest(old_loader.read_bytes()))
        return request,dependency

    def test_missing_source_phase_dependency_blocked_before_site_write(self):
        request,dep=self.source_literal_include_fixture()
        with self.assertRaisesRegex(release.Denied,"PHP_STATIC_INCLUDE_MISSING"):
            release.make_plan(request,self.policy)
        self.assertIn("old loader",(self.site/request["files"][0]["target"]).read_text())

    def test_existing_source_phase_dependency_hash_pinned_and_rechecked(self):
        request,dep=self.source_literal_include_fixture()
        d=self.site/dep;d.parent.mkdir(parents=True,exist_ok=True);d.write_text("<?php echo 'old module';\n")
        plan=release.make_plan(request,self.policy)
        self.assertEqual(plan["dependency_snapshot"]["dependencies"][0]["path"],dep)
        release.confirm_plan(plan,self.policy,expect_preimage=True)
        d.write_text("<?php echo 'concurrent';\n")
        with self.assertRaisesRegex(release.Denied,"PHP_LITERAL_DEPENDENCY_STATE_DRIFT"):
            release.confirm_plan(plan,self.policy,expect_preimage=True)

    def test_source_phase_dependency_can_ship_in_same_signed_release(self):
        request,dep=self.source_literal_include_fixture()
        source=self.repo/dep;source.parent.mkdir(parents=True,exist_ok=True)
        source.write_text("<?php echo 'native phase';\n")
        shell("git", "-C", str(self.repo), "add", ".")
        shell("git", "-C", str(self.repo), "-c", "user.name=Test",
              "-c", "user.email=test@example.invalid", "commit", "-qm", "phase")
        request["git_commit"]=shell("git", "-C", str(self.repo), "rev-parse", "HEAD")
        (self.site/dep).parent.mkdir(parents=True,exist_ok=True)
        request["files"].append({'source':dep,'target':dep,
                                  'expected_before_sha256':None})
        plan=release.make_plan(request,self.policy)
        self.assertEqual(plan["dependency_snapshot"]["dependencies"],[])
        self.plan=plan
        result=self.authorized_apply()
        self.assertEqual(result["state"],"APPLIED_VERIFIED",result)
        self.assertTrue((self.site/dep).is_file())
        release.confirm_plan(plan,self.policy,expect_preimage=False)

    def test_untrusted_literal_include_cannot_escape_site(self):
        request,dep=self.source_literal_include_fixture()
        code=self.repo/request["files"][0]["source"]
        code.write_text("<?php\nrequire __DIR__ . '/../../../../../../../etc/passwd.php';\n")
        shell("git", "-C", str(self.repo), "add", ".")
        shell("git", "-C", str(self.repo), "-c", "user.name=Test",
              "-c", "user.email=test@example.invalid", "commit", "-qm", "escape")
        request["git_commit"]=shell("git", "-C", str(self.repo), "rev-parse", "HEAD")
        with self.assertRaisesRegex(release.Denied,
                                    "PHP_STATIC_INCLUDE_OUTSIDE_DEPLOY_SCOPE"):
            release.make_plan(request,self.policy)

    def test_concurrent_target_edit_requires_unverified_rollback_incident(self):
        def validator(_):
            self.target.write_text("<?php echo 'concurrent external edit';\n")
            raise RuntimeError("deliberate post-apply validation failure")
        result=self.authorized_apply(validator=validator)
        self.assertEqual(result["state"],"ROLLBACK_UNVERIFIED",result)
        self.assertIn("concurrent external edit",self.target.read_text())
        self.assertTrue(result["broker_grant_signature_verified"])
        self.assertFalse(result["original_313_receipt"])
        receipt=self.receipts / (self.grant()["nonce"]+".json")
        self.assertTrue(receipt.exists(),"do not lose a partial-state failure receipt")
        self.assertEqual(json.loads(receipt.read_text())["state"],"ROLLBACK_UNVERIFIED")
        with self.assertRaisesRegex(release.Denied,"GRANT_ALREADY_USED"):
            self.authorized_apply()

    def test_dependency_drift_during_rollback_reports_unverified_readback(self):
        request,dependency=self.source_literal_include_fixture()
        module=self.site/dependency;module.parent.mkdir(parents=True,exist_ok=True)
        module.write_text("<?php echo 'original';\n")
        self.plan=release.make_plan(request,self.policy)
        def validator(_):
            module.write_text("<?php echo 'externally updated';\n")
            raise RuntimeError("WordPress bootstrap failed")
        result=self.authorized_apply(validator=validator)
        self.assertEqual(result["state"],"ROLLBACK_UNVERIFIED",result)
        self.assertIn("old loader",(self.site/request["files"][0]["target"]).read_text())
        self.assertIn("externally updated",module.read_text())
        self.assertIn("PHP_LITERAL_DEPENDENCY_STATE_DRIFT",result["failed_reason"])

    def native_plugin_catalog_fixture(self, *, feature='trust-support-dynamic',
                                     has_profile=True, eligible=True,
                                     with_capabilities=True):
        plugin="mad4b-experience"
        rel=f'wp-content/plugins/{plugin}/src/presentation/modules/373-native.php'
        css=f'wp-content/plugins/{plugin}/src/presentation/assets/mad4b-card.css'
        manifest=f'wp-content/plugins/{plugin}/mad4b-portability.json'
        for path,content in [(rel,"<?php echo 'source native';\n"),
                             (css,'.mad4b-consult { display:grid; }\n')]:
            artifact=self.repo/path;artifact.parent.mkdir(parents=True,exist_ok=True)
            artifact.write_text(content)
            (self.site/path).parent.mkdir(parents=True,exist_ok=True)
        if has_profile:
            profile={'contract':'mad4b.plugin-portability-profile.v1',
                     'plugin':plugin,'scope':'tenant-portable',
                     'features':['trust-support-dynamic'],
                     'sensitive_central_authority_not_included':eligible,
                     'financial_or_customer_data_automatic_write':False,
                     'company_specific_legacy_mu_replacements':[]}
            artifact=self.repo/manifest
            artifact.parent.mkdir(parents=True,exist_ok=True)
            artifact.write_text(json.dumps(profile))
        catalog=self.repo/'ops/company-code-release-v1/catalog/mad4b-release-components.json'
        catalog.parent.mkdir(parents=True,exist_ok=True)
        component={'id':'mad4b-consultation-card','files':[rel,css]}
        if with_capabilities:component['capabilities']=[feature]
        catalog.write_text(json.dumps({'contract':'mad4b.company-release-components.v1',
                                       'components':[component]}))
        shell("git", "-C", str(self.repo), "add", ".")
        shell("git", "-C", str(self.repo), "-c", "user.name=Test",
              "-c", "user.email=test@example.invalid", "commit", "-qm", "plugin catalog")
        commit=shell('git','-C',str(self.repo),'rev-parse','HEAD')
        self.policy['targets']['demo:staging']['release_catalog']=(
            'ops/company-code-release-v1/catalog/mad4b-release-components.json')
        request=json.loads(json.dumps(self.request))
        request['git_commit']=commit
        request['files']=[{'source':rel,'target':rel,'expected_before_sha256':None},
                          {'source':css,'target':css,'expected_before_sha256':None}]
        return request,rel,css

    def test_dynamic_catalog_registered_tenant_portable_plugin_and_assets(self):
        request,mod,css=self.native_plugin_catalog_fixture()
        plan=release.make_plan(request,self.policy)
        self.assertEqual(len(plan['files']),2)
        self.assertEqual(plan['owner_reconciliation']['relations'],[])
        self.plan=plan
        result=self.authorized_apply()
        self.assertEqual(result['state'],'APPLIED_VERIFIED',result)
        self.assertTrue((self.site/mod).is_file())
        self.assertTrue((self.site/css).is_file())
        release.confirm_plan(plan,self.policy,expect_preimage=False)

    def test_dynamic_plugin_catalog_denies_missing_portability_profile(self):
        request,_,_=self.native_plugin_catalog_fixture(has_profile=False)
        with self.assertRaisesRegex(release.Denied,'GIT_SOURCE_MUST_BE_REGULAR_FILE'):
            release.make_plan(request,self.policy)

    def test_dynamic_plugin_catalog_denies_unknown_capability(self):
        request,_,_=self.native_plugin_catalog_fixture(feature='unregistered-feature')
        with self.assertRaisesRegex(release.Denied,'RELEASE_PLUGIN_CAPABILITY_NOT_REGISTERED'):
            release.make_plan(request,self.policy)

    def test_dynamic_plugin_catalog_denies_sensitive_central_authority(self):
        request,_,_=self.native_plugin_catalog_fixture(eligible=False)
        with self.assertRaisesRegex(release.Denied,'PORTABLE_PLUGIN_PROFILE_NOT_ELIGIBLE'):
            release.make_plan(request,self.policy)

    def test_dynamic_plugin_catalog_requires_typed_capability_membership(self):
        request,_,_=self.native_plugin_catalog_fixture(with_capabilities=False)
        with self.assertRaisesRegex(release.Denied,'RELEASE_PLUGIN_CAPABILITY_CONTRACT_REQUIRED'):
            release.make_plan(request,self.policy)

    def test_plugin_catalog_cannot_redirect_source_path(self):
        request,mod,css=self.native_plugin_catalog_fixture()
        request['files'][0]['source']='ops/artifacts/approved.php'
        with self.assertRaisesRegex(release.Denied,'CATALOG_SOURCE_TARGET_MISMATCH'):
            release.make_plan(request,self.policy)

    def test_duplicate_component_membership_not_a_release_privilege(self):
        request,mod,css=self.native_plugin_catalog_fixture()
        catalog=self.repo/'ops/company-code-release-v1/catalog/mad4b-release-components.json'
        obj=json.loads(catalog.read_text())
        obj['components'].append({'id':'mad4b-another-owning-module','files':[mod],
                                  'capabilities':['trust-support-dynamic']})
        catalog.write_text(json.dumps(obj))
        shell('git','-C',str(self.repo),'add','.')
        shell('git','-C',str(self.repo),'-c','user.name=Test',
              '-c','user.email=test@example.invalid','commit','-qm','duplicate module')
        request['git_commit']=shell('git','-C',str(self.repo),'rev-parse','HEAD')
        with self.assertRaisesRegex(release.Denied,'RELEASE_COMPONENT_SCOPE_OR_DUPLICATE_INVALID'):
            release.make_plan(request,self.policy)

    def test_tenant_plugin_profile_change_invalidates_pending_release(self):
        request,mod,css=self.native_plugin_catalog_fixture()
        profile=self.site/'wp-content/plugins/mad4b-experience/mad4b-portability.json'
        profile.parent.mkdir(parents=True,exist_ok=True)
        profile.write_text(json.dumps({
            'contract':'mad4b.plugin-portability-profile.v1',
            'plugin':'mad4b-experience','scope':'tenant-portable',
            'features':['trust-support-dynamic'],'company_field':'Wayfa-owned value'}))
        plan=release.make_plan(request,self.policy)
        self.assertEqual(plan['tenant_plugin_profiles']['profiles'][0]['plugin'],
                         'mad4b-experience')
        release.confirm_plan(plan,self.policy,expect_preimage=True)
        payload=json.loads(profile.read_text());payload['company_field']='newer live value'
        profile.write_text(json.dumps(payload))
        with self.assertRaisesRegex(release.Denied,'TENANT_PLUGIN_PROFILE_STATE_DRIFT'):
            release.confirm_plan(plan,self.policy,expect_preimage=True)

    def test_unregistered_tenant_plugin_profile_identity_fails_closed(self):
        request,mod,css=self.native_plugin_catalog_fixture()
        profile=self.site/'wp-content/plugins/mad4b-experience/mad4b-portability.json'
        profile.write_text(json.dumps({'contract':'mad4b.plugin-portability-profile.v1',
                                       'plugin':'mad4b-foreign','scope':'tenant-portable'}))
        with self.assertRaisesRegex(release.Denied,
                                    'TENANT_PORTABILITY_IDENTITY_MISMATCH|PORTABILITY_PLUGIN_IDENTITY_INVALID'):
            release.make_plan(request,self.policy)

    def test_legacy_tenant_profile_without_features_is_compatible(self):
        request,mod,css=self.native_plugin_catalog_fixture()
        profile=self.site/'wp-content/plugins/mad4b-experience/mad4b-portability.json'
        profile.write_text(json.dumps({'contract':'mad4b.plugin-portability-profile.v1',
                                       'plugin':'mad4b-experience'}))
        plan=release.make_plan(request,self.policy)
        self.assertEqual(plan['tenant_plugin_profiles']['profiles'][0]['features_preimage'],[])
        release.confirm_plan(plan,self.policy,expect_preimage=True)

    def test_dynamic_component_cannot_grant_two_plugins_together(self):
        request,mod,css=self.native_plugin_catalog_fixture()
        other='wp-content/plugins/mad4b-second/src/other.php'
        source=self.repo/other;source.parent.mkdir(parents=True,exist_ok=True)
        source.write_text('<?php echo "second";')
        catalog=self.repo/'ops/company-code-release-v1/catalog/mad4b-release-components.json'
        record=json.loads(catalog.read_text())
        record['components'][0]['files'].append(other)
        catalog.write_text(json.dumps(record))
        shell('git','-C',str(self.repo),'add','.')
        shell('git','-C',str(self.repo),'-c','user.name=Test',
              '-c','user.email=test@example.invalid','commit','-qm','cross owner')
        request['git_commit']=shell('git','-C',str(self.repo),'rev-parse','HEAD')
        with self.assertRaisesRegex(release.Denied,'RELEASE_COMPONENT_CROSSES_PLUGIN_OWNERS'):
            release.make_plan(request,self.policy)

    def test_untrusted_portability_path_is_rejected(self):
        manifest, _, _ = self.capability_portability_fixture()
        record = json.loads(manifest.read_text())
        record["company_specific_legacy_mu_replacements"][0][
            "replacement"] = "src/../../other-plugin/untrusted.php"
        manifest.write_text(json.dumps(record))
        with self.assertRaisesRegex(release.Denied,
                                    "NONCANONICAL_FILE_PATH|UNSAFE_FILE_PATH"):
            release.make_plan(self.request, self.policy)

    def test_production_plan_uses_exact_binding_without_environment_waiver(self):
        # Production is no longer categorically denied, but its site profile,
        # per-file preimage and separate signature remain mandatory.
        self.policy["targets"]["demo:production"] = dict(
            self.policy["targets"]["demo:staging"])
        self.request["environment"] = "production"
        plan = release.make_plan(self.request, self.policy)
        self.assertEqual(plan["environment"], "production")
        release.confirm_plan(plan, self.policy, expect_preimage=True)
        with self.assertRaisesRegex(release.Denied,
                                    "BROKER_GRANT_NOT_BOUND_TO_RELEASE"):
            release.verify_grant(plan, self.policy, self.grant(), "apply")
        production_grant = self.grant()
        production_grant.update(
            environment="production", plan_sha256=plan["plan_sha256"],
        )
        production_grant["signature"] = base64.b64encode(
            self.private.sign(release.grant_message(production_grant))
        ).decode()
        outcome = release.apply_release(
            plan, self.policy, production_grant, self.receipts,
            runtime_check=lambda p,c:None, validate_code=lambda p:None,
            preflight_code=lambda p:None
        )
        self.assertEqual(outcome["state"], "APPLIED_VERIFIED")
        self.assertEqual(self.target.read_text(), "<?php echo 'NEW';\n")
        self.assertFalse(outcome["original_313_receipt"])

    def test_custom_environment_plan_and_signed_exact_scope(self):
        self.policy["targets"]["demo:qa"] = dict(
            self.policy["targets"]["demo:staging"])
        self.request["environment"] = "qa"
        plan = release.make_plan(self.request, self.policy)
        self.assertEqual(plan["environment"], "qa")
        release.confirm_plan(plan, self.policy, expect_preimage=True)
        self.policy["targets"].pop("demo:qa")
        with self.assertRaisesRegex(release.Denied, "TARGET_NOT_ENROLLED"):
            release.confirm_plan(plan, self.policy, expect_preimage=True)

    def test_named_environment_no_longer_sets_authority(self):
        self.request["environment"] = "production"
        with self.assertRaisesRegex(release.Denied, "TARGET_NOT_ENROLLED"):
            release.make_plan(self.request, self.policy)

    def test_signed_grant_cannot_cross_environment(self):
        self.policy["targets"]["demo:production"] = dict(
            self.policy["targets"]["demo:staging"])
        self.request["environment"] = "production"
        prod = release.make_plan(self.request, self.policy)
        signed_staging = self.grant()
        with self.assertRaisesRegex(release.Denied,
                                    "BROKER_GRANT_NOT_BOUND_TO_RELEASE"):
            release.verify_grant(prod, self.policy, signed_staging, "apply")

    def test_separate_signed_rollback_restores_original(self):
        result = self.authorized_apply()
        self.assertEqual(result["state"], "APPLIED_VERIFIED")
        grant = self.grant("rollback")
        grant["nonce"] = "rollback_nonce_abcdef12345678"
        grant["signature"] = base64.b64encode(
            self.private.sign(release.grant_message(grant))
        ).decode()
        undo = release.rollback_release(
            self.plan, self.policy, grant, self.receipts,
            runtime_check=lambda p, cfg: None
        )
        self.assertEqual(undo["state"], "ROLLBACK_VERIFIED")
        self.assertEqual(self.target.read_text(), "<?php echo 'OLD';\n")
        self.assertEqual(undo["plan_sha256"], self.plan["plan_sha256"])

    def test_rollback_denied_after_policy_target_revocation(self):
        self.authorized_apply()
        self.policy["targets"]["demo:staging"]["allowed_targets"] = []
        grant = self.grant("rollback")
        grant["nonce"] = "rollback_revoked_abcdef1234"
        grant["signature"] = base64.b64encode(
            self.private.sign(release.grant_message(grant))
        ).decode()
        with self.assertRaisesRegex(release.Denied, "INCOMPLETE_TARGET_BINDING|PLAN_TARGET_NO_LONGER_ALLOWED"):
            release.rollback_release(self.plan, self.policy, grant, self.receipts,
                                     runtime_check=lambda p, cfg: None)
        self.assertEqual(self.target.read_text(), "<?php echo 'NEW';\n")

    def test_rollback_denied_if_git_source_hash_drift(self):
        self.authorized_apply()
        source_row = self.plan["files"][0]
        source_row["source_sha256"] = "0" * 64
        grant = self.grant("rollback")
        grant["nonce"] = "rollback_drift_abcdef12345"
        grant["signature"] = base64.b64encode(
            self.private.sign(release.grant_message(grant))
        ).decode()
        with self.assertRaisesRegex(release.Denied, "PLAN_TAMPERED"):
            release.rollback_release(self.plan, self.policy, grant, self.receipts,
                                     runtime_check=lambda p, cfg: None)
        self.assertEqual(self.target.read_text(), "<?php echo 'NEW';\n")

    def test_rollback_without_prior_verified_apply_denied(self):
        grant = self.grant("rollback")
        with self.assertRaisesRegex(release.Denied, "NO_VERIFIED_APPLY"):
            release.rollback_release(
                self.plan, self.policy, grant, self.receipts,
                runtime_check=lambda p, cfg: None
            )

    def test_backup_written_before_target_change(self):
        self.authorized_apply()
        path = release.backup_path(self.receipts, self.plan)
        self.assertTrue(path.exists())
        self.assertEqual(path.stat().st_mode & 0o777, 0o600)
        backup = release.load_backup(self.plan, self.receipts)
        self.assertEqual(backup["files"][0]["sha256"],
                         self.plan["files"][0]["expected_before_sha256"])

    def test_rollback_rejects_concurrent_edit(self):
        self.authorized_apply()
        self.target.write_text("<?php echo 'THIRD PARTY';\\n")
        grant = self.grant("rollback")
        grant["nonce"] = "rollback_nonce_abcdef12345678"
        grant["signature"] = base64.b64encode(
            self.private.sign(release.grant_message(grant))
        ).decode()
        with self.assertRaisesRegex(release.Denied, "TARGET_CONTENT_DRIFT"):
            release.rollback_release(self.plan, self.policy, grant, self.receipts,
                                     runtime_check=lambda p, cfg: None)
        self.assertIn("THIRD PARTY", self.target.read_text())

    def test_readback_rejects_unapplied_plan(self):
        with self.assertRaisesRegex(release.Denied, "TARGET_CONTENT_DRIFT"):
            release.check_sha_readback(self.plan, self.policy)

    def test_acceptance_checks_are_immutable_named_functions(self):
        self.request["acceptance_checks"] = ["wp_boot", "copy_coverage_readiness"]
        plan = release.make_plan(self.request, self.policy)
        self.assertEqual(plan["acceptance_checks"],
                         ["wp_boot", "copy_coverage_readiness"])
        release.confirm_plan(plan, self.policy, expect_preimage=True)
        plan["acceptance_checks"] = ["wp_boot"]
        with self.assertRaisesRegex(release.Denied, "PLAN_TAMPERED"):
            release.confirm_plan(plan, self.policy, expect_preimage=True)

    def test_unknown_or_injected_acceptance_check_denied(self):
        for value in ([ "wp_boot", "shell:rm -rf /" ],
                      [ "wp_boot", "wp_boot" ],
                      [ "copy_coverage_readiness" ],
                      [ "wp_boot", { "php": "exit(0);" } ]):
            with self.subTest(value=value):
                self.request["acceptance_checks"] = value
                with self.assertRaisesRegex(release.Denied,
                                            "UNREGISTERED_ACCEPTANCE_CHECK"):
                    release.make_plan(self.request, self.policy)

    def test_copy_coverage_runtime_acceptance_uses_fixed_wp_evaluation(self):
        self.plan["acceptance_checks"] = ["wp_boot", "copy_coverage_readiness"]
        calls = []
        original = release.run
        def fake(command, timeout=20):
            calls.append(command)
            if command[-2:] == ["php", "-l"]:
                return b"No syntax errors detected"
            if "php" in command and "-l" in command:
                return b"No syntax errors detected"
            if "MAD4B_CODE_RELEASE_COPY_READINESS_PASS" in command[-1]:
                return b"MAD4B_CODE_RELEASE_COPY_READINESS_PASS"
            return b"MAD4B_CODE_RELEASE_WP_BOOT_OK"
        try:
            release.run = fake
            release.php_lint(self.plan)
            self.assertEqual(len(calls), 3)
            self.assertIn("mad4b_copy_coverage_readiness_advisories", calls[-1][-1])
            self.assertNotIn("shell:", " ".join(" ".join(item) for item in calls))
        finally:
            release.run = original

    def test_copy_coverage_acceptance_ignores_unrelated_incomplete_scan(self):
        plan = dict(self.plan)
        plan["acceptance_checks"] = ["wp_boot", "copy_coverage_readiness"]
        cmds = []
        before = release.run
        def fake_run(command, timeout=20):
            cmds.append(command)
            if "php" in command and "-l" in command:
                return b"No syntax errors detected"
            if "MAD4B_CODE_RELEASE_COPY_READINESS_PASS" in command[-1]:
                return b"MAD4B_CODE_RELEASE_COPY_READINESS_PASS"
            return b"MAD4B_CODE_RELEASE_WP_BOOT_OK"
        try:
            release.run = fake_run
            release.php_lint(plan)
        finally:
            release.run = before
        script = cmds[-1][-1]
        self.assertIn('mad4b.content-readiness.discovery.v1', script)
        self.assertIn('mad4b-elementor-copy-coverage', script)
        self.assertIn('apply_filters("mad4b/content_readiness/external_issues",'
                      '[],["post_types"=>["elementor_library"]])', script)
        self.assertNotIn('apply_filters("mad4b/content_readiness/external_issues",[])',
                         script)
        self.assertNotIn('$r["complete"]', script)
        self.assertNotIn("exit(3);'\n            'echo", script)

    def test_copy_coverage_acceptance_failure_blocks_release(self):
        self.plan["acceptance_checks"] = ["wp_boot", "copy_coverage_readiness"]
        original = release.run
        def fake(command, timeout=20):
            if "php" in command and "-l" in command:
                return b"No syntax errors detected"
            if "MAD4B_CODE_RELEASE_COPY_READINESS_PASS" in command[-1]:
                return b""
            return b"MAD4B_CODE_RELEASE_WP_BOOT_OK"
        try:
            release.run = fake
            with self.assertRaisesRegex(release.Denied,
                                        "COPY_COVERAGE_RUNTIME_ACCEPTANCE_NOT_PROVEN"):
                release.php_lint(self.plan)
        finally:
            release.run = original

    def test_malicious_paths_denied(self):
        for value in ("../wp-config.php", "/etc/passwd", "foo//bar.php",
                      "foo\\bar.php", "wp-content/mu-plugins/x.php/../y.php"):
            with self.subTest(value=value):
                with self.assertRaises(release.Denied):
                    release.exact_relative(value)

    def test_broker_expired_grant_denied(self):
        grant = self.grant()
        grant["expires_at"] = (dt.datetime.now(dt.timezone.utc)
                               - dt.timedelta(seconds=5)).isoformat()
        grant["signature"] = base64.b64encode(
            self.private.sign(release.grant_message(grant))
        ).decode()
        with self.assertRaisesRegex(release.Denied, "BROKER_SIGNATURE_OR_EXPIRY_INVALID"):
            self.authorized_apply(grant)

    def runtime_fixture(self):
        registry = {
            "contract": "mad4b.company-native-target-runtime-bindings.v1",
            "targets": {"demo:staging": {
                "site_uuid": self.request["site_uuid"],
                "canonical_origin": "https://demo.example.invalid",
                "target_container": "legacy-renamed-container",
                "coolify_service_uuid": "example123service",
            }},
        }
        observed = {
            "contract": "mad4b.faore.native-operator-dynamic-target.v1",
            "company_id": "demo", "environment": "staging",
            "site_uuid": self.request["site_uuid"],
            "canonical_origin": "https://demo.example.invalid",
            "target_container": "demo-php",
            "coolify_service_uuid": "example123service",
            "read_only": True, "authorization_created": False,
            "native_execution_performed": False,
        }
        mounts = [{
            "Type": "bind", "RW": True,
            "Destination": "/var/www/html",
            "Source": str(self.site),
        }]
        return registry, observed, mounts

    def test_verified_dynamic_container_rotates_old_registry_alias(self):
        registry, observed, mounts = self.runtime_fixture()
        release.assert_runtime_binding(
            self.plan, self.policy,
            read_registry=lambda: registry,
            read_original_target=lambda c, e: observed,
            read_mounts=lambda name: mounts,
        )
        self.assertEqual(registry["targets"]["demo:staging"]["target_container"],
                         "legacy-renamed-container")

    def test_dynamic_binding_rejects_stale_git_plan_container(self):
        registry, observed, mounts = self.runtime_fixture()
        self.plan["container"] = "outdated-php"
        with self.assertRaisesRegex(release.Denied, "DYNAMIC_CONTAINER_RELEASE_PLAN_DRIFT"):
            release.assert_runtime_binding(
                self.plan, self.policy,
                read_registry=lambda: registry,
                read_original_target=lambda c, e: observed,
                read_mounts=lambda name: mounts,
            )

    def test_dynamic_binding_rejects_unverified_site_identity(self):
        registry, observed, mounts = self.runtime_fixture()
        observed["site_uuid"] = "00000000-0000-0000-0000-000000000000"
        with self.assertRaisesRegex(release.Denied, "ORIGINAL_DYNAMIC_TARGET_IDENTITY_INVALID"):
            release.assert_runtime_binding(
                self.plan, self.policy,
                read_registry=lambda: registry,
                read_original_target=lambda c, e: observed,
                read_mounts=lambda name: mounts,
            )

    def test_dynamic_binding_rejects_foreign_mount(self):
        registry, observed, mounts = self.runtime_fixture()
        mounts[0]["Source"] = str(self.repo)
        with self.assertRaisesRegex(release.Denied, "TARGET_CONTAINER_MOUNT_MISMATCH"):
            release.assert_runtime_binding(
                self.plan, self.policy,
                read_registry=lambda: registry,
                read_original_target=lambda c, e: observed,
                read_mounts=lambda name: mounts,
            )

    def test_dynamic_binding_rejects_coolify_service_drift(self):
        registry, observed, mounts = self.runtime_fixture()
        observed["coolify_service_uuid"] = "foreign123service"
        with self.assertRaisesRegex(release.Denied, "ORIGINAL_COOLIFY_SERVICE_DRIFT"):
            release.assert_runtime_binding(
                self.plan, self.policy,
                read_registry=lambda: registry,
                read_original_target=lambda c, e: observed,
                read_mounts=lambda name: mounts,
            )

    def test_doctor_distinguishes_305_312_from_missing_release_handler(self):
        original = {
            "contract": "mad4b.company-code-release.lifecycle-bridge.v1",
            "company": "demo", "environment": "staging",
            "site_uuid": self.request["site_uuid"],
            "original_305_standing_wpcli_verified": True,
            "original_312_verifier_registered": True,
            "original_313_code_release_handler_registered": False,
            "company_code_release_dispatch_ability_registered": False,
            "blockers": ["ORIGINAL_313_COMPANY_CODE_RELEASE_HANDLER_NOT_REGISTERED"],
        }
        result = release.release_admission_doctor(
            self.plan,self.policy,
            fixed_policy_path=self.repo/"no-installed-policy",
            runtime_check=lambda p,c: None, original_broker_status=original)
        self.assertEqual(result["state"],"HOLD_CODE_RELEASE_OPERATION_ENROLLMENT")
        self.assertTrue(result["original_305_standing_wpcli_verified"])
        self.assertTrue(result["original_312_verifier_registered"])
        self.assertFalse(result["production_execution_authorized"])
        self.assertFalse(result["production_mutated"])

    def test_original_broker_doctor_refuses_other_company_identity(self):
        foreign = {
            "contract": "mad4b.company-code-release.lifecycle-bridge.v1",
            "company": "foreign", "environment": "staging",
            "site_uuid": self.request["site_uuid"],
        }
        with self.assertRaisesRegex(release.Denied,
                                    "ORIGINAL_BROKER_STATUS_SCOPE_INVALID"):
            release.release_admission_doctor(
                self.plan,self.policy,
                fixed_policy_path=self.repo/"no-installed-policy",
                runtime_check=lambda p,c: None,
                original_broker_status=foreign)

    def test_admission_doctor_reports_unregistered_broker_without_grant(self):
        report = release.release_admission_doctor(
            self.plan, self.policy,
            fixed_policy_path=self.repo / "absent-policy.json",
            runtime_check=lambda plan, policy: None
        )
        self.assertTrue(report["git_plan_verified"])
        self.assertTrue(report["original_runtime_binding_verified"])
        self.assertFalse(report["host_policy_installed_and_pinned"])
        self.assertEqual(report["state"], "HOLD_ORIGINAL_BROKER_ENROLLMENT")
        self.assertFalse(report["production_execution_authorized"])
        self.assertFalse(report["production_mutated"])
        self.assertIn("PINNED_HOST_RELEASE_POLICY_NOT_ENROLLED",
                      report["blockers"])

    def test_admission_doctor_exposes_preimage_drift_without_write(self):
        self.target.write_text("<?php echo 'OTHER';\n")
        report = release.release_admission_doctor(
            self.plan, self.policy,
            fixed_policy_path=self.repo / "absent-policy.json",
            runtime_check=lambda plan, policy: None
        )
        self.assertFalse(report["git_plan_verified"])
        self.assertEqual(report["state"], "HOLD_GIT_PLAN_OR_TARGET_DRIFT")
        self.assertFalse(report["production_execution_authorized"])
        self.assertIn("OTHER", self.target.read_text())

    def test_plan_mutation_denied(self):
        self.plan["files"][0]["source_sha256"] = "0" * 64
        with self.assertRaisesRegex(release.Denied, "PLAN_TAMPERED"):
            self.authorized_apply()


if __name__ == "__main__":
    unittest.main(verbosity=2)
