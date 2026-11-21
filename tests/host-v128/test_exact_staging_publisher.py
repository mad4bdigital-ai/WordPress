"""Genuine signed-contract-to-filesystem v130 transactional MU pair tests.

All sites/keys are temporary ephemeral fixtures. Never touches live WordPress.
"""
import base64
import copy
import hashlib
import os
import sys
import tempfile
from pathlib import Path
import unittest
from unittest import mock

ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/"ops/host-authority-v128"))
import exact_staging_publisher as pub130
import live_first_reconciliation as v
import live_current_source as live
import standing_write as standing
import test_standing_write as sf
import test_live_first_reconciliation as df
from persistent_enrollment import ConnectionDenied

NOW=sf.NOW
SITE=df.SITE_FILE
MU0,MU1=pub130.MU_PAIR
class SignedAtomicPublisherTests(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        root=Path(self.tmp.name)
        self.wp=root/"wordpress"
        self.wp.mkdir()
        (self.wp/"wp-config.php").write_text("<?php // test site only\n")
        (self.wp/"wp-content/mu-plugins").mkdir(parents=True)
        site=self.wp/SITE
        site.parent.mkdir(parents=True)
        site.write_bytes(b"<?php // original-318-v2\n")
        self.backup_root=root/"root-private-backups"
        self.backup_root.mkdir(mode=0o700)
        self.new_bytes={
            MU0:b"<?php // v3 trusted loader\n",
            MU1:b"<?php // v3 signed reader\n",
        }
        self.op=sf.PersistentStandingWriteTests(
            "test_exact_write_admission_requires_all_signatures")
        self.op.setUp()
        self.df=df.LiveFirstReconcileTests(
            "test_approved_desired_is_pinned_to_independent_registry")
        self.df.setUp()
        self.binding=self.df.site
        self.assertEqual(self.binding,self.op.binding)
        self.read_counter=0
        self.live_base=self._site_signed_current()
        self.site_reader=self._trusted_site_reader
        current=live.verify_current_receipt(
            self.live_base,self.binding,self.df.l.pub,df.live_tests.ORIGIN,
            now=NOW)
        self.current=current
        base_files=self.live_base["files"]
        targets=[]
        for row in base_files:
            p=row["relative_path"]
            if p in self.new_bytes:
                raw=self.new_bytes[p]
                targets.append({
                    "relative_path":p,"state":"PRESENT",
                    "sha256":hashlib.sha256(raw).hexdigest(),
                    "size_bytes":len(raw)})
            else:
                targets.append(dict(row))
        self.df.row["base_files"]=copy.deepcopy(base_files)
        self.df.row["files"]=copy.deepcopy(targets)
        self.df.sign_desired()
        self.approved=v.verify_desired(
            self.df.row,self.binding,
            df.pub(self.df.desired_key),
            self.df.checkpoint,df.pub(self.df.registry_key),now=NOW)
        self.signed_backup={
           "contract":v.BACKUP_CONTRACT,
           "company":self.binding.company,
           "environment":self.binding.environment,
           "site_uuid":self.binding.site_uuid,
           "target_host_id":self.binding.host_id,
           "source_current_snapshot_sha256":current.snapshot_sha256,
           "source_current_nonce":current.snapshot_nonce,
           "group_id":"authority",
           "files":[dict(row) for row in base_files if row["relative_path"] in self.new_bytes],
           "backup_bundle_sha256":"e"*64,
           "backup_receipt_id":"a"*32,
           "backup_kind":"host_private_rollback_store",
           "status":"SEALED_AND_HASH_VERIFIED",
           "verified_epoch":NOW,
        }
        self.sign_backup()
        plan={
           "contract":"mad4b.host-v129.atomic-group-execution-proposal.v1",
           "site_uuid":self.binding.site_uuid,
           "company":self.binding.company,
           "environment":self.binding.environment,
           "group_id":"authority",
           "paths":[MU0,MU1],
           "current_sha256":current.snapshot_sha256,
           "initial_current_sha256":current.snapshot_sha256,
           "desired_sha256":self.approved.signed_record_sha256,
           "source_commit_sha":self.approved.source_head_sha,
           "candidate_exact":True,
           "previous":{
               "source":"independently_signed_actual_host_runner_backup",
               "snapshot_sha256":current.snapshot_sha256,
               "group_id":"authority",
               "backup_receipt_id":"a"*32,
               "backup_bundle_sha256":"e"*64,
               "verified":True,
           },
           "executor_must_recheck_live_preimage_at_commit":True,
           "exact_owner_or_operator_signed_grant_required":True,
           "host_write_authorized":False,
           "operation_executed":False,
           "desired_promoted":False,
        }
        self.op.signed_plan["plan"]=plan
        self.op.sign_plan()

    def sign_backup(self):
        self.signed_backup={**{k:self.signed_backup[k]
                              for k in v.BACKUP_FIELDS},
            "signature":base64.b64encode(
              self.df.l.key.sign(v.canonical_backup(self.signed_backup))
            ).decode()
        }

    def _site_signed_current(self):
        rows=[]
        for path in sorted((MU0,MU1,SITE)):
            content=pub130._read_exact(self.wp,path) if path in pub130.MU_PAIR else (
                (self.wp/path).read_bytes() if (self.wp/path).is_file() else None
            )
            rows.append({"relative_path":path,
                 "state":"ABSENT" if content is None else "PRESENT",
                 "sha256":"ABSENT" if content is None else hashlib.sha256(content).hexdigest(),
                 "size_bytes":0 if content is None else len(content)})
        self.read_counter+=1
        nonce=f"{self.read_counter:032x}"
        body={**{k:self.df.l.receipt[k] for k in live.FIELDS},
              "observed_epoch":NOW,"snapshot_nonce":nonce,
              "files":rows}
        return self.df.l.sign(body)

    def _trusted_site_reader(self,binding):
        if self.read_counter==1:
            self.read_counter+=1
            return self.live_base
        return self._site_signed_current()

    def execute(self,*,health=True,new_bytes=None,backup=None):
        f=self.op.f
        return pub130.execute_v3_mu_pair(
            wp_root=self.wp,private_backup_root=self.backup_root,
            site_binding=self.binding,origin=df.live_tests.ORIGIN,
            current_reader=self.site_reader,
            pinned_current_key=self.df.l.pub,
            approved_desired=self.df.row,
            desired_highwater=self.df.checkpoint,
            desired_public=df.pub(self.df.desired_key),
            desired_registry_public=df.pub(self.df.registry_key),
            entitlement=self.op.capability,
            entitlement_highwater=self.op.highwater,
            owner305_public=df.pub(self.op.owner),
            issuer312_public=f.hp,
            independent313_public=f.cp,
            operation_grant_public=f.gp,activity_public=f.ap,
            plan_signer_public=df.pub(self.op.plan_signer),
            v3_enrollment=f.enrollment,
            v3_connection_highwater=f.checkpoint,
            original318_readback=f.wp,
            operation_grant=f.grant,operation_activity=f.activity,
            signed_plan=self.op.signed_plan,
            signed_previous_backup=self.signed_backup if backup is None else backup,
            new_bytes=self.new_bytes if new_bytes is None else new_bytes,
            healthcheck=health if callable(health) else lambda root:health,
            now=NOW)

    def rebase_existing_pair(self):
        self.original_bytes={MU0:b"<?php // old loader\n",
                             MU1:b"<?php // old reader\n"}
        for path,raw in self.original_bytes.items():
            pub130._write_exact(self.wp,path,raw)
        self.read_counter=0
        self.live_base=self._site_signed_current()
        self.current=live.verify_current_receipt(
            self.live_base,self.binding,self.df.l.pub,
            df.live_tests.ORIGIN,now=NOW)
        base=self.live_base["files"]
        target=[]
        for item in base:
            path=item["relative_path"]
            if path in self.new_bytes:
                raw=self.new_bytes[path]
                target.append({"relative_path":path,"state":"PRESENT",
                    "sha256":hashlib.sha256(raw).hexdigest(),
                    "size_bytes":len(raw)})
            else:target.append(dict(item))
        self.df.row["base_files"]=copy.deepcopy(base)
        self.df.row["files"]=copy.deepcopy(target)
        self.df.sign_desired()
        self.approved=v.verify_desired(
            self.df.row,self.binding,df.pub(self.df.desired_key),
            self.df.checkpoint,df.pub(self.df.registry_key),now=NOW)
        self.signed_backup["source_current_snapshot_sha256"]=self.current.snapshot_sha256
        self.signed_backup["source_current_nonce"]=self.current.snapshot_nonce
        self.signed_backup["files"]=[dict(item) for item in base
                                     if item["relative_path"] in self.new_bytes]
        self.sign_backup()
        plan=self.op.signed_plan["plan"]
        plan["current_sha256"]=self.current.snapshot_sha256
        plan["initial_current_sha256"]=self.current.snapshot_sha256
        plan["desired_sha256"]=self.approved.signed_record_sha256
        plan["previous"]["snapshot_sha256"]=self.current.snapshot_sha256
        self.op.sign_plan()

    def test_failure_restores_two_previous_existing_files(self):
        self.rebase_existing_pair()
        with self.assertRaisesRegex(ConnectionDenied,"ORIGINAL318_APPLY_FAILED_ROLLED_BACK"):
            self.execute(health=False)
        self.assertEqual({p:(self.wp/p).read_bytes() for p in pub130.MU_PAIR},
                         self.original_bytes)
        backup=self.backup_root/self.op.f.grant["operation_id"]
        self.assertEqual((backup/"0.before").read_bytes(),self.original_bytes[MU0])
        self.assertEqual((backup/"1.before").read_bytes(),self.original_bytes[MU1])

    def test_second_file_write_fails_after_first_and_rolls_back(self):
        self.rebase_existing_pair()
        original=pub130._write_exact
        def fail_second(root,path,raw):
            if path==MU1 and raw==self.new_bytes[MU1]:
                raise OSError("simulated second write failure")
            return original(root,path,raw)
        with mock.patch.object(pub130,"_write_exact",fail_second):
            with self.assertRaisesRegex(ConnectionDenied,"ORIGINAL318_APPLY_FAILED_ROLLED_BACK"):
                self.execute()
        self.assertEqual({p:(self.wp/p).read_bytes() for p in pub130.MU_PAIR},
                         self.original_bytes)

    def test_post_replace_first_file_error_is_recoverable(self):
        self.rebase_existing_pair()
        original=pub130._write_exact
        def fsync_after_replace(root,path,raw):
            answer=original(root,path,raw)
            if path==MU0 and raw==self.new_bytes[MU0]:
                raise OSError("simulated fsync failure after rename")
            return answer
        with mock.patch.object(pub130,"_write_exact",fsync_after_replace):
            with self.assertRaisesRegex(ConnectionDenied,"ORIGINAL318_APPLY_FAILED_ROLLED_BACK"):
                self.execute()
        self.assertEqual({p:(self.wp/p).read_bytes() for p in pub130.MU_PAIR},
                         self.original_bytes)

    def test_rollback_preserves_concurrent_third_party_change(self):
        competing=b"<?php // third-party change\n"
        def changed(root):
            pub130._write_exact(self.wp,MU0,competing)
            return False
        with self.assertRaisesRegex(ConnectionDenied,"ORIGINAL318_RECOVERY_REQUIRED_CONCURRENT_WRITE"):
            self.execute(health=changed)
        self.assertEqual((self.wp/MU0).read_bytes(),competing)
        self.assertFalse((self.wp/MU1).exists())

    def test_symlinked_backup_ancestor_denied(self):
        outside=Path(self.tmp.name)/"other"
        outside.mkdir(mode=0o700)
        parent=Path(self.tmp.name)/"shortcut"
        parent.symlink_to(outside,target_is_directory=True)
        self.backup_root=parent
        with self.assertRaisesRegex(ConnectionDenied,"ORIGINAL318_PATH_SYMLINK_ANCESTOR"):
            self.execute()
        self.assertFalse((self.wp/MU0).exists())

    def test_signed_entitlement_can_publish_bounded_mu_pair(self):
        receipt=self.execute()
        self.assertTrue(receipt["write_performed"])
        self.assertTrue(receipt["postwrite_readback_verified"])
        self.assertFalse(receipt["rollback_required"])
        self.assertEqual((self.wp/MU0).read_bytes(),self.new_bytes[MU0])
        self.assertEqual((self.wp/MU1).read_bytes(),self.new_bytes[MU1])
        self.assertTrue((self.backup_root/self.op.f.grant["operation_id"]/"MANIFEST.json").is_file())

    def test_failed_wordpress_boot_restores_both_absent_files(self):
        with self.assertRaisesRegex(
                ConnectionDenied,"ORIGINAL318_APPLY_FAILED_ROLLED_BACK"):
            self.execute(health=False)
        for path in pub130.MU_PAIR:
            self.assertFalse((self.wp/path).exists())

    def test_wrong_artifact_hash_never_writes(self):
        candidate=dict(self.new_bytes)
        candidate[MU0]=b"<?php // TAMPERED"
        with self.assertRaisesRegex(ConnectionDenied,"ORIGINAL318_ARTIFACT_NOT_APPROVED"):
            self.execute(new_bytes=candidate)
        self.assertFalse((self.wp/MU0).exists())

    def test_revoked_root_standing_permission_never_writes(self):
        self.op.highwater["state"]="REVOKED"
        self.op.sign_highwater()
        with self.assertRaisesRegex(ConnectionDenied,"STANDING_WRITE_REVOKED_OR_STALE"):
            self.execute()
        self.assertFalse((self.wp/MU0).exists())

    def test_unsigned_previous_backup_never_writes(self):
        wrong=copy.deepcopy(self.signed_backup)
        wrong["signature"]="AA=="
        with self.assertRaisesRegex(ConnectionDenied,"PREVIOUS_BACKUP_SIGNATURE_INVALID"):
            self.execute(backup=wrong)
        self.assertFalse((self.wp/MU0).exists())

    def test_missing_private_backup_denied(self):
        self.backup_root.chmod(0o755)
        with self.assertRaisesRegex(ConnectionDenied,"ORIGINAL318_BACKUP_ROOT_NOT_PRIVATE"):
            self.execute()
        self.assertFalse((self.wp/MU0).exists())

    def test_symlink_in_target_denied_without_following_it(self):
        secret=Path(self.tmp.name)/"secret.txt"
        secret.write_bytes(b"outside")
        (self.wp/MU0).symlink_to(secret)
        with self.assertRaises(OSError):
            self.execute()
        self.assertEqual(secret.read_bytes(),b"outside")

    def test_extra_artifact_path_denied(self):
        candidates={**self.new_bytes,"wp-config.php":b"attack"}
        with self.assertRaisesRegex(ConnectionDenied,"ORIGINAL318_SIGNED_PLAN_OR_ARTIFACT_SET_DRIFT"):
            self.execute(new_bytes=candidates)
        self.assertEqual((self.wp/"wp-config.php").read_text(),
                         "<?php // test site only\n")

    def test_source_commit_mismatch_denied(self):
        self.op.signed_plan["plan"]["source_commit_sha"]="f"*40
        self.op.sign_plan()
        with self.assertRaisesRegex(ConnectionDenied,"ORIGINAL318_SIGNED_PLAN_OR_ARTIFACT_SET_DRIFT"):
            self.execute()

    def test_postwrite_independent_hash_mismatch_rolls_back(self):
        callback=self.site_reader
        def misleading(binding):
            if self.read_counter>=2 and (self.wp/MU0).exists():
                current=self._site_signed_current()
                current["files"][0]["sha256"]="f"*64
                return self.df.l.sign(current)
            return callback(binding)
        self.site_reader=misleading
        with self.assertRaisesRegex(ConnectionDenied,"ORIGINAL318_APPLY_FAILED_ROLLED_BACK"):
            self.execute()
        for path in pub130.MU_PAIR:
            self.assertFalse((self.wp/path).exists())

    def test_precommit_readback_file_change_denied(self):
        callback=self.site_reader
        def modified(binding):
            row=callback(binding)
            if self.read_counter==3:
                row["files"][-1]["sha256"]="f"*64
                row=self.df.l.sign(row)
            return row
        self.site_reader=modified
        with self.assertRaisesRegex(ConnectionDenied,"ORIGINAL318_ATOMIC_PRECOMMIT_CAS_FAILED"):
            self.execute()
        self.assertFalse((self.wp/MU0).exists())

if __name__=="__main__":unittest.main()
