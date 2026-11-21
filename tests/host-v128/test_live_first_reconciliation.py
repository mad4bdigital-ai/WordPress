"""Signed Desired/high-water plus live-first three-way atomic group tests."""
import base64
import copy
import hashlib
import sys
import unittest
from pathlib import Path
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey
from cryptography.hazmat.primitives.serialization import Encoding,PublicFormat
ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/"ops/host-authority-v128"))
import live_first_reconciliation as r
import live_current_source as lc
import test_live_current_source as live_tests
from persistent_enrollment import ConnectionDenied

NOW=1800000000
SITE_FILE="wp-content/plugins/mad4b-platform/modules/authority/318-mad4b-native-host-exact-grant.php"

def pub(key):
    return key.public_key().public_bytes(Encoding.Raw,PublicFormat.Raw)

def fil(path, digest, size):
    return {"relative_path":path, "state":"PRESENT" if digest!="ABSENT" else "ABSENT",
            "sha256":digest, "size_bytes":size}

class LiveFirstReconcileTests(unittest.TestCase):
    def setUp(self):
        self.l=live_tests.LiveCurrentSourceTests(
            "test_live_code_is_only_current_after_signed_site_readback")
        self.l.setUp()
        self.site=self.l.binding
        current_files=list(self.l.receipt["files"])
        current_files.append(fil(SITE_FILE,"e"*64,100))
        self.l.receipt=self.l.sign({**self.l.receipt, "files":current_files})
        self.current=self.l.current()
        self.desired_key=Ed25519PrivateKey.generate()
        self.registry_key=Ed25519PrivateKey.generate()
        target=[
            fil(live_tests.PATH_LOADER,"a"*64,500),
            fil(live_tests.PATH_READER,"b"*64,1000),
            fil(SITE_FILE,"c"*64,200),
        ]
        self.row={
            "contract":r.DESIRED_CONTRACT,"company":self.site.company,
            "environment":self.site.environment,"site_uuid":self.site.site_uuid,
            "target_host_id":self.site.host_id,
            "host_key_sha256":self.site.host_key_sha256,
            "policy_sha256":self.site.policy_sha256,
            "revision":2,"source_repo":r.SOURCE_REPO,"source_head_sha":"8"*40,
            "approval_nonce":"2"*32,"approved_epoch":NOW-2,"state":"APPROVED",
            "base_files":current_files,"files":copy.deepcopy(target),
            "deployment_groups":[
                {"group_id":"authority","paths":[live_tests.PATH_LOADER,
                                                  live_tests.PATH_READER]},
                {"group_id":"site","paths":[SITE_FILE]},
            ],
        }
        self.sign_desired()
        self.candidate={
            "contract":lc.CANDIDATE_CONTRACT,
            "company":self.site.company,"environment":self.site.environment,
            "site_uuid":self.site.site_uuid,"target_host_id":self.site.host_id,
            "source_head_sha":self.row["source_head_sha"],
            "files":copy.deepcopy(target),
        }

    def sign_desired(self):
        self.row={k:self.row[k] for k in r.DESIRED_FIELDS}
        self.row["signature"]=base64.b64encode(
            self.desired_key.sign(r.canonical_desired(self.row))).decode()
        self.checkpoint={
            "contract":r.DESIRED_HIGHWATER_CONTRACT,
            "company":self.site.company,"environment":self.site.environment,
            "site_uuid":self.site.site_uuid,"target_host_id":self.site.host_id,
            "revision":self.row["revision"],
            "desired_record_sha256":hashlib.sha256(
                r.canonical_desired(self.row)).hexdigest(),
            "state":"APPROVED","observed_epoch":NOW-1,"nonce":"3"*32,
        }
        self.sign_highwater()

    def sign_highwater(self):
        self.checkpoint={k:self.checkpoint[k] for k in r.HIGHWATER_FIELDS}
        self.checkpoint["signature"]=base64.b64encode(
            self.registry_key.sign(r.canonical_highwater(self.checkpoint))).decode()

    def desired(self):
        return r.verify_desired(self.row,self.site,pub(self.desired_key),
                                self.checkpoint,pub(self.registry_key),now=NOW)

    def plan(self):
        return r.reconcile(self.current,self.desired(),self.candidate,self.site)

    def denied(self, code):
        with self.assertRaisesRegex(ConnectionDenied, code):
            self.desired()

    def live_change(self,path,sha):
        original=[dict(x) for x in self.l.receipt["files"]]
        for x in original:
            if x["relative_path"]==path:
                x["sha256"]=sha
                x["state"]="PRESENT"
                x["size_bytes"]=125
        self.l.receipt=self.l.sign({**self.l.receipt,"files":original})
        self.current=self.l.current()

    def test_approved_desired_is_pinned_to_independent_registry(self):
        d=self.desired()
        self.assertEqual(d.approved_revision,2)
        self.assertEqual(d.source_head_sha,"8"*40)
        self.assertEqual(d.site_uuid,self.site.site_uuid)

    def test_two_groups_can_be_deployed_independently_after_authorization(self):
        p=self.plan()
        self.assertEqual(p["eligible_atomic_groups"],["authority","site"])
        self.assertEqual(p["blocked_atomic_groups"],[])
        self.assertFalse(p["host_write_authorized"])
        self.assertFalse(p["automatic_deployment_performed"])
        self.assertFalse(p["previous"]["verified"])

    def test_live_hotfix_is_preserved_without_blocking_other_group(self):
        self.live_change(live_tests.PATH_READER,"f"*64)
        p=self.plan()
        self.assertEqual(p["eligible_atomic_groups"],["site"])
        self.assertEqual(p["blocked_atomic_groups"],["authority"])
        self.assertEqual(p["groups"][0]["verdict"],"PRESERVE_LIVE_DRIFT_REVIEW")
        self.assertEqual(p["groups"][0]["change_paths"],[])
        self.assertEqual(p["groups"][0]["preserved_live_paths"],
                         [live_tests.PATH_READER])
        self.assertFalse(p["all_desired_realized"])

    def test_unchanged_approved_file_with_unexpected_live_edit_is_conflict(self):
        self.row["files"][-1]=self.row["base_files"][-1]
        self.sign_desired()
        self.candidate["files"][-1]=self.row["files"][-1]
        self.live_change(SITE_FILE,"f"*64)
        self.assertEqual(self.plan()["groups"][1]["verdict"],
                         "PRESERVE_LIVE_DRIFT_REVIEW")

    def test_sibling_files_remain_atomic(self):
        self.live_change(live_tests.PATH_READER,"f"*64)
        self.assertNotIn("authority",self.plan()["eligible_atomic_groups"])

    def test_unapproved_candidate_does_not_deploy(self):
        self.candidate["source_head_sha"]="9"*40
        p=self.plan()
        self.assertEqual(p["eligible_atomic_groups"],[])
        self.assertEqual(set(p["blocked_atomic_groups"]),{"authority","site"})

    def test_candidate_content_mismatch_is_unapproved_even_with_same_sha(self):
        self.candidate["files"][1]=fil(live_tests.PATH_READER,"f"*64,1000)
        p=self.plan()
        self.assertFalse(p["candidate_exactly_matches_desired"])
        self.assertEqual(p["eligible_atomic_groups"],[])

    def test_full_live_match_does_not_trigger_redeployment(self):
        self.l.receipt=self.l.sign({**self.l.receipt,
                                    "files":copy.deepcopy(self.row["files"])})
        self.current=self.l.current()
        p=self.plan()
        self.assertTrue(p["all_desired_realized"])
        self.assertEqual(p["eligible_atomic_groups"],[])
        self.assertEqual(p["blocked_atomic_groups"],[])

    def test_live_to_source_returns_review_request_never_pushes(self):
        self.live_change(live_tests.PATH_READER,"f"*64)
        q=r.propose_live_source_review(self.current,self.desired(),self.site)
        self.assertTrue(q["review_required"])
        self.assertEqual(len(q["live_divergence"]),1)
        self.assertEqual(len(q["desired_pending_paths"]),2)
        self.assertFalse(q["auto_commit_to_git"])
        self.assertFalse(q["auto_update_desired"])
        self.assertFalse(q["auto_deploy"])

    def test_modified_signed_desired_denied(self):
        self.row["files"][-1]["sha256"]="7"*64
        self.denied("DESIRED_SIGNATURE_INVALID")

    def test_old_signed_desired_replay_rejected_by_registry_highwater(self):
        self.row["revision"]=1
        self.row["signature"]=base64.b64encode(
            self.desired_key.sign(r.canonical_desired(self.row))).decode()
        self.denied("DESIRED_REGISTRY_NOT_CURRENT")

    def test_revoked_registry_highwater_rejected(self):
        self.checkpoint["state"]="REVOKED"
        self.sign_highwater()
        self.denied("DESIRED_REGISTRY_NOT_CURRENT")

    def test_stale_registry_highwater_rejected(self):
        self.checkpoint["observed_epoch"]=NOW-40
        self.sign_highwater()
        self.denied("DESIRED_REGISTRY_NOT_CURRENT")

    def test_highwater_signature_tampering_rejected(self):
        self.checkpoint["signature"]=base64.b64encode(b"0"*64).decode()
        self.denied("DESIRED_REGISTRY_SIGNATURE_INVALID")

    def test_independent_signer_required(self):
        with self.assertRaisesRegex(ConnectionDenied,"INDEPENDENT_SIGNERS_REQUIRED"):
            r.verify_desired(self.row,self.site,pub(self.desired_key),
                             self.checkpoint,pub(self.desired_key),now=NOW)

    def test_cross_company_desired_denied(self):
        self.row["company"]="wayfatours"
        self.denied("DESIRED_SITE_BINDING_MISMATCH")

    def test_group_overlap_rejected(self):
        self.row["deployment_groups"][1]["paths"].append(live_tests.PATH_READER)
        with self.assertRaisesRegex(ConnectionDenied,"ATOMIC_GROUPS_"):
            self.desired()

    def test_atomic_groups_cannot_omit_a_file(self):
        self.row["deployment_groups"][1]["paths"]=[]
        with self.assertRaisesRegex(ConnectionDenied,"ATOMIC_GROUPS_INVALID"):
            self.desired()

    def test_desired_cannot_deploy_unsupported_files(self):
        self.row["files"][-1]["relative_path"]="wp-config.php"
        with self.assertRaisesRegex(ConnectionDenied,"CURRENT_SOURCE_PATH_NOT_ALLOWED"):
            self.desired()

    def test_file_deletion_is_not_silent_deploy(self):
        self.row["files"][-1]=fil(SITE_FILE,"ABSENT",0)
        with self.assertRaisesRegex(ConnectionDenied,"DESIRED_UNSUPPORTED_DELETION"):
            self.desired()

    def test_candidate_cross_site_denied(self):
        self.candidate["site_uuid"]="22222222-2222-4222-8222-222222222222"
        with self.assertRaisesRegex(ConnectionDenied,"CANDIDATE_SCOPE_OR_SOURCE_INVALID"):
            self.plan()

    def test_candidate_with_missing_file_denied(self):
        self.candidate["files"]=self.candidate["files"][:-1]
        with self.assertRaisesRegex(ConnectionDenied,
                                    "CANDIDATE_MUST_HAVE_LIVE_PREIMAGE_FOR_ALL_FILES"):
            self.plan()

    def signed_backup(self, group_id="site"):
        group=next(row for row in self.plan()["groups"]
                   if row["group_id"]==group_id)
        files=[fil(path,self.current.file_map()[path][1],
                   self.current.file_map()[path][2])
               for path in group["paths"]]
        # The actual Root Host Runner would hash the sealed backup bytes.
        # Test signers here are ephemeral and have no operational authority.
        backup={
            "contract":r.BACKUP_CONTRACT,"company":self.site.company,
            "environment":self.site.environment,"site_uuid":self.site.site_uuid,
            "target_host_id":self.site.host_id,
            "source_current_snapshot_sha256":self.current.snapshot_sha256,
            "source_current_nonce":self.current.snapshot_nonce,
            "group_id":group_id,"files":files,
            "backup_bundle_sha256":"e"*64,"backup_receipt_id":"a"*32,
            "backup_kind":"host_private_rollback_store",
            "status":"SEALED_AND_HASH_VERIFIED","verified_epoch":NOW,
        }
        return self.sign_backup(backup)

    def sign_backup(self, backup):
        row={k:backup[k] for k in r.BACKUP_FIELDS}
        row["signature"]=base64.b64encode(
            self.l.key.sign(r.canonical_backup(row))).decode()
        return row

    def atomic(self, group_id="site", backup=None):
        if backup is None:backup=self.signed_backup(group_id)
        return r.prepare_atomic_group_operation(
            self.current,self.desired(),self.candidate,self.site,
            group_id,backup,lambda binding:self.l.receipt,
            self.l.pub,live_tests.ORIGIN,now=NOW)

    def test_signed_previous_is_accepted_only_as_evidence(self):
        plan=self.plan()
        selected=next(x for x in plan["groups"] if x["group_id"]=="site")
        evidence=r.verify_previous_backup(
            self.signed_backup(),self.current,self.site,selected,self.l.pub,now=NOW)
        self.assertTrue(evidence["verified"])
        self.assertEqual(evidence["snapshot_sha256"],self.current.snapshot_sha256)
        self.assertFalse(plan["previous"]["verified"])

    def test_no_new_readback_blocks_even_with_valid_backup(self):
        with self.assertRaisesRegex(
                ConnectionDenied,"PRECOMMIT_LIVE_CURRENT_CHANGED_OR_NOT_FRESH"):
            self.atomic()

    def test_fresh_unchanged_live_readback_prepares_but_never_executes(self):
        self.l.receipt=self.l.sign({
            **self.l.receipt,"snapshot_nonce":"4"*32,"observed_epoch":NOW})
        proposal=self.atomic()
        self.assertEqual(proposal["group_id"],"site")
        self.assertTrue(proposal["previous"]["verified"])
        self.assertFalse(proposal["host_write_authorized"])
        self.assertFalse(proposal["operation_executed"])
        self.assertFalse(proposal["desired_promoted"])
        self.assertTrue(proposal["executor_must_recheck_live_preimage_at_commit"])
        self.assertEqual(proposal["paths"],[SITE_FILE])

    def test_live_edit_during_backup_window_blocks_operation(self):
        backup=self.signed_backup()
        original=[dict(x) for x in self.l.receipt["files"]]
        original[-1]["sha256"]="f"*64
        self.l.receipt=self.l.sign({
            **self.l.receipt,"files":original,
            "snapshot_nonce":"4"*32,"observed_epoch":NOW})
        with self.assertRaisesRegex(
                ConnectionDenied,"PRECOMMIT_LIVE_CURRENT_CHANGED_OR_NOT_FRESH"):
            self.atomic(backup=backup)

    def test_backup_from_another_company_denied(self):
        backup=self.signed_backup()
        backup["company"]="wayfatours"
        backup=self.sign_backup(backup)
        group=self.plan()["groups"][1]
        with self.assertRaisesRegex(
                ConnectionDenied,"PREVIOUS_BACKUP_PREIMAGE_OR_SCOPE_MISMATCH"):
            r.verify_previous_backup(
                backup,self.current,self.site,group,self.l.pub,now=NOW)

    def test_backup_cannot_be_reused_for_another_group(self):
        backup=self.signed_backup()
        group=self.plan()["groups"][0]
        with self.assertRaisesRegex(
                ConnectionDenied,"PREVIOUS_BACKUP_PREIMAGE_OR_SCOPE_MISMATCH"):
            r.verify_previous_backup(
                backup,self.current,self.site,group,self.l.pub,now=NOW)

    def test_backup_with_changed_preimage_rejected(self):
        backup=self.signed_backup()
        backup["files"][0]["sha256"]="f"*64
        backup=self.sign_backup(backup)
        group=self.plan()["groups"][1]
        with self.assertRaisesRegex(ConnectionDenied,"PREVIOUS_BACKUP_CONTENT_DRIFT"):
            r.verify_previous_backup(
                backup,self.current,self.site,group,self.l.pub,now=NOW)

    def test_backup_not_signed_by_enrolled_runner_rejected(self):
        backup=self.signed_backup()
        backup["signature"]=base64.b64encode(b"x"*64).decode()
        group=self.plan()["groups"][1]
        with self.assertRaisesRegex(ConnectionDenied,"PREVIOUS_BACKUP_SIGNATURE_INVALID"):
            r.verify_previous_backup(
                backup,self.current,self.site,group,self.l.pub,now=NOW)

    def test_backup_not_sealed_rejected(self):
        backup=self.signed_backup()
        backup["status"]="UNVERIFIED"
        backup=self.sign_backup(backup)
        group=self.plan()["groups"][1]
        with self.assertRaisesRegex(
                ConnectionDenied,"PREVIOUS_BACKUP_RECEIPT_SHAPE_INVALID"):
            r.verify_previous_backup(
                backup,self.current,self.site,group,self.l.pub,now=NOW)

    def test_backup_cannot_be_produced_before_live_observation(self):
        backup=self.signed_backup()
        backup["verified_epoch"]=NOW-100
        backup=self.sign_backup(backup)
        group=self.plan()["groups"][1]
        with self.assertRaisesRegex(
                ConnectionDenied,"PREVIOUS_BACKUP_NOT_FRESH_OR_PINNED"):
            r.verify_previous_backup(
                backup,self.current,self.site,group,self.l.pub,now=NOW)

    def test_blocked_group_cannot_be_prepared_despite_backup(self):
        backup=self.signed_backup(group_id="authority")
        self.live_change(live_tests.PATH_READER,"f"*64)
        with self.assertRaisesRegex(ConnectionDenied,"ATOMIC_GROUP_NOT_ELIGIBLE"):
            self.atomic(group_id="authority",backup=backup)

    def test_review_requested_from_another_site_rejected(self):
        other=copy.copy(self.site)
        from fleet_policy import SiteBinding
        foreign=SiteBinding(**{**other.__dict__,"site_uuid":"22222222-2222-4222-8222-222222222222"})
        with self.assertRaisesRegex(ConnectionDenied,"LIVE_TO_SOURCE_SITE_DRIFT"):
            r.propose_live_source_review(self.current,self.desired(),foreign)

if __name__=="__main__": unittest.main()
